/**
 * Toast toàn cục (ToastHost trong AppLayout).
 *   toast('Đã lưu')                      // type: success (mặc định) | error | warning | info
 *   toast('Có lỗi xảy ra', 'error')
 * Thông báo flash từ server (session success/status/error/warning/info) tự hiện qua shared prop `flash`.
 */
import { reactive } from 'vue';

export const toasts = reactive([]);
let seq = 0;

export function toast(message, type = 'success', { timeout = 5000 } = {}) {
    if (!message) return;
    const id = ++seq;
    toasts.push({ id, message: String(message), type });
    if (typeof window !== 'undefined' && timeout) window.setTimeout(() => dismissToast(id), timeout);
}

export function dismissToast(id) {
    const index = toasts.findIndex((t) => t.id === id);
    if (index !== -1) toasts.splice(index, 1);
}

if (typeof window !== 'undefined') {
    // Mã cũ / thư viện ngoài: window.dispatchEvent(new CustomEvent('toast', { detail: { message, type } }))
    window.addEventListener('toast', (event) => toast(event.detail?.message, event.detail?.type || 'success'));
}
