<?php

declare(strict_types=1);

namespace Folklore\Image\Listeners;

use Folklore\Image\Contracts\DerivativeStore;
use Folklore\Image\Sources\ImageSource;

/**
 * Clears the derivatives of a media of laravel-mediatheque 2 when its visibility changes or its
 * original is replaced (docs/decisions/0013-transform-focal-point-and-metadata.md): a media made
 * private keeps no derivative a public disk URL could serve, and a replaced original keeps no
 * derivative of the previous one. Its posters and thumbnails (`med_…/{handle}`) are cleared
 * with it.
 *
 * The service provider registers it when the mediatheque is installed. This package doesn't
 * require the mediatheque, so its events are named, not imported.
 */
final readonly class ClearMediaDerivatives
{
    /** The mediatheque's events that clear a media's derivatives. */
    public const array EVENTS = [
        'Folklore\Mediatheque\Events\MediaVisibilityChanged',
        'Folklore\Mediatheque\Events\OriginalReplaced',
    ];

    /** The name of the mediatheque's resolver, the first segment of its derivatives' paths. */
    public const string RESOLVER = 'media';

    /**
     * The events of EVENTS that exist: none when the mediatheque isn't installed.
     *
     * @return list<string>
     */
    public static function installedEvents(): array
    {
        return array_values(array_filter(self::EVENTS, class_exists(...)));
    }

    public function __construct(
        private DerivativeStore $store,
    ) {}

    /**
     * @param  object  $event  one of EVENTS, whose `media` has a `uid()`
     */
    public function handle(object $event): void
    {
        $media = property_exists($event, 'media') ? $event->media : null;
        $uid = is_object($media) && method_exists($media, 'uid') ? $media->uid() : null;

        if (is_string($uid) && ImageSource::isValidKey($uid)) {
            $this->store->clear(new ImageSource(self::RESOLVER, $uid));
        }
    }
}
