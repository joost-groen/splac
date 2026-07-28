import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const administrationDirectory = path.resolve(
    testDirectory,
    '../../src/Resources/app/administration/src/module/splac',
);
const snippetDirectory = path.join(administrationDirectory, 'snippet');

const flatten = (value, prefix = '', result = {}) => {
    Object.entries(value).forEach(([key, child]) => {
        const fullKey = prefix ? `${prefix}.${key}` : key;

        if (child && typeof child === 'object' && !Array.isArray(child)) {
            flatten(child, fullKey, result);
            return;
        }

        result[fullKey] = child;
    });

    return result;
};

const collectSourceFiles = async (directory) => {
    const entries = await readdir(directory, { withFileTypes: true });
    const files = await Promise.all(entries.map(async (entry) => {
        const fullPath = path.join(directory, entry.name);

        if (entry.isDirectory()) {
            return collectSourceFiles(fullPath);
        }

        return /\.(?:js|html\.twig)$/.test(entry.name) ? [fullPath] : [];
    }));

    return files.flat();
};

const en = flatten(JSON.parse(await readFile(path.join(snippetDirectory, 'en-GB.json'), 'utf8')));
const de = flatten(JSON.parse(await readFile(path.join(snippetDirectory, 'de-DE.json'), 'utf8')));

test('German admin snippets have exact key parity with English', () => {
    assert.deepEqual(Object.keys(de).sort(), Object.keys(en).sort());

    Object.entries(de).forEach(([key, value]) => {
        assert.equal(typeof value, 'string', `${key} must be a string`);
        assert.notEqual(value.trim(), '', `${key} must not be empty`);
    });
});

test('every static Splac translation key used by the administration exists', async () => {
    const files = await collectSourceFiles(administrationDirectory);
    const missing = new Set();

    await Promise.all(files.map(async (file) => {
        const source = await readFile(file, 'utf8');
        const keyPattern = /\$tc\(\s*['"](splac\.[^'"]+)['"]/g;

        for (const match of source.matchAll(keyPattern)) {
            if (!(match[1] in en) || !(match[1] in de)) {
                missing.add(match[1]);
            }
        }
    }));

    assert.deepEqual([...missing].sort(), []);
});

test('core navigation and template UI are genuinely localized in German', () => {
    const requiredGermanCopy = {
        'splac.general.menuDashboard': 'Splac-Übersicht',
        'splac.general.menuTemplates': 'Splac-Vorlagen',
        'splac.wizard.step1Label': 'Vorlage',
        'splac.templates.title': 'Vorlagen',
        'splac.templateDetail.saveSuccess': 'Vorlage gespeichert.',
        'splac.categoryTemplateDetail.titleNew': 'Neue Kategorievorlage',
    };

    Object.entries(requiredGermanCopy).forEach(([key, expected]) => {
        assert.equal(de[key], expected);
        assert.notEqual(de[key], en[key]);
    });
});
