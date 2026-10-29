<?php

declare(strict_types=1);

namespace Folklore\Image\Compat;

/**
 * Reports the use of the 1.x API (docs/decisions/0007-compatibility-layer.md) as a PHP
 * deprecation, which Laravel logs on its `deprecations` channel (`logging.deprecations`), once
 * per call site: the first file outside this package in the stack.
 *
 * @internal
 */
final class Deprecations
{
    /** @var array<string, true> */
    private static array $reported = [];

    public static function report(string $message): void
    {
        $site = self::callSite();
        if (isset(self::$reported[$site])) {
            return;
        }
        self::$reported[$site] = true;

        @trigger_error("folklore/laravel-image: {$message}".($site === '' ? '' : " (called in {$site})"), E_USER_DEPRECATED);
    }

    /**
     * Forgets the call sites already reported, for tests.
     */
    public static function flush(): void
    {
        self::$reported = [];
    }

    /**
     * The file and line of the first frame outside this package, or of the package's own caller.
     */
    private static function callSite(): string
    {
        $source = dirname(__DIR__);
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $site = '';

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;
            if ($file === null) {
                continue;
            }
            $site = $file.':'.($frame['line'] ?? 0);
            if (! str_starts_with($file, $source.DIRECTORY_SEPARATOR) && ! str_contains($file, DIRECTORY_SEPARATOR.'laravel'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR)) {
                break;
            }
        }

        return $site;
    }
}
