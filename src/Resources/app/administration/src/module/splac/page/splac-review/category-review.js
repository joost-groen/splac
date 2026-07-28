export const isGeneratedCategoryInvalid = (hasCategoryOutput, category, locales) => {
    if (!hasCategoryOutput) {
        return false;
    }
    if (!category?.name) {
        return true;
    }

    return locales.some((locale) => !String(category.name[locale] || '').trim());
};

export const createCategorySlug = (name, fallback) => {
    const slug = String(name || '')
        .trim()
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/ß/g, 'ss')
        .toLocaleLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

    return slug || fallback;
};

export const splitCategoryKeywords = (value, limit = 12) => (
    String(value || '')
        .split(',')
        .map((keyword) => keyword.trim())
        .filter((keyword) => keyword !== '')
        .slice(0, limit)
);

export const characterCountState = (value, maximum) => ({
    'is--over-limit': String(value || '').length > maximum,
    'is--near-limit': String(value || '').length >= Math.round(maximum * 0.85),
});
