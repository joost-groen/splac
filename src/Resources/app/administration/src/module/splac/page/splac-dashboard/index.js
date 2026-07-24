import template from './splac-dashboard.html.twig';
import './splac-dashboard.scss';

const { Criteria } = Shopware.Data;

const STATUS_BADGES = {
    draft: 'neutral',
    extracting: 'progress',
    generating: 'progress',
    review: 'warning',
    creating: 'progress',
    done: 'success',
    failed: 'danger',
    cancelled: 'neutral',
};

Shopware.Component.register('splac-dashboard', {
    template,

    inject: ['repositoryFactory', 'splacApiService'],

    mixins: [Shopware.Mixin.getByName('notification')],

    data() {
        return {
            processes: null,
            isLoading: false,
            total: 0,
            page: 1,
            limit: 25,
            pollTimer: null,
            costStatistics: null,
        };
    },

    computed: {
        processRepository() {
            return this.repositoryFactory.create('splac_process');
        },

        columns() {
            return [
                {
                    property: 'name',
                    label: this.$tc('splac.dashboard.columnName'),
                    primary: true,
                    allowResize: true,
                },
                {
                    property: 'status',
                    label: this.$tc('splac.dashboard.columnStatus'),
                    allowResize: true,
                },
                {
                    property: 'template.name',
                    label: this.$tc('splac.dashboard.columnTemplate'),
                    allowResize: true,
                },
                {
                    property: 'createdAt',
                    label: this.$tc('splac.dashboard.columnCreatedAt'),
                    allowResize: true,
                },
                {
                    property: 'llmCost',
                    label: this.$tc('splac.dashboard.columnCost'),
                    allowResize: true,
                    align: 'right',
                },
            ];
        },

        hasRunningProcesses() {
            if (!this.processes) {
                return false;
            }
            return this.processes.some((p) => ['extracting', 'generating', 'creating'].includes(p.status));
        },
    },

    created() {
        this.loadProcesses();
    },

    beforeUnmount() {
        this.stopPolling();
    },

    methods: {
        async loadProcesses() {
            this.isLoading = true;

            const criteria = new Criteria(this.page, this.limit);
            criteria.addAssociation('template');
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));

            try {
                const [result, costStatistics] = await Promise.all([
                    this.processRepository.search(criteria, Shopware.Context.api),
                    this.splacApiService.getCostStatistics().catch(() => this.costStatistics),
                ]);
                this.processes = result;
                this.total = result.total;
                if (costStatistics) {
                    this.costStatistics = costStatistics;
                }

                if (this.hasRunningProcesses) {
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
                this.loadProcesses();
            }, 5000);
        },

        stopPolling() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        },

        statusVariant(status) {
            return STATUS_BADGES[status] || 'neutral';
        },

        statusLabel(status) {
            return this.$tc(`splac.status.${status}`);
        },

        formatCost(value, currency = null) {
            const costCurrency = currency || this.costStatistics?.currency;
            if (!costCurrency) {
                return '—';
            }

            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: costCurrency,
                minimumFractionDigits: 2,
                maximumFractionDigits: 6,
            }).format(Number(value) || 0);
        },

        onPageChange({ page, limit }) {
            this.page = page;
            this.limit = limit;
            this.loadProcesses();
        },

        openProcess(process) {
            this.$router.push({ name: 'splac.review', params: { id: process.id } });
        },

        async onRetry(process) {
            try {
                await this.splacApiService.retry(process.id);
                this.createNotificationSuccess({
                    message: this.$tc('splac.dashboard.retryStarted'),
                });
                this.loadProcesses();
            } catch (error) {
                this.createNotificationError({
                    message: error?.response?.data?.message || error?.message || this.$tc('splac.general.errorGeneric'),
                });
            }
        },

        async onCancel(process) {
            try {
                await this.splacApiService.cancel(process.id);
                this.loadProcesses();
            } catch (error) {
                this.createNotificationError({
                    message: error?.response?.data?.message || error?.message || this.$tc('splac.general.errorGeneric'),
                });
            }
        },

        async onDelete(process) {
            try {
                await this.processRepository.delete(process.id, Shopware.Context.api);
                this.loadProcesses();
            } catch (error) {
                this.createNotificationError({
                    message: error?.response?.data?.message || error?.message || this.$tc('splac.general.errorGeneric'),
                });
            }
        },

        canRetry(process) {
            return ['failed', 'cancelled'].includes(process.status);
        },

        canCancel(process) {
            return ['draft', 'extracting', 'generating', 'review'].includes(process.status);
        },
    },
});
