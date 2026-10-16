<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit;

use Folklore\Image\Contracts\Signer;
use Folklore\Image\Exceptions\InvalidImageConfig;
use Folklore\Image\ImageConfig;
use Folklore\Image\ImageServiceProvider;
use Folklore\Image\Tests\TestCase;
use Folklore\Image\Urls\HmacSigner;
use Illuminate\Contracts\Config\Repository;

final class ImageServiceProviderTest extends TestCase
{
    public function test_the_service_provider_is_loaded(): void
    {
        $this->assertTrue($this->app?->providerIsLoaded(ImageServiceProvider::class));
    }

    public function test_the_package_config_is_the_default(): void
    {
        $config = $this->config();

        $this->assertSame('img', $config->get('image.url.prefix'));
        $this->assertTrue($config->get('image.strict'));
        $this->assertSame(3600, $config->get('image.signing.default_ttl'));
        $this->assertSame(['max_width' => 5000, 'max_height' => 5000, 'max_pixels' => 25_000_000, 'max_operations' => 10], $config->get('image.limits'));
        $this->assertSame(['serve' => 'stream', 'max_age' => 31536000], $config->get('image.delivery'));
    }

    public function test_a_published_config_only_needs_the_keys_it_changes(): void
    {
        $this->publish([
            'url' => ['host' => 'https://img.test'],
            'limits' => ['max_width' => 2000],
            'presets' => ['thumbnail' => ['width' => 200, 'height' => 200]],
        ]);
        $config = $this->config();

        $this->assertSame(['host' => 'https://img.test', 'prefix' => 'img'], $config->get('image.url'));
        $this->assertSame(2000, $config->get('image.limits.max_width'));
        $this->assertSame(5000, $config->get('image.limits.max_height'));
        $this->assertSame(['serve' => 'stream', 'max_age' => 31536000], $config->get('image.delivery'));
        $this->assertSame(['thumbnail' => ['width' => 200, 'height' => 200]], $config->get('image.presets'));
    }

    public function test_lists_replace_the_defaults(): void
    {
        $this->publish(['signing' => ['keys' => ['first', 'second']]]);

        $this->assertSame(['first', 'second'], $this->config()->get('image.signing.keys'));
        $this->assertSame(3600, $this->config()->get('image.signing.default_ttl'));
    }

    public function test_signing_keys_are_derived_from_the_application_keys(): void
    {
        $this->config()->set('app.key', 'base64:'.base64_encode('current-application-key'));
        $this->config()->set('app.previous_keys', ['previous-application-key']);
        $config = $this->imageConfig();

        $this->assertTrue($config->derivesSigningKeys());
        $keys = $config->signerKeys();
        $this->assertSame([
            hash_hmac('sha256', 'folklore/laravel-image signing key', 'current-application-key', true),
            hash_hmac('sha256', 'folklore/laravel-image signing key', 'previous-application-key', true),
        ], $keys);
        $this->assertNotContains('current-application-key', $keys);

        $previous = (new HmacSigner([$keys[1]]))->sign('payload');
        $this->assertTrue($this->signer()->verify('payload', $previous), 'URLs signed before a rotation of APP_KEY keep working.');
    }

    public function test_configured_signing_keys_are_used_first(): void
    {
        $this->config()->set('image.signing.keys', ['base64:'.base64_encode('decoded'), 'plain']);

        $this->assertFalse($this->imageConfig()->derivesSigningKeys());
        $this->assertSame(['decoded', 'plain'], $this->imageConfig()->signerKeys());
        $this->assertSame((new HmacSigner(['decoded']))->sign('payload'), $this->signer()->sign('payload'));
    }

    public function test_signing_needs_a_key(): void
    {
        $this->config()->set('app.key', null);
        $this->config()->set('app.previous_keys', []);

        $this->expectException(InvalidImageConfig::class);
        $this->signer();
    }

    public function test_invalid_values_fail_with_the_name_of_their_key(): void
    {
        $cases = [
            'image.strict' => ['yes', fn (ImageConfig $config) => $config->strict()],
            'image.url.prefix' => [12, fn (ImageConfig $config) => $config->prefix()],
            'image.signing.default_ttl' => [0, fn (ImageConfig $config) => $config->defaultTtl()],
            'image.limits.max_width' => ['big', fn (ImageConfig $config) => $config->limits()],
            'image.delivery.serve' => ['proxy', fn (ImageConfig $config) => $config->serve()],
            'image.signing.keys' => [[''], fn (ImageConfig $config) => $config->signingKeys()],
        ];

        foreach ($cases as $key => [$value, $read]) {
            $this->config()->set($key, $value);

            try {
                $read($this->imageConfig());
                $this->fail("{$key} accepted an invalid value.");
            } catch (InvalidImageConfig $exception) {
                $this->assertStringContainsString($key, $exception->getMessage());
            }

            $this->refreshApplication();
        }
    }

    /**
     * Boots the provider again with this as the published config/image.php.
     *
     * @param  array<mixed>  $config
     */
    private function publish(array $config): void
    {
        $this->config()->set('image', $config);
        $this->app?->register(ImageServiceProvider::class, force: true);
    }

    private function config(): Repository
    {
        $config = $this->app?->make(Repository::class);
        $this->assertInstanceOf(Repository::class, $config);

        return $config;
    }

    private function imageConfig(): ImageConfig
    {
        $config = $this->app?->make(ImageConfig::class);
        $this->assertInstanceOf(ImageConfig::class, $config);

        return $config;
    }

    private function signer(): Signer
    {
        $signer = $this->app?->make(Signer::class);
        $this->assertInstanceOf(Signer::class, $signer);

        return $signer;
    }
}
