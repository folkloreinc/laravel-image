<?php

namespace Folklore\Image\Tests\Unit\Filters;

use Folklore\Image\Filters\Rotate as RotateFilter;
use Folklore\Image\Tests\Mocks\EffectsMock;
use Folklore\Image\Tests\Mocks\ImageMock;
use Folklore\Image\Tests\TestCase;

/**
 * @coversDefaultClass Folklore\Image\Filters\Rotate
 */
class RotateTest extends TestCase
{
    protected $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filter = new RotateFilter;
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
        $this->assertEquals('rotate', $imageMock->called);
        $this->assertEquals(30, $imageMock->callValue);
    }
}
