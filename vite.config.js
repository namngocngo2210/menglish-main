import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            // app.js: ứng dụng Inertia (Vue). legacy.js: các trang Blade còn lại (Alpine + htmx).
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/legacy.js'],
            // SSR chỉ dùng khi chạy test (tests/Support/SsrServer tự build): hosting không có Node.
            ssr: 'resources/js/ssr.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: { base: null, includeAbsolute: false },
            },
        }),
    ],
    resolve: {
        alias: { '@': '/resources/js' },
    },
});
