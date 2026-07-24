import template from './splac-template-detail.html.twig';
import './splac-template-detail.scss';

const LOCALES = ['de-DE', 'en-GB'];
const BLOCK_TYPES = ['heading', 'paragraph', 'table', 'html'];
const BORDER_STYLES = ['none', 'solid', 'dashed', 'dotted', 'double'];
const HORIZONTAL_ALIGNMENTS = ['left', 'center', 'right'];
const VERTICAL_ALIGNMENTS = ['top', 'middle', 'bottom'];
const COLOR_PATTERN = /^#[0-9a-f]{6}$/i;

const DEFAULT_TABLE_STYLE = Object.freeze({
    borderStyle: 'solid',
    borderWidth: 1,
    borderColor: '#d9e0e8',
    leftColumnWidth: 30,
    headerAlignment: 'left',
    valueAlignment: 'left',
    verticalAlignment: 'middle',
    paddingVertical: 10,
    paddingHorizontal: 12,
    cellSpacing: 0,
    labelBackgroundColor: '#ffffff',
    valueBackgroundColor: '#ffffff',
});

const RECOMMENDED_TABLE_STYLE = Object.freeze({
    borderStyle: 'solid',
    borderWidth: 1,
    borderColor: '#d5dce5',
    leftColumnWidth: 34,
    headerAlignment: 'left',
    valueAlignment: 'left',
    verticalAlignment: 'top',
    paddingVertical: 12,
    paddingHorizontal: 16,
    cellSpacing: 0,
    labelBackgroundColor: '#f4f6f8',
    valueBackgroundColor: '#ffffff',
});

let blockSequence = 0;

const createId = (prefix) => {
    blockSequence += 1;

    return `${prefix}-${Date.now()}-${blockSequence}`;
};

const normalizePlaceholder = (value) => String(value || '')
    .trim()
    .replace(/ß/g, 'ss')
    .replace(/Æ/g, 'AE')
    .replace(/æ/g, 'ae')
    .replace(/Ø/g, 'O')
    .replace(/ø/g, 'o')
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/\s+/g, '_')
    .replace(/[^a-zA-Z0-9_.-]/g, '');

const createTableRow = (values = {}) => {
    const label = values.label || '';
    const mode = values.mode === 'static' ? 'static' : 'placeholder';

    return {
        id: values.id || createId('row'),
        label,
        mode,
        placeholder: values.placeholder || (mode === 'placeholder' ? normalizePlaceholder(label) : ''),
        content: values.content || '',
    };
};

const clampNumber = (value, min, max, fallback) => {
    const number = Number(value);

    return Number.isFinite(number) ? Math.min(max, Math.max(min, Math.round(number))) : fallback;
};

const createTableStyle = (values = {}) => ({
    borderStyle: BORDER_STYLES.includes(values.borderStyle)
        ? values.borderStyle
        : DEFAULT_TABLE_STYLE.borderStyle,
    borderWidth: clampNumber(values.borderWidth, 0, 10, DEFAULT_TABLE_STYLE.borderWidth),
    borderColor: COLOR_PATTERN.test(String(values.borderColor || ''))
        ? values.borderColor
        : DEFAULT_TABLE_STYLE.borderColor,
    leftColumnWidth: clampNumber(values.leftColumnWidth, 10, 90, DEFAULT_TABLE_STYLE.leftColumnWidth),
    headerAlignment: HORIZONTAL_ALIGNMENTS.includes(values.headerAlignment)
        ? values.headerAlignment
        : DEFAULT_TABLE_STYLE.headerAlignment,
    valueAlignment: HORIZONTAL_ALIGNMENTS.includes(values.valueAlignment)
        ? values.valueAlignment
        : DEFAULT_TABLE_STYLE.valueAlignment,
    verticalAlignment: VERTICAL_ALIGNMENTS.includes(values.verticalAlignment)
        ? values.verticalAlignment
        : DEFAULT_TABLE_STYLE.verticalAlignment,
    paddingVertical: clampNumber(values.paddingVertical, 0, 50, DEFAULT_TABLE_STYLE.paddingVertical),
    paddingHorizontal: clampNumber(values.paddingHorizontal, 0, 80, DEFAULT_TABLE_STYLE.paddingHorizontal),
    cellSpacing: clampNumber(values.cellSpacing, 0, 30, DEFAULT_TABLE_STYLE.cellSpacing),
    labelBackgroundColor: COLOR_PATTERN.test(String(values.labelBackgroundColor || ''))
        ? values.labelBackgroundColor
        : DEFAULT_TABLE_STYLE.labelBackgroundColor,
    valueBackgroundColor: COLOR_PATTERN.test(String(values.valueBackgroundColor || ''))
        ? values.valueBackgroundColor
        : DEFAULT_TABLE_STYLE.valueBackgroundColor,
});

