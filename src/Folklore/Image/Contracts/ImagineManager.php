<?php

namespace Folklore\Image\Contracts;

use Imagine\Image\ImagineInterface;

interface ImagineManager
{
    public function driver($driver = null): ImagineInterface;
}