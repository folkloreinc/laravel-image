<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit\Specs;

use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Crop;
use Folklore\Image\Specs\Flip;
use Folklore\Image\Specs\Focus;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Specs\Size;
use Folklore\Image\Specs\Transform;
use Folklore\Image\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Transforms in Panneau 5's shape (`MediaTransform`), and their focal point
 * (docs/decisions/0013-transform-focal-point-and-metadata.md).
 */
final class TransformTest extends TestCase
{
    public function test_transforms_read_panneau_s_shape(): void
    {
        $transform = Transform::fromArray([
            'crop' => ['x' => 10, 'y' => 20, 'width' => 300, 'height' => 200],
            'rotate' => 90,
            'flipX' => true,
            'flipY' => false,
            'focalPoint' => ['x' => 0.3, 'y' => 0.6],
            'unknown' => 'ignored',
        ]);

        $this->assertEquals(new Crop(10, 20, 300, 200), $transform->crop);
        $this->assertSame(90, $transform->rotate);
        $this->assertSame(Flip::Horizontal, $transform->flip);
        $this->assertEquals(new Focus(0.3, 0.6), $transform->focalPoint);
        $this->assertSame([
            'crop' => ['x' => 10, 'y' => 20, 'width' => 300, 'height' => 200],
            'rotate' => 90,
            'flip' => 'horizontal',
            'focalPoint' => ['x' => 0.3, 'y' => 0.6],
        ], $transform->toArray());
    }

    public function test_flip_x_mirrors_left_to_right_and_flip_y_top_to_bottom(): void
    {
        $this->assertSame(Flip::Horizontal, Transform::fromArray(['flipX' => true])->flip);
        $this->assertSame(Flip::Vertical, Transform::fromArray(['flipY' => true])->flip);
        $this->assertSame(Flip::Both, Transform::fromArray(['flipX' => true, 'flipY' => true])->flip);
        $this->assertNull(Transform::fromArray(['flipX' => false, 'flipY' => false])->flip);
    }

    public function test_empty_values_of_panneau_s_shape_are_no_edits(): void
    {
        $this->assertTrue(Transform::fromArray(['crop' => null, 'rotate' => null, 'flipX' => null, 'flipY' => null, 'focalPoint' => null])->isEmpty());
        $this->assertFalse(Transform::fromArray(['focalPoint' => ['x' => 0.5, 'y' => 0.5]])->isEmpty());
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function invalidTransforms(): array
    {
        return [
            'a flip and flipX' => [['flip' => 'vertical', 'flipX' => true]],
            'a flipX that is not a boolean' => [['flipX' => 1]],
            'a focal point that is not an array' => [['focalPoint' => 0.5]],
            'a focal point outside the image' => [['focalPoint' => ['x' => 1.5, 'y' => 0.5]]],
            'a crop in decimal pixels' => [['crop' => ['x' => 0.5, 'y' => 0, 'width' => 10, 'height' => 10]]],
        ];
    }

    /**
     * @param  array<mixed>  $transform
     */
    #[DataProvider('invalidTransforms')]
    public function test_invalid_transforms_are_refused(array $transform): void
    {
        $this->expectException(InvalidImageSpec::class);

        Transform::fromArray($transform);
    }

    /**
     * Points of an 800×500 upright original, and where they are once transformed.
     *
     * @return array<string, array{Transform, array{float, float}, array{float, float}}>
     */
    public static function focalPoints(): array
    {
        $crop = new Crop(200, 100, 400, 300);

        return [
            'no edits' => [new Transform, [0.3, 0.6], [0.3, 0.6]],
            'the center of a crop' => [new Transform($crop), [0.5, 0.5], [0.5, 0.5]],
            'the corner of a crop' => [new Transform($crop), [0.25, 0.2], [0, 0]],
            'outside the crop, clamped to its edges' => [new Transform($crop), [0.1, 0.9], [0, 1]],
            'a crop past the edge of the source' => [new Transform(new Crop(600, 0, 400, 500)), [0.85, 0.5], [0.4, 0.5]],
            'rotated 90° clockwise' => [new Transform(rotate: 90), [0.2, 0.1], [0.9, 0.2]],
            'rotated 180°' => [new Transform(rotate: 180), [0.2, 0.1], [0.8, 0.9]],
            'rotated 270° clockwise' => [new Transform(rotate: 270), [0.2, 0.1], [0.1, 0.8]],
            'flipped horizontally' => [new Transform(flip: Flip::Horizontal), [0.2, 0.1], [0.8, 0.1]],
            'flipped vertically' => [new Transform(flip: Flip::Vertical), [0.2, 0.1], [0.2, 0.9]],
            'flipped both ways' => [new Transform(flip: Flip::Both), [0.2, 0.1], [0.8, 0.9]],
            // The crop's top left corner, rotated to the top right, then flipped to the top left.
            'cropped, then rotated, then flipped' => [new Transform($crop, 90, Flip::Horizontal), [0.25, 0.2], [0, 0]],
        ];
    }

    /**
     * @param  array{float, float}  $point
     * @param  array{float, float}  $expected
     */
    #[DataProvider('focalPoints')]
    public function test_the_focal_point_of_the_upright_original_follows_the_transform(Transform $transform, array $point, array $expected): void
    {
        $focus = $transform->focus(new Focus(...$point), new Size(800, 500));

        $this->assertEqualsWithDelta($expected, [$focus->x, $focus->y], 0.001);
    }

    public function test_a_spec_takes_the_focal_point_of_its_transform_as_its_focus(): void
    {
        $transform = new Transform(new Crop(0, 0, 400, 300), focalPoint: new Focus(0.3, 0.6));

        $spec = new ImageSpec(transform: $transform, width: 100, height: 100);

        $this->assertEquals(new Focus(0.3, 0.6), $spec->focus);
        $this->assertNull($spec->transform?->focalPoint);
        $this->assertSame('c-0-0-400-300,w100,h100,fp-30-60.auto', $spec->key());
        $this->assertEquals(new Focus(0.1, 0.1), (new ImageSpec(transform: $transform, width: 100, height: 100, focus: new Focus(0.1, 0.1)))->focus);
        $this->assertNull((new ImageSpec(transform: new Transform(focalPoint: new Focus(0.3, 0.6))))->transform);
    }

    public function test_a_spec_is_rendered_with_the_transform_of_its_source_when_it_has_none(): void
    {
        $source = new ImageSource('disk', 'photo.jpg', transform: new Transform(new Crop(0, 0, 400, 300)));
        $own = new ImageSpec(transform: new Transform(rotate: 90), width: 100);

        $this->assertSame('c-0-0-400-300,w100.auto', (new ImageSpec(width: 100))->forSource($source)->key());
        $this->assertSame($own, $own->forSource($source));
        $this->assertEquals(new Size(100, 75), (new ImageSpec(width: 100))->forSource($source)->outputSize(new Size(800, 500)));
    }
}
