<?php
declare(strict_types=1);

namespace Flownative\Azure\BlobStorage;

/*
 * This file is part of the Flownative.Azure.BlobStorage package.
 *
 * (c) Karsten Dambekalns, Flownative GmbH - www.flownative.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use Neos\Flow\Annotations as Flow;

/**
 * Factory for the Azure BlobRestProxy "blob service" class
 *
 * @Flow\Scope("singleton")
 */
class BlobServiceFactory
{
    private const CONNECTIONSTRING_TEMPLATE = 'DefaultEndpointsProtocol=https;AccountName=%1$s;AccountKey=%2$s';

    /**
     * @Flow\InjectConfiguration
     * @var array
     */
    protected $configuration;

    /**
     * Creates a new BlobRestProxy instance and authenticates against the Azure API
     *
     * @param string $credentialsProfileName
     * @return BlobRestProxy
     * @throws Exception
     */
    public function create(string $credentialsProfileName = 'default'): BlobRestProxy
    {
        if (!isset($this->configuration['profiles'][$credentialsProfileName])) {
            throw new Exception(sprintf('The specified Azure Blob Storage credentials profile "%s" does not exist, please check your settings.', $credentialsProfileName), 1621592468);
        }

        if (!empty($this->configuration['profiles'][$credentialsProfileName]['credentials']['connectionString'])) {
            $connectionString = $this->configuration['profiles'][$credentialsProfileName]['credentials']['connectionString'];
        } else {
            $connectionString = sprintf(
                self::CONNECTIONSTRING_TEMPLATE,
                $this->configuration['profiles'][$credentialsProfileName]['credentials']['accountName'],
                $this->configuration['profiles'][$credentialsProfileName]['credentials']['accountKey']
            );
        }

        return BlobRestProxy::createBlobService($connectionString);
    }
}
