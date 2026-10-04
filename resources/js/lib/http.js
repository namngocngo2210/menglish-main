/**
 * POST bằng fetch cho các chỗ cần phản hồi JSON mà không điều hướng trang (kéo-thả, tải file, modal lồng).
 * Gửi sẵn CSRF + `Accept: application/json`; lỗi mạng ném exception, lỗi HTTP trả `ok: false`.
 *   const { ok, status, data } = await postJson(route('x'), { ids });
 *   const { ok, data } = await postForm(route('y'), formData);       // FormData → trình duyệt tự đặt boundary
 *   toast(firstError(data, 'Có lỗi, vui lòng thử lại.'), 'error');
 * Form thường dùng `UiForm` / `router` của Inertia, không dùng file này.
 */
import { usePage } from '@inertiajs/vue3';

export function csrfToken() {
    return usePage().props.csrf ?? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function send(url, body, headers = {}) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken(), ...headers },
        body,
    });
    const data = await response.json().catch(() => ({}));
    return { ok: response.ok, status: response.status, data };
}

export function postJson(url, payload = null) {
    return send(url, payload === null ? null : JSON.stringify(payload), { 'Content-Type': 'application/json' });
}

export function postForm(url, formData) {
    return send(url, formData);
}

/** Thông báo lỗi đầu tiên trong phản hồi JSON của Laravel (`errors` theo field hoặc `message`). */
export function firstError(data, fallback = 'Có lỗi, vui lòng thử lại.') {
    return (data?.errors && Object.values(data.errors).flat()[0]) || data?.message || fallback;
}
