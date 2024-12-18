<?php

namespace ThomasVantuycom\FlysystemCloudinary;

use Cloudinary\Cloudinary;
use Cloudinary\Api\Exception\NotFound;
use Cloudinary\Asset\AssetType;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use GuzzleHttp\Psr7\Utils;
use League\Flysystem\UnableToCopyFile;
use League\MimeTypeDetection\MimeTypeDetector;
use Throwable;
use Generator;

class CloudinaryAdapter implements FilesystemAdapter
{
    private Cloudinary $client;
    private MimeTypeDetector $mimeTypeDetector;
    private bool $dynamicFolders;

    public function __construct(
        Cloudinary $client,
        MimeTypeDetector $mimeTypeDetector = null,
        bool $dynamicFolders = false
    ) {
        $this->client = $client;
        $this->mimeTypeDetector = $mimeTypeDetector ?? new FinfoMimeTypeDetector();
        $this->dynamicFolders = $dynamicFolders;
    }

    public function fileExists(string $path): bool
    {
        try {
            $resourceType = $this->pathToResourceType($path);
            $publicId = $this->pathToPublicId($path, $resourceType);
            $resource = $this->client->adminApi()->asset($publicId, [
                "resource_type" => $resourceType,
            ]);

            if ($resource["bytes"] === 0) {
                return false;
            }
        } catch (NotFound $e) {
            return false;
        } catch (Throwable $e) {
            throw UnableToCheckFileExistence::forLocation($path, $e);
        }
        return true;
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
            $stream = Utils::streamFor($contents);
            $this->writeStream($path, $stream, $config);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $resourceType = $this->pathToResourceType($path);
            $publicId = $this->pathToPublicId($path, $resourceType);
            $options = [
                "filename" => $path,
                "invalidate" => true,
                "overwrite" => true,
                "public_id" => $publicId,
                "resource_type" => $resourceType,
            ];
            if ($this->dynamicFolders) {
                $folder = $this->pathToFolder($path);
                if ($folder !== "" && $folder !== ".") {
                    $options["asset_folder"] = $folder;
                }
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
            $contents = Utils::tryGetContents($stream);
            return $contents;
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function readStream(string $path)
    {
        try {
            $resourceType = $this->pathToResourceType($path);
            $publicId = $this->pathToPublicId($path, $resourceType);
            $url = $this->pathToUrl($publicId, $resourceType);
            $contents = Utils::tryFopen($url, "rb");
            return $contents;
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function delete(string $path): void
    {
        try {
            $resourceType = $this->pathToResourceType($path);
            $publicId = $this->pathToPublicId($path, $resourceType);
            $this->client->adminApi()->deleteAssets(
                [$publicId],
                [
                    "invalidate" => true,
                    "resource_type" => $resourceType,
                ]
            );
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
        throw UnableToSetVisibility::atLocation(
            $path,
            "Cloudinary does not support this operation."
        );
    }

    public function visibility(string $path): FileAttributes
    {
        try {
            $resourceType = $this->pathToResourceType($path);
            $publicId = $this->pathToPublicId($path, $resourceType);
            $this->client->adminApi()->asset($publicId, [
                "resource_type" => $resourceType,
            ]);
            return new FileAttributes($path, null, Visibility::PUBLIC);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::visibility($path, $e->getMessage(), $e);
        }
    }

    public function mimeType(string $path): FileAttributes
    {
        try {
            $resourceType = $this->pathToResourceType($path);
            $publicId = $this->pathToPublicId($path, $resourceType);
            $response = $this->client->adminApi()->asset($publicId, [
                "resource_type" => $resourceType,
            ]);
            $detector = new FinfoMimeTypeDetector();
            $mimeType = $detector->detectMimeTypeFromPath($path);
            if ($mimeType === null) {
                throw UnableToRetrieveMetadata::mimeType($path);
            }
            return new FileAttributes($path, null, null, null, $mimeType);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::mimeType($path, $e->getMessage(), $e);
        }
    }

    public function lastModified(string $path): FileAttributes
    {
        try {
            $resourceType = $this->pathToResourceType($path);
            $publicId = $this->pathToPublicId($path, $resourceType);
            $response = $this->client->adminApi()->asset($publicId, [
                "resource_type" => $resourceType,
            ]);
            $lastModified = strtotime(
                $response["last_updated"]["updated_at"] ?? $response["created_at"]
            );
            return new FileAttributes($path, null, null, $lastModified);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::lastModified($path, $e->getMessage(), $e);
        }
    }

    public function fileSize(string $path): FileAttributes
    {
        try {
            $resourceType = $this->pathToResourceType($path);
            $publicId = $this->pathToPublicId($path, $resourceType);
            $response = $this->client->adminApi()->asset($publicId, [
                "resource_type" => $resourceType,
            ]);
            $fileSize = $response["bytes"];
            return new FileAttributes($path, $fileSize);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::fileSize($path, $e->getMessage(), $e);
        }
    }

    private function mapResourceAttributes(array $resource): FileAttributes
    {
        $path = $resource["resource_type"] === "raw" ? $resource["public_id"] : $resource["public_id"] . "." . $resource["format"];
        if ($this->dynamicFolders) {
            if ($resource["asset_folder"] !== "") {
                $path = $resource["asset_folder"] . "/" . $path;
            }
        }
        $fileSize = $resource["bytes"];
        $visibility = "public";
        $lastModified = strtotime($resource["last_updated"]["updated_at"] ?? $resource["created_at"]);
        $mimeType = $this->mimeTypeDetector->detectMimeTypeFromPath($path);

        return new FileAttributes(
            $path,
            $fileSize,
            $visibility,
            $lastModified,
            $mimeType
        );
    }

    private function mapFolderAttributes(array $folder): DirectoryAttributes
    {
        $path = $folder["path"];

        return new DirectoryAttributes(
            $path
        );
    }

    private function listFilesByDynamicFolder(string $path): Generator
    {
        do {
            $response = $this->client->adminApi()->assetsByAssetFolder($path, [
                "max_results" => 500,
                "next_cursor" => $response["next_cursor"] ?? null,
            ]);

            foreach ($response['resources'] as $resource) {
                yield $this->mapResourceAttributes($resource);
            }

        } while (isset($response["next_cursor"]));
    }

    private function listFilesByFixedFolder(string $path, bool $deep): Generator
    {
        foreach ([AssetType::IMAGE, AssetType::VIDEO, AssetType::RAW] as $resourceType) {
            do {
                $response = $this->client->adminApi()->assets([
                    "resource_type" => $resourceType,
                    "type" => "upload",
                    "prefix" => $path === "" ? "" : $path . "/",
                    "max_results" => 500,
                    "next_cursor" => $response["next_cursor"] ?? null,
                ]);

                foreach ($response["resources"] as $resource) {
                    if (!$deep && $resource["folder"] !== $path) {
                        continue;
                    }

                    if (!empty($resource["placeholder"])) {
                        continue;
                    }

                    yield $this->mapResourceAttributes($resource);
                }
            } while (isset($response["next_cursor"]));
        }
    }

    private function listFolders(string $path, bool $deep): Generator
    {
        do {
            if ($path === "") {
                $response = $this->client->adminApi()->rootFolders([
                    "max_results" => 500,
                    "next_cursor" => $response["next_cursor"] ?? null,
                ]);
            } else {
                $response = $this->client->adminApi()->subFolders($path, [
                    "max_results" => 500,
                    "next_cursor" => $response["next_cursor"] ?? null,
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
            $resourceType = $this->pathToResourceType($source);
            $publicId = $this->pathToPublicId($source, $resourceType);
            $newResourceType = $this->pathToResourceType($destination);
            $newPublicId = $this->pathToPublicId($destination, $newResourceType);
            $options = [
                "invalidate" => true,
                "overwrite" => true,
                "resource_type" => $newResourceType,
            ];
            if ($this->dynamicFolders) {
                $folder = $this->pathToFolder($destination);
                if ($folder !== "" && $folder !== ".") {
                    $options["asset_folder"] = $folder;
                }
            }
            if ($newPublicId === $publicId) {
                $this->client->adminApi()->update($publicId, $options);        
            } else {
                $this->client->uploadApi()->rename($publicId, $newPublicId, $options);
            }
        } catch (Throwable $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $resourceType = $this->pathToResourceType($source);
            $publicId = $this->pathToPublicId($source, $resourceType);
            $url = $this->pathToUrl($publicId, $resourceType);
            $newResourceType = $this->pathToResourceType($destination);
            $newPublicId = $this->pathToPublicId($destination, $newResourceType);
            $options = [
                "overwrite" => true,
                "public_id" => $newPublicId,
                "resource_type" => $newResourceType,
            ];
            if ($this->dynamicFolders) {
                $folder = $this->pathToFolder($destination);
                if ($folder !== "" && $folder !== ".") {
                    $options["asset_folder"] = $folder;
                }
            }
            $this->client->uploadApi()->upload($url, $options);
        } catch (Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    private function pathToUrl(string $publicId, string $resourceType): string
    {
        return $this->client->$resourceType($publicId)->toUrl();
    }

    private function pathToPublicId(string $path, string $resourceType): string
    {
        // For resources of type 'raw', the extension is included in the Public ID.
        if ($resourceType === AssetType::RAW) {
            return $this->dynamicFolders ? pathinfo($path, PATHINFO_BASENAME) : $path;
        }

        // For resources of type 'image' or 'video', the extension is excluded from the Public ID.
        $pathInfo = pathinfo($path);
        $dirname = $pathInfo["dirname"];
        $filename = $pathInfo["filename"];
        return $dirname !== "." && !$this->dynamicFolders ? "$dirname/$filename" : $filename;
    }

    private function pathToResourceType(string $path): string
    {
        $mimeType = $this->mimeTypeDetector->detectMimeTypeFromPath($path);

        if ($mimeType === null) {
            return AssetType::RAW;
        }

        switch (true) {
            case str_starts_with($mimeType, "image/"):
            case $mimeType === "application/pdf":
                return AssetType::IMAGE;
            case str_starts_with($mimeType, "video/"):
            case str_starts_with($mimeType, "audio/"):
                return AssetType::VIDEO;
            default:
                return AssetType::RAW;
        }
    }

    private function pathToFolder(string $path): string
    {
        return pathinfo($path, PATHINFO_DIRNAME);
    }
}
