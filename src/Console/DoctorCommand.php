<?php

declare(strict_types=1);

namespace Folklore\Image\Console;

use Folklore\Image\Exceptions\InvalidImageConfig;
use Folklore\Image\ImageConfig;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Routing\Router;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Drivers\Vips\Driver as VipsDriver;
use Intervention\Image\Interfaces\DriverInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Checks what a deployment needs to render and serve images, and fails when something is
 * missing: the driver and its formats, FFI and libvips, the disks, the lock store, the
 * signing key, the route and the URLs.
 */
#[AsCommand(name: 'image:doctor')]
final class DoctorCommand extends Command
{
    private const string OK = 'ok';

    private const string WARN = 'warn';

    private const string FAIL = 'fail';

    /** The drivers, in the order `auto` tries them. */
    private const array DRIVERS = ['vips' => VipsDriver::class, 'imagick' => ImagickDriver::class, 'gd' => GdDriver::class];

    /** The formats derivatives are written in, then the formats only read. */
    private const array FORMATS = ['jpeg', 'png', 'gif', 'webp', 'avif'];

    private const array READ_FORMATS = ['heic'];

    protected $signature = 'image:doctor
        {--write : Also write, read and delete a file on the derivatives disk}';

    protected $description = 'Check the image driver, formats, disks, lock store, signing key and route';

    /** @var list<array{string, string, string}> */
    private array $checks = [];

    public function handle(Repository $config, ImageConfig $image, CacheFactory $cache, FilesystemFactory $filesystems, Router $router): int
    {
        $this->checks = [];

        $this->checkDriver($config);
        $this->checkFfi($config);
        $this->checkDisks($config, $filesystems);
        $this->checkLockStore($config, $image, $cache);
        $this->checkSigning($image);
        $this->checkRoute($image, $router);
        $this->checkUrls($image);

        $this->table(['Check', 'Status', 'Details'], array_map(
            fn (array $check) => [$check[0], match ($check[1]) {
                self::OK => '<info>ok</info>',
                self::WARN => '<comment>warning</comment>',
                default => '<error>failed</error>',
            }, $check[2]],
            $this->checks,
        ));

        $failed = count(array_filter($this->checks, fn (array $check) => $check[1] === self::FAIL));
        if ($failed > 0) {
            $this->error("{$failed} ".($failed === 1 ? 'check' : 'checks').' failed.');

            return self::FAILURE;
        }

        $this->info('Images can be rendered and served.');

        return self::SUCCESS;
    }

    private function checkDriver(Repository $config): void
    {
        $name = $config->get('image.renderer.driver', 'auto');
        $available = [];
        $errors = [];
        foreach (self::DRIVERS as $driverName => $class) {
            try {
                $driver = new $class;
                $driver->checkHealth();
                $available[$driverName] = $driver;
            } catch (Throwable $exception) {
                $errors[$driverName] = $exception->getMessage();
            }
        }

        $this->add('Drivers available', $available === [] ? self::FAIL : self::OK, $available === [] ? 'none: install libvips with intervention/image-driver-vips, ext-imagick or ext-gd' : implode(', ', array_keys($available)));

        if (! is_string($name) || ($name !== 'auto' && ! isset(self::DRIVERS[$name]))) {
            $this->add('Driver', self::FAIL, 'image.renderer.driver is auto, vips, imagick or gd, not '.(is_string($name) ? $name : get_debug_type($name)));

            return;
        }

        $driver = $name === 'auto' ? (array_values($available)[0] ?? null) : ($available[$name] ?? null);
        $used = $name === 'auto' ? (array_keys($available)[0] ?? null) : $name;
        if ($driver === null || $used === null) {
            $this->add('Driver', self::FAIL, $name === 'auto' ? 'no driver is available' : "{$name} is not available: ".($errors[$name] ?? ''));

            return;
        }

        $this->add('Driver', self::OK, "{$used} ".$this->version($driver).($name === 'auto' ? ' (auto)' : ''));
        $this->checkFormats($driver);
    }

    private function checkFormats(DriverInterface $driver): void
    {
        $supported = fn (string $format): bool => $this->supports($driver, $format);
        $written = array_values(array_filter(self::FORMATS, $supported));
        $missing = array_values(array_diff(self::FORMATS, $written));
        $read = array_values(array_filter(self::READ_FORMATS, $supported));

        $this->add('Formats', match (true) {
            ! in_array('jpeg', $written, true) || ! in_array('png', $written, true) => self::FAIL,
            $missing !== [] => self::WARN,
            default => self::OK,
        }, implode(', ', $written).($missing === [] ? '' : '; not '.implode(', ', $missing)).($read === [] ? '' : '; reads '.implode(', ', $read)));
    }

    private function checkFfi(Repository $config): void
    {
        $wantsVips = in_array($config->get('image.renderer.driver', 'auto'), ['auto', 'vips'], true) && class_exists(VipsDriver::class);
        $enabled = ini_get('ffi.enable');
        $enabled = $enabled === false ? '' : $enabled;

        if (! extension_loaded('ffi')) {
            $this->add('FFI', $wantsVips ? self::WARN : self::OK, 'ext-ffi is not loaded: libvips needs it');

            return;
        }

        $on = in_array(strtolower($enabled), ['1', 'true', 'on'], true);
        $this->add('FFI', $on || ! $wantsVips ? self::OK : self::WARN, "ffi.enable={$enabled}".match (true) {
            $on => '',
            $enabled === 'preload' => ': FFI works on the command line, not in web requests; libvips needs ffi.enable=true',
            default => ': libvips needs ffi.enable=true',
        });

        if (! class_exists(VipsDriver::class)) {
            $this->add('libvips', self::OK, 'not used: intervention/image-driver-vips is not installed');

            return;
        }

        try {
            $driver = new VipsDriver;
            $driver->checkHealth();
            $this->add('libvips', self::OK, $driver->version());
        } catch (Throwable $exception) {
            $this->add('libvips', $config->get('image.renderer.driver') === 'vips' ? self::FAIL : self::WARN, $exception->getMessage());
        }
    }

