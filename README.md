[![MIT license](http://img.shields.io/badge/license-MIT-brightgreen.svg)](http://opensource.org/licenses/MIT)
[![Packagist](https://img.shields.io/packagist/v/flownative/azure-blobstorage.svg)](https://packagist.org/packages/flownative/azure-blobstorage)
[![Packagist](https://img.shields.io/packagist/dm/flownative/azure-blobstorage)](https://packagist.org/packages/flownative/azure-blobstorage)
[![Maintenance level: Love](https://img.shields.io/badge/maintenance-%E2%99%A1%E2%99%A1%E2%99%A1-ff69b4.svg)](https://www.flownative.com/en/products/open-source.html)

# Azure Blob Storage Adaptor for Neos 7.x and Flow 7.x

This [Flow](https://flow.neos.io) package allows you to store assets (resources)
in [Azure Blob Storage](https://azure.microsoft.com/en-us/services/storage/blobs/)
and publish resources there. Because [Neos CMS](https://www.neos.io) is using
Flow's resource management under the hood, this adaptor also works nicely for
all kinds of assets in Neos.

## Key Features

- store all assets or only a specific collection in a private container
- publish assets to a private or public container
- supports GZIP compression for selected media types
- command line interface for basic tasks like connection check or republish resources

Using this connector, you can run a Neos website which does not store any asset
(images, PDFs etc.) on your webserver.

## Installation

The Flownative Azure Blob Storage connector is installed as a regular Flow
package via Composer. For your existing project, simply include
`flownative/azure-blobstorage` into the dependencies of your Flow or Neos
distribution:

```bash
$ composer require flownative/azure-blobstorage
```

## Configuration

### Credentials

In order to communicate with the Azure API, you need to provide the credentials
of an account which has access to ABS. Add the following configuration to the
`Settings.yaml` for your desired Flow context (for example in
`Configuration/Production/Settings.yaml`) and make sure to replace the
credentials with your own data:
  
```yaml
Flownative:
  Azure:
    BlobStorage:
      profiles:
        default:
          credentials:
            accountName: 'myaccountname'
            accountKey: 'myaccountkey'
```

Instead of using name and key with the default connection string (which is
`DefaultEndpointsProtocol=https;AccountName=myAccountName;AccountKey=myAccountKey`),
the connection string can also be specified directly. This allows for providing
the variations [described in the Azure documentation](https://docs.microsoft.com/en-us/azure/storage/common/storage-configure-connection-string).

```yaml
Flownative:
  Azure:
    BlobStorage:
      profiles:
        default:
          credentials:
            connectionString: 'UseDevelopmentStorage=true'
```

Right now, you can only define one connection profile, namely the "default"
profile. Additional profiles may be supported in future versions.

### Container Setup

You need one container each for use as a resource storage and a publishing
target. How you name them is up to you. The one used as the storage container
should **not** be publicly accessible, the container used as the publishing
target **must have** blobs publicly accessible. See the section on [configuring
anonymous public read access](https://docs.microsoft.com/en-us/azure/storage/blobs/anonymous-read-access-configure)
in the Azure documentation for instructions on how to do that.

### Testing the Setup

You can test your settings by executing the `connect` command with a container
of your choice.

```bash
$ ./flow abs:connect storage.example.net
```

## Publish Assets to Azure Blob Storage

Once the connector package is in place, you add a new publishing target which
uses that connect and assign this target to your collection.

```yaml
Neos:
  Flow:
    resource:
      collections:
        persistent:
          target: 'azurePersistentResourcesTarget'
      targets:
        azurePersistentResourcesTarget:
          target: 'Flownative\Azure\BlobStorage\AbsTarget'
          targetOptions:
            container: 'target.example.net'
            keyPrefix: '/'
            baseUri: 'https://myaccountname.blob.core.windows.net/target.example.net/'
```

Since the new publishing target will be empty initially, you need to publish
your assets to the new target by using the ``resource:publish`` command:

```bash
$ ./flow resource:publish
```

This command will upload your files to the target and use the calculated remote
URL for all your assets from now on.

## Switching the Storage of a Collection

If you want to migrate from your default local filesystem storage to a remote
storage, you need to copy all your existing persistent resources to that new
storage and use that storage afterwards by default.

You start by adding a new storage with the ABS connector to your configuration.
As you might want also want to serve your assets by the remote storage system,
you also add a target that contains your published resources.

```yaml
Neos:
  Flow:
    resource:
      storages:
        azurePersistentResourcesStorage:
          storage: 'Flownative\Azure\BlobStorage\AbsStorage'
          storageOptions:
            container: 'storage.example.net'
            keyPrefix: '/'
      targets:
        azurePersistentResourcesTarget:
          target: 'Flownative\Azure\BlobStorage\AbsTarget'
          targetOptions:
            container: 'target.example.net'
            keyPrefix: '/'
            baseUri: 'https://myaccountname.blob.core.windows.net/target.example.net/'
```

Some words regarding the configuration options:

The `keyPrefix` option allows you to share one container across multiple websites
or applications. All object keys will be prefixed by the given string.

The `baseUri` option defines the root of the publicly accessible address pointing
to your published resources. In the example above, baseUri points to a subdomain
which needs to be set up separately. If `baseUri` is empty, the Azure Blob
Storage Publishing Target will determine a public URL automatically.

In order to copy the resources to the new storage we need a temporary collection
that uses the storage and the new publication target.

```yaml
Neos:
  Flow:
    resource:
      collections:
        tmpNewCollection:
          storage: 'azurePersistentResourcesStorage'
          target: 'azurePersistentResourcesTarget'
```

Now you can use the ``resource:copy`` command:

```bash
$ ./flow resource:copy persistent tmpNewCollection --publish
```

This will copy all your files from your current storage (local filesystem) to
the new remote storage. The ``--publish`` flag means that this command also
publishes all the resources to the new target, and you have the same state on
your current storage and publication target as on the new one.

Now you can overwrite your old collection configuration and remove the temporary
one:

```yaml
Neos:
  Flow:
    resource:
      collections:
        persistent:
          storage: 'azurePersistentResourcesStorage'
          target: 'azurePersistentResourcesTarget'
```

Clear caches and you're done.

```bash
$ ./flow flow:cache:flush
```

## Two-Container Setup

Due to the way public access for blobs is handled in Azure Blob Storage, only
a two-container setup is possible: One container is private and one is publicly
accessible.

In a two-container setup, resources will be duplicated: the original is stored
in the "storage" container and then copied to the "target" container. Each time
a new resource is created or imported, it will be stored in the storage container
and then automatically published (i.e. copied) into the target container.

Om the positive side, this allows to have human- and SEO-friendly URLs pointing
to your resources, because objects copied into the target container can have a
more telling name which includes the original filename of the resource (check
the `publicPersistentResourceUris` options further below).

## Customizing the Public URLs

The Azure Blob Storage Target supports a way to customize the URLs which are
presented to the user. Even though the paths and filenames used for objects in
the containers are rather fixed (see above for the `baseUri` and `keyPrefix`
options), you may want to use a reverse proxy or content delivery network to
deliver resources stored in your target container. In that case, you can tell
the Target to render URLs according to your own rules. It is then your
responsibility to make sure that these URLs actually work.

Let's assume that we have set up a webserver acting as a reverse proxy. Requests
to `assets.flownative.com` are re-written so that using a URI like
`https://assets.flownative.com/a817…cb1/logo.svg` will actually deliver a file
stored in the Storage container using the given SHA1.

You can tell the Target to render URIs like these by defining a pattern with
placeholders:

```yaml
      targets:
        azurePersistentResourcesTarget:
          target: 'Flownative\Azure\BlobStorage\AbsTarget'
          targetOptions:
            container: 'flownativecom.flownative.cloud'
            baseUri: 'https://assets.flownative.com/'
            persistentResourceUris:
              pattern: '{baseUri}{sha1}/{filename}'
```

The possible placeholders are:

- `{baseUri}` The base URI as defined in the target options
- `{containerName}` The target's container name
- `{keyPrefix}` The target's configured key prefix
- `{sha1}` The resource's SHA1
- `{filename}` The resource's full filename, for example "logo.svg"
- `{fileExtension}` The resource's file extension, for example "svg"

For legacy and convenience reasons, the default pattern depends on the setup
being used:
 
 - no pattern and no baseUri set: `https://myaccountname.blob.core.windows.net/{containerName}/{keyPrefix}{sha1}`
 - no pattern set: `{baseUri}/{keyPrefix}{sha1}/{filename}`

The respective setup is auto-detected by the Target and the patterns set
accordingly. You may, of course, override the patterns, by specifying the
`pattern` setting as explained above.

## Dynamic Custom Base Uri

Your application may take the responsibility to render a base URI by registering
a custom method. After the options were set, the Target will call your method
and use the returned string as a base URI.

This mechanism allows you to tweak the domain, or other parts of the base URI,
depending on the current request. In the following example, we replace the domain
"example.com" by "replaced.com", using a custom base URI method.

```php
namespace Flownative\Test;

class BlobStorageDemo {
    /**
     * @param array $targetOptions
     * @return string
     */
    public function renderBaseUri(array $targetOptions): string
    {
        return str_replace('example.com', 'replaced.com', $targetOptions['baseUri']);
    }
}
```

```yaml
      targets:
        azurePersistentResourcesTarget:
          target: 'Flownative\Azure\BlobStorage\AbsTarget'
          targetOptions:
            customBaseUriMethod:
              objectName: 'Flownative\Test\BlobStorageDemo'
              methodName: 'renderBaseUri'
```

The following options are passed to your render method:
- targetClass
- containerName
- keyPrefix
- baseUri
- persistentResourceUriEnableSigning

## GZIP Compression

Azure Blob Storage supports GZIP compression for delivering files to the user,
however, these files need to be compressed outside Azure Blob Storage and then
uploaded as GZIP compressed data. This plugin supports transcoding resources on
the fly, while they are being published. Data in the *storage* is always stored
uncompressed, as-is. Files which are of one of the media types configured for
GZIP compression are automatically converted to GZIP while they are being
published to the *target*.

You can configure the compression level and the media types which should be
compressed as such:

```yaml
Neos:
  Flow:
    resource:
      targets:
        azurePersistentResourcesTarget:
          target: 'Flownative\Azure\BlobStorage\AbsTarget'
          targetOptions:
            gzipCompressionLevel: 9
            gzipCompressionMediaTypes:
            - 'text/plain'
            - 'text/css'
            - 'text/xml'
            - 'text/mathml'
            - 'text/javascript'
            - 'application/x-javascript'
            - 'application/xml'
            - 'application/rss+xml'
            - 'application/atom+xml'
            - 'application/javascript'
            - 'application/json'
            - 'application/x-font-woff'
            - 'image/svg+xml'
```

Note that adding media types for data which is already compressed – for example
images or movies – will likely rather increase the data size and thus should be
avoided.

## Full Example Configuration for ABS

```yaml
Neos:
  Flow:
    resource:
      storages:
        azurePersistentResourcesStorage:
          storage: 'Flownative\Azure\BlobStorage\AbsStorage'
          storageOptions:
            container: 'storage.example.net'
            keyPrefix: '/'
      collections:
        # Collection which contains all persistent resources
        persistent:
          storage: 'azurePersistentResourcesStorage'
          target: 'azurePersistentResourcesTarget'
      targets:
        localWebDirectoryPersistentResourcesTarget:
          target: 'Neos\Flow\ResourceManagement\Target\FileSystemTarget'
          targetOptions:
            path: '%FLOW_PATH_WEB%_Resources/Persistent/'
            baseUri: '_Resources/Persistent/'
            subdivideHashPathSegment: false
        azurePersistentResourcesTarget:
          target: 'Flownative\Azure\BlobStorage\AbsTarget'
          targetOptions:
            container: 'target.example.net'
            keyPrefix: '/'
            baseUri: 'https://demostorage.blob.core.windows.net/target.example.net/'

Flownative:
  Azure:
    BlobStorage:
      profiles:
        default:
          credentials:
            accountName: 'demostorage'
            accountKey: '…fgur674wurz…gfhsdjvru4brtfg…'
```
