import template from './splac-review.html.twig';
import './splac-review.scss';

const { Criteria } = Shopware.Data;

Shopware.Component.register('splac-review', {
    template,

    inject: ['repositoryFactory', 'splacApiService'],

    mixins: [Shopware.Mixin.getByName('notification')],

    props: {
        processId: {
            type: String,
            required: true,
        },
    },

    data() {
        return {
            process: null,
            output: {},
            isLoading: true,
            isApproving: false,
            regeneratingStep: null,
            pollTimer: null,
            previewLocale: 'de-DE',
        };
    },

    computed: {
        processRepository() {
            return this.repositoryFactory.create('splac_process');
        },

        locales() {
            const selected = this.process?.input?.language;
            if (typeof selected === 'string' && selected) {
                return [selected];
            }

            const langs = this.process?.template?.config?.languages;
            return Array.isArray(langs) && langs.length ? [langs[0]] : ['de-DE'];
        },

        features() {
            return this.process?.template?.config?.features || {};
        },

        isGenerating() {
            return ['extracting', 'generating'].includes(this.process?.status);
        },

        isReviewable() {
            return this.process?.status === 'review';
        },

        isDone() {
            return this.process?.status === 'done';
        },

        hasCategoryOutput() {
            return this.process?.input?.categoryMode === 'template';
        },

        emptyFields() {
            const empty = [];
            this.locales.forEach((locale) => {
                ['productName', 'metaTitle', 'metaDescription', 'keywords'].forEach((field) => {
                    if (this.output[field] && this.output[field][locale] === '') {
                        empty.push(`${field} (${locale})`);
                    }
                });
            });
            ['ean', 'manufacturerNumber'].forEach((field) => {
                if (field in this.output && this.output[field] === '') {
                    empty.push(field);
                }
            });
            return empty;
        },
    },

    created() {
        this.loadProcess();
    },

    beforeUnmount() {
        this.stopPolling();
    },

    methods: {
        async loadProcess() {
            const criteria = new Criteria(1, 1);
            criteria.addAssociation('template');
            criteria.addAssociation('categoryTemplate');
            criteria.addAssociation('sources');

            try {
                this.process = await this.processRepository.get(this.processId, Shopware.Context.api, criteria);
                this.output = JSON.parse(JSON.stringify(this.process.output || {}));
                if (!this.locales.includes(this.previewLocale)) {
                    [this.previewLocale] = this.locales;
                }

                if (this.isGenerating) {
                    this.startPolling();
                } else {
                    this.stopPolling();
                }
            } finally {
                this.isLoading = false;
            }
        },

        startPolling() {
            if (this.pollTimer) {
                return;
            }
            this.pollTimer = setInterval(() => {
                this.loadProcess();
            }, 4000);
        },

        stopPolling() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        },

        async onRegenerate(step) {
            this.regeneratingStep = step;

            try {
                await this.splacApiService.regenerate(this.processId, step, this.output);
                await this.loadProcess();
                this.startPolling();
            } catch (error) {
                this.createNotificationError({
                    message: error?.response?.data?.message || error?.message || this.$tc('splac.general.errorGeneric'),
                });
            } finally {
                this.regeneratingStep = null;
            }
        },

        async onApprove() {
            this.isApproving = true;

            try {
                const { productId } = await this.splacApiService.approve(this.processId, {
                    output: this.output,
                });

                this.createNotificationSuccess({
                    message: this.$tc('splac.review.approveSuccess'),
                });

                this.$router.push({ name: 'sw.product.detail', params: { id: productId } });
            } catch (error) {
                this.createNotificationError({
                    message: error?.response?.data?.message || error?.message || this.$tc('splac.general.errorGeneric'),
                });
                this.loadProcess();
            } finally {
                this.isApproving = false;
            }
        },

        openProduct() {
            if (this.process?.productId) {
                this.$router.push({ name: 'sw.product.detail', params: { id: this.process.productId } });
            }
        },

        tagList() {
            return Array.isArray(this.output.tags) ? this.output.tags.join(', ') : '';
        },

        onTagsChange(value) {
            this.output.tags = value.split(',').map((t) => t.trim()).filter((t) => t !== '');
        },
    },
});
