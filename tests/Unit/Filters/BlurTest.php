<?php

namespace Folklore\Image\Tests\Unit\Filters;

use Folklore\Image\Filters\Blur as BlurFilter;
use Folklore\Image\Tests\Mocks\EffectsMock;
use Folklore\Image\Tests\Mocks\ImageMock;
use Folklore\Image\Tests\TestCase;

/**
 * @coversDefaultClass Folklore\Image\Filters\Blur
 */
class BlurTest extends TestCase
{
    protected $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filter = new BlurFilter;
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

        $this->filter->apply($imageMock, 30);

        $this->assertEquals('effects', $imageMock->called);
        $this->assertEquals('blur', $effectsMock->called);
        $this->assertEquals(30, $effectsMock->callValue);
    }
}
