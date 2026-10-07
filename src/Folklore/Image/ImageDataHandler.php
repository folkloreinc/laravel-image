<?php

namespace Folklore\Image;

use Folklore\Image\Contracts\ImageDataHandler as ImageDataHandlerContract;
use Imagine\Image\ImageInterface;

class ImageDataHandler implements ImageDataHandlerContract
{
    public function save(ImageInterface $image, $path, array $opts = [])
    {
        $format = pathinfo($path, \PATHINFO_EXTENSION);

        return $image->save($path, $this->options($format, $opts));
    }

    public function get(ImageInterface $image, $format, array $opts = [])
    {
        return $image->get($format, $this->options($format, $opts));
    }

    /**
     * The encoding options for a format: the defaults from `image.quality`, overridden
     * by the given options. PNG is always lossless, with the highest compression.
     */
    protected function options($format, array $opts): array
    {
        $format = strtolower((string) $format);
        if ($format === 'jpg') {
            $format = 'jpeg';
        }

        $defaults = [
            'flatten' => $format !== 'gif',
        ];

        $hasQuality = isset($opts['quality']);
        if ($format === 'png') {
            if (! $hasQuality && ! isset($opts['png_compression_level'])) {
                $defaults['png_compression_level'] = 9;
            }
        } elseif (! $hasQuality) {
            $quality = config('image.quality.'.$format);
            if ($quality !== null) {
                $defaults['quality'] = (int) $quality;
            }
        }

        return array_merge($defaults, $opts);
    }
}
