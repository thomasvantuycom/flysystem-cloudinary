<?php

namespace ThomasVantuycom\FlysystemCloudinary\Tests;

use Cloudinary\Cloudinary;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use ThomasVantuycom\FlysystemCloudinary\CloudinaryAdapter;

class CloudinaryAdapterTest extends FilesystemAdapterTestCase {
    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        $client = new Cloudinary([
            "cloud" => [
                'cloud_name' => getenv('CLOUDINARY_CLOUD_NAME'), 
                'api_key' => getenv('CLOUDINARY_API_KEY'), 
                'api_secret' => getenv('CLOUDINARY_API_SECRET'),
            ],
            "url" => [
                "analytics" => false,
                "forceVersion" => false,
            ],
        ]);

        return new CloudinaryAdapter($client);
    }

    public function writing_a_file_with_an_empty_stream(): void
    {
        $this->markTestSkipped("Cloudinary doesn't support empty files.");
    }

    public function overwriting_a_file(): void
    {
        $this->runScenario(function () {
            $this->givenWeHaveAnExistingFile("path.txt", "contents");
            $adapter = $this->adapter();

            $adapter->write("path.txt", "new contents", new Config());

            $contents = $adapter->read("path.txt");
            $this->assertEquals("new contents", $contents);
        });
    }

    public function setting_visibility(): void
    {
        $this->markTestSkipped("Cloudinary doesn't support setting visibility.");
    }

    public function setting_visibility_on_a_file_that_does_not_exist(): void
    {
        $this->markTestSkipped("Cloudinary doesn't support setting visibility.");
    }
}
