<?php

declare(strict_types=1);

namespace Folklore\Image\Exceptions;

use InvalidArgumentException;

/**
 * A spec, or one of its options, is invalid.
 */
final class InvalidImageSpec extends InvalidArgumentException implements ImageException {}
