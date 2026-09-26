/**
 * Modal tải nội dung từ server (htmx 2 + Alpine) — xem docs/frontend-interaction-redesign.md §5.
 *
 * - Nút có hx-target="#remote-modal-body" (vd. <x-ui.button :href modal="md">) nằm NGOÀI modal:
 *   mở modal "remote" theo data-modal-size, hiện skeleton trong lúc tải.
 * - Response HX-Trigger từ server (trait RendersModals::modalSaved):
 *     close-modal → đóng modal chứa phần tử gửi request (mặc định: mọi modal), toast → toast chung,
 *     sự kiện làm mới (vd. holidays-changed) → tự nổi lên body, vùng danh sách nghe bằng hx-trigger="... from:body".
 * - Server trả trang đầy đủ (không phải fragment, vd. hết phiên → trang đăng nhập) → chuyển hẳn sang trang đó.
 */
import htmx from 'htmx.org';

const BODY_ID = 'remote-modal-body';

// 204 = không swap; 422 = form kèm lỗi validate → swap lại vào modal; lỗi khác → không swap, báo toast.
htmx.config.responseHandling = [
    { code: '204', swap: false },
    { code: '422', swap: true },
    { code: '[23]..', swap: true },
    { code: '[45]..', swap: false, error: true },
];
// Không dùng lịch sử htmx (không push URL) và HX-Request luôn nghĩa là "trả fragment".
htmx.config.historyEnabled = false;
htmx.config.historyRestoreAsHxRequest = false;

const modalBody = () => document.getElementById(BODY_ID);
const isRemoteTarget = (target) => target?.id === BODY_ID;
const toast = (message, type = 'error') => window.dispatchEvent(new CustomEvent('toast', { detail: { message, type } }));
const openModal = (name, size) => window.dispatchEvent(new CustomEvent('open-modal', { detail: { name, size } }));

function showSkeleton() {
    const tpl = document.getElementById('remote-modal-skeleton');
    const body = modalBody();
    if (tpl && body) body.replaceChildren(tpl.content.cloneNode(true));
}

function focusFirstField(body) {
    const el = body.querySelector('[aria-invalid="true"], [autofocus], input:not([type=hidden]):not([disabled]), select, textarea');
    el?.focus({ preventScroll: true });
}

// Mở modal khi request đến từ nút bên ngoài (request từ form bên trong modal thì giữ nguyên nội dung).
document.addEventListener('htmx:beforeRequest', (e) => {
    const body = modalBody();
    if (!isRemoteTarget(e.detail.target) || body?.contains(e.detail.elt)) return;
    showSkeleton();
    openModal('remote', e.detail.elt.dataset.modalSize);
});

// Form boost: đọc action lúc gửi (action có thể đổi bằng Alpine :action, vd. modal xác nhận xoá dùng chung).
const VERB_ATTRS = ['hx-get', 'hx-post', 'hx-put', 'hx-patch', 'hx-delete'];
document.addEventListener('htmx:configRequest', (e) => {
    const elt = e.detail.elt;
    if (!(elt instanceof HTMLFormElement) || !elt.getAttribute('action')) return;
    if (VERB_ATTRS.some((a) => elt.hasAttribute(a))) return;
    e.detail.path = elt.getAttribute('action');
});

document.addEventListener('htmx:beforeSwap', (e) => {
    if (!isRemoteTarget(e.detail.target) || !e.detail.shouldSwap) return;
    // Không phải fragment (layout đầy đủ) → đi hẳn tới trang đó thay vì nhét cả trang vào modal.
    if (/^\s*<!doctype html/i.test(e.detail.serverResponse)) {
        e.detail.shouldSwap = false;
        window.location.href = e.detail.xhr.responseURL || e.detail.requestConfig.path;
    }
});

document.addEventListener('htmx:afterSwap', (e) => {
    if (isRemoteTarget(e.detail.target)) focusFirstField(e.detail.target);
});

document.addEventListener('htmx:responseError', (e) => {
    const status = e.detail.xhr.status;
    const message = {
        403: 'Bạn không có quyền thực hiện thao tác này.',
        404: 'Không tìm thấy dữ liệu (có thể đã bị xoá).',
        419: 'Phiên làm việc đã hết hạn, vui lòng tải lại trang.',
    }[status] ?? `Có lỗi xảy ra (mã ${status}), vui lòng thử lại.`;
    toast(message);
    // Lỗi ngay khi mở (modal còn skeleton) → đóng modal.
    if (isRemoteTarget(e.detail.target) && modalBody()?.querySelector('[aria-busy]')) {
        window.dispatchEvent(new CustomEvent('close-modal', { detail: 'remote' }));
    }
});

document.addEventListener('htmx:sendError', () => toast('Không kết nối được máy chủ, vui lòng kiểm tra mạng.'));

// HX-Trigger "close-modal": htmx phát trên phần tử gửi request với detail { value } → đổi sang tên modal cho x-ui.modal.
document.addEventListener('close-modal', (e) => {
    if (!e.detail || typeof e.detail !== 'object' || !('value' in e.detail)) return;
    const name = typeof e.detail.value === 'string'
        ? e.detail.value
        : e.target.closest?.('[data-modal]')?.dataset.modal ?? '*';
    window.dispatchEvent(new CustomEvent('close-modal', { detail: name }));
});

// Đóng xong thì dọn nội dung (form cũ không còn nằm trong DOM, lần mở sau luôn tải mới).
window.addEventListener('modal-closed', (e) => {
    if (e.detail !== 'remote') return;
    setTimeout(() => {
        const host = document.querySelector('[data-modal="remote"]');
        if (host && !window.Alpine?.$data(host).show) modalBody()?.replaceChildren();
    }, 200);
});

window.htmx = htmx;

export default htmx;
