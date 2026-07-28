import template from './splac-category-template-detail.html.twig';
import './splac-category-template-detail.scss';

const LOCALES = ['de-DE', 'en-GB'];
const CONTENT_FIELDS = ['name', 'description'];
const SEO_FIELDS = ['metaTitle', 'metaDescription', 'keywords'];

const defaultConfig = () => ({
    name: { mode: 'instruction', 'de-DE': '', 'en-GB': '' },
    description: { mode: 'instruction', 'de-DE': '', 'en-GB': '' },
    metaTitle: { mode: 'instruction', 'de-DE': '', 'en-GB': '' },
    metaDescription: { mode: 'instruction', 'de-DE': '', 'en-GB': '' },
    keywords: { mode: 'instruction', 'de-DE': '', 'en-GB': '' },
});

Shopware.Component.register('splac-category-template-detail', {
    template,

    inject: ['repositoryFactory'],

    mixins: [Shopware.Mixin.getByName('notification')],

    props: {
        categoryTemplateId: {
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
            activeLocale: LOCALES[0],
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('splac_category_template');
        },

        modeOptions() {
            return [
                { value: 'instruction', label: this.$tc('splac.templateDetail.modeInstruction') },
                { value: 'placeholder', label: this.$tc('splac.templateDetail.modePlaceholder') },
            ];
        },

        contentFields() {
            return CONTENT_FIELDS;
        },

        seoFields() {
            return SEO_FIELDS;
        },
    },

    created() {
        this.loadItem();
    },

    methods: {
        localeLabel(locale) {
            return this.$tc(`splac.templateDetail.locale.${locale}`);
        },

        async loadItem() {
            this.isLoading = true;

            try {
                if (this.categoryTemplateId) {
                    this.item = await this.repository.get(this.categoryTemplateId, Shopware.Context.api);
                } else {
                    this.item = this.repository.create(Shopware.Context.api);
                    this.item.name = '';
                    this.item.active = true;
                }

                const merged = defaultConfig();
                const existing = this.item.config || {};
                this.item.config = [...CONTENT_FIELDS, ...SEO_FIELDS].reduce((config, field) => ({
                    ...config,
                    [field]: { ...merged[field], ...(existing[field] || {}) },
                }), {});
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

            this.isSaving = true;

            try {
                await this.repository.save(this.item, Shopware.Context.api);
                this.createNotificationSuccess({
                    message: this.$tc('splac.templateDetail.saveSuccess'),
                });

                if (!this.categoryTemplateId) {
                    this.$router.push({ name: 'splac.categoryTemplateDetail', params: { id: this.item.id } });
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
    },
});
