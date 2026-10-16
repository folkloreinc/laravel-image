<?php

declare(strict_types=1);

namespace Folklore\Image\Exceptions;

use InvalidArgumentException;

/**
 * An image source with an invalid key, version or metadata.
 */
final class InvalidImageSource extends InvalidArgumentException implements ImageException {}
