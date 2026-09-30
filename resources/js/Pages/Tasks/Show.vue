<script setup>
/**
 * Chi tiết công việc: mở từ danh sách → modal xem nhanh 2xl; mở thẳng link → trang đầy đủ.
 * Đổi trạng thái ngay tại đây (chỉ các bước server cho phép): lưu xong đóng modal, danh sách nền tự làm mới.
 */
import { computed, ref, watch } from 'vue';

defineOptions({ layout: (props) => ({ title: props.task?.title }) });

const props = defineProps({
    task: { type: Object, required: true },
    allowed: { type: Array, default: () => [] },
    asModal: { type: Boolean, default: false },
});

const statusColors = {
    new: 'status-new', in_progress: 'status-progress', pending_confirmation: 'status-pending',
    blocked: 'status-blocked', completed: 'status-done', overdue: 'status-overdue', canceled: 'status-canceled',
};
const next = ref(props.allowed[0]?.value ?? '');
// Trang đầy đủ: lưu xong server trả lại trang với các bước mới → chọn lại bước đầu.
watch(
    () => props.allowed,
    (list) => {
        if (!list.some((o) => o.value === next.value)) next.value = list[0]?.value ?? '';
    },
);
const reasonRequired = computed(() => ['blocked', 'canceled'].includes(next.value));
const formId = computed(() => (props.asModal ? 'modal-task-status-form' : 'task-status-form'));
</script>

<template>
    <UiModalFrame :title="task.title" :description="`Công việc #${task.id}`" :cancel="asModal ? 'Đóng' : false" :back="route('tasks.index')" size="2xl">
        <div class="space-y-lg" data-testid="task-detail">
            <div class="flex flex-wrap items-center gap-sm">
                <UiBadge :color="statusColors[task.status] ?? 'neutral'">{{ task.status_label }}</UiBadge>
                <span v-if="task.slot_label" class="rounded bg-surface-container-high px-sm font-caption text-caption text-on-surface-variant">{{ task.slot_label }}</span>
            </div>

            <dl class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <div v-for="[icon, label, value] in task.rows" :key="label" class="flex items-start gap-sm">
                    <span class="material-symbols-outlined mt-0.5 text-[18px] text-on-surface-variant" aria-hidden="true">{{ icon }}</span>
                    <div class="min-w-0">
                        <dt class="font-caption text-caption text-on-surface-variant">{{ label }}</dt>
                        <dd class="break-words font-body-medium text-body-medium text-on-surface">{{ value }}</dd>
                    </div>
                </div>
            </dl>

            <div v-if="task.description" class="rounded-lg bg-surface-container-low p-md">
                <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Mô tả</p>
                <p class="whitespace-pre-line font-body-base text-body-base text-on-surface">{{ task.description }}</p>
            </div>
            <UiAlert v-for="note in task.notes" :key="note.title" :type="note.type" :title="note.title">{{ note.text }}</UiAlert>

            <UiForm v-if="allowed.length" :id="formId" :action="route('tasks.status.update', task.id)" method="post" class="space-y-md rounded-lg border border-outline-variant p-md" reset-on-success #default="{ errors }">
                <UiAlert v-if="errors.status" type="error">{{ errors.status }}</UiAlert>
                <p class="font-body-medium text-body-medium font-semibold text-on-surface">Cập nhật trạng thái</p>
                <div class="flex flex-wrap gap-sm" role="radiogroup" aria-label="Trạng thái mới">
                    <label
                        v-for="opt in allowed"
                        :key="opt.value"
                        :class="[
                            'inline-flex cursor-pointer items-center gap-xs rounded-lg border px-sm py-xs font-body-small text-body-small',
                            next === opt.value ? 'border-primary-container bg-primary-container/10 text-primary' : 'border-outline-variant text-on-surface-variant',
                        ]"
                    >
                        <input v-model="next" type="radio" name="status" :value="opt.value" class="sr-only" />{{ opt.label }}
                    </label>
                </div>
                <UiTextarea :id="(asModal ? 'modal-' : '') + 'task-status-reason'" name="reason" :rows="2" maxlength="1000" :required="reasonRequired" label="Ghi chú lý do / kết quả" hint="Bắt buộc khi chuyển Bị chặn hoặc Hủy công việc." />
                <div v-if="!asModal" class="flex justify-end"><UiButton type="submit" icon="check">Cập nhật trạng thái</UiButton></div>
            </UiForm>
        </div>

        <template v-if="asModal" #footer>
            <UiButton variant="secondary" icon="open_in_new" :href="route('tasks.show', task.id)" native>Mở trang đầy đủ</UiButton>
            <UiButton v-if="allowed.length" type="submit" :form="formId" icon="check">Cập nhật trạng thái</UiButton>
        </template>
    </UiModalFrame>
</template>
