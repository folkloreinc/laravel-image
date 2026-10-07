<?php

namespace Folklore\Image\Tests\Feature;

use Folklore\Image\Tests\TestCase;

/**
 * The URLs shown in README.md: they are generated as written, and served by the
 * default image route. Update the README when one of these changes.
 */
class ReadmeExamplesTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('image.routes', [
            'map' => null,
            'domain' => null,
            'namespace' => null,
            'middleware' => [],
            'controller' => '\Folklore\Image\Http\ImageController@serve',
            'pattern_name' => 'image_pattern',
            'cache_middleware' => 'image.middleware.cache',
        ]);
    }

    public function test_the_readme_urls_are_generated_as_written()
    {
        app('image')->filter('thumbnail', ['width' => 300, 'height' => 300, 'crop' => true]);

        $examples = [
            '/uploads/photo-filters(300x300-crop).jpg' => [300, 300, ['crop']],
            '/uploads/photo-filters(300x300-crop(top_left)-grayscale).jpg' => [300, 300, ['crop' => 'top_left', 'grayscale']],
            '/uploads/photo-filters(800x_).jpg.webp' => [800, null, ['format' => 'webp']],
            '/uploads/photo-filters(800x_-grayscale).jpg.webp' => [800, null, ['grayscale', 'format' => 'webp']],
            '/uploads/photo-filters(thumbnail).jpg' => [['thumbnail']],
        ];
        foreach ($examples as $url => $arguments) {
            $this->assertEquals($url, image_url('/uploads/photo.jpg', ...$arguments));
        }
    }

    public function test_the_readme_urls_are_served()
    {
        app('image')->filter('thumbnail', ['width' => 300, 'height' => 300, 'crop' => true]);
        $this->app['router']->image('readme/{pattern}', ['as' => 'image.readme', 'cache' => false]);

        $examples = [
            '/readme/image-filters(300x300-crop).jpg' => 'image/jpeg',
            '/readme/image-filters(300x300-crop(top_left)-grayscale).jpg' => 'image/jpeg',
            '/readme/image-filters(200x_-grayscale).jpg.webp' => 'image/webp',
            '/readme/image-filters(thumbnail).jpg' => 'image/jpeg',
        ];
        foreach ($examples as $url => $mime) {
            $response = $this->get($url);

            $response->assertOk();
            $this->assertEquals($mime, $response->headers->get('Content-Type'), $url);
        }
    }
}
