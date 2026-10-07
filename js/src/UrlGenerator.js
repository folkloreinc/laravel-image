import URL from 'url-parse';
import parsePath from 'parse-filepath';
import isEmpty from 'lodash/isEmpty';
import isObject from 'lodash/isObject';
import isArray from 'lodash/isArray';
import isString from 'lodash/isString';
import pick from 'lodash/pick';
import omit from 'lodash/omit';
import get from 'lodash/get';
import trimStart from 'lodash/trimStart';
import trimEnd from 'lodash/trimEnd';
import trim from 'lodash/trim';

const PATTERN_KEYS = ['format', 'filters_format', 'filter_format', 'filter_separator'];

class UrlGenerator {
    constructor(opts) {
        this.options = {
            format: '{dirname}/{basename}{filters}.{extension}{format_extension}',
            filters_format: '-filters({filter})',
            filter_format: '{key}({value})',
            filter_separator: '-',
            ...opts,
        };
    }

    make(path, width, height, opts) {
        // Don't allow empty path
        if (isEmpty(path)) {
            return '';
        }

        // Extract the path from a URL if a URL was provided instead of a path
        const srcUrl = new URL(path);
        const src = srcUrl.pathname;
        const options = {
            ...(isObject(width) && !isArray(width) ? width : null),
            ...(isObject(opts) && !isArray(opts) ? opts : null),
        };
        if (isArray(width) || isString(width)) {
            (isString(width) ? [width] : width).forEach((key) => {
                options[key] = true;
            });
        }
        if (isArray(opts) || isString(opts)) {
            (isString(opts) ? [opts] : opts).forEach((key) => {
                options[key] = true;
            });
        }

        // Like the PHP generator, `format` is the output format (`.jpg.webp`) and
        // `pattern` overrides the URL template. A `format` containing placeholders is
        // still read as the URL template, as in earlier versions.
        const legacyFormat = isString(options.format) && options.format.indexOf('{') !== -1;
        const config = {
            ...pick(this.options, PATTERN_KEYS),
            ...pick(options, ['filters_format', 'filter_format', 'filter_separator']),
            ...(legacyFormat ? { format: options.format } : null),
            ...(isObject(options.pattern) ? pick(options.pattern, PATTERN_KEYS) : null),
        };
        const host = get(options, 'host', get(this.options, 'host', null));
        const filters = omit(options, [
            'route',
            'pattern',
            'host',
            'filters_format',
            'filter_format',
            'filter_separator',
            ...(legacyFormat ? ['format'] : []),
        ]);

        if (width !== null && !isObject(width) && !isArray(width) && !isString(width)) {
            filters.width = width;
        }
        if (height !== null && typeof height !== 'undefined') {
            filters.height = height;
        }

        // The output format goes in `{format_extension}` when the template has it
        const template = get(config, 'format');
        const hasFormatExtension = /\{\s*format_extension\s*\}/i.test(template);
        const outputFormat = get(filters, 'format', null);
        const urlParameters = this.getParametersFromFilters(
            hasFormatExtension ? omit(filters, ['format']) : filters,
            get(config, 'filter_format'),
        );
        const filtersParameter = this.getFiltersParameter(
            urlParameters,
            get(config, 'filters_format'),
            get(config, 'filter_separator'),
        );

        // Build the url by replacing the placeholders
        const srcParts = parsePath(src);
        const urlHost = host !== null ? host : srcUrl.host || null;
        const placeholders = {
            host: urlHost !== null ? trimEnd(urlHost, '/') : '',
            dirname: srcParts.dirname !== '.' ? trim(srcParts.dirname, '/') : '',
            basename: srcParts.name,
            filename: `${srcParts.name}${srcParts.extname}`,
            extension: srcParts.extname.replace(/^\./, ''),
            format_extension: hasFormatExtension && outputFormat !== null ? `.${outputFormat}` : '',
            filters: filtersParameter,
        };

        const url = Object.keys(placeholders).reduce(
            (fullUrl, key) =>
                fullUrl.replace(new RegExp(`{\\s*${key}\\s*}`, 'gi'), placeholders[key]),
            template,
        );

        // With a host (from the options or the source URL), the URL is absolute
        if (urlHost !== null && !/^https?:\/\//i.test(url)) {
            const scheme = (srcUrl.protocol || 'http:').replace(/:$/, '');
            const base = /^https?:\/\//i.test(urlHost) ? urlHost : `${scheme}://${urlHost}`;
            return `${trimEnd(base, '/')}/${trimStart(url, '/')}`;
        }

        return `/${trimStart(url, '/')}`;
    }

    getParametersFromFilters(allFilters, filterFormat) {
        const format = filterFormat || this.options.filter_format;
        const parameters = [];

        // Size parameters are treated separatly
        const width = get(allFilters, 'width', -1);
        const height = get(allFilters, 'height', -1);
        const filters = omit(allFilters, ['width', 'height']);
        if (width !== -1 || height !== -1) {
            parameters.push(`${width !== -1 ? width : '_'}x${height !== -1 ? height : '_'}`);
        }

        // If the key as no value or is equal to
        // true or null, only the key is added.
        Object.keys(filters).forEach((key) => {
            const val = filters[key];
            if (val === true || val === null) {
                parameters.push(key);
            } else if (val !== false) {
                const strVal = isArray(val) ? val.join(',') : val;
                const parameter = format
                    .replace(/\{\s*key\s*\}/i, key)
                    .replace(/\{\s*value\s*\}/i, strVal);
                parameters.push(parameter);
            }
        });

        return parameters;
    }

    getFiltersParameter(parameters, filtersFormat, filterSeparator) {
        if (isEmpty(parameters)) {
            return '';
        }

        const format = filtersFormat || this.options.filters_format;
        const separator = filterSeparator || this.options.filter_separator;
        const urlFilters = parameters.join(separator);
        return format.replace(/\{\s*filter\s*\}/i, urlFilters);
    }
}

export default UrlGenerator;
