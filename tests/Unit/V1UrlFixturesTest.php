<?php

namespace Folklore\Image\Tests\Unit;

use Folklore\Image\Tests\TestCase;

/**
 * The 1.x URLs that 2.x must keep decoding (docs/decisions/0004-specs-and-urls.md)
 * and serving through the compatibility layer (docs/decisions/0007-compatibility-layer.md).
 * The 1.x code isn't on this branch anymore: until V1Codec lands, these tests only keep
 * the fixtures complete and well-formed. Never remove a case.
 */
class V1UrlFixturesTest extends TestCase
{
    public function test_the_url_fixtures_are_well_formed()
    {
        $fixtures = static::jsonFixture('urls/v1.json');

        $this->assertGreaterThanOrEqual(19, count($fixtures), 'A 1.x URL fixture was removed.');

        $descriptions = [];
        foreach ($fixtures as $fixture) {
            $this->assertSame(['description', 'filters', 'height', 'parsed', 'src', 'url', 'width'], $this->sortedKeys($fixture));
            $this->assertIsString($fixture['url'], $fixture['description']);
            $this->assertIsArray($fixture['filters'], $fixture['description']);
            $this->assertNotContains($fixture['description'], $descriptions, 'Descriptions are unique.');
            $descriptions[] = $fixture['description'];

            // Absolute URLs aren't parsed: routes only see the path.
            if ($fixture['parsed'] !== null) {
                $this->assertSame(['filters', 'path'], $this->sortedKeys($fixture['parsed']), $fixture['description']);
            }
        }
    }

    public function test_the_readme_examples_are_well_formed()
    {
        $examples = static::jsonFixture('urls/v1-readme.json');

        $this->assertSame(['description', 'filters', 'generated', 'made', 'served'], $this->sortedKeys($examples));

        foreach ($examples['generated'] as $example) {
            $this->assertSame(['description', 'filters', 'height', 'src', 'url', 'width'], $this->sortedKeys($example));
            $this->assertStringStartsWith('/uploads/photo-filters(', $example['url']);
        }

        foreach ($examples['served'] as $example) {
            $this->assertFileExists(static::fixturePath('images/'.$example['source']));
            $this->assertMatchesRegularExpression('/^\/image-filters\(.+\)\.jpg(\.webp)?$/', $example['url']);
        }
    }

    /**
     * @param  array<string, mixed>  $array
     * @return list<string>
     */
    private function sortedKeys(array $array): array
    {
        $keys = array_keys($array);
        sort($keys);

        return $keys;
    }
}