    private function checkDisks(Repository $config, FilesystemFactory $filesystems): void
    {
        $disks = [];
        $derivatives = $config->get('image.derivatives.disk');
        if (is_string($derivatives)) {
            $disks['Derivatives disk'] = $derivatives;
        }
        $source = $config->get('image.resolvers.disk.disk');
        if (is_string($source)) {
            $disks['Source disk'] = $source;
        }

        if ($disks === []) {
            $this->add('Disks', self::OK, 'none configured');
        }

        foreach ($disks as $label => $name) {
            $driver = $config->get("filesystems.disks.{$name}.driver");
            if (! is_string($driver)) {
                $this->add($label, self::FAIL, "{$name} is not a disk of config/filesystems.php");

                continue;
            }

            try {
                $disk = $filesystems->disk($name);
                $detail = "{$name} ({$driver})";
                if ($label === 'Derivatives disk' && $this->option('write') === true) {
                    $directory = $config->get('image.derivatives.path');
                    $path = ltrim((is_string($directory) ? trim($directory, '/') : '').'/.image-doctor-'.bin2hex(random_bytes(4)), '/');
                    $disk->put($path, 'image:doctor');
                    $written = $disk->get($path) === 'image:doctor';
                    $disk->delete($path);
                    if (! $written) {
                        throw new InvalidImageConfig('the file written could not be read back');
                    }
                    $detail .= ', written and read';
                }
                $this->add($label, self::OK, $detail);
            } catch (Throwable $exception) {
                $this->add($label, self::FAIL, "{$name}: {$exception->getMessage()}");
            }
        }
    }

    private function checkLockStore(Repository $config, ImageConfig $image, CacheFactory $cache): void
    {
        try {
            $name = $image->lockStore();
            $store = $cache->store($name)->getStore();
        } catch (Throwable $exception) {
            $this->add('Lock store', self::FAIL, $exception->getMessage());

            return;
        }

        $name ??= $config->get('cache.default');
        $driver = $config->get('cache.stores.'.(is_string($name) ? $name : '').'.driver');
        $label = (is_string($name) ? $name : '?').' ('.(is_string($driver) ? $driver : get_debug_type($store)).')';

        $this->add('Lock store', match (true) {
            ! $store instanceof LockProvider => self::FAIL,
            in_array($driver, ['array', 'null'], true) => self::WARN,
            $driver === 'file' => self::WARN,
            default => self::OK,
        }, match (true) {
            ! $store instanceof LockProvider => "{$label} has no locks",
            in_array($driver, ['array', 'null'], true) => "{$label} keeps locks in one process only: concurrent requests render the same image",
            $driver === 'file' => "{$label} is only shared on one server: use redis or database with several servers",
            default => $label,
        });
    }

    private function checkSigning(ImageConfig $image): void
    {
        try {
            $keys = $image->signerKeys();
        } catch (InvalidImageConfig $exception) {
            $this->add('Signing key', self::FAIL, $exception->getMessage());

            return;
        }

        $count = count($keys);
        $this->add('Signing key', self::OK, ($image->derivesSigningKeys() ? 'derived from APP_KEY' : 'image.signing.keys').($count > 1 ? ', with '.($count - 1).' previous '.($count === 2 ? 'key' : 'keys') : ''));
    }

    private function checkRoute(ImageConfig $image, Router $router): void
    {
        $router->getRoutes()->refreshNameLookups();
        $route = $router->getRoutes()->getByName('image');

        if ($route === null) {
            $this->add('Route', self::WARN, 'no image route: call Route::image() to serve image URLs');

            return;
        }

        $expected = ltrim($image->prefix().'/{path}', '/');
        $middleware = $route->gatherMiddleware();
        $this->add('Route', match (true) {
            in_array('web', $middleware, true) => self::WARN,
            ! str_ends_with($route->uri(), $expected) => self::WARN,
            default => self::OK,
        }, match (true) {
            in_array('web', $middleware, true) => "/{$route->uri()} has the web middleware: its session cookies keep CDNs from caching images",
            ! str_ends_with($route->uri(), $expected) => "/{$route->uri()} doesn't end with image.url.prefix ({$image->prefix()}), the prefix of the URLs built",
            default => "/{$route->uri()}",
        });
    }

    private function checkUrls(ImageConfig $image): void
    {
        $host = $image->host();
        $this->add('URL host', $host === null ? self::WARN : self::OK, $host ?? 'none: URLs use the application\'s URL; set IMAGE_URL to a CDN host');
        $this->add('Strict mode', $image->strict() ? self::OK : self::WARN, $image->strict() ? 'on' : 'off: unsigned URLs can ask for any spec within the limits');
    }

    private function supports(DriverInterface $driver, string $format): bool
    {
        try {
            return $driver->supports($format);
        } catch (Throwable) {
            return false;
        }
    }

    private function version(DriverInterface $driver): string
    {
        try {
            return $driver->version();
        } catch (Throwable) {
            return '';
        }
    }

    private function add(string $check, string $status, string $detail): void
    {
        $this->checks[] = [$check, $status, $detail];
    }
}
