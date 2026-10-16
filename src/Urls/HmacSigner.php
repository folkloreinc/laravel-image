<?php

declare(strict_types=1);

namespace Folklore\Image\Urls;

use Folklore\Image\Contracts\Signer;
use InvalidArgumentException;

/**
 * HMAC-SHA256, truncated to 128 bits and encoded in base64url (22 characters). The first key
 * signs; all of them verify.
 */
final readonly class HmacSigner implements Signer
{
    /** @var non-empty-list<non-empty-string> */
    private array $keys;

    /**
     * @param  list<string>  $keys
     */
    public function __construct(array $keys)
    {
        $valid = [];
        foreach ($keys as $key) {
            if ($key === '') {
                throw new InvalidArgumentException('A signing key can\'t be empty.');
            }
            $valid[] = $key;
        }
        if ($valid === []) {
            throw new InvalidArgumentException('The signer needs at least one key.');
        }

        $this->keys = $valid;
    }

    public function sign(string $payload): string
    {
        return $this->signWith($this->keys[0], $payload);
    }

    public function verify(string $payload, string $signature): bool
    {
        foreach ($this->keys as $key) {
            if (hash_equals($this->signWith($key, $payload), $signature)) {
                return true;
            }
        }

        return false;
    }

    private function signWith(string $key, string $payload): string
    {
        $hash = substr(hash_hmac('sha256', $payload, $key, true), 0, 16);

        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }
}
