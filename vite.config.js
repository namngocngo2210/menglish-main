import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            // app.js: ứng dụng Inertia (Vue). app.css dùng chung cho trang Vue và vài trang Blade còn lại (bản in, bảng điểm).
            input: ['resources/css/app.css', 'resources/js/app.js'],
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
