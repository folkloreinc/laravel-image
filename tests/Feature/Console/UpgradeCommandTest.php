<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Console;

use Folklore\Image\Http\LegacyImageController;
use Folklore\Image\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;

/**
 * image:upgrade on a copy of a 1.x project (tests/fixtures/legacy/project): its config, its
 * routes file and its code.
 */
final class UpgradeCommandTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = sys_get_temp_dir().'/laravel-image-project-'.bin2hex(random_bytes(4));
        $files = new Filesystem;
        $files->copyDirectory(self::fixturePath('legacy/project'), $this->project);
        $files->makeDirectory("{$this->project}/public");
        $files->makeDirectory("{$this->project}/storage");
        $this->app?->setBasePath($this->project);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->project);

        parent::tearDown();
    }

    public function test_check_lists_what_the_project_uses(): void
    {
        $before = $this->contents();

        $this->assertSame(0, Artisan::call('image:upgrade', ['--check' => true]));
        $output = Artisan::output();

        foreach (['sizes', 'filters', 'sources', 'url', 'routes', 'driver'] as $key) {
            $this->assertMatchesRegularExpression("/^\s+{$key}\s/m", $output, "The 1.x key {$key} is listed.");
        }
        $this->assertStringContainsString('sepia (config/image.php)', $output);
        $this->assertStringContainsString('app/Providers/AppServiceProvider.php:19', $output, 'The closure filter is listed.');
        $this->assertStringContainsString('app/Providers/AppServiceProvider.php:7', $output, 'The 1.x contract is listed.');
        $this->assertStringContainsString('app/Entities/Image.php:17', $output, 'image($path) is listed.');
        $this->assertStringNotContainsString('app/Entities/Image.php:12', $output, 'image()->url() keeps working.');
        $this->assertStringContainsString('writes images under public/', $output);
        $this->assertMatchesRegularExpression('/image\.routes\.controller .*Folklore/', $output, 'Dropped keys are listed with their value.');
        $this->assertMatchesRegularExpression('/image\.sources\.cloud\.cache_path /', $output);
        $this->assertMatchesRegularExpression('/What would be lost .*\n\s+none/', $output);
        $this->assertStringNotContainsString('config/image.php:', $output, 'The config is converted, not listed.');
        $this->assertSame($before, $this->contents(), '--check changes nothing.');
    }

    public function test_check_lists_lookups_of_the_1x_container_keys_only(): void
    {
        $this->writeCode('app/Services/Snippets.php', <<<'PHP'
            <?php

            use Illuminate\Support\Facades\App;

            $url = data_get($snippet, 'image.url');
            $host = config('image.url.host');
            $paths = ['image.url' => $snippet['image.url']];
            $generator = app('image.url');
            $source = app()->make('image.source');
            $imagine = resolve('image.imagine');
            $router = $this->app['image.router'];
            $manager = App::make("image.imagine_manager");
            Route::get('/', fn () => 'ok')->middleware('image.middleware.cache');
            PHP);

        $this->assertSame(0, Artisan::call('image:upgrade', ['--check' => true]));
        $output = Artisan::output();

        foreach ([5, 6, 7] as $line) {
            $this->assertStringNotContainsString("app/Services/Snippets.php:{$line} ", $output, "Line {$line} is no container lookup.");
        }
        foreach ([8, 9, 10, 11, 12] as $line) {
            $this->assertMatchesRegularExpression("/app\/Services\/Snippets\.php:{$line} .*a 1\.x container key/", $output);
        }
        $this->assertMatchesRegularExpression('/app\/Services\/Snippets\.php:13 .*cache middleware is removed/', $output);
    }

    public function test_check_lists_every_use_of_the_1x_utils(): void
    {
        $this->writeCode('app/Imports/WordPressImage.php', <<<'PHP'
            <?php

            namespace App\Imports;

            use Folklore\Image\Utils;

            class WordPressImage
            {
                public function mime(string $path): string
                {
                    return Utils::isPng($path) && Utils::hasTransparency($path) ? 'image/png' : Utils::getMimeFromExtension($path);
                }

                public function convert(string $path): ?string
                {
                    return Utils::convertImage($path);
                }
            }
            PHP);
        $this->writeCode('app/Imports/Aliased.php', <<<'PHP'
            <?php

            use Folklore\Image\Utils as ImageUtils;

            $png = ImageUtils::isPng($path);
            $qualified = \Folklore\Image\Utils::hasTransparency($path);
            PHP);
        $this->writeCode('app/Support/Other.php', <<<'PHP'
            <?php

            use App\Support\Utils;

            $slug = Utils::slug($title);
            PHP);

        $this->assertSame(0, Artisan::call('image:upgrade', ['--check' => true]));
        $output = Artisan::output();

        foreach (['app/Imports/WordPressImage.php:5', 'app/Imports/WordPressImage.php:11', 'app/Imports/Aliased.php:3', 'app/Imports/Aliased.php:5', 'app/Imports/Aliased.php:6'] as $location) {
            $this->assertMatchesRegularExpression('/'.preg_quote($location, '/').' .*Folklore\\\\Image\\\\Utils is removed in 2\.x/', $output);
        }
        $this->assertMatchesRegularExpression('/app\/Imports\/WordPressImage\.php:16 .*Utils::convertImage\(\) is removed in 2\.x/', $output);
        $this->assertStringNotContainsString('app/Support/Other.php', $output, 'Another class named Utils is not listed.');
    }

    public function test_imagine_autorotate_is_dropped_not_rewritten(): void
    {
        $this->replaceInConfig("'square' => ['width' => 500, 'height' => 500, 'crop' => true],", "'square' => ['width' => 500, 'height' => 500, 'crop' => true, 'autorotate' => true],\n        'autorotate' => Imagine\\Filter\\Basic\\Autorotate::class,");
        $this->writeCode('app/Providers/ImageServiceProvider.php', <<<'PHP'
            <?php

            use Imagine\Filter\Basic\Autorotate;

            $this->app['image']->filter('upright', Autorotate::class);
            PHP);

        $this->assertSame(0, Artisan::call('image:upgrade', ['--check' => true]));
        $check = Artisan::output();

        $this->assertMatchesRegularExpression('/autorotate \(config\/image\.php\) .*drop it: 2\.x always turns images upright/', $check);
        $this->assertMatchesRegularExpression('/app\/Providers\/ImageServiceProvider\.php:5 .*drop it/', $check);
        $this->assertMatchesRegularExpression('/sepia \(config\/image\.php\) .*rewrite/', $check, 'Other Imagine filters are still to rewrite.');
        $this->assertMatchesRegularExpression('/What would be lost .*\n\s+none/', $check);

        $this->assertSame(0, Artisan::call('image:upgrade'));
        $output = Artisan::output();

        $this->assertStringNotContainsString('autorotate (config/image.php), must be rewritten', $output);
        $this->assertStringNotContainsString('ImageServiceProvider.php:5, must be rewritten', $output);
        $this->assertStringContainsString('sepia (config/image.php), must be rewritten', $output);
        $config = require "{$this->project}/config/image.php";
        $this->assertIsArray($config);
        $this->assertIsArray($config['presets']);
        $this->assertSame(['width' => 500, 'height' => 500], $config['presets']['square'], 'The preset has no autorotate operation.');
    }

    public function test_it_converts_the_config_to_the_2x_shape(): void
    {
        $this->assertSame(0, Artisan::call('image:upgrade'));

        $contents = (string) file_get_contents("{$this->project}/config/image.php");
        $this->assertStringContainsString("'map' => base_path('routes/images.php'),", $contents);
        $this->assertStringNotContainsString($this->project, $contents, 'Paths are written with their helpers.');

        $config = require "{$this->project}/config/image.php";
        $this->assertIsArray($config);
        $this->assertSame(['driver' => 'gd'], $config['renderer']);
        $this->assertSame([
            'thumbnail' => ['width' => 200, 'height' => 200],
            'small' => ['width' => 400, 'height' => 400, 'fit' => 'inside'],
            'large' => ['width' => 1400, 'height' => 1400, 'fit' => 'inside'],
            'facebook' => ['width' => 1200, 'height' => 630],
            'square' => ['width' => 500, 'height' => 500],
        ], $config['presets']);
        $this->assertIsArray($config['url']);
        $this->assertSame('{dirname}/{filters}{basename}.{extension}', $config['url']['format']);
        $this->assertSame('filters/{filter}/', $config['url']['filters_format']);
        $this->assertArrayHasKey('sizes', $config, 'The code reads image.sizes.');
        $this->assertArrayNotHasKey('sources', $config, 'The sources are the 1.x defaults.');
        $this->assertArrayNotHasKey('filters', $config, 'Every array filter is a preset.');
        $this->assertArrayNotHasKey('memory_limit', $config);
        $this->assertSame(['map' => "{$this->project}/routes/images.php", 'domain' => null, 'middleware' => []], $config['routes']);
        $this->assertTrue($config['bind_image'], 'The code uses the image key.');
    }

    public function test_it_replaces_the_routes_file_with_the_legacy_route(): void
    {
        $this->assertSame(0, Artisan::call('image:upgrade'));

        $contents = (string) file_get_contents("{$this->project}/routes/images.php");
        $this->assertStringContainsString("Route::imageLegacy('{pattern}', [", $contents);
        $this->assertStringContainsString("'cache_path' => public_path(),", $contents);
        $this->assertStringNotContainsString('$router->image', $contents);

        $router = $this->app?->make(Router::class);
        $this->assertInstanceOf(Router::class, $router);
        $router->setRoutes(new RouteCollection);
        require "{$this->project}/routes/images.php";

        $route = $router->getRoutes()->getByName('image');
        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame(LegacyImageController::class, $route->getControllerClass());
        $options = $route->getAction('image');
        $this->assertIsArray($options);
        $this->assertSame([true, "{$this->project}/public", 2678400], [$options['cache'], $options['cache_path'], $options['expires']]);
    }

    public function test_it_keeps_the_original_files(): void
    {
        $before = $this->contents();

        $this->assertSame(0, Artisan::call('image:upgrade'));

        $this->assertSame($before['config/image.php'], file_get_contents("{$this->project}/config/image.php.1x"));
        $this->assertSame($before['routes/images.php'], file_get_contents("{$this->project}/routes/images.php.1x"));
    }

    public function test_it_refuses_to_lose_values_from_env_or_code_and_unknown_keys(): void
    {
        $this->replaceInConfig("'memory_limit' => '128M',", "'memory_limit' => env('IMAGE_MEMORY', '512M'),\n    'custom' => true,");
        $before = $this->contents();

        $this->assertSame(0, Artisan::call('image:upgrade', ['--check' => true]));
        $check = Artisan::output();
        $this->assertSame(1, Artisan::call('image:upgrade'));
        $output = Artisan::output();

        foreach ([$check, $output] as $listed) {
            $this->assertMatchesRegularExpression('/config\/image\.php:\d+ .*env\(\)/', $listed);
            $this->assertMatchesRegularExpression('/image\.custom .*doesn\'t know/', $listed);
        }
        $this->assertSame($before, $this->contents(), 'Nothing is written.');

        $this->assertSame(0, Artisan::call('image:upgrade', ['--force' => true]));
        $config = require "{$this->project}/config/image.php";
        $this->assertIsArray($config);
        $this->assertSame('512M', $config['memory_limit']);
    }

    public function test_a_routes_file_that_does_more_is_left_as_it_is(): void
    {
        $routes = "{$this->project}/routes/images.php";
        file_put_contents($routes, (string) file_get_contents($routes)."\nif (true) {\n    \$router->get('/ping', fn () => 'pong');\n}\n");
        $before = file_get_contents($routes);

        $this->assertSame(0, Artisan::call('image:upgrade'));

        $this->assertStringContainsString('does more than call $router->image()', Artisan::output());
        $this->assertSame($before, file_get_contents($routes));
    }

    public function test_without_routes_key_the_default_route_is_written(): void
    {
        $this->replaceConfigKey('routes');
        unlink("{$this->project}/routes/images.php");

        $this->assertSame(0, Artisan::call('image:upgrade', ['--check' => true]));
        $this->assertStringContainsString('2.x registers no route until image:upgrade sets image.routes.map', Artisan::output());

        $this->assertSame(0, Artisan::call('image:upgrade'));

        $config = require "{$this->project}/config/image.php";
        $this->assertIsArray($config);
        $this->assertSame(['map' => "{$this->project}/routes/images.php"], $config['routes']);
        $route = $this->legacyRoute();
        $this->assertSame([true, "{$this->project}/public"], [$route['cache'], $route['cache_path']]);
    }

    public function test_the_1x_upscale_is_kept_in_the_config(): void
    {
        $this->replaceInConfig("'driver' => 'gd',", "'driver' => 'gd',\n    'upscale' => false,");

        $this->assertSame(0, Artisan::call('image:upgrade'));

        $config = require "{$this->project}/config/image.php";
        $this->assertIsArray($config);
        $this->assertFalse($config['upscale']);
    }

    public function test_it_upgrades_once(): void
    {
        $this->assertSame(0, Artisan::call('image:upgrade'));
        $upgraded = $this->contents();

        $this->assertSame(0, Artisan::call('image:upgrade'));

        $this->assertStringContainsString('already in the 2.x shape', Artisan::output());
        $this->assertSame($upgraded, $this->contents());
    }

    public function test_a_2x_config_has_nothing_to_upgrade(): void
    {
        file_put_contents("{$this->project}/config/image.php", "<?php\n\nreturn ['presets' => ['card' => ['width' => 800]]];\n");

        $this->assertSame(0, Artisan::call('image:upgrade'));

        $this->assertStringContainsString('already in the 2.x shape', Artisan::output());
    }

    /**
     * The options of the legacy route that the routes file registers.
     *
     * @return array<mixed>
     */
    private function legacyRoute(): array
    {
        $router = $this->app?->make(Router::class);
        $this->assertInstanceOf(Router::class, $router);
        $router->setRoutes(new RouteCollection);
        require "{$this->project}/routes/images.php";

        $route = $router->getRoutes()->getByName('image');
        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame(LegacyImageController::class, $route->getControllerClass());
        $options = $route->getAction('image');
        $this->assertIsArray($options);

        return $options;
    }

    /**
     * Adds a file to the project's code.
     */
    private function writeCode(string $path, string $contents): void
    {
        $file = "{$this->project}/{$path}";
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, $contents."\n");
    }

    private function replaceInConfig(string $search, string $replace): void
    {
        $file = "{$this->project}/config/image.php";
        $contents = (string) file_get_contents($file);
        $this->assertStringContainsString($search, $contents);
        file_put_contents($file, str_replace($search, $replace, $contents));
    }

    /**
     * Removes a top-level key of the project's config, and its value.
     */
    private function replaceConfigKey(string $key): void
    {
        $file = "{$this->project}/config/image.php";
        $contents = (string) file_get_contents($file);
        $updated = (string) preg_replace("/\n    '{$key}' => \[.*?\n    \],/s", '', $contents, 1);
        $this->assertNotSame($contents, $updated);
        file_put_contents($file, $updated);
    }

    /**
     * @return array<string, string>
     */
    private function contents(): array
    {
        $contents = [];
        foreach ((new Filesystem)->allFiles($this->project) as $file) {
            $contents[$file->getRelativePathname()] = $file->getContents();
        }

        return $contents;
    }
}
