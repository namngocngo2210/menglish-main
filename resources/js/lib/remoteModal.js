/**
 * Modal tải trang từ server (thay cho modal htmx cũ): mở URL của một route Inertia trong modal chung
 * (RemoteModalHost trong AppLayout) — trang đó dùng <UiModalFrame> để có khung modal.
 *
 *   openRemoteModal(route('holidays.create'), { size: 'md' })   // hoặc <UiButton :href="..." modal="md">
 *
 * - Mở trực tiếp URL (tab mới, F5) vẫn ra trang đầy đủ: UiModalFrame tự dựng khung trang khi không nằm trong modal.
 * - Form trong modal (<UiForm>) gửi bằng Inertia; lỗi validate → hiện ngay trong modal; lưu xong → đóng modal,
 *   trang nền tải lại dữ liệu (server trả về trang hiện tại kèm thông báo).
 * - Link Inertia thường bên trong modal → chuyển trang và đóng modal; <UiButton modal> bên trong → đổi nội dung modal.
 * - Modal xem nhanh (ticket, công việc): openRemoteModal(url, { history: true }) / <UiButton modal-history> → mở modal thêm
 *   1 mục lịch sử (cùng URL), nút Back của trình duyệt / điện thoại đóng modal thay vì rời trang; đóng bằng nút thì gỡ mục đó.
 */
import { reactive } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { toast } from './toast';

export const remoteModal = reactive({
    open: false,
    loading: false,
    url: null,
    size: 'lg',
    component: null,
    props: {},
    key: 0,
});

let resolveComponent = null;
let requestId = 0;

// Mục lịch sử của modal xem nhanh: { id, href } khi đang mở; afterPop chạy ở popstate do chính ta gọi history.back().
let historyEntry = null;
let historySeq = 0;
let afterPop = null;

if (typeof window !== 'undefined') {
    // Đăng ký trước router Inertia (module này được import trước createInertiaApp) → chặn được popstate của modal,
    // Inertia không khôi phục lại trang nền từ lịch sử (dữ liệu cũ).
    window.addEventListener('popstate', (event) => {
        if (afterPop) {
            const done = afterPop;
            afterPop = null;
            event.stopImmediatePropagation();
            done();
            return;
        }
        if (historyEntry && event.state?.remoteModal !== historyEntry.id) {
            historyEntry = null;
            event.stopImmediatePropagation();
            closeRemoteModal();
        }
    });
}

function pushHistoryEntry() {
    if (historyEntry) return;
    historyEntry = { id: ++historySeq, href: window.location.href };
    window.history.pushState({ ...window.history.state, remoteModal: historyEntry.id }, '');
}

/** Modal đóng bằng nút / lưu xong → gỡ mục lịch sử đã thêm (nếu trang vẫn ở đúng URL đó). */
function popHistoryEntry() {
    const entry = historyEntry;
    historyEntry = null;
    if (!entry || window.location.href !== entry.href) return; // đã chuyển sang trang khác → giữ lịch sử như trình duyệt
    // Lưu xong, Inertia đã thay mục của modal bằng trang mới (cùng URL) → lùi về rồi đặt lại trạng thái mới đó.
    const replaced = window.history.state?.remoteModal === entry.id ? null : window.history.state;
    afterPop = () => {
        if (replaced) window.history.replaceState(replaced, '');
    };
    window.history.back();
}

/** app.js đăng ký hàm tìm component theo tên trang (cùng bộ với createInertiaApp). */
export function setRemoteModalResolver(resolver) {
    resolveComponent = resolver;
}

export async function openRemoteModal(url, { size = null, quiet = false, history = false } = {}) {
    const id = ++requestId;
    remoteModal.open = true;
    remoteModal.loading = !quiet;
    remoteModal.url = url;
    if (size) remoteModal.size = size;

    let response;
    try {
        response = await fetch(url, {
            headers: {
                Accept: 'text/html, application/xhtml+xml',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Inertia': 'true',
                'X-Inertia-Version': usePage().version ?? '',
                'X-Remote-Modal': 'true',
            },
            credentials: 'same-origin',
        });
    } catch {
        if (id === requestId) closeRemoteModal();
        toast('Không kết nối được máy chủ, vui lòng kiểm tra mạng.', 'error');
        return;
    }
    if (id !== requestId) return;

    // Phiên bản tài nguyên đổi (vừa deploy) hoặc không phải trang Inertia → mở hẳn trang đó.
    if (response.status === 409 || !response.headers.get('X-Inertia')) {
        if (response.ok && response.status !== 409) {
            closeRemoteModal();
            window.location.href = response.url || url;
            return;
        }
        if (response.status === 409) {
            window.location.href = response.headers.get('X-Inertia-Location') || url;
            return;
        }
        closeRemoteModal();
        toast(response.status === 403 ? 'Bạn không có quyền thực hiện thao tác này.' : 'Không tải được nội dung, vui lòng thử lại.', 'error');
        return;
    }

    const page = await response.json();
    // Bị chuyển hướng sang trang khác (hết phiên → đăng nhập…) → chuyển hẳn trang.
    const requested = new URL(url, window.location.origin);
    const landed = new URL(page.url, window.location.origin);
    if (requested.pathname !== landed.pathname) {
        closeRemoteModal();
        router.visit(page.url);
        return;
    }

    const component = await resolveComponent(page.component);
    if (id !== requestId) return;
    remoteModal.component = component.default ?? component;
    remoteModal.props = page.props;
    remoteModal.url = page.url;
    remoteModal.loading = false;
    if (!quiet) remoteModal.key++;
    if (history) pushHistoryEntry();
}

/** Tải lại nội dung modal đang mở (giữ nguyên khung, không hiện skeleton) — vd. sau khi gửi phản hồi ticket. */
export function reloadRemoteModal() {
    if (remoteModal.open && remoteModal.url) return openRemoteModal(remoteModal.url, { quiet: true });
}

export function closeRemoteModal() {
    requestId++;
    remoteModal.open = false;
    remoteModal.loading = false;
    if (historyEntry) popHistoryEntry();
}

/** Sau khi modal đóng hẳn (hết hiệu ứng) mới bỏ nội dung. */
export function clearRemoteModal() {
    if (!remoteModal.open) {
        remoteModal.component = null;
        remoteModal.props = {};
        remoteModal.url = null;
    }
}
