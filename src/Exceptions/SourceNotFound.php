<?php

declare(strict_types=1);

namespace Folklore\Image\Exceptions;

use RuntimeException;

/**
 * The key of a URL, or a reference, points to no source. It answers 404.
 */
final class SourceNotFound extends RuntimeException implements ImageException {}
