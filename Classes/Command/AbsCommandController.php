<?php
declare(strict_types=1);

namespace Flownative\Azure\BlobStorage\Command;

/*
 * This file is part of the Flownative.Azure.BlobStorage package.
 *
 * (c) Karsten Dambekalns, Flownative GmbH - www.flownative.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Doctrine\Common\Persistence\ObjectManager as DoctrineObjectManager;
use Doctrine\DBAL\Driver\Exception as DbalDriverException;
use Doctrine\ORM\EntityManagerInterface;
use Flownative\Azure\BlobStorage\AbsTarget;
use Flownative\Azure\BlobStorage\BlobServiceFactory;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\SetBlobPropertiesOptions;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\ResourceManagement\Storage\StorageObject;

/**
 * Azure Blob Storage command controller
 *
 * @Flow\Scope("singleton")
 */
final class AbsCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var BlobServiceFactory
     */
    protected $blobServiceFactory;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * Checks the connection
     *
     * This command checks if the configured credentials and connectivity allows for connecting with the Azure API.
     *
     * @param string $container The container which is used for trying to upload and retrieve some test data
     * @return void
     */
    public function connectCommand(string $container): void
    {
        try {
            $blobService = $this->blobServiceFactory->create();
        } catch (\Exception $e) {
            $this->outputLine('<error>%s</error>', [$e->getMessage()]);
            exit(1);
        }

        $this->outputLine('Writing test object into container (%s) ...', [$container]);
        try {
            $blobOptions = new CreateBlockBlobOptions();
            $blobOptions->setContentType('text/plain');
            $blobService->createBlockBlob($container, 'Flownative.Azure.BlobStorage.ConnectionTest.txt', 'I am a teapot', $blobOptions);
        } catch (ServiceException $e) {
            $this->outputLine('<error>%s</error>', [$e->getMessage()]);
            exit(1);
        }

        $this->outputLine('Retrieving test object from container ...');
        try {
            $blob = $blobService->getBlob($container, 'Flownative.Azure.BlobStorage.ConnectionTest.txt');
        } catch (ServiceException $e) {
            $this->outputLine('<error>%s</error>', [$e->getMessage()]);
            exit(1);
        }
        $this->outputLine('Content read back: <em>%s</em>', [fread($blob->getContentStream(), 200)]);

        $this->outputLine('Deleting test object from container ...');
        try {
            $blobService->deleteBlob($container, 'Flownative.Azure.BlobStorage.ConnectionTest.txt');
        } catch (ServiceException $e) {
            $this->outputLine('<error>%s</error>', [$e->getMessage()]);
            exit(1);
        }

        $this->outputLine('OK');
    }

    /**
     * Republish a collection
     *
     * This command forces publishing resources of the given collection by copying resources from the respective storage
     * to target container.
     *
     * @param string $collection Name of the collection to publish
     */
    public function republishCommand(string $collection = 'persistent'): void
    {
        [$resourceCollection, $target] = $this->getResourceCollectionAndTarget($collection);

        $this->outputLine('Republishing collection ...');
        $this->output->progressStart();
        try {
            foreach ($resourceCollection->getObjects() as $object) {
                /** @var StorageObject $object */
                $resource = $this->resourceManager->getResourceBySha1($object->getSha1());
                if ($resource) {
                    $target->publishResource($resource, $resourceCollection);
                }
                $this->output->progressAdvance();
            }
        } catch (\Exception $e) {
            $this->outputLine('<error>Publishing failed</error>');
            $this->outputLine($e->getMessage());
            $this->outputLine(get_class($e));
            exit(2);
        }
        $this->output->progressFinish();
        $this->outputLine();
    }

    /**
     * Update resource metadata
     *
     * This command iterates through all known resources of a collection and sets the metadata in the configured target.
     * The resource must exist in the target, but metadata like "content-type" will be updated.
     *
     * The resources are processed in alphabetical order of their SHA1 content hashes. That allows you to resume updates
     * at a specific resource (using the --start-sha1 option) in case a large update was interrupted.
     *
     * @param string $collection Name of the collection to publish
     * @param string|null $startSha1 If specified, updates are starting at this SHA1 in alphabetical order
     * @throws \Exception
     * @throws DbalDriverException
     */
    public function updateResourceMetadataCommand(string $collection = 'persistent', string $startSha1 = null): void
    {
        /** @var AbsTarget $target */
        [, $target] = $this->getResourceCollectionAndTarget($collection);
        $targetContainer = $target->getContainerName();

        $this->outputLine();
        $this->outputLine('Updating metadata for resources in container %s ...', [$targetContainer]);
        $this->outputLine();

        try {
            $blobService = $this->blobServiceFactory->create();
        } catch (\Exception $e) {
            $this->outputLine('<error>%s</error>', [$e->getMessage()]);
            exit(1);
        }

        if ($this->objectManager->isRegistered(EntityManagerInterface::class)) {
            $entityManager = $this->objectManager->get(EntityManagerInterface::class);
        } else {
            $entityManager = $this->objectManager->get(DoctrineObjectManager::class);
        }

        if ($startSha1 === null) {
            $queryResult = $entityManager->getConnection()->executeQuery(
                'SELECT sha1, filename, mediatype FROM neos_flow_resourcemanagement_persistentresource AS r WHERE collectionname = :collectionName ORDER BY sha1',
                ['collectionName' => $collection]
            );
        } else {
            $queryResult = $entityManager->getConnection()->executeQuery(
                'SELECT sha1, filename, mediatype FROM neos_flow_resourcemanagement_persistentresource AS r WHERE collectionname = :collectionName AND sha1 > :startSha1 ORDER BY sha1',
                [
                    'collectionName' => $collection,
                    'startSha1' => $startSha1
                ]
            );
        }

        try {
            $targetKeyPrefix = $target->getKeyPrefix();
            $previousSha1 = null;
            while ($resourceRecord = $queryResult->fetchAssociative()) {
                if ($resourceRecord['sha1'] === $previousSha1) {
                    continue;
                }
                $previousSha1 = $resourceRecord['sha1'];

                $setBlobPropertiesOptions = new SetBlobPropertiesOptions();
                $setBlobPropertiesOptions->setContentType($resourceRecord['mediatype']);
                try {
                    $blobService->setBlobProperties($targetContainer, $targetKeyPrefix . $resourceRecord['sha1'] . '/' . $resourceRecord['filename'], $setBlobPropertiesOptions);
                    $this->outputLine('   ✅  %s %s ', [$resourceRecord['sha1'], $resourceRecord['filename']]);
                } catch (ServiceException $exception) {
                    $this->outputLine('   ❌  <error>%s %s</error>', [$resourceRecord['sha1'], $resourceRecord['filename']]);
                    $this->outputLine('      %s', [$exception->getMessage()]);
                }
            }
        } catch (\Exception $e) {
            $this->outputLine('<error>Update failed</error>');
            $this->outputLine($e->getMessage());
            exit(2);
        }
        $this->outputLine();
    }

    /**
     * @param string $collectionName
     * @return array [CollectionInterface, TargetInterface]
     */
    private function getResourceCollectionAndTarget(string $collectionName): array
    {
        $resourceCollection = $this->resourceManager->getCollection($collectionName);
        if (!$resourceCollection) {
            $this->outputLine('<error>The collection %s does not exist.</error>', [$collectionName]);
            exit(1);
        }

        $target = $resourceCollection->getTarget();
        if (!$target instanceof AbsTarget) {
            $this->outputLine('<error>The target defined in collection %s is not an Azure Blob Storage target.</error>', [$collectionName]);
            exit(1);
        }
        return [$resourceCollection, $target];
    }
}
