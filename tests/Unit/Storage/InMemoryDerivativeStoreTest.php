<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit\Storage;

use Folklore\Image\Contracts\DerivativeStore;
use Folklore\Image\Testing\Contracts\DerivativeStoreContractTests;
use Folklore\Image\Tests\Doubles\InMemoryDerivativeStore;
use Folklore\Image\Tests\TestCase;

/**
 * Runs the store contract tests against a test double, before DiskStore uses them.
 */
final class InMemoryDerivativeStoreTest extends TestCase
{
    use DerivativeStoreContractTests;

    protected function store(): DerivativeStore
    {
        return new InMemoryDerivativeStore;
    }
}
