import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { installEnterpriseDomLocalization } from '@/lib/enterprise-locale';
import '../css/app.css';

const appName = import.meta.env.VITE_APP_NAME || 'Ngwe Lwe System';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
        installEnterpriseDomLocalization(document.body);
    },
    progress: {
        color: '#ffb000',
    },
});
