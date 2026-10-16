<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature;

use Folklore\Image\Contracts\Signer;
use Folklore\Image\Facades\Image;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Tests\Doubles\ArraySourceResolver;
use Folklore\Image\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;

/**
 * An application with presets, an `array` resolver of a few sources and a signing key.
 */
abstract class ImageTestCase extends TestCase
{
    protected const array CARD = ['width' => 800, 'ratio' => '16:9', 'widths' => [400, 800, 1200], 'formats' => ['avif', 'webp']];

    protected const array OG = ['width' => 1200, 'height' => 630, 'format' => 'jpeg'];

    protected ImageSource $photo;

    protected ImageSource $private;

    protected ImageSource $unsized;

    protected function setUp(): void
    {
        parent::setUp();

        $this->photo = new ImageSource('array', 'photos/2026/photo.jpg', '8c1f', width: 1200, height: 800, mime: 'image/jpeg');
        $this->private = new ImageSource('array', 'private.jpg', 'a1b2', width: 1200, height: 800, mime: 'image/jpeg', private: true);
        $this->unsized = new ImageSource('array', 'unsized.png', 'e5f6', mime: 'image/png');

        Image::resolver(new ArraySourceResolver($this->photo, $this->private, $this->unsized));
    }

    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);
        $config->set('image.url.host', 'https://img.test');
        $config->set('image.signing.keys', ['test-signing-key']);
        $config->set('image.presets', ['card' => self::CARD, 'og' => self::OG]);
    }

    protected function signer(): Signer
    {
        $signer = $this->app?->make(Signer::class);
        $this->assertInstanceOf(Signer::class, $signer);

        return $signer;
    }

    protected function config(): Repository
    {
        $config = $this->app?->make(Repository::class);
        $this->assertInstanceOf(Repository::class, $config);

        return $config;
    }
}
