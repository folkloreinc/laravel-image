<?php

declare(strict_types=1);

namespace Folklore\Image\Console;

use Closure;
use Folklore\Image\Compat\LegacyConfig;
use Folklore\Image\Compat\LegacyFilters;
use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Urls\PresetReference;
use Folklore\Image\Urls\V1Codec;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Upgrades a 1.x project (docs/upgrade.md, docs/decisions/0007-compatibility-layer.md):
 *
 * - `--check` lists what the project uses: the 1.x config keys to convert, the filters written
 *   against Imagine, the classes and container keys 2.x removes, and the image routes;
 * - without it, it rewrites `config/image.php` in the 2.x shape, with only the project's
 *   overrides, its named sizes and array filters as presets, and replaces the routes file of
 *   `image.routes.map` with the legacy route, so 1.x URLs and the files already written under
 *   `public/` keep being served.
 *
 * It never changes stored content or the images already written.
 */
#[AsCommand(name: 'image:upgrade')]
final class UpgradeCommand extends Command
{
    /** The 1.x filters that 2.x renders or reads otherwise: they are left out of the config. */
    private const array BUILT_IN_FILTERS = ['blur', 'colorize', 'gamma', 'grayscale', 'interlace', 'negative', 'rotate', 'resize'];

    /** Classes and container keys of 1.x that 2.x removes, with what replaces them. */
    private const array REMOVED = [
        '/Folklore\\\\Image\\\\Contracts\\\\(?!UrlCodec|Renderer|Operation|SourceResolver|DerivativeStore|Signer|Preset\b)\w+/' => 'the 1.x contracts are removed: use Folklore\Image\ImageManager or the 2.x contracts',
        '/Folklore\\\\Image\\\\(ImageHandler|ImageDataHandler|Imagine|ImagineManager|UrlGenerator|RouteRegistrar|RouteResolver|RouteRestrictions|SourceManager|CacheManager|Image)\b(?!\\\\)/' => 'removed in 2.x: use Folklore\Image\ImageManager',
        '/Folklore\\\\Image\\\\(Filters|Sources|Handlers|Jobs)\\\\\w+/' => 'removed in 2.x: filters are operations, sources are resolvers',
        '/Folklore\\\\Image\\\\Http\\\\(ImageResponse|CacheMiddleware)\b/' => 'removed in 2.x: the legacy route writes images under its cache_path',
        '/Utils::convertImage/' => 'removed in 2.x: import remote images with laravel-mediatheque 2',
        '/image:create_url_cache|CreateUrlCache/' => 'removed in 2.x: use image:warm',
        '/[\'"]image\.(url|source|imagine|imagine_manager|router|middleware\.cache)[\'"]/' => 'a 1.x container key, removed in 2.x',
        '/response\(\)->image\(/' => 'the image response macro is removed in 2.x',
        '/(?<!->)(?<!::)\bimage\(\s*[\'"$]/' => 'image($path) no longer makes an Imagine image: render with Image::for($path)->render()',
        '/Folklore\\\\Image\\\\(Facade|ServiceProvider)\b/' => 'deprecated: use Folklore\Image\Facades\Image; package discovery registers the provider',
    ];

    /** Keys of the 2.x config: a config with one of them is upgraded. */
    private const array KEYS_2X = ['renderer', 'presets', 'resolvers', 'derivatives', 'delivery', 'signing', 'limits', 'strict', 'bind_image'];

    /** Calls that register a filter written against Imagine: a closure or a class. */
    private const string IMAGINE_FILTER = '/->filter\(\s*[\'"][^\'"]+[\'"]\s*,\s*(function\b|fn\b|static\s|[A-Z\\\\][\w\\\\]*::class|[\'"][A-Z\\\\])/';

    /** Uses of the `image` container key, which the compatibility layer keeps binding. */
    private const string IMAGE_KEY = '/app\(\s*[\'"]image[\'"]\s*\)|\[\s*[\'"]image[\'"]\s*\]|resolve\(\s*[\'"]image[\'"]\s*\)|\bimage\(\)\s*->/';

    protected $signature = 'image:upgrade
        {--check : List what the project uses, without changing anything}';

    protected $description = 'Upgrade a 1.x project: convert config/image.php and the image routes';

