<?php

declare(strict_types=1);

namespace Folklore\Image\Exceptions;

use RuntimeException;

/**
 * A value of the `image` config is invalid, or something the package needs is missing from it,
 * such as a signing key.
 */
final class InvalidImageConfig extends RuntimeException implements ImageException {}
