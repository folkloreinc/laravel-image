import fixtures from '../../../tests/fixture/urls.json';
import UrlGenerator from '../UrlGenerator';

// Shared with the PHP suite (tests/Unit/UrlFixturesTest.php), so both URL generators keep
// producing the same URLs.
describe('shared URL fixtures', () => {
    const urlGenerator = new UrlGenerator();

    test.each(fixtures.map((fixture) => [fixture.description, fixture]))(
        '%s',
        (description, { src, width, height, filters, url }) => {
            expect(urlGenerator.make(src, width, height, filters)).toEqual(url);
        },
    );
});