    public function handle(Application $app): int
    {
        $file = $app->configPath('image.php');
        $project = is_file($file) ? self::load($file) : [];

        if ($this->option('check')) {
            return $this->check($app, $project);
        }

        if (! self::isLegacy($project)) {
            $this->components->info('config/image.php is already in the 2.x shape: there is nothing to upgrade.');

            return self::SUCCESS;
        }

        $routes = $this->routesFile($project);
        $converted = $this->convert($app, $project);
        file_put_contents($file, $this->exportConfig($app, $converted));
        $this->components->info('config/image.php is in the 2.x shape.');

        if ($routes !== null) {
            $calls = $this->recordRoutes($routes);
            if ($calls === null) {
                $this->components->warn("{$routes} registers other routes: replace its \$router->image() calls with Route::imageLegacy() yourself.");
            } else {
                file_put_contents($routes, $this->exportRoutes($app, $calls, $project));
                $this->components->info(self::relative($app, $routes).' registers the legacy route.');
            }
        }

        foreach ($this->imagineFilters($app, $project) as $filter) {
            $this->components->warn("A filter written against Imagine, {$filter}, must be rewritten as an operation (docs/upgrade.md).");
        }
        $this->line('  Run php artisan image:upgrade --check to list the code that uses what 2.x removes.');

        return self::SUCCESS;
    }

