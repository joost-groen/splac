import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const sourceUrl = new URL(
    '../../src/Resources/app/administration/src/module/splac/page/splac-review/category-review.js',
    import.meta.url,
);
const source = await readFile(sourceUrl, 'utf8');
const moduleUrl = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const {
    characterCountState,
    createCategorySlug,
    isGeneratedCategoryInvalid,
    splitCategoryKeywords,
} = await import(moduleUrl);

test('generated category review requires a name for every active locale', () => {
    assert.equal(isGeneratedCategoryInvalid(false, null, ['en-GB']), false);
    assert.equal(isGeneratedCategoryInvalid(true, null, ['en-GB']), true);
    assert.equal(isGeneratedCategoryInvalid(true, {
        name: { 'en-GB': 'Laptops', 'de-DE': '   ' },
    }, ['en-GB', 'de-DE']), true);
    assert.equal(isGeneratedCategoryInvalid(true, {
        name: { 'en-GB': 'Laptops', 'de-DE': 'Notebooks' },
    }, ['en-GB', 'de-DE']), false);
});

test('search preview slug is portable and has a localized fallback', () => {
    assert.equal(createCategorySlug('Büro & Zubehör', 'category'), 'buro-zubehor');
    assert.equal(createCategorySlug('Straße', 'category'), 'strasse');
    assert.equal(createCategorySlug('', 'new-category'), 'new-category');
});

test('keyword chips are trimmed, empty-safe, and bounded', () => {
    assert.deepEqual(
        splitCategoryKeywords(' laptops, business notebooks, , accessories '),
        ['laptops', 'business notebooks', 'accessories'],
    );
    assert.deepEqual(splitCategoryKeywords('one,two,three', 2), ['one', 'two']);
});

test('SEO character counts warn near and over the limit', () => {
    assert.deepEqual(characterCountState('short', 60), {
        'is--over-limit': false,
        'is--near-limit': false,
    });
    assert.equal(characterCountState('x'.repeat(55), 60)['is--near-limit'], true);
    assert.equal(characterCountState('x'.repeat(61), 60)['is--over-limit'], true);
});
