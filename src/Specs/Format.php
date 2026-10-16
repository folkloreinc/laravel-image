<?php

declare(strict_types=1);

namespace Folklore\Image\Specs;

use Folklore\Image\Exceptions\InvalidImageSpec;

enum Format: string
{
    /** The source's format when browsers display it, otherwise JPEG. Resolved before a URL is built. */
    case Auto = 'auto';
    case Jpeg = 'jpeg';
    case Png = 'png';
    case Gif = 'gif';
    case Webp = 'webp';
    case Avif = 'avif';

    /**
     * The extension of URLs and derivatives in this format.
     *
     * @throws InvalidImageSpec for Auto, which has none
     */
    public function extension(): string
    {
        return match ($this) {
            self::Auto => throw new InvalidImageSpec('The auto format has no extension: resolve it from the source first.'),
            self::Jpeg => 'jpg',
            default => $this->value,
        };
    }

    /**
     * @throws InvalidImageSpec for Auto, which has none
     */
    public function mime(): string
    {
        return match ($this) {
            self::Auto => throw new InvalidImageSpec('The auto format has no mime type: resolve it from the source first.'),
            default => 'image/'.$this->value,
        };
    }

    /**
     * The format of a canonical extension (`jpg`, not `jpeg`), or null.
     */
    public static function tryFromExtension(string $extension): ?self
    {
        foreach (self::cases() as $format) {
            if ($format !== self::Auto && $format->extension() === $extension) {
                return $format;
            }
        }

        return null;
    }

    public static function tryFromMime(?string $mime): ?self
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg', 'image/pjpeg' => self::Jpeg,
            'image/png' => self::Png,
            'image/gif' => self::Gif,
            'image/webp' => self::Webp,
            'image/avif' => self::Avif,
            default => null,
        };
    }

    /**
     * This format, or for Auto the source's format when browsers display it, otherwise JPEG
     * (HEIC, TIFF, unknown sources).
     */
    public function resolve(?string $sourceMime): self
    {
        if ($this !== self::Auto) {
            return $this;
        }

        return self::tryFromMime($sourceMime) ?? self::Jpeg;
    }
}
