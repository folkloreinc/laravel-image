<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit;

use Folklore\Image\Tests\TestCase;

/**
 * The 1.x URLs that 2.x must keep decoding (docs/decisions/0004-specs-and-urls.md)
 * and serving through the compatibility layer (docs/decisions/0007-compatibility-layer.md).
 * The 1.x code isn't on this branch anymore: until V1Codec lands, these tests only keep
 * the fixtures complete and well-formed. Never remove a case.
 */
final class V1UrlFixturesTest extends TestCase
{
    public function test_the_url_fixtures_are_well_formed(): void
    {
        $fixtures = self::jsonFixture('urls/v1.json');

        $this->assertGreaterThanOrEqual(19, count($fixtures), 'A 1.x URL fixture was removed.');

        $descriptions = [];
        foreach ($fixtures as $fixture) {
            $this->assertIsArray($fixture);
            $this->assertSame(['description', 'filters', 'height', 'parsed', 'src', 'url', 'width'], $this->sortedKeys($fixture));
            $this->assertIsString($description = $fixture['description']);
            $this->assertIsString($fixture['url'], $description);
            $this->assertIsArray($fixture['filters'], $description);
            $this->assertNotContains($description, $descriptions, 'Descriptions are unique.');
            $descriptions[] = $description;

            // Absolute URLs aren't parsed: routes only see the path.
            if ($fixture['parsed'] !== null) {
                $this->assertIsArray($fixture['parsed'], $description);
                $this->assertSame(['filters', 'path'], $this->sortedKeys($fixture['parsed']), $description);
            }
        }
    }

    public function test_the_readme_examples_are_well_formed(): void
    {
        $examples = self::jsonFixture('urls/v1-readme.json');

        $this->assertSame(['description', 'filters', 'generated', 'made', 'served'], $this->sortedKeys($examples));

        $this->assertIsArray($examples['generated']);
        foreach ($examples['generated'] as $example) {
            $this->assertIsArray($example);
            $this->assertSame(['description', 'filters', 'height', 'src', 'url', 'width'], $this->sortedKeys($example));
            $this->assertIsString($example['url']);
            $this->assertStringStartsWith('/uploads/photo-filters(', $example['url']);
        }

        $this->assertIsArray($examples['served']);
        foreach ($examples['served'] as $example) {
            $this->assertIsArray($example);
            $this->assertIsString($example['source']);
            $this->assertIsString($example['url']);
            $this->assertFileExists(self::fixturePath('images/'.$example['source']));
            $this->assertMatchesRegularExpression('/^\/image-filters\(.+\)\.jpg(\.webp)?$/', $example['url']);
        }
    }

    /**
     * @param  array<mixed>  $array
     * @return list<int|string>
     */
    private function sortedKeys(array $array): array
    {
        $keys = array_keys($array);
        sort($keys);

        return $keys;
    }
}
