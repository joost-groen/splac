import template from './splac-template-list.html.twig';
import './splac-template-list.scss';

const { Criteria } = Shopware.Data;

Shopware.Component.register('splac-template-list', {
    template,

    inject: ['repositoryFactory'],

    mixins: [Shopware.Mixin.getByName('notification')],

    data() {
        return {
            templates: null,
            categoryTemplates: null,
            isLoading: false,
        };
    },

    computed: {
        templateRepository() {
            return this.repositoryFactory.create('splac_template');
        },

        categoryTemplateRepository() {
            return this.repositoryFactory.create('splac_category_template');
        },

        templateColumns() {
            return [
                {
                    property: 'name',
                    label: this.$tc('splac.templates.columnName'),
                    routerLink: 'splac.templateDetail',
                    primary: true,
                    allowResize: true,
                },
                {
                    property: 'active',
                    label: this.$tc('splac.templates.columnActive'),
                    align: 'center',
                },
            ];
        },

        categoryTemplateColumns() {
            return [
                {
                    property: 'name',
                    label: this.$tc('splac.templates.columnName'),
                    routerLink: 'splac.categoryTemplateDetail',
                    primary: true,
                    allowResize: true,
                },
            ];
        },
    },

    created() {
        this.loadData();
    },

    methods: {
        async loadData() {
            this.isLoading = true;

            const criteria = new Criteria(1, 100);
            criteria.addSorting(Criteria.sort('name', 'ASC'));

            try {
                [this.templates, this.categoryTemplates] = await Promise.all([
                    this.templateRepository.search(criteria, Shopware.Context.api),
                    this.categoryTemplateRepository.search(criteria, Shopware.Context.api),
                ]);
            } finally {
                this.isLoading = false;
            }
        },

        async onDeleteTemplate(item) {
            await this.templateRepository.delete(item.id, Shopware.Context.api);
            this.loadData();
        },

        async onDeleteCategoryTemplate(item) {
            await this.categoryTemplateRepository.delete(item.id, Shopware.Context.api);
            this.loadData();
        },
    },
});