const createBlock = (type, values = {}) => {
    const safeType = BLOCK_TYPES.includes(type) ? type : 'paragraph';
    const block = {
        id: values.id || createId('block'),
        type: safeType,
    };

    if (safeType === 'heading') {
        return {
            ...block,
            level: values.level || 'h2',
            contentMode: values.contentMode || (String(values.content || '').includes('{{') ? 'placeholder' : 'static'),
            content: values.content || '',
            instruction: values.instruction || '',
        };
    }
    if (safeType === 'paragraph') {
        return {
            ...block,
            contentMode: values.contentMode || (String(values.content || '').includes('{{') ? 'placeholder' : 'static'),
            content: values.content || '',
            instruction: values.instruction || '',
        };
    }
    if (safeType === 'table') {
        return {
            ...block,
            title: values.title || '',
            rows: Array.isArray(values.rows) ? values.rows.map((row) => createTableRow(row)) : [],
            tableStyle: createTableStyle(values.tableStyle),
        };
    }

    return { ...block, content: values.content || '' };
};

const defaultConfig = () => ({
    languages: ['de-DE', 'en-GB'],
    features: {
        description: true,
        seo: true,
        tags: true,
        keywords: true,
        properties: true,
        manufacturer: true,
        identifiers: true,
        productNumber: true,
        categoryCreation: true,
        advancedPricing: false,
    },
    fieldModes: {
        metaTitle: { mode: 'instruction', 'de-DE': '', 'en-GB': '' },
        metaDescription: { mode: 'instruction', 'de-DE': '', 'en-GB': '' },
    },
    descriptionStyle: {
        blockSpacingEnabled: false,
        blockSpacing: 16,
        tableFormattingEnabled: true,
        recommendedTableStyleForAllTables: false,
    },
    descriptionBlocks: {},
    productNumberPattern: '',
    defaultTaxId: null,
    defaultSalesChannelIds: [],
});

