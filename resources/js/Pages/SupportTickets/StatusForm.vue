<script setup>
/**
 * Đổi trạng thái ticket (chọn là gửi ngay) — cần quyền support_ticket.close; người khác chỉ xem trạng thái.
 * Trong modal: `stay` → modal giữ mở, tải lại nội dung mới.
 */
defineProps({ ticket: { type: Object, required: true } });

const labels = { open: 'Mới tiếp nhận (Open)', in_progress: 'Đang xử lý (In Progress)', resolved: 'Đã giải quyết (Resolved)', closed: 'Đã đóng (Closed)' };
</script>

<template>
    <UiForm v-if="can('support_ticket.close')" :action="route('tickets.status.update', ticket.id)" method="post" class="flex items-center gap-1.5" stay>
        <select name="status" aria-label="Trạng thái ticket" :class="['rounded-xl border border-surface-container-highest p-2 text-xs font-bold', ticket.status_badge]" @change="$event.target.form.requestSubmit()">
            <option v-for="(label, value) in labels" :key="value" :value="value" :selected="ticket.status === value">{{ label }}</option>
        </select>
    </UiForm>
    <span v-else :class="['inline-flex items-center rounded-xl border border-surface-container-highest p-2 text-xs font-bold', ticket.status_badge]">{{ labels[ticket.status] ?? ticket.status }}</span>
</template>
