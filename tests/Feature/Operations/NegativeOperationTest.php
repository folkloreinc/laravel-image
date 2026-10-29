<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Operations;

use Folklore\Image\Contracts\Operation;
use Folklore\Image\Rendering\Operations\Negative;
use Folklore\Image\Testing\Contracts\OperationContractTests;
use Folklore\Image\Tests\TestCase;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * The built-in operation of the 1.x filter of the same name, on the driver under test.
 */
final class NegativeOperationTest extends TestCase
{
    use OperationContractTests;

    protected function operation(): Operation
    {
        return new Negative;
    }

    protected function image(): ImageInterface
    {
        return (new ImageManager($this->driverOrSkip(self::driverUnderTest() ?? 'gd')))
            ->decodePath(self::fixturePath('images/image_small.jpg'));
    }

    protected function validArguments(): array
    {
        return [[]];
    }

    protected function invalidArguments(): array
    {
        return [['1'], ['a']];
    }

    public function test_the_colors_are_inverted(): void
    {
        $original = $this->image();
        $inverted = $this->operation()->apply($this->image(), []);
        $twice = $this->operation()->apply($this->operation()->apply($this->image(), []), []);

        $this->assertNotSame($original->colorAt(10, 10)->toHex(), $inverted->colorAt(10, 10)->toHex());
        $this->assertSame($original->colorAt(10, 10)->toHex(), $twice->colorAt(10, 10)->toHex());
    }
}