Shopware.Component.register('splac-template-detail', {
    template,

    inject: ['repositoryFactory'],

    mixins: [Shopware.Mixin.getByName('notification')],

    props: {
        templateId: {
            type: String,
            required: false,
            default: null,
        },
    },

    data() {
        return {
            item: null,
            isLoading: true,
            isSaving: false,
            locales: LOCALES,
            activeDescriptionLocale: LOCALES[0],
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('splac_template');
        },

        modeOptions() {
            return [
                { value: 'instruction', label: this.$tc('splac.templateDetail.modeInstruction') },
                { value: 'placeholder', label: this.$tc('splac.templateDetail.modePlaceholder') },
            ];
        },

        headingLevelOptions() {
            return [
                { value: 'h2', label: this.$tc('splac.templateDetail.headingLarge') },
                { value: 'h3', label: this.$tc('splac.templateDetail.headingMedium') },
                { value: 'h4', label: this.$tc('splac.templateDetail.headingSmall') },
            ];
        },

        tableValueModeOptions() {
            return [
                { value: 'placeholder', label: this.$tc('splac.templateDetail.tableValueGenerated') },
                { value: 'static', label: this.$tc('splac.templateDetail.tableValueStatic') },
            ];
        },

        borderStyleOptions() {
            return BORDER_STYLES.map((value) => ({
                value,
                label: this.$tc(`splac.templateDetail.borderStyle.${value}`),
            }));
        },

        horizontalAlignmentOptions() {
            return HORIZONTAL_ALIGNMENTS.map((value) => ({
                value,
                label: this.$tc(`splac.templateDetail.alignment.${value}`),
            }));
        },

        verticalAlignmentOptions() {
            return VERTICAL_ALIGNMENTS.map((value) => ({
                value,
                label: this.$tc(`splac.templateDetail.verticalAlignment.${value}`),
            }));
        },

        blockContentModeOptions() {
            return [
                { value: 'static', label: this.$tc('splac.templateDetail.blockModeStatic') },
                { value: 'generated', label: this.$tc('splac.templateDetail.blockModeGenerated') },
                { value: 'placeholder', label: this.$tc('splac.templateDetail.blockModePlaceholder') },
            ];
        },

        featureList() {
            return [
                'description',
                'seo',
                'tags',
                'keywords',
                'properties',
                'manufacturer',
                'identifiers',
                'productNumber',
                'categoryCreation',
                'advancedPricing',
            ];
        },

        activeDescriptionBlocks() {
            return this.item?.config?.descriptionBlocks?.[this.activeDescriptionLocale] || [];
        },
    },

    created() {
        this.loadItem();
    },

    methods: {
        async loadItem() {
            this.isLoading = true;

            try {
                if (this.templateId) {
                    this.item = await this.repository.get(this.templateId, Shopware.Context.api);
                } else {
                    this.item = this.repository.create(Shopware.Context.api);
                    this.item.name = '';
                    this.item.active = true;
                }

                this.item.descriptionTemplates = this.item.descriptionTemplates || {};
                LOCALES.forEach((locale) => {
                    if (typeof this.item.descriptionTemplates[locale] !== 'string') {
                        this.item.descriptionTemplates[locale] = '';
                    }
                });

                const merged = defaultConfig();
                const existing = this.item.config || {};
                const existingBlocks = existing.descriptionBlocks || {};
                const descriptionBlocks = {};

                LOCALES.forEach((locale) => {
                    descriptionBlocks[locale] = Array.isArray(existingBlocks[locale])
                        ? existingBlocks[locale].map((block) => createBlock(block.type, block))
                        : this.blocksFromHtml(this.item.descriptionTemplates[locale]);
                });

                this.item.config = {
                    ...merged,
                    ...existing,
                    features: { ...merged.features, ...(existing.features || {}) },
                    fieldModes: {
                        metaTitle: { ...merged.fieldModes.metaTitle, ...((existing.fieldModes || {}).metaTitle || {}) },
                        metaDescription: { ...merged.fieldModes.metaDescription, ...((existing.fieldModes || {}).metaDescription || {}) },
                    },
                    descriptionStyle: {
                        ...merged.descriptionStyle,
                        ...(existing.descriptionStyle || {}),
                        blockSpacingEnabled: typeof existing.descriptionStyle?.blockSpacingEnabled === 'boolean'
                            ? existing.descriptionStyle.blockSpacingEnabled
                            : Number(existing.descriptionStyle?.blockSpacing) > 0,
                        blockSpacing: clampNumber(existing.descriptionStyle?.blockSpacing, 0, 200, 16),
                        tableFormattingEnabled: existing.descriptionStyle?.tableFormattingEnabled !== false,
                        recommendedTableStyleForAllTables: (
                            existing.descriptionStyle?.recommendedTableStyleForAllTables === true
                        ),
                    },
                    descriptionBlocks,
                };

                const configuredLanguages = Array.isArray(this.item.config.languages) && this.item.config.languages.length
                    ? this.item.config.languages
                    : LOCALES;
                [this.activeDescriptionLocale] = configuredLanguages;
            } finally {
                this.isLoading = false;
            }
        },

        async onSave() {
            if (!this.item.name) {
                this.createNotificationError({
                    message: this.$tc('splac.templateDetail.errorNameRequired'),
                });
                return;
            }

            this.syncDescriptionTemplates();
            this.isSaving = true;

            try {
                await this.repository.save(this.item, Shopware.Context.api);
                this.createNotificationSuccess({
                    message: this.$tc('splac.templateDetail.saveSuccess'),
                });

                if (!this.templateId) {
                    this.$router.push({ name: 'splac.templateDetail', params: { id: this.item.id } });
                } else {
                    await this.loadItem();
                }
            } catch (error) {
                this.createNotificationError({
                    message: error?.message || this.$tc('splac.general.errorGeneric'),
                });
            } finally {
                this.isSaving = false;
            }
        },

        blocksFromHtml(html) {
            if (typeof html !== 'string' || html.trim() === '') {
                return [];
            }

            // Keep existing HTML byte-for-byte intact. Shopware's isolated
            // administration runtime does not expose the browser DOM parser,
            // so legacy templates are migrated as one editable compatibility
            // block and can be replaced with structured blocks over time.
            return [createBlock('html', { content: html })];
        },

        addDescriptionBlock(type) {
            const block = createBlock(type);
            if (type === 'table') {
                const useRecommendedStyle = (
                    this.item.config.descriptionStyle.recommendedTableStyleForAllTables === true
                );
                block.tableStyle = createTableStyle(
                    useRecommendedStyle ? RECOMMENDED_TABLE_STYLE : DEFAULT_TABLE_STYLE,
                );
                block.rows.push(createTableRow());
            }
            this.setActiveDescriptionBlocks([...this.activeDescriptionBlocks, block]);
        },

        removeDescriptionBlock(index) {
            this.setActiveDescriptionBlocks(
                this.activeDescriptionBlocks.filter((block, blockIndex) => blockIndex !== index),
            );
        },

        moveDescriptionBlock(index, direction) {
            const target = index + direction;
            if (target < 0 || target >= this.activeDescriptionBlocks.length) {
                return;
            }

            const blocks = [...this.activeDescriptionBlocks];
            const [block] = blocks.splice(index, 1);
            blocks.splice(target, 0, block);
            this.setActiveDescriptionBlocks(blocks);
        },

        setActiveDescriptionBlocks(blocks) {
            this.item.config = {
                ...this.item.config,
                descriptionBlocks: {
                    ...(this.item.config.descriptionBlocks || {}),
                    [this.activeDescriptionLocale]: blocks,
                },
            };
        },

        addTableRow(block) {
            block.rows.push(createTableRow());
        },

        resetTableStyle(block) {
            block.tableStyle = createTableStyle();
        },

        applyRecommendedTableStyle(block) {
            block.tableStyle = createTableStyle(RECOMMENDED_TABLE_STYLE);
        },

        isRecommendedTableStyle(block) {
            const style = createTableStyle(block.tableStyle);

            return Object.entries(RECOMMENDED_TABLE_STYLE)
                .every(([property, value]) => style[property] === value);
        },

        toggleRecommendedTableStyleForAllTables() {
            const descriptionStyle = this.item.config.descriptionStyle;
            const enabled = descriptionStyle.recommendedTableStyleForAllTables !== true;
            descriptionStyle.recommendedTableStyleForAllTables = enabled;

            if (!enabled) {
                return;
            }

            LOCALES.forEach((locale) => {
                const blocks = this.item?.config?.descriptionBlocks?.[locale] || [];
                blocks.forEach((block) => {
                    if (block.type === 'table') {
                        this.applyRecommendedTableStyle(block);
                    }
                });
            });
        },

        removeTableRow(block, rowIndex) {
            block.rows.splice(rowIndex, 1);
        },

        insertBlockPlaceholder(block) {
            const value = window.prompt(this.$tc('splac.templateDetail.placeholderPrompt'));
            const placeholder = this.normalizePlaceholder(value);
            if (!placeholder) {
                return;
            }

            const token = `{{${placeholder}}}`;
            block.content = block.content ? `${block.content} ${token}` : token;
        },

        blockTypeLabel(type) {
            return this.$tc(`splac.templateDetail.blockType.${type}`);
        },

        syncDescriptionTemplates() {
            this.normalizeDescriptionFormatting();
            this.ensureTablePlaceholders();

            const templates = {};
            LOCALES.forEach((locale) => {
                templates[locale] = this.renderDescriptionLocale(locale);
            });
            this.item.descriptionTemplates = templates;
        },

        normalizeDescriptionFormatting() {
            this.item.config.descriptionStyle.blockSpacingEnabled = (
                this.item.config.descriptionStyle.blockSpacingEnabled === true
            );
            this.item.config.descriptionStyle.blockSpacing = clampNumber(
                this.item.config.descriptionStyle.blockSpacing,
                0,
                200,
                16,
            );
            this.item.config.descriptionStyle.tableFormattingEnabled = (
                this.item.config.descriptionStyle.tableFormattingEnabled !== false
            );
            this.item.config.descriptionStyle.recommendedTableStyleForAllTables = (
                this.item.config.descriptionStyle.recommendedTableStyleForAllTables === true
            );

            LOCALES.forEach((locale) => {
                const blocks = this.item?.config?.descriptionBlocks?.[locale] || [];
                blocks.forEach((block) => {
                    if (block.type === 'table') {
                        block.tableStyle = createTableStyle(block.tableStyle);
                    }
                });
            });
        },

        ensureTablePlaceholders() {
            LOCALES.forEach((locale) => {
                const blocks = this.item?.config?.descriptionBlocks?.[locale] || [];
                blocks.forEach((block) => {
                    if (block.type !== 'table') {
                        return;
                    }

                    (block.rows || []).forEach((row) => {
                        if (row.mode !== 'static' && !this.normalizePlaceholder(row.placeholder)) {
                            row.placeholder = this.normalizePlaceholder(row.label);
                        }
                    });
                });
            });
        },

        renderDescriptionLocale(locale) {
            const blocks = this.item?.config?.descriptionBlocks?.[locale] || [];
            const blockSpacing = clampNumber(
                this.item?.config?.descriptionStyle?.blockSpacing,
                0,
                200,
                16,
            );
            const spacingEnabled = this.item?.config?.descriptionStyle?.blockSpacingEnabled === true;
            const tableFormattingEnabled = (
                this.item?.config?.descriptionStyle?.tableFormattingEnabled !== false
            );

            return blocks.map((block) => this.renderDescriptionBlock(
                block,
                spacingEnabled ? blockSpacing : 0,
                tableFormattingEnabled,
            )).join('\n');
        },

        renderDescriptionBlock(block, blockSpacing = 0, tableFormattingEnabled = true) {
            const content = block.contentMode === 'generated'
                ? `{{${this.generatedBlockPlaceholder(block)}}}`
                : block.content;
            const blockStyle = blockSpacing > 0
                ? ` style="margin-bottom: ${blockSpacing}px;"`
                : '';

            if (block.type === 'heading') {
                const level = ['h2', 'h3', 'h4'].includes(block.level) ? block.level : 'h2';
                return `<${level}${blockStyle}>${block.contentMode === 'generated' ? content : this.escapeHtml(content)}</${level}>`;
            }

            if (block.type === 'paragraph') {
                return `<p${blockStyle}>${block.contentMode === 'generated'
                    ? content
                    : this.escapeHtml(content).replace(/\n/g, '<br>')}</p>`;
            }

            if (block.type === 'table') {
                if (!tableFormattingEnabled) {
                    const caption = block.title
                        ? `\n    <caption>${this.escapeHtml(block.title)}</caption>`
                        : '';
                    const rows = (block.rows || []).map((row) => {
                        const placeholder = this.normalizePlaceholder(row.placeholder || row.label);
                        const value = row.mode === 'static'
                            ? this.escapeHtml(row.content).replace(/\n/g, '<br>')
                            : (placeholder ? `{{${placeholder}}}` : '');

                        return [
                            '        <tr>',
                            `            <th scope="row">${this.escapeHtml(row.label)}</th>`,
                            `            <td>${value}</td>`,
                            '        </tr>',
                        ].join('\n');
                    }).join('\n');

                    return [
                        `<table${blockStyle}>${caption}`,
                        '    <tbody>',
                        rows,
                        '    </tbody>',
                        '</table>',
                    ].filter((line) => line !== '').join('\n');
                }

                const tableStyle = createTableStyle(block.tableStyle);
                const rightColumnWidth = 100 - tableStyle.leftColumnWidth;
                const collapse = tableStyle.cellSpacing > 0 ? 'separate' : 'collapse';
                const tableDeclarations = [
                    'width: 100%;',
                    'table-layout: fixed;',
                    `border-collapse: ${collapse};`,
                    `border-spacing: ${tableStyle.cellSpacing}px;`,
                ];
                if (blockSpacing > 0) {
                    tableDeclarations.push(`margin-bottom: ${blockSpacing}px;`);
                }
                const border = tableStyle.borderStyle === 'none' || tableStyle.borderWidth === 0
                    ? 'none'
                    : `${tableStyle.borderWidth}px ${tableStyle.borderStyle} ${tableStyle.borderColor}`;
                const headerDeclarations = [
                    `border: ${border};`,
                    `padding: ${tableStyle.paddingVertical}px ${tableStyle.paddingHorizontal}px;`,
                    `text-align: ${tableStyle.headerAlignment};`,
                    `vertical-align: ${tableStyle.verticalAlignment};`,
                    `background-color: ${tableStyle.labelBackgroundColor};`,
                    'overflow-wrap: anywhere;',
                ];
                const valueDeclarations = [
                    `border: ${border};`,
                    `padding: ${tableStyle.paddingVertical}px ${tableStyle.paddingHorizontal}px;`,
                    `text-align: ${tableStyle.valueAlignment};`,
                    `vertical-align: ${tableStyle.verticalAlignment};`,
                    `background-color: ${tableStyle.valueBackgroundColor};`,
                    'overflow-wrap: anywhere;',
                ];
                const caption = block.title
                    ? `\n    <caption style="caption-side: top; padding: 0 0 10px; text-align: left; font-weight: 600;">${this.escapeHtml(block.title)}</caption>`
                    : '';
                const rows = (block.rows || []).map((row) => {
                    const placeholder = this.normalizePlaceholder(row.placeholder || row.label);
                    const value = row.mode === 'static'
                        ? this.escapeHtml(row.content).replace(/\n/g, '<br>')
                        : (placeholder ? `{{${placeholder}}}` : '');

                    return [
                        '        <tr>',
                        '            <th',
                        '                scope="row"',
                        '                style="',
                        ...headerDeclarations.map((declaration) => `                    ${declaration}`),
                        '                "',
                        '            >',
                        `                ${this.escapeHtml(row.label)}`,
                        '            </th>',
                        '            <td',
                        '                style="',
                        ...valueDeclarations.map((declaration) => `                    ${declaration}`),
                        '                "',
                        '            >',
                        `                ${value}`,
                        '            </td>',
                        '        </tr>',
                    ].join('\n');
                }).join('\n');

                return [
                    '<table',
                    '    style="',
                    ...tableDeclarations.map((declaration) => `        ${declaration}`),
                    '    "',
                    `>${caption}`,
                    '    <colgroup>',
                    `        <col style="width: ${tableStyle.leftColumnWidth}%;">`,
                    `        <col style="width: ${rightColumnWidth}%;">`,
                    '    </colgroup>',
                    '    <tbody>',
                    rows,
                    '    </tbody>',
                    '</table>',
                ].filter((line) => line !== '').join('\n');
            }

            const legacyHtml = block.content || '';
            if (blockSpacing > 0 && legacyHtml) {
                return `${legacyHtml}\n<div aria-hidden="true" style="height: ${blockSpacing}px;"></div>`;
            }

            return legacyHtml;
        },

        generatedBlockPlaceholder(block) {
            return `splac_block_${String(block.id || '').replace(/[^a-zA-Z0-9_.-]/g, '_')}`;
        },

        normalizePlaceholder(value) {
            return normalizePlaceholder(value);
        },

        escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },
    },
});
