<?php

declare(strict_types=1);

namespace Folklore\Image\Exceptions;

use InvalidArgumentException;

/**
 * A URL path that no codec decodes, or that isn't in its canonical form. It answers 404.
 */
final class InvalidImageUrl extends InvalidArgumentException implements ImageException {}
