<?php

namespace Folklore\Image\Tests\Unit;

use Folklore\Image\Tests\TestCase;
use Folklore\Image\Utils;
use Illuminate\Support\Facades\Http;

class UtilsConvertImageTest extends TestCase
{
    protected $results = [];

    protected function tearDown(): void
    {
        foreach ($this->results as $result) {
            @unlink($result);
        }

        parent::tearDown();
    }

    public function test_a_local_conversion_leaves_no_temporary_files()
    {
        $before = $this->temporaryFiles();

        $result = $this->convert(__DIR__.'/../fixture/image.jpg', 'image/png');

        $this->assertNotNull($result);
        $this->assertEquals('image/png', mime_content_type($result));
        $this->assertEquals($before, array_values(array_diff($this->temporaryFiles(), [$result])));
    }

    public function test_a_remote_conversion_leaves_no_temporary_files()
    {
        Http::fake([
            'https://images.example.com/*' => Http::response(file_get_contents(__DIR__.'/../fixture/image.jpg')),
        ]);
        $before = $this->temporaryFiles();

        $result = $this->convert('https://images.example.com/photo.jpg', 'image/png');

        $this->assertNotNull($result);
        $this->assertEquals('image/png', mime_content_type($result));
        $this->assertEquals($before, array_values(array_diff($this->temporaryFiles(), [$result])));
    }

    public function test_a_failed_conversion_leaves_no_temporary_files()
    {
        Http::fake([
            'https://images.example.com/missing.jpg' => Http::response('', 404),
            'https://images.example.com/broken.jpg' => Http::response('not an image'),
        ]);
        $before = $this->temporaryFiles();

        $this->assertNull($this->convert('https://images.example.com/missing.jpg', 'image/png'));
        $this->assertNull($this->convert('https://images.example.com/broken.jpg', 'image/png'));
        $this->assertNull($this->convert(__DIR__.'/../fixture/wrong.jpg', 'image/png'));

        $this->assertEquals($before, $this->temporaryFiles());
    }

    public function test_only_http_and_https_urls_are_fetched()
    {
        Http::fake();

        $this->assertNull($this->convert('ftp://images.example.com/photo.jpg', 'image/png'));
        $this->assertNull($this->convert('file:///etc/hosts', 'image/png'));

        Http::assertNothingSent();
    }

    public function test_an_allow_list_restricts_the_hosts_that_are_fetched()
    {
        $this->app['config']->set('image.utils.allowed_hosts', ['images.example.com']);
        Http::fake([
            'https://images.example.com/*' => Http::response(file_get_contents(__DIR__.'/../fixture/image.jpg')),
            '*' => Http::response(file_get_contents(__DIR__.'/../fixture/image.jpg')),
        ]);

        $this->assertNull($this->convert('https://internal.example.com/photo.jpg', 'image/png'));
        Http::assertNothingSent();

        $this->assertNotNull($this->convert('https://images.example.com/photo.jpg', 'image/png'));
    }

    protected function convert(string $path, string $mime): ?string
    {
        $result = Utils::convertImage($path, $mime);
        if ($result !== null) {
            $this->results[] = $result;
        }

        return $result;
    }

    protected function temporaryFiles(): array
    {
        $files = glob(sys_get_temp_dir().'/imgconv_*') ?: [];
        sort($files);

        return $files;
    }
}
