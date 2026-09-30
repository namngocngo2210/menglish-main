/**
 * Phản hồi ticket đang gửi (hiện ngay cuối hội thoại, trước khi server lưu xong). Show.vue tạo danh sách (providePendingReplies),
 * ReplyForm thêm / cập nhật, TicketMessages hiển thị khung "Đang gửi…" / "Chưa gửi được" + Gửi lại / Bỏ.
 * Mỗi mục: { key, text, internal, fileCount, state: 'sending' | 'failed', error, data (FormData để gửi lại) }.
 */
import { inject, provide, reactive } from 'vue';

const KEY = Symbol('ticket-pending-replies');

export function providePendingReplies() {
    const list = reactive([]);
    provide(KEY, list);
    return list;
}

export function usePendingReplies() {
    return inject(KEY, reactive([]));
}
