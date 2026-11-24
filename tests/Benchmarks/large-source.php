<?php

declare(strict_types=1);

/*
 * Peak and resident memory of one render of a large JPEG, in a process of its own.
 *
 *     php -d ffi.enable=true tests/Benchmarks/large-source.php [driver] [spec] [renders]
 *
 * driver: vips (default), imagick or gd. spec: the width of an `inside` box (3200 by default,
 * as urbania.ca's `original-filters(high)`), or `cover` for a 1200×630 crop around a focal
 * point. The source, 10,000 × 5,625 with an embedded profile and an EXIF orientation, is
 * generated in the system's temporary directory on the first run.
 */

use Folklore\Image\Rendering\InterventionRenderer;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Fit;
use Folklore\Image\Specs\Focus;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Jcupitt\Vips\FFI;
use Jcupitt\Vips\Image as VipsImage;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

require __DIR__.'/../../vendor/autoload.php';

$driver = $argv[1] ?? 'vips';
$spec = $argv[2] ?? '3200';
$renders = (int) ($argv[3] ?? 1);

$directory = sys_get_temp_dir().'/laravel-image-benchmarks';
$file = 'large-10000x5625.jpg';
if (! is_file("{$directory}/{$file}")) {
    @mkdir($directory, 0777, true);
    // Noise with some structure, so the JPEG is about as heavy as a photo.
    $image = VipsImage::gaussnoise(10000, 5625, ['mean' => 128, 'sigma' => 40])
        ->bandjoin([
            VipsImage::xyz(10000, 5625)->extract_band(0)->linear(255 / 10000, 0),
            VipsImage::xyz(10000, 5625)->extract_band(1)->linear(255 / 5625, 0),
        ])
        ->cast('uchar')
        ->copy(['interpretation' => 'srgb']);
    // The Adobe RGB profile of a fixture, so the render converts colors to sRGB.
    $profile = VipsImage::newFromFile(__DIR__.'/../fixtures/images/metadata/adobe-rgb.jpg')->get('icc-profile-data');
    $image->setType(FFI::gtypes('VipsBlob'), 'icc-profile-data', $profile);
    $image->set('orientation', 6);
    $image->jpegsave("{$directory}/{$file}", ['Q' => 90]);
}

$filesystems = new class($directory) implements FilesystemFactory
{
    public function __construct(private string $root) {}

    public function disk($name = null): Filesystem
    {
        $adapter = new LocalFilesystemAdapter($this->root);

        return new FilesystemAdapter(new Flysystem($adapter), $adapter, ['root' => $this->root]);
    }
};

$renderer = new InterventionRenderer($filesystems, $driver);
$source = new ImageSource('disk', $file, disk: 'local', path: $file, mime: 'image/jpeg', focalPoint: new Focus(0.3, 0.6));
$spec = $spec === 'cover'
    ? new ImageSpec(width: 1200, height: 630, format: Format::Jpeg)
    : new ImageSpec(width: (int) $spec, height: (int) $spec, fit: Fit::Inside, format: Format::Jpeg);

$rss = static function (): int {
    // Resident set size of this process, in bytes.
    return (int) trim((string) shell_exec('ps -o rss= -p '.getmypid())) * 1024;
};
$megabytes = static fn (int $bytes): string => number_format($bytes / 1024 ** 2, 0).' MB';

$before = $rss();
$start = microtime(true);
for ($i = 0; $i < $renders; $i++) {
    $rendered = $renderer->render($source, $spec);
}
$time = microtime(true) - $start;
unset($rendered);
gc_collect_cycles();
$after = $rss();
// libvips' own counters, through FFI.
$vips = static function (string $function): int {
    $value = FFI::vips()->{$function}();

    return is_int($value) ? $value : 0;
};
$usage = getrusage();

// ru_maxrss is in bytes on macOS, in kilobytes on Linux.
$maxrss = is_array($usage) ? $usage['ru_maxrss'] : 0;
$peak = (is_int($maxrss) ? $maxrss : 0) * (PHP_OS_FAMILY === 'Darwin' ? 1 : 1024);

printf(
    "%s %s ×%d: peak RSS %s, RSS before %s, after %s, %.2f s per render%s\n",
    $driver,
    $argv[2] ?? '3200',
    $renders,
    $megabytes($peak),
    $megabytes($before),
    $megabytes($after),
    $time / $renders,
    $driver !== 'vips' ? '' : sprintf(
        '; libvips: %s allocated, %s at most, %d operations cached',
        $megabytes($vips('vips_tracked_get_mem')),
        $megabytes($vips('vips_tracked_get_mem_highwater')),
        $vips('vips_cache_get_size'),
    ),
);
