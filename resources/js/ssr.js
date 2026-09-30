/**
 * Render phía server (Node). Production hiện không bật (hosting không chạy Node);
 * bộ test PHP dùng để kiểm tra HTML thật của trang Vue (tests/Support/InertiaSsr).
 * Cổng: INERTIA_SSR_PORT (mặc định 13714).
 */
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import ui from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const pages = import.meta.glob('./Pages/**/*.vue');

createServer(
    (page) =>
        createInertiaApp({
            page,
            render: renderToString,
            title: (title) => (title ? `${title} · MEnglish` : 'MEnglish'),
            resolve: (name) => pages[`./Pages/${name}.vue`](),
            layout: (name) => (name.startsWith('Auth/') ? GuestLayout : AppLayout),
            setup({ App, props, plugin }) {
                return createSSRApp({ render: () => h(App, props) })
                    .use(plugin)
                    .use(ui);
            },
        }),
    { port: Number(process.env.INERTIA_SSR_PORT || 13714), host: '127.0.0.1' },
);
