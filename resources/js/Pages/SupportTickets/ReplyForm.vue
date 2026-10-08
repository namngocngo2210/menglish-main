<script setup>
/**
 * Ô gửi phản hồi ticket — dùng chung trang và modal. Bấm Gửi → phản hồi hiện ngay cuối hội thoại với nhãn "Đang gửi…"
 * (pendingReplies), ô nhập được xoá để gõ tiếp; lưu ở nền bằng Inertia (thông báo + email server chạy sau khi trả phản hồi).
 *   - Lưu xong: hội thoại tải lại có phản hồi thật (trong modal: tải lại nội dung modal), khung tạm biến mất.
 *   - Lỗi: khung tạm chuyển "Chưa gửi được" + lý do, Gửi lại (gửi lại đúng dữ liệu cũ) / Bỏ (trả nội dung về ô nhập).
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { reloadRemoteModal } from '@/lib/remoteModal';
import { useRemoteModal } from '@/Components/ui/modalContext';
import { route } from '@/lib/route';
import AttachmentUploader from './AttachmentUploader.vue';
import { usePendingReplies } from './pendingReplies';

const props = defineProps({
    ticket: { type: Object, required: true },
    canPostInternal: { type: Boolean, default: false },
    asModal: { type: Boolean, default: false },
});

const STATUS_ERRORS = {
    401: 'Phiên đăng nhập đã hết, vui lòng tải lại trang.',
    403: 'Bạn không có quyền phản hồi ticket này.',
    404: 'Ticket không còn tồn tại.',
    413: 'Tệp đính kèm quá lớn.',
    419: 'Phiên làm việc đã hết hạn, vui lòng tải lại trang.',
};

const pending = usePendingReplies();
const modal = useRemoteModal();
const formEl = ref(null);
const uploader = ref(null);
let seq = 0;

function fileCount(data) {
    const files = data.getAll('attachments[]').filter((f) => f instanceof File && f.size > 0).length;
    let pasted = 0;
    try {
        pasted = JSON.parse(String(data.get('pasted_images') || '[]')).length;
    } catch {
        pasted = 0;
    }
    return files + pasted;
}

function submit() {
    const data = new FormData(formEl.value);
    const text = String(data.get('message') ?? '').trim();
    const box = formEl.value.elements.namedItem('message');
    if (!text) {
        box?.focus();
        return;
    }

    const item = { key: ++seq, text, internal: data.get('is_internal_note') === '1', fileCount: fileCount(data), state: 'sending', error: null, data };
    item.retry = () => send(item);
    item.discard = () => {
        remove(item);
        if (box && !box.value.trim()) box.value = text;
        box?.focus();
    };
    pending.push(item);

    formEl.value.reset();
    uploader.value?.reset();
    modal?.markClean(); // nội dung đã chuyển sang khung "Đang gửi…", ô nhập trống → đóng modal không hỏi "chưa lưu"
    send(item);
}

function find(item) {
    return pending.find((p) => p.key === item.key);
}

function remove(item) {
    const index = pending.findIndex((p) => p.key === item.key);
    if (index >= 0) pending.splice(index, 1);
}

function fail(item, message) {
    const entry = find(item);
    if (!entry) return;
    entry.state = 'failed';
    entry.error = message;
}

function send(item) {
    const entry = find(item);
    if (!entry) return;
    entry.state = 'sending';
    entry.error = null;

    router.post(route('tickets.messages.store', props.ticket.id), item.data, {
        headers: props.asModal ? { 'X-Remote-Modal': 'true' } : {},
        preserveScroll: true,
        preserveState: true,
        errorBag: 'ticketReply', // lỗi hiện trên khung tạm, không lặp lại dưới ô nhập
        onSuccess: async () => {
            if (props.asModal) await reloadRemoteModal();
            remove(item);
        },
        onError: (errors) => {
            const first = Object.values(errors)[0];
            fail(item, (Array.isArray(first) ? first[0] : first) || 'Dữ liệu chưa hợp lệ.');
        },
        onHttpException: (response) => {
            fail(item, STATUS_ERRORS[response.status] ?? `Có lỗi xảy ra (mã ${response.status}).`);
            return false;
        },
        onNetworkError: () => {
            fail(item, 'Không kết nối được máy chủ, kiểm tra mạng.');
            return false;
        },
    });
}
</script>

<template>
    <div class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm">
        <h3 class="mb-3 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-on-surface">
            <span class="material-symbols-outlined text-base text-primary">reply</span>
            Gửi phản hồi / Cập nhật tiến độ
        </h3>
        <form :id="asModal ? 'modal-ticket-reply-form' : 'ticket-reply-form'" ref="formEl" :action="route('tickets.messages.store', ticket.id)" method="post" class="space-y-3" @submit.prevent="submit">
            <UiField name="message">
                <UiTextarea name="message" :rows="3" required placeholder="Nhập câu trả lời hoặc tiến độ giải quyết vấn đề..." class="text-xs" />
            </UiField>

            <AttachmentUploader ref="uploader" compact />

            <div class="flex items-center justify-between pt-1">
                <label v-if="canPostInternal" class="flex cursor-pointer items-center gap-2 text-xs text-on-surface-variant">
                    <input type="checkbox" name="is_internal_note" value="1" class="rounded border-outline-variant text-warning focus:ring-warning" />
                    <span>Chỉ hiển thị nội bộ giữa các phòng ban</span>
                </label>
                <span v-else></span>
                <UiButton type="submit" size="sm" icon="send">Gửi phản hồi</UiButton>
            </div>
        </form>
    </div>
</template>
