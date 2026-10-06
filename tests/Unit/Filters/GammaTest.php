<?php

namespace Folklore\Image\Tests\Unit\Filters;

use Folklore\Image\Filters\Gamma as GammaFilter;
use Folklore\Image\Tests\Mocks\EffectsMock;
use Folklore\Image\Tests\Mocks\ImageMock;
use Folklore\Image\Tests\TestCase;

/**
 * @coversDefaultClass Folklore\Image\Filters\Gamma
 */
class GammaTest extends TestCase
{
    protected $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filter = new GammaFilter;
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

        $this->filter->apply($imageMock, 0.1);

        $this->assertEquals('effects', $imageMock->called);
        $this->assertEquals('gamma', $effectsMock->called);
        $this->assertEquals(0.1, $effectsMock->callValue);
    }
}
