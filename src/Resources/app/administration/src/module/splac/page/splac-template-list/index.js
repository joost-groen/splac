import template from './splac-template-list.html.twig';
import './splac-template-list.scss';
import {
    cloneJson,
    readTemplateFile,
    safeFilename,
    serializeTemplateFile,
} from './template-file';

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
            isImporting: false,
            pendingImport: null,
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

        pendingImportTypeLabel() {
            if (!this.pendingImport) {
                return '';
            }

            return this.$tc(`splac.templates.importType.${this.pendingImport.type}`);
        },

        pendingImportHasDuplicateName() {
            if (!this.pendingImport) {
                return false;
            }

            const collection = this.pendingImport.type === 'listing'
                ? this.templates
                : this.categoryTemplates;
            const name = String(this.pendingImport.template.name || '').trim().toLocaleLowerCase();

            return !!name && Array.from(collection || []).some(
                (item) => String(item.name || '').trim().toLocaleLowerCase() === name,
            );
        },

        isPendingImportNameValid() {
            const name = String(this.pendingImport?.template?.name || '').trim();

            return name.length > 0 && name.length <= 255;
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

        openImportFilePicker() {
            this.$refs.templateFileInput?.click();
        },

        async onImportFileSelected(event) {
            const [file] = Array.from(event.target?.files || []);
            // Reset immediately so selecting the same file after correcting it
            // still triggers a change event.
            if (event.target) {
                event.target.value = '';
            }
            if (!file) {
                return;
            }

            try {
                this.pendingImport = await readTemplateFile(file);
            } catch (error) {
                const key = error?.message && error.message.startsWith('splac.templates.')
                    ? error.message
                    : 'splac.templates.importErrorInvalid';
                this.createNotificationError({ message: this.$tc(key) });
            }
        },

        closeImportModal() {
            if (!this.isImporting) {
                this.pendingImport = null;
            }
        },

        async confirmImport() {
            if (!this.pendingImport) {
                return;
            }

            const name = String(this.pendingImport.template.name || '').trim();
            if (!name) {
                this.createNotificationError({
                    message: this.$tc('splac.templateDetail.errorNameRequired'),
                });
                return;
            }
            if (name.length > 255) {
                this.createNotificationError({
                    message: this.$tc('splac.templates.importErrorNameLength'),
                });
                return;
            }

            this.isImporting = true;
            const { type, template } = this.pendingImport;
            const repository = type === 'listing'
                ? this.templateRepository
                : this.categoryTemplateRepository;
            const entity = repository.create(Shopware.Context.api);

            entity.name = name;
            entity.config = cloneJson(template.config);
            if (type === 'listing') {
                entity.active = template.active;
                entity.descriptionTemplates = cloneJson(template.descriptionTemplates);
            } else {
                // Category IDs are installation-specific and intentionally
                // never imported. The destination category is selected locally.
                entity.parentCategoryId = null;
            }

            try {
                await repository.save(entity, Shopware.Context.api);
                this.pendingImport = null;
                await this.loadData();
                this.createNotificationSuccess({
                    message: this.$tc('splac.templates.importSuccess', 0, { name }),
                });
            } catch (error) {
                this.createNotificationError({
                    message: error?.message || this.$tc('splac.general.errorGeneric'),
                });
            } finally {
                this.isImporting = false;
            }
        },

        exportTemplate(item, type) {
            const contents = serializeTemplateFile(item, type);
            const blob = new Blob([contents], { type: 'application/json;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');

            link.href = url;
            link.download = `${safeFilename(item.name)}.splac-template.json`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 0);
        },
    },
});
