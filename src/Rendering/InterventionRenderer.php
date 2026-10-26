<?php

declare(strict_types=1);

namespace Folklore\Image\Rendering;

use Folklore\Image\Contracts\Operation;
use Folklore\Image\Contracts\Renderer;
use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Exceptions\RenderFailed;
use Folklore\Image\Exceptions\SourceNotFound;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Fit;
use Folklore\Image\Specs\Flip;
use Folklore\Image\Specs\Focus;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Specs\Size;
use Folklore\Image\Specs\Transform;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use Intervention\Image\Direction;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Drivers\Vips\Driver as VipsDriver;
use Intervention\Image\Encoders\AvifEncoder;
use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Exceptions\MissingDependencyException;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\DriverInterface;
use Intervention\Image\Interfaces\EncoderInterface;
use Intervention\Image\Interfaces\ExceptionInterface as InterventionException;
use Intervention\Image\Interfaces\ImageInterface;
use InvalidArgumentException;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * The default renderer, on Intervention Image 4 (docs/decisions/0002-rendering-engine.md).
 *
 * The driver is `auto` by default: libvips when `intervention/image-driver-vips` is installed
 * and libvips loads, otherwise Imagick, otherwise GD. Animated sources give their first frame.
 */
final class InterventionRenderer implements Renderer
{
    /** The drivers by name, in the order `auto` tries them. */
    public const array DRIVERS = [
        'vips' => VipsDriver::class,
        'imagick' => ImagickDriver::class,
        'gd' => GdDriver::class,
    ];

    /** The quality per format when the config gives none. Other formats have no quality. */
    public const array DEFAULT_QUALITY = ['jpeg' => 82, 'webp' => 80, 'avif' => 60];

    private ?DriverInterface $resolvedDriver = null;

    private ?ImageManager $manager = null;

