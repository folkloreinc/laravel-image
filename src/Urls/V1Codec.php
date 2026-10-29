<?php

declare(strict_types=1);

namespace Folklore\Image\Urls;

use Folklore\Image\Compat\LegacyFilters;
use Folklore\Image\Contracts\UrlCodec;
use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Exceptions\InvalidImageUrl;
use Folklore\Image\Sources\DiskResolver;
use Folklore\Image\Specs\Fit;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;

/**
 * The 1.x URLs (docs/decisions/0004-specs-and-urls.md): a template of the 1.x `image.url`
 * config, filled with the filters in the order they were given.
 *
 *     {dirname}/{basename}{filters}.{extension}{format_extension}
 *
 *     uploads/photo-filters(300x300-crop-grayscale).jpg
 *     uploads/photo-filters(large).jpg.webp
 *     uploads/filters/large/photo.jpg        with format {dirname}/{filters}{basename}.{extension},
 *                                            filters_format filters/{filter}/ and filter_separator /
 *
 * It decodes every URL 1.x generates, with the project's 1.x config, for as long as 2.x and later
 * versions exist. The parsing is the one of 1.x's UrlGenerator, quirks included. 1.x URLs have no
 * canonical form: every spelling 1.x accepted is decoded, and each is a URL of its own.
 *
 * Named filters (`large`) are expanded from the 1.x filters registered with Image::filter(), or
 * read as the preset of that name (LegacyFilters).
 */
