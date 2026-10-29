<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Operations;

use Folklore\Image\Contracts\Operation;
use Folklore\Image\Rendering\Operations\Grayscale;
use Folklore\Image\Testing\Contracts\OperationContractTests;
use Folklore\Image\Tests\TestCase;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * The built-in operation of the 1.x filter of the same name, on the driver under test.
 */
final class GrayscaleOperationTest extends TestCase
{
    use OperationContractTests;

    protected function operation(): Operation
    {
        return new Grayscale;
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

    public function test_the_image_is_grey(): void
    {
        $image = $this->operation()->apply($this->image(), []);
        $color = $image->colorAt(intdiv($image->width(), 2), intdiv($image->height(), 2));

        $this->assertTrue($color->isGrayscale());
    }
}
