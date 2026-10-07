import UrlGenerator from '../UrlGenerator';

test('generating url with size', () => {
    const urlGenerator = new UrlGenerator();
    const url = urlGenerator.make('path/to/image.jpg', 300, 300);
    expect(url).toEqual('/path/to/image-filters(300x300).jpg');
});

test('generating url with string filter', () => {
    const urlGenerator = new UrlGenerator();
    const url = urlGenerator.make('path/to/image.jpg', 'small');
    expect(url).toEqual('/path/to/image-filters(small).jpg');
});

test('generating url with array filter', () => {
    const urlGenerator = new UrlGenerator();
    const url = urlGenerator.make('path/to/image.jpg', ['small', 'bw']);
    expect(url).toEqual('/path/to/image-filters(small-bw).jpg');
});

test('generating url with filters object', () => {
    const urlGenerator = new UrlGenerator();
    const url = urlGenerator.make('path/to/image.jpg', {
        small: true,
        rotate: 90,
    });
    expect(url).toEqual('/path/to/image-filters(small-rotate(90)).jpg');
});

test('generating url with filters object and size', () => {
    const urlGenerator = new UrlGenerator();
    const url = urlGenerator.make('path/to/image.jpg', 300, 300, {
        small: true,
        rotate: 90,
    });
    expect(url).toEqual('/path/to/image-filters(300x300-small-rotate(90)).jpg');
});

test('generating url with config', () => {
    const urlGenerator = new UrlGenerator();
    const url = urlGenerator.make('path/to/image.jpg', 300, 300, {
        small: true,
        rotate: 90,
        format: '{dirname}/{filters}/{basename}.{extension}',
        filters_format: '{filter}',
        filter_format: '{key}-{value}',
        filter_separator: '/',
    });
    expect(url).toEqual('/path/to/300x300/small/rotate-90/image.jpg');
});

test('generating url with an output format', () => {
    const urlGenerator = new UrlGenerator();
    const url = urlGenerator.make('path/to/image.jpg', 300, null, { format: 'webp' });
    expect(url).toEqual('/path/to/image-filters(300x_).jpg.webp');
});

test('generating url with a pattern, like the PHP generator', () => {
    const urlGenerator = new UrlGenerator();
    const url = urlGenerator.make('path/to/image.jpg', 300, 300, {
        format: 'webp',
        pattern: {
            format: '{dirname}/{filters}/{basename}.{extension}{format_extension}',
            filters_format: '{filter}',
        },
    });
    expect(url).toEqual('/path/to/300x300/image.jpg.webp');
});

test('generating url with a host', () => {
    const urlGenerator = new UrlGenerator({ host: 'cdn.example.com' });
    expect(urlGenerator.make('path/to/image.jpg', 300, 300)).toEqual(
        'http://cdn.example.com/path/to/image-filters(300x300).jpg',
    );
    expect(
        urlGenerator.make('path/to/image.jpg', 300, 300, { host: 'https://img.example.com/' }),
    ).toEqual('https://img.example.com/path/to/image-filters(300x300).jpg');
});

test('generator options are not added as filters', () => {
    const urlGenerator = new UrlGenerator({
        placeholders_patterns: { extension: '(jpg|png)' },
    });
    expect(urlGenerator.make('path/to/image.jpg', 300, 300)).toEqual(
        '/path/to/image-filters(300x300).jpg',
    );
});
