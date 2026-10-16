<?php

declare(strict_types=1);

namespace Folklore\Image\Exceptions;

use RuntimeException;

/**
 * Another request is rendering the same derivative, and didn't finish in time. It answers 503
 * with a Retry-After header: the derivative will be there soon.
 */
final class RenderInProgress extends RuntimeException implements ImageException {}
