<?php

namespace Folklore\Image\Tests\Unit\Filters;

use Folklore\Image\Filters\Colorize as ColorizeFilter;
use Folklore\Image\Tests\Mocks\EffectsMock;
use Folklore\Image\Tests\Mocks\ImageMock;
use Folklore\Image\Tests\TestCase;

/**
 * @coversDefaultClass Folklore\Image\Filters\Colorize
 */
class ColorizeTest extends TestCase
{
    protected $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filter = new ColorizeFilter;
    }

    /**
     * Test the apply method
     *
     * @test
     *
     * @covers ::apply
     */
    public function test_apply()
    {
        $effectsMock = new EffectsMock;
        $imageMock = new ImageMock($effectsMock);

        $this->filter->apply($imageMock, '#ffffff');

        $this->assertEquals('effects', $imageMock->called);
        $this->assertEquals('colorize', $effectsMock->called);
        $this->assertEquals('#ffffff', (string) $effectsMock->callValue);
    }
}
