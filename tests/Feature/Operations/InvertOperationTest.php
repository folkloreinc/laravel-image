<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Operations;

use Folklore\Image\Contracts\Operation;
use Folklore\Image\Testing\Contracts\OperationContractTests;
use Folklore\Image\Tests\Doubles\InvertOperation;
use Folklore\Image\Tests\TestCase;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Runs the operation contract tests against a test double, on the driver under test.
 */
final class InvertOperationTest extends TestCase
{
    use OperationContractTests;

    protected function operation(): Operation
    {
        return new InvertOperation;
    }

    protected function image(): ImageInterface
    {
        return (new ImageManager($this->driverOrSkip(self::driverUnderTest() ?? 'gd')))
            ->decodePath(self::fixturePath('images/image_small.jpg'));
    }

    protected function validArguments(): array
    {
        return [[], ['1'], ['2'], ['03']];
    }

    protected function invalidArguments(): array
    {
        return [['0'], ['4'], ['a'], ['1', '2']];
    }
}
