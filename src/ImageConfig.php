<?php

declare(strict_types=1);

namespace Folklore\Image;

use Folklore\Image\Exceptions\InvalidImageConfig;
use Folklore\Image\Specs\Limits;
use Illuminate\Contracts\Config\Repository;

/**
 * The `image` config, read with its types checked, so a mistake in a published config fails
 * with a message instead of a type error deep in a request.
 *
 * @internal
 */
final readonly class ImageConfig
{
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * The host of image URLs, without a trailing slash, or null for the application's URL.
     */
    public function host(): ?string
    {
        $host = $this->config->get('image.url.host');

        return match (true) {
            $host === null, $host === '' => null,
            is_string($host) => rtrim($host, '/'),
            default => throw new InvalidImageConfig('image.url.host is a URL or null.'),
        };
    }

    /**
     * The path before image URLs, without slashes around it. It can be empty.
     */
    public function prefix(): string
    {
        $prefix = $this->config->get('image.url.prefix', 'img');

        return is_string($prefix) ? trim($prefix, '/') : throw new InvalidImageConfig('image.url.prefix is a string.');
    }

    public function strict(): bool
    {
        return $this->bool('image.strict', true);
    }

    public function upscale(): bool
    {
        return $this->bool('image.upscale', false);
    }

    /**
     * How long signed URLs last when no expiry is given, in seconds, or null for no expiry.
     */
    public function defaultTtl(): ?int
    {
        $ttl = $this->config->get('image.signing.default_ttl', 3600);

        return $ttl === null || (is_int($ttl) && $ttl > 0) ? $ttl : throw new InvalidImageConfig('image.signing.default_ttl is a positive number of seconds, or null.');
    }

    /**
     * The configured signing keys, which can be empty. `base64:` keys are decoded, as APP_KEY is.
     *
     * @return list<string>
     */
    public function signingKeys(): array
    {
        $keys = $this->config->get('image.signing.keys', []);
        if (! is_array($keys)) {
            throw new InvalidImageConfig('image.signing.keys is a list of keys.');
        }

        $valid = [];
        foreach ($keys as $key) {
            if (! is_string($key) || $key === '') {
                throw new InvalidImageConfig('image.signing.keys is a list of non-empty strings.');
            }
            $valid[] = self::decodeKey($key);
        }

        return $valid;
    }

    /**
     * APP_KEY, then APP_PREVIOUS_KEYS, decoded, which the default signing keys are derived from.
     *
     * @return list<string>
     */
    public function applicationKeys(): array
    {
        $keys = [];
        $current = $this->config->get('app.key');
        if (is_string($current) && $current !== '') {
            $keys[] = self::decodeKey($current);
        }

        $previous = $this->config->get('app.previous_keys', []);
        foreach (is_array($previous) ? $previous : [] as $key) {
            if (is_string($key) && $key !== '') {
                $keys[] = self::decodeKey($key);
            }
        }

        return $keys;
    }

    /**
     * The keys of the signer: the configured ones, or keys derived from the application's,
     * so an image signature never reveals anything about APP_KEY.
     *
     * @return non-empty-list<string>
     *
     * @throws InvalidImageConfig when there is no key at all
     */
    public function signerKeys(): array
    {
        $keys = $this->signingKeys();
        if ($keys === []) {
            $keys = array_map(fn (string $key) => hash_hmac('sha256', 'folklore/laravel-image signing key', $key, true), $this->applicationKeys());
        }

        return $keys === [] ? throw new InvalidImageConfig('Image URLs are signed with image.signing.keys, or keys derived from APP_KEY: set one of them.') : $keys;
    }

    /**
     * Whether the signing keys are derived from the application's keys.
     */
    public function derivesSigningKeys(): bool
    {
        return $this->signingKeys() === [];
    }

    public function limits(): Limits
    {
        $limits = $this->config->get('image.limits', []);
        if (! is_array($limits)) {
            throw new InvalidImageConfig('image.limits is an array.');
        }

        $value = function (string $name) use ($limits): ?int {
            $value = $limits[$name] ?? null;

            return $value === null || (is_int($value) && $value > 0) ? $value : throw new InvalidImageConfig("image.limits.{$name} is a positive integer, or null for no limit.");
        };

        return new Limits($value('max_width'), $value('max_height'), $value('max_pixels'), $value('max_operations'));
    }

    /**
     * How the controller serves derivatives: `stream` or `redirect`.
     */
    public function serve(): string
    {
        $serve = $this->config->get('image.delivery.serve', 'stream');

        return in_array($serve, ['stream', 'redirect'], true) ? $serve : throw new InvalidImageConfig('image.delivery.serve is stream or redirect.');
    }

    /**
     * The max-age of image responses, in seconds.
     */
    public function maxAge(): int
    {
        $maxAge = $this->config->get('image.delivery.max_age', 31536000);

        return is_int($maxAge) && $maxAge >= 0 ? $maxAge : throw new InvalidImageConfig('image.delivery.max_age is a number of seconds.');
    }

    /**
     * The cache store of the render locks, or null for the default store.
     */
    public function lockStore(): ?string
    {
        $store = $this->config->get('image.derivatives.lock.store');

        return $store === null || is_string($store) ? $store : throw new InvalidImageConfig('image.derivatives.lock.store is the name of a cache store, or null.');
    }

    /**
     * @return array<string, mixed>
     */
    public function presets(): array
    {
        return $this->map('image.presets');
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvers(): array
    {
        return $this->map('image.resolvers');
    }

    /**
     * @return array<string, mixed>
     */
    private function map(string $key): array
    {
        $value = $this->config->get($key, []);
        if ($value === null) {
            return [];
        }
        if (! is_array($value)) {
            throw new InvalidImageConfig("{$key} is an array, by name.");
        }

        $map = [];
        foreach ($value as $name => $item) {
            if (! is_string($name)) {
                throw new InvalidImageConfig("{$key} is an array, by name.");
            }
            $map[$name] = $item;
        }

        return $map;
    }

    private function bool(string $key, bool $default): bool
    {
        $value = $this->config->get($key, $default);

        return is_bool($value) ? $value : throw new InvalidImageConfig("{$key} is true or false.");
    }

    private static function decodeKey(string $key): string
    {
        if (! str_starts_with($key, 'base64:')) {
            return $key;
        }

        $decoded = base64_decode(substr($key, 7), true);

        return $decoded === false || $decoded === '' ? throw new InvalidImageConfig('A base64: key is not valid base64.') : $decoded;
    }
}
