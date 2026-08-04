<?php

namespace App\Providers;

use AzureOss\Identity\DefaultAzureCredential;
use AzureOss\Storage\Blob\BlobContainerClient;
use AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;

/**
 * Registers an `azure` filesystem driver backed by Azure Blob Storage.
 *
 * Document storage moves off the container filesystem for two reasons. Local
 * disks are bound to one machine, so a second application container would
 * serve uploads that only exist on the first. And a disk cannot express
 * retention: "delete unsuccessful applicants' CVs after six months" is a
 * lifecycle rule in object storage and a cron job you have to write and
 * maintain anywhere else.
 *
 * The download controllers are untouched by this. They read a stream from a
 * disk handle, so they neither know nor care what is underneath — which is
 * the whole reason the signed-URL work was built that way.
 */
class AzureStorageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Storage::extend('azure', function ($app, array $config): FilesystemAdapter {
            $account   = $config['account']   ?? throw new \InvalidArgumentException('Azure disk is missing "account".');
            $container = $config['container'] ?? throw new \InvalidArgumentException('Azure disk is missing "container".');

            /*
             * DefaultAzureCredential tries each credential source in turn:
             * environment variables first, then managed identity.
             *
             * That ordering is what makes the eventual move to Azure a
             * deployment change rather than a code change. Today the
             * application runs on EC2 and authenticates with a client secret
             * from AZURE_CLIENT_ID / AZURE_TENANT_ID / AZURE_CLIENT_SECRET.
             * On an Azure host those variables are simply absent, managed
             * identity answers instead, and no secret exists to store or
             * rotate at all.
             */
            $credential = new DefaultAzureCredential();

            $client = new BlobContainerClient(
                new Uri("https://{$account}.blob.core.windows.net/{$container}"),
                $credential,
            );

            $adapter = new AzureBlobStorageAdapter(
                containerClient: $client,
                prefix: $config['prefix'] ?? '',
            );

            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config,
            );
        });
    }
}
