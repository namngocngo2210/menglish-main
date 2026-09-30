<script setup>
/**
 * Ô gửi phản hồi ticket — dùng chung trang và modal. Trong modal (`stay`): gửi xong modal giữ mở, hội thoại tải lại
 * có phản hồi mới; trang nền (danh sách) cập nhật + thông báo. Gửi xong xoá nội dung + file đính kèm.
 */
import { ref } from 'vue';
import AttachmentUploader from './AttachmentUploader.vue';

defineProps({
    ticket: { type: Object, required: true },
    canPostInternal: { type: Boolean, default: false },
    asModal: { type: Boolean, default: false },
});

const uploader = ref(null);
</script>

<template>
    <div class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm">
        <h3 class="mb-3 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-on-surface">
            <span class="material-symbols-outlined text-base text-primary">reply</span>
            Gửi phản hồi / Cập nhật tiến độ
        </h3>
        <UiForm :id="asModal ? 'modal-ticket-reply-form' : 'ticket-reply-form'" :action="route('tickets.messages.store', ticket.id)" method="post" class="space-y-3" stay reset-on-success @success="uploader?.reset()">
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
        </UiForm>
    </div>
</template>
