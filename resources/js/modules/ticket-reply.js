/**
 * Alpine.data('ticketReply') — ô trả lời ticket (trang + modal), gồm luôn phần đính kèm (attachmentUploader).
 *
 * Bấm Gửi → bình luận hiện ngay cuối hội thoại ([data-ticket-messages] trong [data-ticket-thread]) với nhãn "Đang gửi…",
 * ô nhập được xoá để gõ tiếp; bình luận lưu ở nền bằng fetch (Accept: application/json), không tải lại trang / modal.
 *   - Lưu xong: thay khung tạm bằng bình luận server render (partials/message.blade.php); trạng thái ticket đổi theo
 *     → thay lại các vùng [data-ticket-part]; phát "tickets-changed" để danh sách phía sau modal làm mới.
 *   - Lỗi: khung tạm chuyển "Chưa gửi được" + lý do, nút Gửi lại (gửi lại đúng dữ liệu cũ) / Bỏ (trả nội dung về ô nhập).
 * Khung tạm lấy từ <template data-pending-message="public|internal"> trong ô trả lời (cùng partial với bình luận thật).
 */
import attachmentUploader from './attachment-uploader';

const STATUS_ERRORS = {
    401: 'Phiên đăng nhập đã hết, vui lòng tải lại trang.',
    403: 'Bạn không có quyền phản hồi ticket này.',
    404: 'Ticket không còn tồn tại.',
    413: 'Tệp đính kèm quá lớn.',
    419: 'Phiên làm việc đã hết hạn, vui lòng tải lại trang.',
};

function errorMessage(status, body) {
    if (status === 422) {
        const first = Object.values(body?.errors ?? {})[0];
        return (Array.isArray(first) ? first[0] : first) || body?.message || 'Dữ liệu chưa hợp lệ.';
    }

    return STATUS_ERRORS[status] ?? `Có lỗi xảy ra (mã ${status}).`;
}

function fragment(html) {
    const tpl = document.createElement('template');
    tpl.innerHTML = String(html ?? '').trim();

    return tpl.content;
}

export default function ticketReply() {
    return {
        ...attachmentUploader(),

        submit(event) {
            const form = event.target;
            const data = new FormData(form);
            const text = String(data.get('message') ?? '').trim();
            const box = form.elements.namedItem('message');
            if (!text) {
                box?.focus();
                return;
            }

            const fileCount = data.getAll('attachments[]').filter((f) => f instanceof File && f.size > 0).length + this.pastedImages.length;
            const card = this.addPendingCard(text, data.get('is_internal_note') === '1', fileCount);
            if (!card) {
                // Không tìm thấy khung hội thoại (không nên xảy ra) → gửi form thường.
                form.submit();
                return;
            }

            this.clearForm(form);
            card.querySelector('[data-message-retry]')?.addEventListener('click', () => this.send(form.action, data, card));
            card.querySelector('[data-message-discard]')?.addEventListener('click', () => {
                card.remove();
                if (box && !box.value.trim()) box.value = text;
                box?.focus();
            });
            this.send(form.action, data, card);
        },

        addPendingCard(text, internal, fileCount) {
            const tpl = this.$root.querySelector(`template[data-pending-message="${internal ? 'internal' : 'public'}"]`);
            const list = this.$root.closest('[data-ticket-thread]')?.querySelector('[data-ticket-messages]');
            if (!tpl || !list) return null;

            const card = tpl.content.firstElementChild.cloneNode(true);
            card.querySelector('[data-message-body]').textContent = text;
            if (fileCount > 0) card.querySelector('[data-message-sending-label]').textContent = `Đang gửi kèm ${fileCount} tệp…`;
            list.append(card);
            card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });

            return card;
        },

        clearForm(form) {
            form.elements.namedItem('message').value = '';
            if (this.$refs.fileInput) this.$refs.fileInput.value = '';
            this.previews = [];
            this.pastedImages = [];
            const internal = form.elements.namedItem('is_internal_note');
            if (internal) internal.checked = false;
        },

        async send(url, data, card) {
            card.dataset.state = 'sending';
            let response;
            let body = {};
            try {
                response = await fetch(url, {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                body = await response.json().catch(() => ({}));
            } catch {
                this.fail(card, 'Không kết nối được máy chủ, kiểm tra mạng.');
                return;
            }

            if (!response.ok) {
                this.fail(card, errorMessage(response.status, body));
                return;
            }

            card.replaceWith(fragment(body.html));
            Object.entries(body.parts ?? {}).forEach(([name, html]) => {
                document.querySelectorAll(`[data-ticket-part="${name}"]`).forEach((el) => {
                    el.replaceChildren(fragment(html));
                    window.htmx?.process(el);
                });
            });
            document.body.dispatchEvent(new CustomEvent('tickets-changed', { bubbles: true }));
        },

        fail(card, message) {
            card.dataset.state = 'failed';
            card.querySelector('[data-message-error]').textContent = `Chưa gửi được: ${message}`;
        },
    };
}
