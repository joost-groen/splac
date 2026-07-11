import './page/splac-dashboard';
import './page/splac-wizard';
import './page/splac-review';
import './page/splac-template-list';
import './page/splac-template-detail';
import './page/splac-category-template-detail';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Module.register('splac-copilot', {
    type: 'plugin',
    name: 'splac-copilot',
    title: 'splac.general.mainMenuItemGeneral',
    description: 'splac.general.description',
    color: '#7c3aed',
    icon: 'regular-rocket',
    routePrefixName: 'splac',
    routePrefixPath: 'splac',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
    },

    routes: {
        index: {
            component: 'splac-dashboard',
            path: 'index',
        },
        wizard: {
            component: 'splac-wizard',
            path: 'wizard',
            meta: {
                parentPath: 'splac.index',
            },
        },
        review: {
            component: 'splac-review',
            path: 'review/:id',
            props: {
                default(route) {
                    return { processId: route.params.id };
                },
            },
            meta: {
                parentPath: 'splac.index',
            },
        },
        templates: {
            component: 'splac-template-list',
            path: 'templates',
            meta: {
                parentPath: 'splac.index',
            },
        },
        templateDetail: {
            component: 'splac-template-detail',
            path: 'template/:id?',
            props: {
                default(route) {
                    return { templateId: route.params.id };
                },
            },
            meta: {
                parentPath: 'splac.templates',
            },
        },
        categoryTemplateDetail: {
            component: 'splac-category-template-detail',
            path: 'category-template/:id?',
            props: {
                default(route) {
                    return { categoryTemplateId: route.params.id };
                },
            },
            meta: {
                parentPath: 'splac.templates',
            },
        },
    },

    navigation: [
        {
            id: 'splac-dashboard',
            parent: 'sw-catalogue',
            label: 'splac.general.mainMenuItemGeneral',
            color: '#7c3aed',
            icon: 'regular-rocket',
            path: 'splac.index',
            position: 100,
        },
        {
            id: 'splac-templates',
            parent: 'sw-catalogue',
            label: 'splac.general.menuTemplates',
            path: 'splac.templates',
            position: 110,
        },
    ],
});
