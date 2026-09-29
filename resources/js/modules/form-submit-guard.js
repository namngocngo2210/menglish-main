/**
 * Chống bấm gửi 2 lần: form POST (hoặc PUT/PATCH/DELETE qua _method) đang gửi thì khóa nút gửi và hiện "đang xử lý".
 *
 * - Áp cho mọi form gửi thường (điều hướng trang). Bỏ qua: form GET (lọc / tìm kiếm), form mở tab khác (target),
 *   form có data-no-submit-guard, form htmx (htmx tự xử lý — xem phần htmx bên dưới).
 * - Nút được khóa SAU khi trình duyệt đã lấy dữ liệu form (setTimeout 0), nên name/value của nút bấm vẫn được gửi.
 * - Quay lại trang bằng nút Back (bfcache) → mở khóa.
 * - Request htmx: phần tử gửi request có nút submit thì khóa trong lúc chờ phản hồi.
 */

const SPINNER = 'progress_activity';

function lock(button) {
    if (!button || button.dataset.submitting === '1') return;
    button.dataset.submitting = '1';
    button.setAttribute('aria-busy', 'true');
    button.disabled = true;
    const icon = button.querySelector('.material-symbols-outlined');
    if (icon) {
        button.dataset.iconBefore = icon.textContent;
        icon.textContent = SPINNER;
        icon.classList.add('animate-spin');
    }
}

function unlock(button) {
    if (!button || button.dataset.submitting !== '1') return;
    delete button.dataset.submitting;
    button.removeAttribute('aria-busy');
    button.disabled = false;
    const icon = button.querySelector('.material-symbols-outlined');
    if (icon && button.dataset.iconBefore !== undefined) {
        icon.textContent = button.dataset.iconBefore;
        icon.classList.remove('animate-spin');
        delete button.dataset.iconBefore;
    }
}

const submitButtons = (form) => [...form.querySelectorAll('button[type=submit], button:not([type]), input[type=submit]'),
    ...(form.id ? document.querySelectorAll(`button[form="${CSS.escape(form.id)}"], input[type=submit][form="${CSS.escape(form.id)}"]`) : [])];

export function registerFormSubmitGuard() {
    document.addEventListener('submit', (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (!form || event.defaultPrevented) return;
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
        if (form.hasAttribute('data-no-submit-guard') || (form.target && form.target !== '_self')) return;
        if (form.hasAttribute('hx-post') || form.hasAttribute('hx-put') || form.hasAttribute('hx-patch') || form.hasAttribute('hx-delete')) return;

        const buttons = event.submitter ? [event.submitter] : submitButtons(form);
        setTimeout(() => buttons.forEach(lock), 0);
    });

    // Back / Forward trả lại trang từ bfcache: mở khóa mọi nút còn đang khóa.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) document.querySelectorAll('[data-submitting="1"]').forEach(unlock);
    });

    // htmx: khóa nút submit của phần tử gửi request trong lúc chờ.
    document.addEventListener('htmx:beforeRequest', (event) => {
        const elt = event.detail.elt;
        if (elt instanceof HTMLFormElement) submitButtons(elt).forEach(lock);
    });
    document.addEventListener('htmx:afterRequest', (event) => {
        const elt = event.detail.elt;
        if (elt instanceof HTMLFormElement) submitButtons(elt).forEach(unlock);
    });
}
