<?php

namespace ThomasVantuycom\FlysystemCloudinary\Tests;

use Cloudinary\Cloudinary;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use ThomasVantuycom\FlysystemCloudinary\CloudinaryAdapter;
use Generator;

class CloudinaryAdapterTest extends FilesystemAdapterTestCase {
    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        $client = new Cloudinary([
            "cloud" => [
                'cloud_name' => getenv('CLOUDINARY_CLOUD_NAME'), 
                'api_key' => getenv('CLOUDINARY_API_KEY'), 
                'api_secret' => getenv('CLOUDINARY_API_SECRET'),
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

    public static function filenameProvider(): Generator
    {
        yield "a path with square brackets in filename 1" => ["some/file[name].txt"];
        // yield "a path with square brackets in filename 2" => ["some/file[0].txt"];
        // yield "a path with square brackets in filename 3" => ["some/file[10].txt"];
        yield "a path with square brackets in dirname 1" => ["some[name]/file.txt"];
        // yield "a path with square brackets in dirname 2" => ["some[0]/file.txt"];
        // yield "a path with square brackets in dirname 3" => ["some[10]/file.txt"];
        yield "a path with curly brackets in filename 1" => ["some/file{name}.txt"];
        yield "a path with curly brackets in filename 2" => ["some/file{0}.txt"];
        yield "a path with curly brackets in filename 3" => ["some/file{10}.txt"];
        yield "a path with curly brackets in dirname 1" => ["some{name}/filename.txt"];
        yield "a path with curly brackets in dirname 2" => ["some{0}/filename.txt"];
        yield "a path with curly brackets in dirname 3" => ["some{10}/filename.txt"];
        yield "a path with space in dirname" => ["some dir/filename.txt"];
        yield "a path with space in filename" => ["somedir/file name.txt"];
    }
}
