<?php

declare(strict_types=1);

namespace Folklore\Image\Exceptions;

use InvalidArgumentException;

/**
 * No resolver supports the reference an image is built from.
 */
final class UnsupportedReference extends InvalidArgumentException implements ImageException {}
