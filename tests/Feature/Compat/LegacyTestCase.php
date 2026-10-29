<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Compat;

use Folklore\Image\Compat\Deprecations;
use Folklore\Image\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * An application with the `config/image.php` of a 1.x project, as it was before upgrading:
 * named sizes, the 1.x filters, a public and a cloud source, GD.
 */
abstract class LegacyTestCase extends TestCase
{
    /** The 1.x config of a client project, made generic. */
    protected const array CONFIG = [
        'filters' => [
            'blur' => 'Folklore\Image\Filters\Blur',
            'colorize' => 'Folklore\Image\Filters\Colorize',
            'gamma' => 'Folklore\Image\Filters\Gamma',
            'grayscale' => 'Folklore\Image\Filters\Grayscale',
            'interlace' => 'Folklore\Image\Filters\Interlace',
            'negative' => 'Folklore\Image\Filters\Negative',
            'rotate' => 'Folklore\Image\Filters\Rotate',
            'resize' => 'Folklore\Image\Filters\Resize',
        ],
        'sizes' => [
            ['id' => 'original'],
            ['id' => 'thumbnail', 'width' => 200, 'height' => 200],
            ['id' => 'small', 'maxWidth' => 400, 'maxHeight' => 400],
            ['id' => 'medium', 'maxWidth' => 700, 'maxHeight' => 700],
            ['id' => 'large', 'maxWidth' => 1400, 'maxHeight' => 1400],
            ['id' => 'bigthumbnail', 'maxWidth' => 1080, 'maxHeight' => 1080, 'crop' => true],
            ['id' => 'facebook', 'width' => 1200, 'height' => 630, 'crop' => true],
            ['id' => 'square', 'width' => 300, 'height' => 300, 'crop' => false],
        ],
        'source' => 'public',
        'sources' => [
            'public' => ['driver' => 'local', 'path' => null, 'cache' => false],
            'cloud' => ['driver' => 'filesystem', 'disk' => 'public', 'path' => null, 'cache' => true],
        ],
        'driver' => 'gd',
        'memory_limit' => '128M',
    ];

    protected TestHandler $deprecations;

    protected function setUp(): void
    {
        parent::setUp();

        Deprecations::flush();
        $this->deprecations = new TestHandler;
        $handler = $this->deprecations;
        $this->config()->set('logging.channels.deprecations', ['driver' => 'custom', 'via' => fn () => new Logger('deprecations', [$handler])]);
    }

    /**
     * The config is the project's before the providers are registered, as in an application.
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        $app->make(Repository::class)->set('image', [...self::CONFIG, ...$this->imageConfig()]);
    }

    /**
     * Keys of the project's config over CONFIG.
     *
     * @return array<string, mixed>
     */
    protected function imageConfig(): array
    {
        return [];
    }

    /**
     * The deprecations logged, without the call site.
     *
     * @return list<string>
     */
    protected function deprecations(): array
    {
        return array_values(array_map(
            fn (LogRecord $record) => (string) preg_replace('/ \(called in .*$/s', '', $record->message),
            $this->deprecations->getRecords(),
        ));
    }

    protected function config(): Repository
    {
        $config = $this->app?->make(Repository::class);
        $this->assertInstanceOf(Repository::class, $config);

        return $config;
    }
}
