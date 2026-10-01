<script setup>
/**
 * Chi tiết order học liệu (modal; mở thẳng URL → trang đầy đủ). Người xử lý đúng loại + chi nhánh có thể
 * Nhận xử lý / Hoàn thành / Từ chối (lý do bắt buộc). Order đã Quá hạn vẫn xử lý được (ghi nhận xử lý trễ).
 */
import { ref } from 'vue';

defineOptions({ layout: (props) => ({ title: props.order?.title }) });

defineProps({
    order: { type: Object, required: true },
    actions: { type: Object, default: () => ({}) },
    asModal: { type: Boolean, default: false },
});

const statusColors = { pending: 'status-new', processing: 'status-progress', done: 'status-done', rejected: 'status-canceled', overdue: 'status-overdue' };
const deadline = {
    ok: { color: 'success', label: 'Còn hạn' },
    soon: { color: 'warning', label: 'Sắp hết hạn' },
    overdue: { color: 'error', label: 'Quá hạn' },
};
const rejecting = ref(false);
</script>

<template>
    <UiModalFrame :title="order.title" :description="`Order ${order.code}`" :cancel="asModal ? 'Đóng' : false" :back="route('material-orders.index')" size="lg">
        <div class="space-y-lg" data-testid="material-order-detail">
            <div class="flex flex-wrap items-center gap-sm">
                <UiBadge :color="statusColors[order.status] ?? 'neutral'">{{ order.status_label }}</UiBadge>
                <UiBadge color="secondary" :dot="false">{{ order.category_label }}</UiBadge>
                <UiBadge v-if="order.deadline_state" :color="deadline[order.deadline_state].color">{{ deadline[order.deadline_state].label }}</UiBadge>
                <UiBadge v-if="order.created_late" color="warning" :dot="false">Tạo trễ</UiBadge>
                <UiBadge v-if="order.processed_late" color="error" :dot="false">Xử lý trễ</UiBadge>
            </div>

            <dl class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <div><dt class="font-caption text-caption text-on-surface-variant">Người tạo</dt><dd>{{ order.requester }}</dd></div>
                <div><dt class="font-caption text-caption text-on-surface-variant">Chi nhánh</dt><dd>{{ order.branch ?? '—' }}</dd></div>
                <div><dt class="font-caption text-caption text-on-surface-variant">Lớp</dt><dd>{{ order.class_name ?? '—' }}</dd></div>
                <div><dt class="font-caption text-caption text-on-surface-variant">Số lượng</dt><dd>{{ order.quantity ?? '—' }}</dd></div>
                <div><dt class="font-caption text-caption text-on-surface-variant">Ngày sử dụng</dt><dd>{{ formatDate(order.use_date) }}</dd></div>
                <div><dt class="font-caption text-caption text-on-surface-variant">Hạn xử lý</dt><dd>{{ formatDate(order.due_at, 'd/m/Y H:i') }}</dd></div>
                <div v-if="order.processed_by"><dt class="font-caption text-caption text-on-surface-variant">Người xử lý</dt><dd>{{ order.processed_by }}</dd></div>
                <div v-if="order.processed_at"><dt class="font-caption text-caption text-on-surface-variant">Xử lý lúc</dt><dd>{{ formatDate(order.processed_at, 'd/m/Y H:i') }}</dd></div>
            </dl>

            <div v-if="order.description" class="rounded-lg bg-surface-container-low p-md">
                <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Mô tả</p>
                <p class="whitespace-pre-line font-body-base text-body-base text-on-surface">{{ order.description }}</p>
            </div>
            <UiAlert v-if="order.reject_reason" type="error" title="Lý do từ chối">{{ order.reject_reason }}</UiAlert>
            <UiAlert v-if="order.processor_note" type="info" title="Ghi chú của người xử lý">{{ order.processor_note }}</UiAlert>

            <div v-if="actions.claim || actions.complete || actions.reject" class="space-y-md rounded-lg border border-outline-variant p-md">
                <p class="font-body-medium text-body-medium font-semibold text-on-surface">Xử lý order</p>
                <UiForm v-if="actions.claim" :action="route('material-orders.claim', order.id)" method="post">
                    <UiButton type="submit" variant="secondary" icon="play_arrow">Nhận xử lý</UiButton>
                </UiForm>
                <UiForm v-if="actions.complete" :action="route('material-orders.complete', order.id)" method="post" class="space-y-sm">
                    <UiTextarea name="processor_note" label="Ghi chú xử lý" :rows="2" maxlength="2000" />
                    <div class="flex flex-wrap justify-end gap-sm">
                        <UiButton v-if="actions.reject" variant="danger-text" icon="block" @click="rejecting = true">Từ chối</UiButton>
                        <UiButton type="submit" icon="check">Hoàn thành</UiButton>
                    </div>
                </UiForm>
                <UiButton v-else-if="actions.reject" variant="danger-text" icon="block" @click="rejecting = true">Từ chối</UiButton>
            </div>
        </div>

        <UiModal :show="rejecting" title="Từ chối order" max-width="md" @close="rejecting = false">
            <UiForm :action="route('material-orders.reject', order.id)" method="post" class="space-y-md">
                <UiTextarea name="reject_reason" label="Lý do từ chối" required :rows="3" maxlength="2000" />
                <div class="flex justify-end gap-sm">
                    <UiButton variant="secondary" @click="rejecting = false">Hủy</UiButton>
                    <UiButton type="submit" variant="danger" icon="block">Từ chối order</UiButton>
                </div>
            </UiForm>
        </UiModal>
    </UiModalFrame>
</template>
