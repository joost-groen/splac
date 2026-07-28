export const TEMPLATE_FILE_FORMAT = 'splac-template';
export const TEMPLATE_FILE_VERSION = 1;
export const MAX_TEMPLATE_FILE_SIZE = 2 * 1024 * 1024;

const TEMPLATE_TYPES = ['listing', 'category'];

const isPlainObject = (value) => (
    value !== null
    && typeof value === 'object'
    && !Array.isArray(value)
);

export const cloneJson = (value, fallback = {}) => {
    if (value === undefined || value === null) {
        return fallback;
    }

    return JSON.parse(JSON.stringify(value));
};

export const safeFilename = (value) => {
    const filename = String(value || 'template')
        .trim()
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-zA-Z0-9._-]+/g, '-')
        .replace(/^[._-]+|[._-]+$/g, '')
        .slice(0, 100);

    return filename || 'template';
};

export const createTemplateFile = (item, type, exportedAt = new Date()) => {
    if (!TEMPLATE_TYPES.includes(type)) {
        throw new Error('splac.templates.importErrorFormat');
    }

    const template = type === 'listing'
        ? {
            name: item.name,
            active: item.active !== false,
            descriptionTemplates: cloneJson(item.descriptionTemplates),
            config: cloneJson(item.config),
        }
        : {
            name: item.name,
            active: item.active !== false,
            config: cloneJson(item.config),
        };

    return {
        format: TEMPLATE_FILE_FORMAT,
        version: TEMPLATE_FILE_VERSION,
        type,
        exportedAt: exportedAt.toISOString(),
        template,
    };
};

export const serializeTemplateFile = (item, type, exportedAt) => (
    JSON.stringify(createTemplateFile(item, type, exportedAt), null, 2)
);

export const parseTemplateFile = (contents) => {
    let data;
    try {
        data = JSON.parse(contents);
    } catch (error) {
        throw new Error('splac.templates.importErrorInvalid');
    }

    if (
        !isPlainObject(data)
        || data.format !== TEMPLATE_FILE_FORMAT
        || !TEMPLATE_TYPES.includes(data.type)
        || !isPlainObject(data.template)
    ) {
        throw new Error('splac.templates.importErrorFormat');
    }
    if (data.version !== TEMPLATE_FILE_VERSION) {
        throw new Error('splac.templates.importErrorVersion');
    }

    const name = String(data.template.name || '').trim();
    if (!name || name.length > 255 || !isPlainObject(data.template.config)) {
        throw new Error('splac.templates.importErrorFormat');
    }
    if (
        data.type === 'listing'
        && data.template.descriptionTemplates !== undefined
        && !isPlainObject(data.template.descriptionTemplates)
    ) {
        throw new Error('splac.templates.importErrorFormat');
    }

    return {
        type: data.type,
        template: {
            name,
            active: data.template.active !== false,
            config: cloneJson(data.template.config),
            descriptionTemplates: cloneJson(data.template.descriptionTemplates),
        },
    };
};

export const readTemplateFile = async (file) => {
    if (file.size > MAX_TEMPLATE_FILE_SIZE) {
        throw new Error('splac.templates.importErrorTooLarge');
    }
    if (!String(file.name || '').toLocaleLowerCase().endsWith('.json')) {
        throw new Error('splac.templates.importErrorFileType');
    }

    return parseTemplateFile(await file.text());
};
