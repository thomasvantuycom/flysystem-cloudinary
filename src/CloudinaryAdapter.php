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
use League\Flysystem\PathPrefixer;
use League\MimeTypeDetection\MimeTypeDetector;
use Throwable;

class CloudinaryAdapter implements FilesystemAdapter
{
    private Cloudinary $client;
    private PathPrefixer $prefixer;
    private MimeTypeDetector $mimeTypeDetector;
    private bool $dynamicFolders;

    public function __construct(
        Cloudinary $client,
        string $prefix = "",
        MimeTypeDetector $mimeTypeDetector = null,
        bool $dynamicFolders = false
    ) {
        $this->client = $client;
        $this->prefixer = new PathPrefixer($prefix);
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
            $path = $this->prefixer->prefixPath($path);
            $expression = "path=$path";
            $response = $this->client
                ->searchFoldersApi()
                ->expression($expression)
                ->maxResults(1)
                ->execute();
            return $response["total_count"] === 1;
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
            $path = $this->prefixer->prefixPath($path);
            if ($this->dynamicFolders) {
                $resources = [];
                $response = null;
                do {
                    $response = $this->client
                        ->searchApi()
                        ->expression("asset_folder=\"$path/*\"")
                        ->maxResults(500)
                        ->nextCursor($response["next_cursor"] ?? null)
                        ->execute();
                    array_push($resources, ...$response["resources"]);
                } while (isset($response["next_cursor"]));
                foreach ([AssetType::IMAGE, AssetType::VIDEO, AssetType::RAW] as $resourceType) {
                    $resourcesOfType = array_filter(
                        $resources,
                        fn($resource) => $resource["resource_type"] === $resourceType
                    );
                    for ($i = 0; $i < count($resourcesOfType); $i += 100) {
                        $this->client
                            ->adminApi()
                            ->deleteAssets(
                                array_map(
                                    fn($resource) => $resource["public_id"],
                                    array_slice($resources, $i, $i + 100)
                                ),
                                [
                                    "invalidate" => true,
                                    "resource_type" => $resourceType,
                                ]
                            );
                    }
                }
            } else {
                foreach ([AssetType::IMAGE, AssetType::VIDEO, AssetType::RAW] as $resourceType) {
                    $response = null;
                    do {
                        $response = $this->client->adminApi()->deleteAssetsByPrefix("$path/", [
                            "invalidate" => true,
                            "next_cursor" => $response["next_cursor"] ?? null,
                            "resource_type" => $resourceType,
                        ]);
                    } while (isset($response["next_cursor"]));
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
            $path = $this->prefixer->prefixPath($path);
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

    public function listContents(string $path, bool $deep): iterable
    {
        try {
            $path = $this->prefixer->prefixPath($path);
            $path = trim($path, "/");
            $originalPath = $path;
            if ($path === "" || $path === ".") {
                $expression = $deep ? "" : "folder=\"\"";
            } else {
                $expression = $deep ? "folder=\"$path/*\"" : "folder=\"$path\"";
            }
            $expression .= ($expression === "") ? "bytes > 0" : " AND bytes > 0";
            $response = null;
            do {
                $response = $this->client
                    ->searchApi()
                    ->expression($expression)
                    ->maxResults(500)
                    ->nextCursor($response["next_cursor"] ?? null)
                    ->execute();
                foreach ($response["resources"] as $resource) {
                    $path =
                        $resource["resource_type"] === "raw"
                            ? $resource["public_id"]
                            : $resource["public_id"] . "." . $resource["format"];
                    if ($this->dynamicFolders && $resource["asset_folder"] !== "") {
                        $path = $resource["asset_folder"] . "/" . $path;
                    }
                    $path = $this->prefixer->stripPrefix($path);
                    $filesize = $resource["bytes"];
                    $visibility = $resource["access_mode"] === "public" ? "public" : "private";
                    $lastModified = strtotime(
                        $resource["last_updated"]["updated_at"] ?? $resource["created_at"]
                    );
                    $detector = new FinfoMimeTypeDetector();
                    $mimeType = $detector->detectMimeTypeFromPath($path);
                    yield new FileAttributes(
                        $path,
                        $filesize,
                        $visibility,
                        $lastModified,
                        $mimeType
                    );
                }
            } while (isset($response["next_cursor"]));

            $path = $originalPath;
            if ($deep) {
                if ($path === "" || $path === "/" || $path === ".") {
                    $expression = "";
                } else {
                    $expression = "path=\"$path/*\"";
                }
                $response = null;
                do {
                    $response = $this->client
                        ->searchFoldersApi()
                        ->expression($expression)
                        ->maxResults(500)
                        ->nextCursor($response["next_cursor"] ?? null)
                        ->execute();
                    foreach ($response["folders"] as $resource) {
                        $path = $this->prefixer->stripPrefix($resource["path"]);
                        yield new DirectoryAttributes($path);
                    }
                } while (isset($response["next_cursor"]));
            } else {
                $response = null;
                do {
                    $response = $this->client->adminApi()->subFolders($path, [
                        "next_cursor" => $response["next_cursor"] ?? null,
                    ]);
                    foreach ($response["folders"] as $resource) {
                        $path = $this->prefixer->stripPrefix($resource["path"]);
                        yield new DirectoryAttributes($path);
                    }
                } while (isset($response["next_cursor"]));
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
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    private function pathToUrl(string $publicId, string $resourceType): string
    {
        return $this->client->$resourceType($publicId)->toUrl();
    }

    private function pathToPublicId(string $path, string $resourceType): string
    {
        $path = $this->prefixer->prefixPath($path);
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
        $path = $this->prefixer->prefixPath($path);
        return pathinfo($path, PATHINFO_DIRNAME);
    }
}
