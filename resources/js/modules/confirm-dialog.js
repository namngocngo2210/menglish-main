/**
 * Hộp xác nhận chung của hệ thống (thay cho window.confirm của trình duyệt).
 *
 * - Form: <form data-confirm="Xóa khóa học A?" data-confirm-label="Xóa" data-confirm-danger> … </form>
 *     data-confirm        nội dung câu hỏi (bắt buộc; Blade tự escape nên an toàn khi chèn tên, mã…)
 *     data-confirm-title  tiêu đề (mặc định "Xác nhận thao tác")
 *     data-confirm-label  nhãn nút đồng ý (mặc định "Đồng ý")
 *     data-confirm-danger có mặt => nút đồng ý màu đỏ (thao tác xóa / hủy)
 * - JS / Alpine: if (await window.confirmDialog({ message: 'Bỏ các thay đổi chưa lưu?' })) { … }
 *
 * Hộp thoại gắn vào modal đang mở (nếu có) để không bị khóa focus của modal (x-trap) chặn; Esc / bấm nền = Hủy.
 */

const DEFAULT_TITLE = 'Xác nhận thao tác';

function openHost() {
    // Modal đang mở gần nhất (panel có x-trap) — hộp xác nhận phải nằm trong vùng giữ focus của nó.
    const panels = [...document.querySelectorAll('[data-modal] [x-trap\\.noscroll], [data-modal] [x-trap]')];
    return panels.reverse().find((el) => el.getClientRects().length > 0) ?? document.body;
}

function button(label, classes) {
    const el = document.createElement('button');
    el.type = 'button';
    el.textContent = label;
    el.className = 'inline-flex min-h-11 items-center justify-center rounded-lg px-md py-sm font-body-medium text-body-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40 md:min-h-0 ' + classes;
    return el;
}

export function confirmDialog({ title = DEFAULT_TITLE, message = '', confirmLabel = 'Đồng ý', cancelLabel = 'Hủy', danger = false } = {}) {
    return new Promise((resolve) => {
        const previousFocus = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'fixed inset-0 z-[70] flex items-end justify-center bg-on-surface/40 p-md sm:items-center';
        overlay.setAttribute('data-confirm-dialog', '');

        const panel = document.createElement('div');
        panel.className = 'w-full max-w-md rounded-xl bg-surface-container-lowest p-lg text-left shadow-level-3';
        panel.setAttribute('role', 'alertdialog');
        panel.setAttribute('aria-modal', 'true');
        const titleId = 'confirm-dialog-title-' + Date.now();
        const messageId = titleId.replace('title', 'message');
        panel.setAttribute('aria-labelledby', titleId);
        panel.setAttribute('aria-describedby', messageId);

        const heading = document.createElement('h2');
        heading.id = titleId;
        heading.className = 'font-h3 text-h3 text-on-surface';
        heading.textContent = title;

        const body = document.createElement('p');
        body.id = messageId;
        body.className = 'mt-sm whitespace-pre-line font-body-base text-body-base text-on-surface-variant';
        body.textContent = message;

        const actions = document.createElement('div');
        actions.className = 'mt-lg flex flex-col-reverse gap-sm sm:flex-row sm:justify-end';
        const cancel = button(cancelLabel, 'border border-outline-variant bg-surface-container-lowest text-on-surface hover:bg-surface-container-low');
        const ok = button(confirmLabel, danger ? 'bg-error text-white hover:bg-on-error-container' : 'bg-primary-container text-white hover:bg-primary');
        actions.append(cancel, ok);
        panel.append(heading, body, actions);
        overlay.append(panel);

        const close = (result) => {
            overlay.remove();
            previousFocus?.focus?.({ preventScroll: true });
            resolve(result);
        };
        cancel.addEventListener('click', () => close(false));
        ok.addEventListener('click', () => close(true));
        overlay.addEventListener('click', (e) => e.target === overlay && close(false));
        overlay.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                // Không để Esc lan ra modal / trang bên dưới.
                e.stopPropagation();
                close(false);
            } else if (e.key === 'Tab') {
                // Giữ focus trong 2 nút.
                const [first, last] = [cancel, ok];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        });

        openHost().append(overlay);
        // Thao tác nguy hiểm: focus nút Hủy trước để Enter không xóa nhầm.
        (danger ? cancel : ok).focus();
    });
}

/** Chặn submit của form có data-confirm cho tới khi người dùng đồng ý (chạy ở pha capture, trước các listener khác). */
export function registerFormConfirm() {
    document.addEventListener('submit', async (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (!form?.hasAttribute('data-confirm') || form.dataset.confirmed === '1') return;

        event.preventDefault();
        event.stopImmediatePropagation();
        const submitter = event.submitter ?? null;
        const agreed = await confirmDialog({
            title: form.dataset.confirmTitle || DEFAULT_TITLE,
            message: form.dataset.confirm,
            confirmLabel: form.dataset.confirmLabel || 'Đồng ý',
            danger: form.hasAttribute('data-confirm-danger'),
        });
        if (!agreed) return;

        form.dataset.confirmed = '1';
        form.requestSubmit(submitter && form.contains(submitter) ? submitter : undefined);
        delete form.dataset.confirmed;
    }, true);
}
