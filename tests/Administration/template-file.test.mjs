import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const sourceUrl = new URL(
    '../../src/Resources/app/administration/src/module/splac/page/splac-template-list/template-file.js',
    import.meta.url,
);
const source = await readFile(sourceUrl, 'utf8');
const moduleUrl = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const {
    MAX_TEMPLATE_FILE_SIZE,
    createTemplateFile,
    parseTemplateFile,
    readTemplateFile,
    safeFilename,
    serializeTemplateFile,
} = await import(moduleUrl);

test('listing templates round-trip without internal entity fields', () => {
    const exportedAt = new Date('2026-07-28T12:00:00.000Z');
    const entity = {
        id: 'internal-id',
        name: 'Laptop template',
        active: false,
        descriptionTemplates: { 'en-GB': '<h2>{{name}}</h2>' },
        config: { languages: ['en-GB'], features: { seo: true } },
        createdAt: 'not-portable',
    };

    const file = createTemplateFile(entity, 'listing', exportedAt);
    const imported = parseTemplateFile(JSON.stringify(file));

    assert.deepEqual(imported, {
        type: 'listing',
        template: {
            name: 'Laptop template',
            active: false,
            descriptionTemplates: { 'en-GB': '<h2>{{name}}</h2>' },
            config: { languages: ['en-GB'], features: { seo: true } },
        },
    });
    assert.equal(file.exportedAt, exportedAt.toISOString());
    assert.equal('id' in file.template, false);
    assert.equal('createdAt' in file.template, false);
});

test('category template files omit installation-specific parent category IDs', () => {
    const contents = serializeTemplateFile({
        name: 'Technical category',
        parentCategoryId: 'installation-specific-id',
        config: {
            name: { mode: 'instruction', 'en-GB': 'Write a category name' },
            metaTitle: { mode: 'instruction', 'en-GB': 'Include the product family' },
            metaDescription: { mode: 'instruction', 'en-GB': 'Summarize the category range' },
            keywords: { mode: 'placeholder', 'en-GB': '[product_family], accessories' },
        },
    }, 'category', new Date('2026-07-28T12:00:00.000Z'));
    const file = JSON.parse(contents);

    assert.equal(file.type, 'category');
    assert.equal('parentCategoryId' in file.template, false);
    assert.deepEqual(parseTemplateFile(contents).template.config, file.template.config);
    assert.equal(
        parseTemplateFile(contents).template.config.metaTitle['en-GB'],
        'Include the product family',
    );
});

test('file reader accepts JSON files and enforces the file contract', async () => {
    const validContents = serializeTemplateFile(
        { name: 'Valid', active: true, config: {}, descriptionTemplates: {} },
        'listing',
    );
    const validFile = {
        name: 'valid.splac-template.json',
        size: validContents.length,
        text: async () => validContents,
    };

    assert.equal((await readTemplateFile(validFile)).template.name, 'Valid');

    await assert.rejects(
        readTemplateFile({ ...validFile, name: 'valid.txt' }),
        { message: 'splac.templates.importErrorFileType' },
    );
    await assert.rejects(
        readTemplateFile({ ...validFile, size: MAX_TEMPLATE_FILE_SIZE + 1 }),
        { message: 'splac.templates.importErrorTooLarge' },
    );
});

test('parser gives distinct errors for malformed, foreign, and future files', () => {
    assert.throws(
        () => parseTemplateFile('{broken'),
        { message: 'splac.templates.importErrorInvalid' },
    );
    assert.throws(
        () => parseTemplateFile(JSON.stringify({ format: 'other', version: 1 })),
        { message: 'splac.templates.importErrorFormat' },
    );
    assert.throws(
        () => parseTemplateFile(JSON.stringify({
            format: 'splac-template',
            version: 2,
            type: 'listing',
            template: { name: 'Future', config: {} },
        })),
        { message: 'splac.templates.importErrorVersion' },
    );
});

test('filenames are portable and never resolve to hidden dot names', () => {
    assert.equal(safeFilename('Über / Laptop: Pro'), 'Uber-Laptop-Pro');
    assert.equal(safeFilename('..'), 'template');
});
