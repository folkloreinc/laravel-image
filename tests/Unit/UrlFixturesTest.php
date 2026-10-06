<?php

namespace Folklore\Image\Tests\Unit;

use Folklore\Image\Tests\TestCase;

/**
 * Runs the URL fixtures shared with the JS suite (js/src/__tests__/fixtures.test.js),
 * so both URL generators keep producing the same URLs.
 */
class UrlFixturesTest extends TestCase
{
    public function test_generated_urls_match_the_shared_fixtures()
    {
        foreach ($this->fixtures() as $fixture) {
            $url = app('image')->url($fixture['src'], $fixture['width'], $fixture['height'], $fixture['filters']);

            $this->assertEquals($fixture['url'], $url, $fixture['description']);
        }
    }

    public function test_fixture_urls_parse_back_to_their_path_and_filters()
    {
        foreach ($this->fixtures() as $fixture) {
            $parsed = app('image')->parse($fixture['url']);

            $this->assertEquals($fixture['parsed']['path'], $parsed['path'], $fixture['description']);
            $this->assertEquals($fixture['parsed']['filters'], $parsed['filters'], $fixture['description']);
        }
    }

    protected function fixtures(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../fixture/urls.json'), true);
    }
}
