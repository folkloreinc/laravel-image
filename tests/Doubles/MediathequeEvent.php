<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Doubles;

/**
 * Stands for the events of laravel-mediatheque 2 (`MediaVisibilityChanged`, `OriginalReplaced`),
 * which carry the media, in tests that don't install the mediatheque.
 */
final readonly class MediathequeEvent
{
    public function __construct(
        public object $media,
    ) {}

    /**
     * An event for a media of this uid.
     */
    public static function forUid(string $uid): self
    {
        return new self(new readonly class($uid)
        {
            public function __construct(private string $uid) {}

            public function uid(): string
            {
                return $this->uid;
            }
        });
    }
}
