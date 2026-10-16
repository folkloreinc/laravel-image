<?php

declare(strict_types=1);

namespace Folklore\Image\Exceptions;

use RuntimeException;

/**
 * The renderer could not read the source or write the output, for example a format the driver doesn't support.
 */
final class RenderFailed extends RuntimeException implements ImageException {}
