<?php

namespace ThomasVantuycom\FlysystemCloudinary;

use Cloudinary\Api\ApiResponse;
use Cloudinary\Api\Exception\NotFound;
use Cloudinary\Asset\AssetType;
use Cloudinary\Cloudinary;
use Generator;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToGeneratePublicUrl;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use Throwable;

class CloudinaryAdapter implements FilesystemAdapter, PublicUrlGenerator
{
    private Cloudinary $client;

    private MimeTypeDetector $mimeTypeDetector;

    private bool $dynamicFolders;

    public function __construct(
        Cloudinary $client,
        MimeTypeDetector $mimeTypeDetector = null,
        bool $dynamicFolders = true
    ) {
        $this->client = $client;
        $this->mimeTypeDetector = $mimeTypeDetector ?? new FinfoMimeTypeDetector();
        $this->dynamicFolders = $dynamicFolders;
    }

    public function fileExists(string $path): bool
    {
        try {
            $this->resource($path);

            return true;
        } catch (NotFound $e) {
            return false;
        } catch (Throwable $e) {
            throw UnableToCheckFileExistence::forLocation($path, $e);
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            $this->client->adminApi()->subFolders($path, [
                'max_results' => 1,
            ]);

            return true;
        } catch (NotFound $e) {
            return false;
        } catch (Throwable $e) {
            throw UnableToCheckDirectoryExistence::forLocation($path, $e);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $contents);
            rewind($stream);

            $this->writeStream($path, $stream, $config);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $resourceType = $this->resourceType($path);
            $publicId = $this->publicId($path, $resourceType);

            $options = [
                'public_id' => $publicId,
                'resource_type' => $resourceType,
                'filename' => $path,
                'overwrite' => true,
                'invalidate' => true,
            ];

            if ($this->dynamicFolders && ($folder = $this->folder($path)) !== '') {
                $options['asset_folder'] = $folder;
            }

            $this->client->uploadApi()->upload($contents, $options);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function read(string $path): string
    {
        try {
            $stream = $this->readStream($path);

            return stream_get_contents($stream);
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function readStream(string $path)
    {
        try {
            $publicUrl = $this->publicUrl($path, new Config());

            return fopen($publicUrl, 'rb');
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function delete(string $path): void
    {
        try {
            $resourceType = $this->resourceType($path);
            $publicId = $this->publicId($path, $resourceType);

            $this->client->uploadApi()->destroy($publicId, [
                'resource_type' => $resourceType,
                'type' => 'upload',
                'invalidate' => true,
            ]);
        } catch (Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            foreach ($this->listContents($path, true) as $item) {
                if ($item->isFile()) {
                    $this->delete($item->path());
                }
            }

            $this->client->adminApi()->deleteFolder($path);
        } catch (NotFound $e) {
            // Silently fail when the remote folder did not exist?
        } catch (Throwable $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->client->adminApi()->createFolder($path);
        } catch (Throwable $e) {
            throw UnableToCreateDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'Cloudinary does not support this operation.');
    }

    public function visibility(string $path): FileAttributes
    {
        try {
            $this->resource($path);

            return new FileAttributes($path, visibility: Visibility::PUBLIC);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::visibility($path, $e->getMessage(), $e);
        }
    }

    public function mimeType(string $path): FileAttributes
    {
        try {
            $this->resource($path);
            $mimeType = $this->mimeTypeDetector->detectMimeTypeFromPath($path);

            if ($mimeType === null) {
                throw UnableToRetrieveMetadata::mimeType($path);
            }

            return new FileAttributes($path, mimeType: $mimeType);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::mimeType($path, $e->getMessage(), $e);
        }
    }

    public function lastModified(string $path): FileAttributes
    {
        try {
            $resource = $this->resource($path);
            $lastModified = strtotime($resource['created_at']);

            return new FileAttributes($path, lastModified: $lastModified);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::lastModified($path, $e->getMessage(), $e);
        }
    }

    public function fileSize(string $path): FileAttributes
    {
        try {
            $resource = $this->resource($path);
            $fileSize = $resource['bytes'];

            return new FileAttributes($path, fileSize: $fileSize);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::fileSize($path, $e->getMessage(), $e);
        }
    }

    public function listContents(string $path, bool $deep): iterable
    {
        try {
            if ($this->dynamicFolders) {
                foreach ($this->listFilesByDynamicFolder($path) as $fileAttributes) {
                    yield $fileAttributes;
                }

                foreach ($this->listFolders($path, $deep) as $directoryAttributes) {
                    yield $directoryAttributes;

                    if ($deep) {
                        foreach ($this->listFilesByDynamicFolder($directoryAttributes->path()) as $fileAttributes) {
                            yield $fileAttributes;
                        }
                    }
                }
            } else {
                foreach ($this->listFilesByFixedFolder($path, $deep) as $fileAttributes) {
                    yield $fileAttributes;
                }

                foreach ($this->listFolders($path, $deep) as $directoryAttributes) {
                    yield $directoryAttributes;
                }
            }
        } catch (Throwable $e) {
            throw UnableToListContents::atLocation($path, $deep, $e);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $sourceResourceType = $this->resourceType($source);
            $sourcePublicId = $this->publicId($source, $sourceResourceType);

            $destinationResourceType = $this->resourceType($destination);
            $destinationPublicId = $this->publicId($destination, $destinationResourceType);

            $options = [
                'resource_type' => $destinationResourceType,
                'overwrite' => true,
                'invalidate' => true,
            ];

            if ($this->dynamicFolders && ($folder = $this->folder($destination)) !== '') {
                $options['asset_folder'] = $folder;
            }

            if ($destinationPublicId === $sourcePublicId) {
                $this->client->adminApi()->update($sourcePublicId, $options);
            } else {
                $this->client->uploadApi()->rename($sourcePublicId, $destinationPublicId, $options);
            }
        } catch (Throwable $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->writeStream($destination, $this->readStream($source), $config);
        } catch (Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function publicUrl(string $path, Config $config): string
    {
        try {
            $resourceType = $this->resourceType($path);
            $publicId = $this->publicId($path, $resourceType);

            $resource = $this->client->adminApi()->asset($publicId, [
                'resource_type' => $resourceType,
            ]);

            return $resource['secure_url'];
        } catch (Throwable $e) {
            throw UnableToGeneratePublicUrl::dueToError($path, $e);
        }
    }

    private function resource(string $path): ApiResponse
    {
        $resourceType = $this->resourceType($path);
        $publicId = $this->publicId($path, $resourceType);

        return $this->client->uploadApi()->explicit($publicId, [
            'resource_type' => $resourceType,
            'type' => 'upload',
        ]);
    }

    private function listFilesByDynamicFolder(string $path): Generator
    {
        do {
            $response = $this->client->adminApi()->assetsByAssetFolder($path, [
                'max_results' => 500,
                'next_cursor' => $response['next_cursor'] ?? null,
            ]);

            foreach ($response['resources'] as $resource) {
                yield $this->mapResourceAttributes($resource);
            }

        } while (isset($response['next_cursor']));
    }

    private function listFilesByFixedFolder(string $path, bool $deep): Generator
    {
        foreach ([AssetType::IMAGE, AssetType::VIDEO, AssetType::RAW] as $resourceType) {
            do {
                $response = $this->client->adminApi()->assets([
                    'resource_type' => $resourceType,
                    'type' => 'upload',
                    'prefix' => $path === '' ? '' : $path . '/',
                    'max_results' => 500,
                    'next_cursor' => $response['next_cursor'] ?? null,
                ]);

                foreach ($response['resources'] as $resource) {
                    if (! $deep && $resource['folder'] !== $path) {
                        continue;
                    }

                    if (! empty($resource['placeholder'])) {
                        continue;
                    }

                    yield $this->mapResourceAttributes($resource);
                }
            } while (isset($response['next_cursor']));
        }
    }

    private function listFolders(string $path, bool $deep): Generator
    {
        do {
            if ($path === '') {
                $response = $this->client->adminApi()->rootFolders([
                    'max_results' => 500,
                    'next_cursor' => $response['next_cursor'] ?? null,
                ]);
            } else {
                $response = $this->client->adminApi()->subFolders($path, [
                    'max_results' => 500,
                    'next_cursor' => $response['next_cursor'] ?? null,
                ]);
            }

            foreach ($response['folders'] as $folder) {
                $directoryAttributes = $this->mapFolderAttributes($folder);

                yield $directoryAttributes;

                if ($deep) {
                    yield from $this->listFolders($directoryAttributes->path(), $deep);
                }
            }
        } while (isset($response['next_cursor']));
    }

    private function mapResourceAttributes(array $resource): FileAttributes
    {
        $path = $resource['public_id'];

        if ($resource['resource_type'] !== AssetType::RAW) {
            $path .= '.' . $resource['format'];
        }

        if ($this->dynamicFolders && $resource['asset_folder'] !== '') {
            $path = $resource['asset_folder'] . '/' . $path;
        }

        $fileSize = $resource['bytes'];
        $visibility = 'public';
        $lastModified = strtotime($resource['created_at']);
        $mimeType = $this->mimeTypeDetector->detectMimeTypeFromPath($path);

        return new FileAttributes(
            $path,
            $fileSize,
            $visibility,
            $lastModified,
            $mimeType,
            extraMetadata: [
                'public_id' => $resource["public_id"],
                'asset_folder' => $resource["asset_folder"],
            ],
        );
    }

    private function mapFolderAttributes(array $folder): DirectoryAttributes
    {
        $path = $folder['path'];

        return new DirectoryAttributes(
            $path
        );
    }

    private function resourceType(string $path): string
    {
        $assetTypes = [
            // Image formats
            '3ds' => AssetType::IMAGE,
            'ai' => AssetType::IMAGE,
            'arw' => AssetType::IMAGE,
            'avif' => AssetType::IMAGE,
            'bmp' => AssetType::IMAGE,
            'bw' => AssetType::IMAGE,
            'cr2' => AssetType::IMAGE,
            'cr3' => AssetType::IMAGE,
            'djvu' => AssetType::IMAGE,
            'dng' => AssetType::IMAGE,
            'eps' => AssetType::IMAGE,
            'eps3' => AssetType::IMAGE,
            'ept' => AssetType::IMAGE,
            'fbx' => AssetType::IMAGE,
            'flif' => AssetType::IMAGE,
            'gif' => AssetType::IMAGE,
            'glb' => AssetType::IMAGE,
            'gltf' => AssetType::IMAGE,
            'hdp' => AssetType::IMAGE,
            'heic' => AssetType::IMAGE,
            'heif' => AssetType::IMAGE,
            'ico' => AssetType::IMAGE,
            'indd' => AssetType::IMAGE,
            'jp2' => AssetType::IMAGE,
            'jpe' => AssetType::IMAGE,
            'jpeg' => AssetType::IMAGE,
            'jpg' => AssetType::IMAGE,
            'jxl' => AssetType::IMAGE,
            'jxr' => AssetType::IMAGE,
            'obj' => AssetType::IMAGE,
            'pdf' => AssetType::IMAGE,
            'ply' => AssetType::IMAGE,
            'png' => AssetType::IMAGE,
            'ps' => AssetType::IMAGE,
            'psd' => AssetType::IMAGE,
            'svg' => AssetType::IMAGE,
            'tga' => AssetType::IMAGE,
            'tif' => AssetType::IMAGE,
            'tiff' => AssetType::IMAGE,
            'u3ma' => AssetType::IMAGE,
            'usdz' => AssetType::IMAGE,
            'wdp' => AssetType::IMAGE,
            'webp' => AssetType::IMAGE,

            // Video formats
            '3g2' => AssetType::VIDEO,
            '3gp' => AssetType::VIDEO,
            'avi' => AssetType::VIDEO,
            'flv' => AssetType::VIDEO,
            'm2ts' => AssetType::VIDEO,
            'mkv' => AssetType::VIDEO,
            'mov' => AssetType::VIDEO,
            'mp4' => AssetType::VIDEO,
            'mpeg' => AssetType::VIDEO,
            'mts' => AssetType::VIDEO,
            'mxf' => AssetType::VIDEO,
            'ogv' => AssetType::VIDEO,
            'ts' => AssetType::VIDEO,
            'webm' => AssetType::VIDEO,
            'wmv' => AssetType::VIDEO,

            // Audio formats
            'aac' => AssetType::VIDEO,
            'aiff' => AssetType::VIDEO,
            'amr' => AssetType::VIDEO,
            'flac' => AssetType::VIDEO,
            'm4a' => AssetType::VIDEO,
            'mp3' => AssetType::VIDEO,
            'ogg' => AssetType::VIDEO,
            'opus' => AssetType::VIDEO,
            'wav' => AssetType::VIDEO,
        ];

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $assetTypes[$extension] ?? AssetType::RAW;
    }

    private function publicId(string $path, string $resourceType): string
    {
        $pathInfo = pathinfo($path);
        $dirname = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];
        $extension = $pathInfo['extension'];

        $publicId = $filename;

        if ($resourceType === AssetType::RAW) {
            $publicId .= ".{$extension}";
        }

        if (! $this->dynamicFolders && $dirname !== '.') {
            $publicId = "{$dirname}/{$publicId}";
        }

        return $publicId;
    }

    private function folder(string $path): string
    {
        $dirname = pathinfo($path, PATHINFO_DIRNAME);

        return $dirname === '.' ? '' : $dirname;
    }
}
