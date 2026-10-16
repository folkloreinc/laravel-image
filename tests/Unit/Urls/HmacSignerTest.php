<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit\Urls;

use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Tests\TestCase;
use Folklore\Image\Urls\HmacSigner;
use Folklore\Image\Urls\ImageUrl;
use Folklore\Image\Urls\V2Codec;
use InvalidArgumentException;

/**
 * Runs tests/fixtures/signatures.json, computed independently of this package.
 */
final class HmacSignerTest extends TestCase
{
    public function test_payloads_are_signed_with_the_first_key(): void
    {
        $fixtures = $this->fixtures();
        $signer = new HmacSigner($fixtures['keys']);

        foreach ($fixtures['signed'] as $case) {
            $this->assertSame($case['signature'], $signer->sign($case['payload']), $case['payload']);
            $this->assertTrue($signer->verify($case['payload'], $case['signature']));
        }
    }

    public function test_signatures_of_every_key_are_verified_so_keys_can_rotate(): void
    {
        $fixtures = $this->fixtures();
        $signer = new HmacSigner($fixtures['keys']);

        foreach ($fixtures['verified'] as $case) {
            $this->assertTrue($signer->verify($case['payload'], $case['signature']), $case['description']);
        }

        $withoutPreviousKey = new HmacSigner([$fixtures['keys'][0]]);
        foreach ($fixtures['verified'] as $case) {
            $this->assertFalse($withoutPreviousKey->verify($case['payload'], $case['signature']), 'A removed key no longer verifies.');
        }
    }

    public function test_other_signatures_are_refused(): void
    {
        $fixtures = $this->fixtures();
        $signer = new HmacSigner($fixtures['keys']);

        foreach ($fixtures['refused'] as $case) {
            $this->assertFalse($signer->verify($case['payload'], $case['signature']), $case['description']);
        }
    }

    public function test_a_signed_url_round_trips_through_the_codec(): void
    {
        $codec = new V2Codec;
        $signer = new HmacSigner(['current-signing-key']);
        $url = ImageUrl::forSpec('disk', 'uploads/photo.jpg', new ImageSpec(width: 800, format: Format::Webp), 'v1', 1767225600);

        $path = $codec->encode($url->withSignature($signer->sign($codec->payload($url))));
        $decoded = $codec->decode($path);

        $this->assertNotNull($decoded->signature);
        $this->assertTrue($signer->verify($codec->payload($decoded), $decoded->signature));
        $this->assertTrue($decoded->hasExpired(1767225600));
        $this->assertFalse($decoded->hasExpired(1767225599));
    }

    public function test_the_signer_needs_a_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HmacSigner([]);
    }

    /**
     * @return array{keys: list<string>, signed: list<array{payload: string, signature: string}>, verified: list<array{description: string, payload: string, signature: string}>, refused: list<array{description: string, payload: string, signature: string}>}
     */
    private function fixtures(): array
    {
        $fixtures = self::jsonFixture('signatures.json');

        $this->assertIsList($fixtures['keys']);
        $keys = array_map(fn (mixed $key) => is_string($key) ? $key : $this->fail('Keys are strings.'), $fixtures['keys']);

        $cases = [];
        foreach (['signed', 'verified', 'refused'] as $group) {
            $this->assertIsList($fixtures[$group]);
            $cases[$group] = array_map(function (mixed $case): array {
                $this->assertIsArray($case);
                $description = $case['description'] ?? '';
                $this->assertIsString($description);
                $this->assertIsString($case['payload']);
                $this->assertIsString($case['signature']);

                return ['description' => $description, 'payload' => $case['payload'], 'signature' => $case['signature']];
            }, $fixtures[$group]);
        }

        return ['keys' => $keys, ...$cases];
    }
}