    /**
     * @param  DriverInterface|string  $driver  `auto`, `vips`, `imagick` or `gd`, or a driver instance
     * @param  array<string, int>  $quality  by format: `['jpeg' => 82, 'webp' => 80, 'avif' => 60]`
     * @param  bool  $upscale  whether outputs can be larger than their source
     * @param  string|null  $memoryLimit  raised to while rendering, never lowered (`256M`)
     * @param  array<string, Operation>  $operations  by name, applied in this order
     */
    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly DriverInterface|string $driver = 'auto',
        private readonly array $quality = [],
        private readonly bool $upscale = false,
        private readonly ?string $memoryLimit = null,
        private readonly array $operations = [],
    ) {}

    public function render(ImageSource $source, ImageSpec $spec): RenderedImage
    {
        $spec = $spec->forSource($source);
        $unknown = array_diff(array_keys($spec->operations), array_keys($this->operations));
        if ($unknown !== []) {
            throw new InvalidImageSpec('Unknown operations: '.implode(', ', $unknown).'.');
        }
        $format = $spec->format->resolve($source->mime);

        if ($this->memoryLimit !== null) {
            self::raiseMemoryLimit($this->memoryLimit);
        }

        try {
            // Decoding turns the image upright from its EXIF orientation, and the encoders strip
            // the metadata. Colors are converted to sRGB before anything else.
            $image = Srgb::convert($this->decode($source));
            $upright = new Size($image->width(), $image->height());
            $output = $spec->outputSize($upright, $this->upscale);

            // The focal point is relative to the upright original: it follows the transform.
            $focus = $spec->focus ?? $source->focalPoint;
            if ($spec->transform !== null) {
                $image = $this->transform($image, $spec->transform);
                $focus = $focus === null ? null : $spec->transform->focus($focus, $upright);
            }
            $image = $this->resize($image, $spec, $output, $focus ?? new Focus(0.5, 0.5));
            foreach ($this->operations as $name => $operation) {
                if (array_key_exists($name, $spec->operations)) {
                    $image = $operation->apply($image, $operation->arguments($spec->operations[$name]));
                }
            }

            $contents = (string) $image->encode($this->encoder($format, $spec->quality));
        } catch (InterventionException $exception) {
            throw new RenderFailed("Could not render {$source->resolver}/{$source->key}: {$exception->getMessage()}", previous: $exception);
        }

        return new RenderedImage($contents, $format, $image->width(), $image->height());
    }

    /**
     * The name of the driver in use: `vips`, `imagick` or `gd`.
     */
    public function driverName(): string
    {
        $driver = $this->resolvedDriver();
        $name = array_search($driver::class, self::DRIVERS, true);

        return is_string($name) ? $name : strtolower($driver->id());
    }

    /**
     * A driver by name, or the first one available for `auto`.
     *
     * @throws InvalidArgumentException for an unknown name
     * @throws RenderFailed when the driver isn't available
     */
    public static function makeDriver(string $name): DriverInterface
    {
        if ($name === 'auto') {
            foreach (array_keys(self::DRIVERS) as $candidate) {
                try {
                    return self::makeDriver($candidate);
                } catch (RenderFailed) {
                    continue;
                }
            }

            throw new RenderFailed('No image driver is available: install libvips with intervention/image-driver-vips, ext-imagick or ext-gd.');
        }

        $class = self::DRIVERS[$name] ?? throw new InvalidArgumentException("Unknown image driver: {$name}. Use auto, vips, imagick or gd.");
        if (! class_exists($class)) {
            throw new RenderFailed("The {$name} driver needs the intervention/image-driver-vips package.");
        }

        try {
            return new $class;
        } catch (MissingDependencyException $exception) {
            throw new RenderFailed("The {$name} driver is not available: {$exception->getMessage()}", previous: $exception);
        }
    }

    private function resolvedDriver(): DriverInterface
    {
        return $this->resolvedDriver ??= is_string($this->driver) ? self::makeDriver($this->driver) : $this->driver;
    }

    private function manager(): ImageManager
    {
        return $this->manager ??= new ImageManager($this->resolvedDriver(), autoOrientation: true, decodeAnimation: false, strip: true);
    }

    /**
     * Reads the original from its disk, in place when the disk is local.
     */
    private function decode(ImageSource $source): ImageInterface
    {
        if ($source->disk === null || $source->path === null) {
            throw new SourceNotFound("The source {$source->resolver}/{$source->key} has no disk and path.");
        }

        $disk = $this->filesystems->disk($source->disk);

        if ($disk instanceof FilesystemAdapter && $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            $path = $disk->path($source->path);
            if (! is_file($path)) {
                throw new SourceNotFound("The original of {$source->resolver}/{$source->key} is missing.");
            }

            return $this->manager()->decodePath($path);
        }

        try {
            $contents = $disk->get($source->path);
        } catch (FilesystemException $exception) {
            throw new SourceNotFound("The original of {$source->resolver}/{$source->key} can't be read: {$exception->getMessage()}", previous: $exception);
        }
        if ($contents === null) {
            throw new SourceNotFound("The original of {$source->resolver}/{$source->key} is missing.");
        }

        return $this->manager()->decodeBinary($contents);
    }

    /**
     * Crops (in pixels of the upright original), rotates clockwise, then flips.
     */
    private function transform(ImageInterface $image, Transform $transform): ImageInterface
    {
        if ($transform->crop !== null) {
            $crop = $transform->crop;
            $image = $image->crop(
                min($crop->width, $image->width() - $crop->x),
                min($crop->height, $image->height() - $crop->y),
                $crop->x,
                $crop->y,
            );
        }
        if ($transform->rotate !== 0) {
            $image = $image->rotate($transform->rotate);
        }

        return match ($transform->flip) {
            null => $image,
            Flip::Horizontal => $image->flip(Direction::HORIZONTAL),
            Flip::Vertical => $image->flip(Direction::VERTICAL),
            Flip::Both => $image->flip(Direction::HORIZONTAL)->flip(Direction::VERTICAL),
        };
    }

    /**
     * Resizes the image to the output size the spec computed. Cover crops the source to the
     * output's ratio around the focal point first; contain pads it to the output size.
     */
    private function resize(ImageInterface $image, ImageSpec $spec, Size $output, Focus $focus): ImageInterface
    {
        $source = new Size($image->width(), $image->height());

        if ($spec->hasBox() && $spec->fit === Fit::Cover) {
            $crop = self::coverCrop($source, $output);
            if (! $crop->equals($source)) {
                $image = $image->crop(
                    $crop->width,
                    $crop->height,
                    self::offset($focus->x, $source->width, $crop->width),
                    self::offset($focus->y, $source->height, $crop->height),
                );
            }
        }

        if ($spec->hasBox() && $spec->fit === Fit::Contain) {
            $inner = $source->scaled(min($output->width / $source->width, $output->height / $source->height));
            $inner = new Size(min($inner->width, $output->width), min($inner->height, $output->height));

            return $this->resizeTo($image, $inner)->resizeCanvas($output->width, $output->height, 'ffffff00');
        }

        return $this->resizeTo($image, $output);
    }

    private function resizeTo(ImageInterface $image, Size $size): ImageInterface
    {
        return $image->width() === $size->width && $image->height() === $size->height
            ? $image
            : $image->resize($size->width, $size->height);
    }

    /**
     * The largest part of the source with the ratio of the output.
     */
    private static function coverCrop(Size $source, Size $output): Size
    {
        $ratio = $output->ratio();

        return $source->ratio() > $ratio
            ? new Size(min($source->width, max(1, (int) round($source->height * $ratio))), $source->height)
            : new Size($source->width, min($source->height, max(1, (int) round($source->width / $ratio))));
    }

    /**
     * Where a part of a length starts, so it is centered on the focal point and stays inside.
     */
    private static function offset(float $focus, int $length, int $part): int
    {
        return max(0, min($length - $part, (int) round($focus * $length - $part / 2)));
    }

    private function encoder(Format $format, ?int $quality): EncoderInterface
    {
        $quality ??= $this->quality[$format->value] ?? self::DEFAULT_QUALITY[$format->value] ?? null;

        return match ($format) {
            Format::Jpeg => new JpegEncoder(quality: $quality ?? self::DEFAULT_QUALITY['jpeg'], strip: true),
            Format::Png => new PngEncoder(strip: true),
            Format::Gif => new GifEncoder,
            Format::Webp => new WebpEncoder(quality: $quality ?? self::DEFAULT_QUALITY['webp'], strip: true),
            Format::Avif => new AvifEncoder(quality: $quality ?? self::DEFAULT_QUALITY['avif'], strip: true),
            Format::Auto => throw new InvalidImageSpec('Resolve the auto format from the source before encoding.'),
        };
    }

    /**
     * Raises the memory limit to this one, unless it is already higher (or unlimited).
     */
    private static function raiseMemoryLimit(string $limit): void
    {
        $current = self::bytes((string) ini_get('memory_limit'));
        $wanted = self::bytes($limit);

        if ($current !== -1 && ($wanted === -1 || $wanted > $current)) {
            ini_set('memory_limit', $limit);
        }
    }

    /**
     * A php.ini size (`256M`, `1G`, `-1`) in bytes, or -1 for no limit.
     */
    private static function bytes(string $size): int
    {
        $size = trim($size);
        if ($size === '' || $size === '-1') {
            return -1;
        }

        $value = (int) $size;

        return match (strtolower(substr($size, -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
