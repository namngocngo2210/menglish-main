/**
 * Ứng dụng Inertia + Vue: mỗi route Laravel trả Inertia::render('Thu/Muc', props) → trang resources/js/Pages/Thu/Muc.vue.
 * Layout mặc định: AppLayout (trang Auth/*: GuestLayout); trang đổi layout bằng defineOptions({ layout }).
 * Trang Blade chưa chuyển (bản in, PDF…) vẫn dùng resources/js/legacy.js.
 */
import { createSSRApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import ui from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { closeRemoteModal, setRemoteModalResolver } from '@/lib/remoteModal';
import { registerRowLinks } from '@/lib/rowLink';
import { toast } from '@/lib/toast';

const pages = import.meta.glob('./Pages/**/*.vue');
const resolve = (name) => {
    const page = pages[`./Pages/${name}.vue`];
    if (!page) throw new Error(`Không tìm thấy trang Vue: ${name}`);
    return page();
};
setRemoteModalResolver(resolve);

createInertiaApp({
    title: (title) => (title ? `${title} · MEnglish` : 'MEnglish'),
    resolve,
    layout: (name) => (name.startsWith('Auth/') ? GuestLayout : AppLayout),
    setup({ el, App, props, plugin }) {
        createSSRApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ui)
            .mount(el);
    },
    progress: { color: '#c2410c', delay: 150 },
});

// Chuyển sang trang khác → đóng modal đang mở.
router.on('start', (event) => {
    const visit = event.detail.visit;
    if (visit.method === 'get' && !visit.prefetch && !visit.only?.length) closeRemoteModal();
});

// Lỗi không phải trang Inertia khi gửi form (403, 500…): báo bằng toast thay cho hộp HTML mặc định.
router.on('httpException', (event) => {
    const status = event.detail.response.status;
    if (status === 419) {
        toast('Phiên làm việc đã hết hạn, đang tải lại trang…', 'warning');
        window.setTimeout(() => window.location.reload(), 1200);
    } else if (status === 403) {
        toast('Bạn không có quyền thực hiện thao tác này.', 'error');
    } else if (status === 404) {
        toast('Không tìm thấy dữ liệu (có thể đã bị xoá).', 'error');
    } else if (status === 429) {
        toast('Thao tác quá nhanh, vui lòng thử lại sau ít phút.', 'error');
    } else {
        toast('Có lỗi xảy ra, vui lòng thử lại.', 'error');
    }
    return false;
});
router.on('networkError', () => {
    toast('Không kết nối được máy chủ, vui lòng kiểm tra mạng.', 'error');
    return false;
});

registerRowLinks();