    /**
     * @param  array<mixed>  $project
     */
    private function check(Application $app, array $project): int
    {
        $this->components->twoColumnDetail('<fg=gray>Config</>', 'config/image.php');
        if (! self::isLegacy($project)) {
            $this->components->twoColumnDetail('1.x keys', '<fg=green>none: already in the 2.x shape</>');
        } else {
            foreach ($this->configChanges($project) as $key => $change) {
                $this->components->twoColumnDetail($key, $change);
            }
        }
        $imageUrl = $app->make(Repository::class)->get('app.image_url');
        if (is_string($imageUrl) && $imageUrl !== '') {
            $this->components->twoColumnDetail('app.image_url', 'read as the host of 2.x URLs when image.url.host is not set');
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Filters written against Imagine</>', 'rewrite them as operations');
        $filters = $this->imagineFilters($app, $project);
        foreach ($filters === [] ? ['none' => '<fg=green>ok</>'] : array_fill_keys($filters, '<fg=yellow>rewrite</>') as $name => $status) {
            $this->components->twoColumnDetail((string) $name, $status);
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Code that uses what 2.x removes</>');
        // config/image.php itself is converted.
        $removed = array_filter($this->scan($app, self::REMOVED), fn (string $location) => ! str_starts_with($location, 'config/image.php:'), ARRAY_FILTER_USE_KEY);
        foreach ($removed === [] ? ['none' => '<fg=green>ok</>'] : $removed as $location => $message) {
            $this->components->twoColumnDetail($location, $message);
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Image routes</>');
        foreach ($this->routeChecks($app, $project) as $route => $status) {
            $this->components->twoColumnDetail($route, $status);
        }

        return self::SUCCESS;
    }

    /**
     * What happens to each 1.x key of the config.
     *
     * @param  array<mixed>  $project
     * @return array<string, string>
     */
    private function configChanges(array $project): array
    {
        $changes = [];
        $describe = [
            'sizes' => 'named sizes, converted to presets (kept while the code reads image.sizes)',
            'filters' => 'array filters converted to presets; built-in filters are operations; Imagine filters must be rewritten',
            'source' => 'the default source of the legacy route',
            'sources' => 'the sources of the legacy route (local and filesystem)',
            'url' => 'the 1.x URL template, kept for 1.x URLs only',
            'routes' => 'the routes file, rewritten with Route::imageLegacy()',
            'restrictions' => 'the restrictions of the legacy route',
            'driver' => 'renderer.driver',
            'upscale' => 'the legacy route keeps 1.x upscaling; 2.x routes never upscale unless it is true',
            'auto_orient' => 'removed: 2.x always turns images upright',
            'strip_metadata' => 'removed: 2.x always strips metadata',
            'utils' => 'removed with Utils::convertImage()',
            'memory_limit' => 'kept, unless it is 1.x\'s default (128M)',
            'quality' => 'kept, unless it is the default',
        ];
        foreach ($describe as $key => $description) {
            if (array_key_exists($key, $project)) {
                $changes[$key] = $description;
            }
        }

        return $changes;
    }

    /**
     * The 2.x config of a 1.x one: only the project's overrides.
     *
     * @param  array<mixed>  $project
     * @return array<string, mixed>
     */
    private function convert(Application $app, array $project): array
    {
        $config = [];

        $driver = $project['driver'] ?? null;
        if (in_array($driver, ['gd', 'imagick'], true)) {
            $config['renderer'] = ['driver' => $driver];
        }

        $quality = LegacyConfig::options($project['quality'] ?? []);
        if ($quality !== [] && $quality !== ['jpeg' => 82, 'webp' => 80, 'avif' => 60]) {
            $config['quality'] = $quality;
        }

        [$presets, $filters] = $this->presets($project);
        if ($presets !== []) {
            $config['presets'] = $presets;
        }
        if ($filters !== []) {
            $config['filters'] = $filters;
        }

        $url = array_intersect_key(LegacyConfig::options($project['url'] ?? []), array_flip(LegacyConfig::URL_KEYS));
        $template = array_diff_key(V1Codec::DEFAULTS, ['placeholders_patterns' => true]);
        foreach ($url as $key => $value) {
            $default = $key === 'placeholders_patterns' ? null : ($template[$key] ?? null);
            if ($value === $default || ($key === 'placeholders_patterns' && self::defaultPatterns($value))) {
                unset($url[$key]);
            }
        }
        if ($url !== []) {
            $config['url'] = $url;
        }

        if (($project['upscale'] ?? null) === true) {
            $config['upscale'] = true;
        }
        $memoryLimit = $project['memory_limit'] ?? null;
        if (is_string($memoryLimit) && $memoryLimit !== '128M') {
            $config['memory_limit'] = $memoryLimit;
        }

        if (isset($project['sizes']) && $this->scan($app, ['/image\.sizes/' => 'reads image.sizes']) !== []) {
            $config['sizes'] = $project['sizes'];
        }
        if (is_string($project['source'] ?? null) && $project['source'] !== 'public') {
            $config['source'] = $project['source'];
        }
        $sources = $this->sources($app, $project['sources'] ?? null);
        if ($sources !== null) {
            $config['sources'] = $sources;
        }
        $restrictions = self::changed(LegacyConfig::options($project['restrictions'] ?? []), LegacyConfig::RESTRICTIONS);
        if ($restrictions !== []) {
            $config['restrictions'] = [...LegacyConfig::RESTRICTIONS, ...$restrictions];
        }
        $routes = LegacyConfig::options($project['routes'] ?? []);
        if (is_string($routes['map'] ?? null)) {
            $config['routes'] = array_intersect_key($routes, array_flip(['map', 'domain', 'prefix', 'as', 'middleware']));
        }

        // Always written: it marks the config as upgraded.
        $config['bind_image'] = $this->scan($app, [self::IMAGE_KEY => 'uses the image key']) !== [];

        return $config;
    }

    /**
     * The presets of the named sizes, then of the array filters, and the array filters that
     * can't be presets, which 1.x URLs keep expanding.
     *
     * @param  array<mixed>  $project
     * @return array{array<string, array<string, mixed>>, array<string, array<mixed>>}
     */
    private function presets(array $project): array
    {
        $presets = (new ConfigRepository(['image' => ['sizes' => $project['sizes'] ?? []]]));
        $presets = (new LegacyConfig($presets))->sizePresets();
        $filters = [];

        foreach (LegacyConfig::options($project['filters'] ?? []) as $name => $filter) {
            if (! is_array($filter) || isset($presets[$name])) {
                continue;
            }
            try {
                if (! PresetReference::isValidName($name)) {
                    throw new InvalidImageSpec('its name has other characters than lowercase letters, digits, - and _.');
                }
                $presets[$name] = LegacyFilters::presetDefinition($filter);
            } catch (InvalidImageSpec $exception) {
                $filters[$name] = $filter;
                $this->components->warn("The filter {$name} stays a 1.x filter, not a preset: {$exception->getMessage()}");
            }
        }

        return [$presets, $filters];
    }

    /**
     * The sources, when they aren't 1.x's defaults.
     *
     * @return array<string, mixed>|null
     */
    private function sources(Application $app, mixed $sources): ?array
    {
        if (! is_array($sources)) {
            return null;
        }

        $defaults = [
            'public' => ['driver' => 'local', 'path' => $app->publicPath()],
            'cloud' => ['driver' => 'filesystem', 'disk' => 'public', 'path' => null],
        ];
        $kept = [];
        foreach (LegacyConfig::options($sources) as $name => $source) {
            $options = array_intersect_key(LegacyConfig::options($source), array_flip(['driver', 'path', 'disk']));
            if (($defaults[$name] ?? null) != $options) {
                $kept[$name] = $options;
            }
        }

        return $kept === [] ? null : LegacyConfig::options($sources);
    }

    /**
     * The routes file to rewrite: the one of `image.routes.map`, when it registers image routes.
     *
     * @param  array<mixed>  $project
     */
    private function routesFile(array $project): ?string
    {
        $map = LegacyConfig::options($project['routes'] ?? [])['map'] ?? null;

        return is_string($map) && is_file($map) && preg_match('/\$router->image\(/', (string) file_get_contents($map)) === 1 ? $map : null;
    }

    /**
     * The `$router->image()` calls of a routes file, recorded by requiring it with a router that
     * records them, or null when it calls anything else.
     *
     * @return list<array{string, array<string, mixed>}>|null
     */
    private function recordRoutes(string $file): ?array
    {
        $router = new class
        {
            /** @var list<array{string, array<string, mixed>}> */
            public array $images = [];

            public bool $other = false;

            /**
             * @param  array<string, mixed>  $config
             */
            public function image(string $path, array $config = []): void
            {
                $this->images[] = [$path, $config];
            }

            /**
             * @param  array<mixed>  $arguments
             */
            public function __call(string $method, array $arguments): void
            {
                $this->other = true;
            }
        };

        try {
            (static function (string $file) use ($router): void {
                require $file;
            })($file);
        } catch (Throwable) {
            return null;
        }

        return $router->other ? null : $router->images;
    }

    /**
     * The names of the filters written against Imagine: in the config, and registered in code.
     *
     * @param  array<mixed>  $project
     * @return list<string>
     */
    private function imagineFilters(Application $app, array $project): array
    {
        $filters = [];
        foreach (LegacyConfig::options($project['filters'] ?? []) as $name => $filter) {
            $builtIn = is_string($filter) && str_starts_with(ltrim($filter, '\\'), 'Folklore\\Image\\Filters\\') && in_array($name, self::BUILT_IN_FILTERS, true);
            if (! is_array($filter) && ! $builtIn) {
                $filters[] = "{$name} (config/image.php)";
            }
        }
        foreach (array_keys($this->scan($app, [self::IMAGINE_FILTER => 'filter'])) as $location) {
            $filters[] = $location;
        }

        return $filters;
    }

    /**
     * The image routes and where their cache writes.
     *
     * @param  array<mixed>  $project
     * @return array<string, string>
     */
    private function routeChecks(Application $app, array $project): array
    {
        $checks = [];
        $routes = $this->routesFile($project);
        $calls = $routes === null ? [] : ($this->recordRoutes($routes) ?? []);

        foreach ($calls as [$path, $options]) {
            $cachePath = $options['cache_path'] ?? null;
            $public = ($options['cache'] ?? false) === true && (! is_string($cachePath) || str_starts_with($cachePath, $app->publicPath()));
            $checks[$path] = $public ? 'writes images under public/: the legacy route keeps doing it' : 'replaced by Route::imageLegacy()';
        }
        if ($routes === null) {
            $map = LegacyConfig::options($project['routes'] ?? [])['map'] ?? null;
            $checks[is_string($map) ? self::relative($app, $map) : 'image.routes.map'] = is_string($map)
                ? 'missing: 1.x registered its default catch-all route, which image:upgrade adds'
                : '<fg=green>none</>';
        }

        return $checks;
    }

    /**
     * The lines of the project's code that match patterns, by `file:line`.
     *
     * @param  array<string, string>  $patterns
     * @return array<string, string>
     */
    private function scan(Application $app, array $patterns): array
    {
        $directories = array_filter(
            array_map(fn (string $directory) => $app->basePath($directory), ['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'src']),
            is_dir(...),
        );
        if ($directories === []) {
            return [];
        }

        $found = [];
        $finder = (new Finder)->files()->in($directories)->name('*.php')->exclude(['cache', 'vendor']);
        foreach ($finder as $file) {
            foreach (preg_split('/\R/', $file->getContents()) ?: [] as $index => $line) {
                foreach ($patterns as $pattern => $message) {
                    if (preg_match($pattern, $line) === 1) {
                        $found[self::relative($app, $file->getPathname()).':'.($index + 1)] = $message;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function exportConfig(Application $app, array $config): string
    {
        $body = $config === [] ? '' : implode("\n", array_map(
            fn (string $key) => '    '.var_export($key, true).' => '.$this->export($app, $config[$key], 1).',',
            array_keys($config),
        ))."\n";

        return <<<PHP
            <?php

            declare(strict_types=1);

            /*
             * The image config of 2.x, written by image:upgrade (docs/upgrade.md). Only the keys this
             * project changes are here: the package's defaults fill in the others
             * (vendor/folklore/laravel-image/config/image.php).
             *
             * The 1.x keys kept here (url.format and the other keys of the 1.x URL template, source,
             * sources, restrictions, sizes and routes) are only read by the compatibility layer, for
             * the 1.x URLs of the legacy route and Image::url().
             */
            return [
            {$body}];

            PHP;
    }

    /**
     * @param  list<array{string, array<string, mixed>}>  $calls
     * @param  array<mixed>  $project
     */
    private function exportRoutes(Application $app, array $calls, array $project): string
    {
        $upscale = $project['upscale'] ?? null;
        $routes = [];
        foreach ($calls as [$path, $options]) {
            if (is_bool($upscale) && ! array_key_exists('upscale', $options)) {
                $options['upscale'] = $upscale;
            }
            $options = array_filter($options, fn ($value, $key) => ! in_array($key, ['domain', 'source', 'quality'], true) || $value !== null, ARRAY_FILTER_USE_BOTH);
            $routes[] = 'Route::imageLegacy('.var_export($path, true).', '.$this->export($app, LegacyConfig::options($options + ['cache' => false, 'expires' => null]), 0).');';
        }

        $body = implode("\n\n", $routes);

        return <<<PHP
            <?php

            declare(strict_types=1);

            use Illuminate\Support\Facades\Route;

            /*
             * The 1.x image routes, written by image:upgrade (docs/upgrade.md): they keep serving the
             * 1.x URLs, and writing each image at its path under cache_path, where the web server
             * serves it next time. 2.x URLs are served by Route::image().
             */

            {$body}

            PHP;
    }

    /**
     * A value as PHP code, with the paths of the application as calls to their helpers.
     */
    private function export(Application $app, mixed $value, int $depth): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }
            $indent = str_repeat('    ', $depth + 1);
            $list = array_is_list($value);
            $items = [];
            foreach ($value as $key => $item) {
                if ($item instanceof Closure) {
                    continue;
                }
                $items[] = $indent.($list ? '' : var_export($key, true).' => ').$this->export($app, $item, $depth + 1).',';
            }

            return "[\n".implode("\n", $items)."\n".str_repeat('    ', $depth).']';
        }

        if (is_string($value)) {
            foreach (['public_path' => $app->publicPath(), 'storage_path' => $app->storagePath(), 'base_path' => $app->basePath()] as $helper => $path) {
                if ($value === $path) {
                    return "{$helper}()";
                }
                if (str_starts_with($value, $path.DIRECTORY_SEPARATOR)) {
                    return "{$helper}(".var_export(substr($value, strlen($path) + 1), true).')';
                }
            }
        }

        return $value === null ? 'null' : var_export($value, true);
    }

    /**
     * The config of a file, as the project wrote it, without the package's defaults.
     *
     * @return array<mixed>
     */
    private static function load(string $file): array
    {
        $config = (static fn (string $file): mixed => require $file)($file);

        return is_array($config) ? $config : [];
    }

    /**
     * Whether a config is a 1.x config: with 1.x keys, and none of the 2.x keys.
     *
     * @param  array<mixed>  $project
     */
    private static function isLegacy(array $project): bool
    {
        if (array_intersect_key($project, array_flip(self::KEYS_2X)) !== []) {
            return false;
        }
        if (array_intersect_key($project, array_flip(LegacyConfig::KEYS)) !== []) {
            return true;
        }

        return is_array($project['url'] ?? null) && array_intersect_key($project['url'], array_flip(LegacyConfig::URL_KEYS)) !== [];
    }

    private static function defaultPatterns(mixed $patterns): bool
    {
        return is_array($patterns) && self::changed(LegacyConfig::options($patterns), V1Codec::DEFAULTS['placeholders_patterns']) === [];
    }

    /**
     * The values that differ from the defaults.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private static function changed(array $values, array $defaults): array
    {
        return array_filter($values, fn (mixed $value, string $key) => ! array_key_exists($key, $defaults) || $defaults[$key] !== $value, ARRAY_FILTER_USE_BOTH);
    }

    private static function relative(Application $app, string $path): string
    {
        return str_starts_with($path, $app->basePath().DIRECTORY_SEPARATOR) ? substr($path, strlen($app->basePath()) + 1) : $path;
    }
}
