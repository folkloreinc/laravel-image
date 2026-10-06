<?php

namespace Folklore\Image;

use Folklore\Image\Contracts\ImagineManager;
use Imagine\Exception\InvalidArgumentException;
use Imagine\Exception\RuntimeException;
use Imagine\Factory\ClassFactoryInterface;
use Imagine\File\LoaderInterface;
use Imagine\Image\BoxInterface;
use Imagine\Image\FontInterface;
use Imagine\Image\ImageInterface;
use Imagine\Image\ImagineInterface;
use Imagine\Image\Metadata\MetadataReaderInterface;
use Imagine\Image\Palette\Color\ColorInterface;

class Imagine implements ImagineInterface
{
    protected $manager;

    public function __construct(ImagineManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Creates a new empty image with an optional background color.
     *
     *
     *
     * @return ImageInterface
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function create(BoxInterface $size, ?ColorInterface $color = null)
    {
        return $this->manager->driver()->create($size, $color);
    }

    /**
     * Opens an existing image from $path.
     *
     * @param  string|LoaderInterface|mixed  $path  the file path, a LoaderInterface instance, or an object whose string representation is the image path
     * @return ImageInterface
     *
     * @throws RuntimeException
     */
    public function open($path)
    {
        if ($this->mimeIsSvg(mime_content_type($path))) {
            return $this->manager->driver('svg')->open($path);
        }

        return $this->manager->driver()->open($path);
    }

    /**
     * Loads an image from a binary $string.
     *
     * @param  string  $string
     * @return ImageInterface
     *
     * @throws RuntimeException
     */
    public function load($string)
    {
        if (preg_match('/\<svg/', $string) === 1) {
            return $this->manager->driver('svg')->load($string);
        }

        return $this->manager->driver()->load($string);
    }

    /**
     * Loads an image from a resource $resource.
     *
     * @param  resource  $resource
     * @return ImageInterface
     *
     * @throws RuntimeException
     */
    public function read($resource)
    {
        if ($this->mimeIsSvg(mime_content_type($resource))) {
            return $this->manager->driver('svg')->read($resource);
        }

        return $this->manager->driver()->read($resource);
    }

    /**
     * Constructs a font with specified $file, $size and $color.
     *
     * The font size is to be specified in points (e.g. 10pt means 10)
     *
     * @param  string  $file
     * @param  int  $size
     * @return FontInterface
     */
    public function font($file, $size, ColorInterface $color)
    {
        return $this->manager->driver()->font($file, $size, $color);
    }

    /**
     * Set the object to be used to read image metadata.
     *
     *
     * @return $this
     */
    public function setMetadataReader(MetadataReaderInterface $metadataReader)
    {
        $this->manager->driver()->setMetadataReader($metadataReader);

        return $this;
    }

    /**
     * Get the object to be used to read image metadata.
     *
     * @return MetadataReaderInterface
     */
    public function getMetadataReader()
    {
        return $this->manager->driver()->getMetadataReader();
    }

    /**
     * Set the class manager instance to be used.
     *
     *
     * @return $this
     */
    public function setClassFactory(ClassFactoryInterface $classFactory)
    {
        return $this->manager->driver()->setClassFactory($classFactory);
    }

    /**
     * Get the class manager instance to be used.
     *
     * @return ClassFactoryInterface
     */
    public function getClassFactory()
    {
        return $this->manager->driver()->getClassFactory();
    }

    protected function mimeIsSvg($mime): bool
    {
        return in_array($mime, ['image/svg+xml', 'image/xml']);
    }
}
