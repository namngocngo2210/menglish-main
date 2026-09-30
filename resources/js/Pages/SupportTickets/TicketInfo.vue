<script setup>
/** Thông tin ticket + phân công người xử lý (quyền support_ticket.assign) — dùng chung trang và modal. */
defineProps({
    ticket: { type: Object, required: true },
    staffs: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="space-y-4">
        <div class="space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 text-xs shadow-sm">
            <h3 class="border-b border-surface-container-highest pb-2 font-bold uppercase tracking-wider text-on-surface">Thông tin Ticket</h3>

            <div class="space-y-3">
                <div class="flex justify-between">
                    <span class="text-on-surface-variant">Danh mục:</span>
                    <span class="font-bold text-on-surface">{{ ticket.category_label }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-on-surface-variant">Mức độ ưu tiên:</span>
                    <span :class="['rounded-full border px-2 py-0.5 text-xs', ticket.priority_badge]">{{ ticket.priority.toUpperCase() }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-on-surface-variant">Trạng thái:</span>
                    <span :class="['rounded-full border px-2 py-0.5 text-xs font-bold', ticket.status_badge]">{{ ticket.status_label }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-on-surface-variant">Người tạo:</span>
                    <span class="font-bold text-on-surface">{{ ticket.creator }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-on-surface-variant">Ngày tạo:</span>
                    <span class="font-mono text-on-surface-variant">{{ formatDate(ticket.created_at, 'd/m/Y H:i') }}</span>
                </div>
            </div>

            <div v-if="can('support_ticket.assign')" class="space-y-2 border-t border-surface-container-highest pt-3">
                <label :for="`ticket-assignee-${ticket.id}`" class="block font-bold text-on-surface">Người phụ trách xử lý:</label>
                <UiForm v-slot="{ errors }" :action="route('tickets.assign', ticket.id)" method="post" class="space-y-2" stay>
                    <UiSelect :id="`ticket-assignee-${ticket.id}`" name="assignee_id" class="text-xs" placeholder="-- Chưa phân công --" :value="ticket.assignee_id ?? ''" :options="staffs" />
                    <UiErrors :messages="errors.assignee_id" />
                    <UiButton type="submit" variant="secondary" size="sm" class="w-full">Cập nhật Phân công</UiButton>
                </UiForm>
            </div>
            <div v-else class="flex justify-between border-t border-surface-container-highest pt-3">
                <span class="text-on-surface-variant">Người phụ trách xử lý:</span>
                <span class="font-bold text-on-surface">{{ ticket.assignee ?? 'Chưa phân công' }}</span>
            </div>
        </div>
    </div>
</template>
