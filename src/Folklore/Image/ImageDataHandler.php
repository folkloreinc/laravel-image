<?php

namespace Folklore\Image;

use Folklore\Image\Contracts\ImageDataHandler as ImageDataHandlerContract;
use Imagine\Image\ImageInterface;

class ImageDataHandler implements ImageDataHandlerContract
{
    public function save(ImageInterface $image, $path, array $opts = [])
    {
        $format = pathinfo($path, \PATHINFO_EXTENSION);

        return $image->save($path, array_merge([
            'flatten' => strtolower($format) !== 'gif',
        ], $opts));
    }

    public function get(ImageInterface $image, $format, array $opts = [])
    {
        return $image->get($format, array_merge([
            'flatten' => strtolower($format) !== 'gif',
        ], $opts));
    }
}
