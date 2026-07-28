import template from './splac-wizard.html.twig';
import './splac-wizard.scss';

const { Criteria } = Shopware.Data;

Shopware.Component.register('splac-wizard', {
    template,

    inject: ['repositoryFactory', 'splacApiService'],

    mixins: [Shopware.Mixin.getByName('notification')],

    data() {
        return {
            step: 1,
            templates: [],
            selectedTemplateId: null,
            files: [],
            isDragging: false,
            isStarting: false,
            llmCapabilities: {
                provider: '',
                reasoning: false,
                batchProcessing: false,
                officiallySupported: true,
                extendedBeta: false,
                enabled: true,
            },
            input: {
                language: null,
                productName: '',
                notes: '',
                price: null,
                taxId: null,
                stock: 0,
                categoryMode: 'existing',
                categoryId: null,
                salesChannelIds: [],
                descriptionInstruction: '',
                seoInstruction: '',
                advancedPrices: [],
                reasoningEnabled: false,
                reasoningLevel: 'medium',
                batchProcessing: false,
            },
            categoryTemplateId: null,
        };
    },

    computed: {
        templateRepository() {
            return this.repositoryFactory.create('splac_template');
        },

        salesChannelRepository() {
            return this.repositoryFactory.create('sales_channel');
        },

        selectedTemplate() {
            return this.templates.find((t) => t.id === this.selectedTemplateId) || null;
        },

        features() {
            return this.selectedTemplate?.config?.features || {};
        },

        canGoToStep2() {
            return !!this.selectedTemplateId && !!this.input.language;
        },

        canGoToStep3() {
            return this.files.length > 0 || this.input.notes.trim().length > 0;
        },

        canStart() {
            const basicsOk = this.input.productName.trim() !== ''
                && !!this.input.language
                && this.input.price > 0
                && this.input.taxId
                && this.input.salesChannelIds.length > 0;

            const categoryOk = this.input.categoryMode === 'existing'
                ? !!this.input.categoryId
                : !!this.categoryTemplateId;

            return basicsOk && categoryOk && this.llmCapabilities.enabled;
        },

        categoryModeOptions() {
            return [
                { value: 'existing', label: this.$tc('splac.wizard.categoryModeExisting') },
                { value: 'template', label: this.$tc('splac.wizard.categoryModeTemplate') },
            ];
        },

        categoryTemplateCriteria() {
            const criteria = new Criteria(1, 100);
            criteria.addFilter(Criteria.equals('active', true));
            criteria.addSorting(Criteria.sort('name', 'ASC'));

            return criteria;
        },

        languageOptions() {
            const configured = this.selectedTemplate?.config?.languages;
            const languages = Array.isArray(configured) && configured.length
                ? configured
                : ['de-DE', 'en-GB'];

            return languages.map((locale) => ({
                value: locale,
                label: this.localeLabel(locale),
            }));
        },

        reasoningLevelOptions() {
            return ['low', 'medium', 'high'].map((value) => ({
                value,
                label: this.$tc(`splac.wizard.reasoningLevel${value.charAt(0).toUpperCase()}${value.slice(1)}`),
            }));
        },

        providerLabel() {
            const labels = {
                openai: 'OpenAI',
                anthropic: 'Anthropic',
                gemini: 'Google Gemini',
                mistral: 'Mistral',
            };

            return labels[this.llmCapabilities.provider] || this.llmCapabilities.provider;
        },
    },

    created() {
        this.loadTemplates();
        this.loadLlmCapabilities();
    },

    methods: {
        async loadTemplates() {
            const criteria = new Criteria(1, 100);
            criteria.addFilter(Criteria.equals('active', true));
            criteria.addSorting(Criteria.sort('name', 'ASC'));

            this.templates = await this.templateRepository.search(criteria, Shopware.Context.api);
        },

        async loadLlmCapabilities() {
            try {
                this.llmCapabilities = await this.splacApiService.getLlmCapabilities();
            } catch {
                // Keep both optional features disabled when capability discovery fails.
            }
        },

        selectTemplate(templateId) {
            this.selectedTemplateId = templateId;

            const config = this.templates.find((t) => t.id === templateId)?.config || {};
            const languages = Array.isArray(config.languages) && config.languages.length
                ? config.languages
                : ['de-DE', 'en-GB'];
            if (!languages.includes(this.input.language)) {
                [this.input.language] = languages;
            }
            if (config.defaultTaxId) {
                this.input.taxId = config.defaultTaxId;
            }
            if (Array.isArray(config.defaultSalesChannelIds) && config.defaultSalesChannelIds.length) {
                this.input.salesChannelIds = [...config.defaultSalesChannelIds];
            }
        },

        onFileInputChange(event) {
            this.addFiles(Array.from(event.target.files || []));
            event.target.value = '';
        },

        onDrop(event) {
            event.preventDefault();
            this.isDragging = false;
            this.addFiles(Array.from(event.dataTransfer?.files || []));
        },

        addFiles(newFiles) {
            const pdfs = newFiles.filter((file) => file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf'));

            if (pdfs.length !== newFiles.length) {
                this.createNotificationWarning({
                    message: this.$tc('splac.wizard.onlyPdfWarning'),
                });
            }

            this.files = [...this.files, ...pdfs];
        },

        removeFile(index) {
            this.files.splice(index, 1);
        },

        addAdvancedPrice() {
            this.input.advancedPrices.push({ ruleId: null, price: null, quantityStart: 1, quantityEnd: null });
        },

        removeAdvancedPrice(index) {
            this.input.advancedPrices.splice(index, 1);
        },

        localeLabel(locale) {
            const labels = {
                'de-DE': this.$tc('splac.wizard.languageGerman'),
                'en-GB': this.$tc('splac.wizard.languageEnglish'),
            };

            return labels[locale] || locale;
        },

        async onStart() {
            this.isStarting = true;

            try {
                const { id: processId } = await this.splacApiService.createProcess({
                    name: this.input.productName,
                    templateId: this.selectedTemplateId,
                    categoryTemplateId: this.input.categoryMode === 'template' ? this.categoryTemplateId : null,
                    input: this.input,
                });

                for (const file of this.files) {
                    // Sequential upload keeps memory usage predictable for large PDFs.
                    // eslint-disable-next-line no-await-in-loop
                    await this.splacApiService.uploadSource(processId, file);
                }

                await this.splacApiService.start(processId);

                this.createNotificationSuccess({
                    message: this.$tc('splac.wizard.startSuccess'),
                });
                this.$router.push({ name: 'splac.index' });
            } catch (error) {
                this.createNotificationError({
                    message: error?.response?.data?.message || error?.message || this.$tc('splac.general.errorGeneric'),
                });
            } finally {
                this.isStarting = false;
            }
        },
    },
});
