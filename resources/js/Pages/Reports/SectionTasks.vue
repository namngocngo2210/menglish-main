<script setup>
/**
 * Task follow-up trong một khối báo cáo (chủ dự án 17/10/2026): task đã tạo ở khối này, việc chưa xong từ báo cáo kỳ trước
 * (nhãn "Từ dd/mm", hiện lại cho tới khi hoàn thành / hủy) và các dòng task mới. Dòng mới gửi cùng form báo cáo theo
 * `tasks[i][…]` với `i` là thứ tự chung của cả form, kèm khối (`section`) và lớp (`class_id`, khi tạo từ một buổi học).
 * `section` rỗng = task chung, không thuộc khối nào. `readonly` = chỉ xem (chi tiết báo cáo của Admin).
 */
defineProps({
    section: { type: String, default: '' },
    title: { type: String, default: 'Task follow-up' },
    tasks: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    indexOf: { type: Function, default: () => 0 },
    assignees: { type: Array, default: () => [] },
    today: { type: String, default: null },
    hint: { type: String, default: null },
    readonly: { type: Boolean, default: false },
});
const emit = defineEmits(['add', 'remove']);

const statusColor = (t) => (t.overdue ? 'error' : t.status === 'completed' ? 'success' : t.status === 'canceled' ? 'neutral' : 'info');
</script>

<template>
    <div class="space-y-sm border-t border-surface-variant pt-md" :data-section-tasks="section || 'general'">
        <div class="flex flex-wrap items-center justify-between gap-sm">
            <p class="flex items-center gap-xs font-label text-label uppercase text-on-surface-variant">
                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">assignment_turned_in</span>{{ title }}
                <span v-if="tasks.length" class="normal-case">· {{ tasks.length }}</span>
            </p>
            <UiButton v-if="!readonly" type="button" variant="ghost" size="sm" icon="add" data-section-task-add @click="emit('add')">Thêm task</UiButton>
        </div>
        <ul v-if="tasks.length" class="space-y-xs" data-section-task-list>
            <li
                v-for="t in tasks"
                :key="t.id"
                class="flex flex-wrap items-center gap-x-sm gap-y-0.5 rounded-lg bg-surface-container-low px-md py-sm font-body-small text-body-small text-on-surface"
                :data-section-task="t.id"
            >
                <UiBadge v-if="t.carried" color="warning" :dot="false" data-task-carried>Từ {{ t.carried }}</UiBadge>
                <strong>{{ t.title }}</strong>
                <span class="text-on-surface-variant">· {{ t.assignee ?? '—' }} · hạn {{ formatDate(t.due_date, 'd/m/Y') }}</span>
                <UiBadge :color="statusColor(t)">{{ t.overdue && t.status !== 'overdue' ? 'Quá hạn' : t.status_label }}</UiBadge>
            </li>
        </ul>
        <p v-else-if="!rows.length && hint" class="font-body-small text-body-small text-on-surface-variant">{{ hint }}</p>
        <div v-for="t in rows" :key="t.uid" class="space-y-md rounded-lg border border-surface-variant bg-surface-container-lowest p-md" data-daily-task-row>
            <input type="hidden" :name="`tasks[${indexOf(t)}][section]`" :value="section" />
            <input v-if="t.class_id" type="hidden" :name="`tasks[${indexOf(t)}][class_id]`" :value="t.class_id" />
            <div class="flex items-start gap-md">
                <div class="min-w-0 flex-1">
                    <UiInput :name="`tasks[${indexOf(t)}][title]`" label="Tiêu đề task" required :value="t.title ?? null" placeholder="Vd: Gọi lại phụ huynh bé An về học bù" />
                </div>
                <UiButton v-if="!readonly" type="button" variant="ghost" size="sm" icon="close" class="mt-lg" :aria-label="`Bỏ task ${indexOf(t) + 1}`" @click="emit('remove', t)" />
            </div>
            <UiTextarea :name="`tasks[${indexOf(t)}][content]`" label="Nội dung (tùy chọn)" :rows="2" />
            <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                <UiDate :name="`tasks[${indexOf(t)}][due_date]`" label="Hạn xử lý" required icon="calendar_today" :value="today" :min="today" />
                <UiSelect :name="`tasks[${indexOf(t)}][assignee_id]`" label="Người phụ trách (PIC)" :options="assignees" :value="assignees[0]?.value" :searchable="assignees.length > 8" />
            </div>
        </div>
    </div>
</template>
