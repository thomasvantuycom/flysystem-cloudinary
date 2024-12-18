# Flysystem adapter for Cloudinary

This is a [Flysystem](https://flysystem.thephpleague.com/docs/) adapter for [Cloudinary](https://cloudinary.com/). Although not the first of its kind, this package strives to be the ultimate and dependable Flysystem adapter for Cloudinary.

## Installation

```bash
composer require thomasvantuycom/flysystem-cloudinary
```

## Usage

Configure a Cloudinary client by supplying the cloud name, API key, and API secret accessible in the [Cloudinary console](https://console.cloudinary.com/pm/getting-started/). Then, pass the client to the adapter and initialize a new filesystem using the adapter.

```php
use Cloudinary\Cloudinary;
use League\Flysystem\Filesystem;
use ThomasVantuycom\FlysystemCloudinary\CloudinaryAdapter;

$client = new Cloudinary([
    'cloud' => [
        'cloud_name' => 'CLOUD_NAME',
        'api_key' => 'API_KEY',
        'api_secret' => 'API_SECRET',
    ],
    'url' => [
        'forceVersion' => false,
    ],
]);

$adapter = new CloudinaryAdapter($client);

$filesystem = new Filesystem($adapater);
```

### Storing assets in a subfolder

By default, the root folder of the filesystem corresponds to Cloudinary's root folder. If you prefer to store your assets in a subfolder on Cloudinary, you can create a path-prefixed adapter.

```php
use League\Flysystem\PathPrefixng\PathPrefixedAdapter;

$adapter = new CloudinaryAdapter($client, 'path/to/folder');
$pathPrefixedAdapter = new PathPrefixedAdapter($adapter, 'path/to/folder');
```

### Customizing mime type detection

By default, the adapter employs `League\MimeTypeDetection\FinfoMimeTypeDetector` for mime type detection and setting the resource type accordingly. If you wish to modify this behavior, you can supply a second argument to the Cloudinary adapter, implementing `League\MimeTypeDetection\MimeTypeDetector`.

```php
$adapter = new CloudinaryAdapter($client, $mimeTypeDetector);
```

### Enabling fixed folder mode.

By default, the adapter operates under the assumption that your Cloudinary cloud uses dynamic folder mode. If you wish to support the legacy fixed folder mode, set the third argument to `false`.

```php
$adapter = new CloudinaryAdapter($client, null, true);
```

## Limitations

- The adapter heavily relies on the Cloudinary admin API to implement most of Flysystem's operations. Because the admin API has rate limits, you may run into timeouts. The Cloudinary API poses challenges in how it distinguishes between images, videos, and other assets, making the task of ensuring seamless operation across all file types quite intricate and expensive in API calls. It's important to highlight that deleting folders can be particularly resource-intensive.
- The adapter is compatible with Cloudinary's dynamic folders mode. However, it operates on the assumption that the public IDs of your assets do not include a path.

## Testing

```bash
composer test
```

The tests exhibit some flakiness due to delays in Cloudinary's upload and delete responses.

## License

The MIT License.
