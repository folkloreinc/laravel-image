<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Console;

use Folklore\Image\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\PendingCommand;

final class DoctorCommandTest extends TestCase
{
    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = sys_get_temp_dir().'/image-doctor-'.bin2hex(random_bytes(4));
        $config = $this->config();
        $config->set('image.renderer.driver', self::driverUnderTest() ?? 'auto');
        $config->set('image.url.host', 'https://img.test');
        $config->set('filesystems.disks.derivatives', ['driver' => 'local', 'root' => $this->disk]);
        $config->set('image.derivatives.disk', 'derivatives');
        $config->set('cache.default', 'array');
        $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->disk)) {
            rmdir($this->disk);
        }

        parent::tearDown();
    }

    public function test_a_working_setup_passes_with_its_warnings(): void
    {
        $this->doctor()
            ->expectsOutputToContain('Drivers available')
            ->expectsOutputToContain('derived from APP_KEY')
            ->expectsOutputToContain('keeps locks in one process only')
            ->expectsOutputToContain('no image route')
            ->expectsOutputToContain('derivatives (local)')
            ->expectsOutputToContain('Images can be rendered and served.')
            ->assertSuccessful();
    }

    public function test_the_driver_in_use_and_its_formats(): void
    {
        $driver = self::driverUnderTest();
        if ($driver !== null) {
            $this->driverOrSkip($driver);
        }

        $this->doctor()
            ->expectsOutputToContain($driver ?? '(auto)')
            ->expectsOutputToContain('jpeg, png')
            ->assertSuccessful();
    }

    public function test_an_unknown_or_missing_driver_fails(): void
    {
        $this->config()->set('image.renderer.driver', 'nope');

        $this->doctor()
            ->expectsOutputToContain('image.renderer.driver is auto, vips, imagick or gd, not nope')
            ->expectsOutputToContain('1 check failed.')
            ->assertFailed();
    }

    public function test_a_missing_signing_key_fails(): void
    {
        $this->config()->set('app.key', null);

        $this->doctor()->expectsOutputToContain('set one of them')->assertFailed();
    }

    public function test_configured_signing_keys_are_counted_and_never_printed(): void
    {
        $this->config()->set('image.signing.keys', ['first-secret', 'second-secret']);

        $this->doctor()
            ->expectsOutputToContain('image.signing.keys, with 1 previous key')
            ->doesntExpectOutputToContain('secret')
            ->assertSuccessful();
    }

    public function test_a_missing_disk_fails(): void
    {
        $this->config()->set('image.derivatives.disk', 'missing');

        $this->doctor()->expectsOutputToContain('missing is not a disk')->assertFailed();
    }

    public function test_write_checks_the_derivatives_disk(): void
    {
        $this->config()->set('image.derivatives.path', 'derivatives');

        $this->doctor(['--write' => true])
            ->expectsOutputToContain('written and read')
            ->assertSuccessful();

        $this->assertSame([], glob($this->disk.'/derivatives/.image-doctor-*'));
        rmdir($this->disk.'/derivatives');
    }

    public function test_a_lock_store_must_exist_and_be_shared(): void
    {
        $this->config()->set('cache.stores.files', ['driver' => 'file', 'path' => $this->disk]);
        $this->config()->set('image.derivatives.lock.store', 'files');
        $this->doctor()->expectsOutputToContain('only shared on one server')->assertSuccessful();

        $this->config()->set('image.derivatives.lock.store', 'missing');
        $this->doctor()->expectsOutputToContain('Cache store [missing] is not defined')->assertFailed();
    }

    public function test_the_route(): void
    {
        $this->imageRoute('img');
        $this->doctor()->expectsOutputToContain('/img/{path}')->assertSuccessful();
    }

    public function test_a_route_with_session_cookies_warns(): void
    {
        Route::middleware('web')->group(fn () => $this->imageRoute('img'));
        $this->doctor()->expectsOutputToContain('session cookies keep CDNs from caching')->assertSuccessful();
    }

    public function test_a_route_on_another_prefix_warns(): void
    {
        $this->imageRoute('pictures');
        $this->doctor()->expectsOutputToContain("doesn't end with image.url.prefix")->assertSuccessful();
    }

    public function test_the_url_host_and_strict_mode(): void
    {
        $this->config()->set('image.url.host', null);
        $this->config()->set('image.strict', false);

        $this->doctor()
            ->expectsOutputToContain('set IMAGE_URL to a CDN host')
            ->expectsOutputToContain('off: unsigned URLs')
            ->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function doctor(array $options = []): PendingCommand
    {
        $command = $this->artisan('image:doctor', $options);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function imageRoute(string $prefix): void
    {
        Route::get("{$prefix}/{path}", fn () => '')->where('path', '.+')->name('image');
    }

    private function config(): Repository
    {
        $config = $this->app?->make(Repository::class);
        $this->assertInstanceOf(Repository::class, $config);

        return $config;
    }
}