final readonly class V1Codec implements UrlCodec
{
    /** The defaults of the 1.x `image.url` config. */
    public const array DEFAULTS = [
        'format' => '{dirname}/{basename}{filters}.{extension}{format_extension}',
        'filters_format' => '-filters({filter})',
        'filter_format' => '{key}({value})',
        'filter_separator' => '-',
        'placeholders_patterns' => [
            'host' => '(.*?)?',
            'dirname' => '(.*?)?',
            'basename' => '([^\/\.]+?)',
            'filename' => '([^\/]+)',
            'extension' => '(jpeg|jpg|gif|png|webp|avif|bmp|heic|svg)',
            'format_extension' => '(\.(jpeg|jpg|gif|png|webp|avif))?',
        ],
    ];

    private string $format;

    private string $filtersFormat;

    private string $filterFormat;

    private string $filterSeparator;

    /** @var array<string, string> */
    private array $placeholdersPatterns;

    /**
     * @param  LegacyFilters  $filters  the named 1.x filters, and their conversion to a spec
     * @param  string  $resolver  the resolver of the decoded URLs: the 1.x source they are read from
     * @param  array<mixed>  $options  the 1.x `image.url` config, or a route's `pattern`; the
     *                                 defaults fill in the keys it doesn't set
     */
    public function __construct(
        private LegacyFilters $filters,
        private string $resolver = 'public',
        array $options = [],
    ) {
        $string = function (string $key) use ($options): string {
            $value = $options[$key] ?? self::DEFAULTS[$key];

            return is_string($value) ? $value : throw new InvalidImageUrl("The 1.x URL option {$key} is a string.");
        };

        $this->format = $string('format');
        $this->filtersFormat = $string('filters_format');
        $this->filterFormat = $string('filter_format');
        $this->filterSeparator = $string('filter_separator');

        $patterns = self::DEFAULTS['placeholders_patterns'];
        $given = $options['placeholders_patterns'] ?? [];
        foreach (is_array($given) ? $given : [] as $name => $pattern) {
            if (is_string($name) && is_string($pattern)) {
                $patterns[$name] = $pattern;
            }
        }
        $this->placeholdersPatterns = $patterns;
    }

    /**
     * The same codec, with options of a route's `pattern` over these.
     *
     * @param  array<mixed>  $options
     */
    public function withOptions(array $options, ?string $resolver = null): self
    {
        return new self($this->filters, $resolver ?? $this->resolver, [
            'format' => $options['format'] ?? $this->format,
            'filters_format' => $options['filters_format'] ?? $this->filtersFormat,
            'filter_format' => $options['filter_format'] ?? $this->filterFormat,
            'filter_separator' => $options['filter_separator'] ?? $this->filterSeparator,
            'placeholders_patterns' => [...$this->placeholdersPatterns, ...(is_array($options['placeholders_patterns'] ?? null) ? $options['placeholders_patterns'] : [])],
        ]);
    }

    /**
     * A 1.x URL for a URL: its free spec as 1.x filters, or its preset's name. Image::url() keeps
     * the filters in the order they were given, with make(); this order is the spec's.
     */
    public function encode(ImageUrl $url): string
    {
        if ($url->signature !== null || $url->expires !== null) {
            throw new InvalidImageUrl('1.x URLs are not signed.');
        }

        return $this->payload($url);
    }

    public function payload(ImageUrl $url): string
    {
        $filters = $url->preset !== null ? [$url->preset->name] : self::specFilters($url->spec ?? new ImageSpec);

        $key = implode('/', array_map(rawurlencode(...), explode('/', $url->key)));
        if ($url->format !== self::sourceFormat($url->key)) {
            $filters['format'] = $url->format->extension();
        }

        return ltrim($this->make($key, $filters), '/');
    }

    /**
     * Decodes a path, percent-encoded, after the route's prefix.
     */
    public function decode(string $path): ImageUrl
    {
        $parsed = $this->parse($path);

        try {
            $spec = $this->filters->spec($parsed['filters']);
            $format = $parsed['format'] === null ? self::sourceFormat($parsed['path']) : self::formatOf($parsed['format']);

            return ImageUrl::forSpec($this->resolver, $this->key($parsed['path']), $spec->withFormat($format));
        } catch (InvalidImageSpec $exception) {
            throw new InvalidImageUrl($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * The path and the filters of a 1.x URL, as 1.x parsed them: named filters expanded, values as
     * strings, and the format of a format extension (`.jpg.webp`).
     *
     * @return array{path: string, filters: array<string, mixed>, format: string|null}
     *
     * @throws InvalidImageUrl when the path doesn't match the template, or a filter is malformed
     */
    public function parse(string $path): array
    {
        $path = rawurldecode($path);
        ['pattern' => $pattern, 'matches' => $positions] = $this->patternAndMatches();

        if (preg_match('#'.$pattern.'#i', $path, $matches) !== 1) {
            throw new InvalidImageUrl("Not a 1.x image URL: {$path}.");
        }

        // Removes the filters from the path.
        $filtersPath = $matches[$positions['filters'] ?? -1] ?? '';
        if ($filtersPath !== '') {
            $filtersFormatPath = self::fill($this->filtersFormat, ['filter' => $filtersPath]);
            $path = (string) preg_replace('#'.preg_quote($filtersFormatPath, '#').'\/?#', '', $path);
        }

        // Removes the format extension.
        $format = null;
        $formatExtension = isset($positions['format_extension']) ? ($matches[$positions['format_extension']] ?? '') : '';
        if ($formatExtension !== '') {
            $path = (string) preg_replace('#'.preg_quote($formatExtension, '#').'$#', '', $path);
            $format = ltrim($formatExtension, '.');
        }

        $filters = $this->parseFilters($filtersPath);
        if ($format === null && isset($filters['format']) && is_string($filters['format'])) {
            $format = $filters['format'];
            unset($filters['format']);
        }

        return ['path' => $path, 'filters' => $filters, 'format' => $format];
    }

    /**
     * The regular expression of the route, without delimiters: the template, its placeholders
     * replaced by their patterns.
     */
    public function pattern(): string
    {
        return $this->patternAndMatches()['pattern'];
    }

    /**
     * A 1.x URL, as Image::url() made it: the source's path with the filters, in their order. A
     * host given, or the source's, makes it absolute.
     *
     * @param  string  $src  a path, or a URL whose path is used
     * @param  array<int|string, mixed>  $filters  filters by name with their value (true for none), or names
     * @param  string|null  $host  a host, with or without a scheme
     * @param  bool  $relative  whether to leave the host out, for the URL of a route
     */
    public function make(string $src, array $filters = [], ?string $host = null, bool $relative = false): string
    {
        $parts = parse_url($src);
        $parts = is_array($parts) ? $parts : [];
        $scheme = $parts['scheme'] ?? 'http';
        $srcHost = $parts['host'] ?? null;
        if ($srcHost !== null && isset($parts['port']) && $parts['port'] !== 80) {
            $srcHost .= ':'.$parts['port'];
        }
        $path = $parts['path'] ?? '';

        $hasFormatExtension = preg_match('/\{\s*format_extension\s*\}/i', $this->format) === 1;
        $format = $filters['format'] ?? null;
        if ($hasFormatExtension) {
            unset($filters['format']);
        }

        $parameters = $this->parameters($filters);
        $filtersParameter = $parameters === [] ? '' : self::fill($this->filtersFormat, ['filter' => implode($this->filterSeparator, $parameters)]);

        $info = pathinfo($path);
        $dirname = $info['dirname'] ?? '.';
        $extension = $info['extension'] ?? '';
        $host ??= $srcHost;
        $url = self::fill($this->format, [
            'host' => $host ?? '',
            'dirname' => $dirname !== '.' ? trim($dirname, '/') : '',
            'basename' => $info['filename'],
            'filename' => $info['filename'].'.'.$extension,
            'extension' => $extension,
            'format_extension' => is_string($format) && $format !== '' ? ".{$format}" : '',
            'filters' => $filtersParameter,
        ]);

        if ($host === null || $relative) {
            return '/'.ltrim($url, '/');
        }
        if (preg_match('/^https?\:\/\//i', $url) === 1) {
            return $url;
        }

        $prefix = preg_match('/^https?\:\/\//i', $host) === 1 ? rtrim($host, '/').'/' : "{$scheme}://{$host}/";

        return $prefix.ltrim($url, '/');
    }

    /**
     * The 1.x filters of a spec, for encode().
     *
     * @return array<string, mixed>
     */
    private static function specFilters(ImageSpec $spec): array
    {
        $filters = array_filter(['width' => $spec->width, 'height' => $spec->height], fn (?int $value) => $value !== null);
        if ($spec->hasBox() && $spec->fit === Fit::Cover) {
            $filters['crop'] = true;
        }
        if ($spec->transform !== null && $spec->transform->rotate !== 0) {
            $filters['rotate'] = $spec->transform->rotate;
        }
        foreach ($spec->operations as $name => $arguments) {
            $filters[$name] = $arguments === [] ? true : implode(',', $arguments);
        }

        return $filters;
    }

    /**
     * @param  array<int|string, mixed>  $filters
     * @return list<string>
     */
    private function parameters(array $filters): array
    {
        $parameters = [];

        // The size comes first: width x height, `_` for a missing one.
        if (array_key_exists('width', $filters) || array_key_exists('height', $filters)) {
            $dimension = fn (string $key) => array_key_exists($key, $filters) ? self::scalar($filters[$key]) : '_';
            $parameters[] = $dimension('width').'x'.$dimension('height');
            unset($filters['width'], $filters['height']);
        }

        foreach ($filters as $key => $value) {
            if (is_int($key)) {
                $parameters[] = self::scalar($value);
            } elseif ($value === true || $value === null) {
                $parameters[] = $key;
            } else {
                $value = is_array($value) ? implode(',', array_map(self::scalar(...), $value)) : self::scalar($value);
                $parameters[] = self::fill($this->filterFormat, ['key' => $key, 'value' => $value]);
            }
        }

        return $parameters;
    }

    /**
     * The filters of the filters part of a path, as 1.x's UrlGenerator::parseFilters().
     *
     * @return array<string, mixed>
     */
    private function parseFilters(string $path): array
    {
        if ($path === '') {
            return [];
        }

        $filterPattern = (string) preg_replace('#\\\{\s*key\s*\\\}#i', '(\w+)', preg_quote($this->filterFormat, '#'));
        $filterPattern = (string) preg_replace('#\\\{\s*value\s*\\\}#i', '([a-z0-9\,\.\-_]+)', $filterPattern);

        $filters = [];
        foreach ($this->splitFilters($path) as $filter) {
            if (preg_match('/([0-9]+|_)x([0-9]+|_)/i', $filter, $size) === 1) {
                $filters['width'] = $size[1] === '_' ? null : (int) $size[1];
                $filters['height'] = $size[2] === '_' ? null : (int) $size[2];

                continue;
            }

            $value = null;
            if (preg_match('#^(\w+)$#i', $filter, $matches) === 1) {
                $key = $matches[1];
            } elseif (preg_match('/^'.$filterPattern.'$/i', $filter, $matches) === 1) {
                [$key, $value] = [$matches[1], $matches[2] ?? null];
            } else {
                throw new InvalidImageUrl("The 1.x filter {$filter} has an invalid pattern.");
            }

            $named = $this->filters->get($key);
            if ($named !== null) {
                // An array filter, as 1.x merged it: names without options are filters without values.
                foreach ($named as $name => $option) {
                    if (is_string($name)) {
                        $filters[$name] = $option;
                    } elseif (is_string($option)) {
                        $filters[$option] = true;
                    }
                }
            } else {
                // As in 1.x, a value of "0" reads as no value.
                $filters[$key] = $value !== null && $value !== '' && $value !== '0' ? $value : true;
            }
        }

        return $filters;
    }

    /**
     * Splits the filters on the separator, except inside parentheses, so a value can contain
     * the separator, as in `rotate(-90)`.
     *
     * @return list<string>
     */
    private function splitFilters(string $path): array
    {
        if ($this->filterSeparator === '') {
            return [$path];
        }

        $parts = [];
        $current = '';
        $depth = 0;
        $length = strlen($path);
        $separatorLength = strlen($this->filterSeparator);
        for ($i = 0; $i < $length; $i++) {
            $char = $path[$i];
            if ($depth === 0 && substr($path, $i, $separatorLength) === $this->filterSeparator) {
                $parts[] = $current;
                $current = '';
                $i += $separatorLength - 1;

                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')' && $depth > 0) {
                $depth--;
            }
            $current .= $char;
        }
        $parts[] = $current;

        return $parts;
    }

    /**
     * The pattern of the template, and the index of the group of each placeholder.
     *
     * @return array{pattern: string, matches: array<string, int>}
     */
    private function patternAndMatches(): array
    {
        $filtersPattern = (string) preg_replace('#\\\{\s*filter\s*\\\}#', '(.*?)', preg_quote($this->filtersFormat, '#'));
        $placeholders = ['filters' => '('.$filtersPattern.')?', ...$this->placeholdersPatterns];

        $pattern = preg_quote($this->format, '#');
        $pattern = (string) preg_replace('#(\\\{\s*dirname\s*\\\})\/#i', '$1\/?', $pattern);

        $positions = [];
        foreach (array_keys($placeholders) as $key) {
            if (preg_match('#\\\{\s*('.preg_quote($key, '#').')\s*\\\}#', $pattern, $found, PREG_OFFSET_CAPTURE) === 1) {
                $positions[$key] = $found[1][1];
            }
        }
        asort($positions);

        $matches = [];
        $index = 1;
        foreach (array_keys($positions) as $key) {
            $replace = $placeholders[$key];
            $pattern = (string) preg_replace_callback('#\\\{\s*'.preg_quote($key, '#').'\s*\\\}#', fn () => $replace, $pattern);
            $matches[$key] = $key === 'filters' ? $index + 1 : $index;
            $index += substr_count($replace, '(') - substr_count($replace, '\(');
        }

        return ['pattern' => '^'.$pattern.'$', 'matches' => $matches];
    }

    /**
     * The key of a parsed path: the path without its leading slash, inside the source.
     */
    private function key(string $path): string
    {
        $key = DiskResolver::normalize($path);

        return $key !== null && $key === ltrim($path, '/')
            ? $key
            : throw new InvalidImageUrl("The path {$path} is outside the source.");
    }

    /**
     * The format a source is served in without a format extension: its own when browsers
     * display it, otherwise JPEG (HEIC, BMP), as 1.x.
     */
    private static function sourceFormat(string $path): Format
    {
        return self::formatOf(pathinfo($path, PATHINFO_EXTENSION), Format::Jpeg);
    }

    private static function formatOf(string $extension, ?Format $default = null): Format
    {
        $extension = strtolower($extension);

        return Format::tryFromExtension($extension === 'jpeg' ? 'jpg' : $extension)
            ?? $default
            ?? throw new InvalidImageUrl("Unknown format: {$extension}.");
    }

    /**
     * Replaces the `{placeholder}` of a template, without reading their values as patterns.
     *
     * @param  array<string, string>  $values
     */
    private static function fill(string $template, array $values): string
    {
        foreach ($values as $key => $value) {
            $template = (string) preg_replace_callback('/\{\s*'.preg_quote($key, '/').'\s*\}/i', fn () => $value, $template);
        }

        return $template;
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (is_bool($value) ? ($value ? '1' : '') : (string) $value) : '';
    }
}
