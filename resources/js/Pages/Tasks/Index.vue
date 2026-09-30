<script setup>
/**
 * Danh sách công việc — giao việc 2 chiều, đổi trạng thái theo luật (allowedTransitions tính ở server).
 * "Giao việc" mở modal 2xl (có lựa chọn "Giao cho: Trợ giảng"); bấm tiêu đề → modal xem nhanh chi tiết.
 * Lưu xong trong modal: server trả lại trang này → danh sách tự làm mới (giữ tab, bộ lọc, trang hiện tại).
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { openRemoteModal } from '@/lib/remoteModal';
import { urlWith } from '@/lib/url';
import { can } from '@/lib/can';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Danh sách công việc' } });

const props = defineProps({
    tasks: { type: Object, required: true },
    tab: { type: String, default: 'mine' },
    status: { type: String, default: 'all' },
    taskType: { type: String, default: 'all' },
    counts: { type: Object, required: true },
    canViewAll: { type: Boolean, default: false },
});

const statuses = {
    all: 'Tất cả', overdue: 'Quá hạn', blocked: 'Bị chặn', pending_confirmation: 'Chờ xác nhận',
    in_progress: 'Đang thực hiện', new: 'Mới', completed: 'Hoàn thành', canceled: 'Đã hủy',
};
const statusColors = {
    new: 'status-new', in_progress: 'status-progress', pending_confirmation: 'status-pending',
    blocked: 'status-blocked', completed: 'status-done', overdue: 'status-overdue', canceled: 'status-canceled',
};
const transitionLabels = {
    in_progress: ['Đang thực hiện', 'play_arrow'], blocked: ['Bị chặn', 'block'],
    pending_confirmation: ['Gửi chờ xác nhận', 'outgoing_mail'], completed: ['Xác nhận hoàn thành', 'check_circle'],
    canceled: ['Hủy công việc', 'cancel'],
};
const taskTypes = [
    { value: 'one_time', label: 'Phát sinh' },
    { value: 'recurring', label: 'Lặp đi lặp lại' },
];
const canCreate = computed(() => can('work_task.create') || can('work_task.request'));

/** Nhãn + icon của một bước chuyển (việc chờ xác nhận trả về "Đang thực hiện" = "Trả về làm tiếp"). */
function transition(task, next) {
    const [label, icon] = transitionLabels[next] ?? [next, 'arrow_forward'];
    return { label: next === 'in_progress' && task.status === 'pending_confirmation' ? 'Trả về làm tiếp' : label, icon };
}

// Modal "Thay đổi trạng thái"
const current = ref(null);
const newStatus = ref('');
const statusLabel = ref('');
const reasonRequired = computed(() => ['blocked', 'canceled'].includes(newStatus.value));
function openStatusModal(task, next) {
    current.value = task;
    newStatus.value = next;
    statusLabel.value = transition(task, next).label;
}

/** Bấm tiêu đề → modal xem nhanh; Ctrl/⌘-click / chuột giữa → mở trang đầy đủ ở tab mới như link thường. */
function openTask(event, task) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.button === 1) return;
    event.preventDefault();
    openRemoteModal(route('tasks.show', task.id), { size: '2xl' });
}
</script>

<template>
    <UiPageHeader title="Danh sách công việc" description="Quản lý, phân công và theo dõi tiến độ công việc — giao việc hai chiều.">
        <template #actions>
            <UiButton v-if="can('work_task.approve')" variant="secondary" icon="fact_check" :href="route('tasks.manual-approvals')">
                Chờ xác nhận
                <span v-if="counts.pending > 0" class="rounded-full bg-error px-1.5 font-code text-caption text-white">{{ counts.pending }}</span>
            </UiButton>
            <UiButton v-if="canCreate" icon="add" :href="route('tasks.create')" modal="2xl">
                {{ can('work_task.create') ? 'Giao việc' : 'Đề xuất việc cho Admin / Học vụ' }}
            </UiButton>
        </template>
    </UiPageHeader>

    <div id="task-list">
        <div class="mb-md grid grid-cols-2 gap-md sm:grid-cols-4">
            <UiStatCard label="Tất cả công việc" :value="counts.all" icon="assignment" />
            <UiStatCard label="Việc của tôi" :value="counts.mine" icon="person" tone="primary" />
            <UiStatCard label="Chờ xác nhận" :value="counts.pending" icon="pending_actions" tone="warning" />
            <UiStatCard label="Quá hạn" :value="counts.overdue" icon="warning" tone="error" />
        </div>

        <!-- Giữ tab + trạng thái đang chọn khi lọc; "Xóa lọc" quay về tab hiện tại -->
        <UiFilterBar :action="route('tasks.index')" search="q" placeholder="Tìm công việc, nhân sự..." :reset-url="route('tasks.index', { tab })">
            <input type="hidden" name="tab" :value="tab" />
            <input type="hidden" name="status" :value="status" />
            <UiSelect name="task_type" label="Loại công việc" :options="taskTypes" :value="taskType === 'all' ? null : taskType" placeholder="Mọi loại" @change="$event.target.form?.requestSubmit()" />
        </UiFilterBar>

        <UiDataTable min-width="880px">
            <template #header>
                <div class="flex w-full flex-col gap-sm">
                    <div class="flex flex-col gap-sm md:flex-row md:items-center md:justify-between">
                        <UiTabs class="border-0">
                            <UiTab :href="urlWith({ page: null, tab: 'mine' })" :active="tab === 'mine'" :count="counts.mine">Của tôi</UiTab>
                            <UiTab :href="urlWith({ page: null, tab: 'assigned' })" :active="tab === 'assigned'" :count="counts.assigned">Tôi giao</UiTab>
                            <UiTab v-if="canViewAll" :href="urlWith({ page: null, tab: 'all' })" :active="tab === 'all'" :count="counts.all">Tất cả</UiTab>
                        </UiTabs>
                    </div>
                    <div class="flex flex-wrap items-center gap-xs">
                        <span class="mr-xs font-body-small text-body-small text-on-surface-variant">Trạng thái:</span>
                        <Link
                            v-for="(stLabel, stKey) in statuses"
                            :key="stKey"
                            :href="urlWith({ page: null, status: stKey })"
                            :class="[
                                'rounded-full border px-sm py-[2px] font-body-small text-body-small transition-colors',
                                status === stKey ? 'border-primary-container bg-primary-container text-white' : 'border-outline-variant text-on-surface-variant hover:bg-surface-container-low',
                            ]"
                            >{{ stLabel }}</Link
                        >
                    </div>
                </div>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Tiêu đề</th>
                        <th>Loại</th>
                        <th>Người nhận</th>
                        <th>Hạn hoàn thành</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="task in tasks.data" :key="task.id" :class="[task.status === 'overdue' ? 'bg-error-container/20' : '', task.status === 'canceled' ? 'opacity-60' : '']">
                        <td class="max-w-[360px]">
                            <a :href="route('tasks.show', task.id)" data-modal-size="2xl" :class="['font-semibold text-on-surface hover:text-primary', task.status === 'canceled' ? 'line-through' : '']" @click="openTask($event, task)">{{ task.title }}</a>
                            <div v-if="task.description" class="line-clamp-1 font-caption text-caption text-on-surface-variant">{{ task.description }}</div>
                            <div class="mt-xs flex flex-wrap gap-xs">
                                <span v-if="task.class_label" class="inline-flex items-center gap-xs rounded bg-secondary-fixed/60 px-sm font-caption text-caption text-secondary">
                                    <span class="material-symbols-outlined text-[13px]" aria-hidden="true">school</span>{{ task.class_label }}
                                </span>
                                <span v-if="task.slot_label" class="rounded bg-surface-container-high px-sm font-caption text-caption text-on-surface-variant">{{ task.slot_label }}</span>
                            </div>
                            <div v-if="task.blocked_reason" class="mt-xs flex items-center gap-xs font-caption text-caption text-status-blocked">
                                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">block</span>Lý do: {{ task.blocked_reason }}
                            </div>
                        </td>
                        <td>
                            <UiBadge :color="task.task_type === 'recurring' ? 'secondary' : 'info'" :dot="false">{{ task.type_label }}</UiBadge>
                        </td>
                        <td>
                            <div class="flex items-center gap-sm">
                                <UiAvatar :name="task.assignee ?? '?'" size="sm" />
                                <div class="min-w-0">
                                    <div class="truncate font-body-small text-body-small font-medium">{{ task.assignee ?? 'Chưa phân công' }}</div>
                                    <div v-if="tab !== 'mine' && task.creator" class="truncate font-caption text-caption text-on-surface-variant">Giao bởi {{ task.creator }}</div>
                                </div>
                            </div>
                        </td>
                        <td :class="['whitespace-nowrap font-code text-body-small', task.status === 'overdue' ? 'font-semibold text-error' : '']">
                            {{ task.due_date ? formatDate(task.due_date) : '—' }}
                            <span v-if="task.due_time" class="block text-caption text-on-surface-variant">{{ task.due_time }}</span>
                        </td>
                        <td>
                            <UiBadge :color="statusColors[task.status] ?? 'neutral'">{{ task.status_label }}</UiBadge>
                        </td>
                        <td class="text-right">
                            <UiDropdown width="56">
                                <template #trigger><UiButton variant="ghost" icon="more_vert" aria-label="Thao tác" /></template>
                                <template #content>
                                    <button
                                        v-for="next in task.allowed"
                                        :key="next"
                                        type="button"
                                        class="flex w-full items-center gap-sm px-md py-xs text-left font-body-small text-body-small hover:bg-surface-container-low"
                                        @click="openStatusModal(task, next)"
                                    >
                                        <span class="material-symbols-outlined text-[16px] text-on-surface-variant" aria-hidden="true">{{ transition(task, next).icon }}</span>{{ transition(task, next).label }}
                                    </button>
                                    <p v-if="!task.allowed.length" class="px-md py-xs text-left font-caption text-caption text-on-surface-variant">Không có thao tác khả dụng</p>
                                </template>
                            </UiDropdown>
                        </td>
                    </tr>
                    <tr v-if="!tasks.data.length">
                        <td colspan="6"><UiEmptyState icon="assignment_late" title="Không tìm thấy công việc nào phù hợp" /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="tasks" unit="công việc" /></template>
        </UiDataTable>
    </div>

    <!-- Modal: Thay đổi trạng thái -->
    <UiModal :show="!!current" title="Thay đổi trạng thái" max-width="md" @close="current = null">
        <UiForm v-if="current" id="task-status-change-form" :action="route('tasks.status.update', current.id)" method="post" class="space-y-md" @success="current = null" #default="{ errors }">
            <input type="hidden" name="status" :value="newStatus" />
            <div>
                <p class="font-caption text-caption text-on-surface-variant">Công việc</p>
                <p class="font-body-medium text-body-medium font-semibold">{{ current.title }}</p>
            </div>
            <div>
                <p class="font-caption text-caption text-on-surface-variant">Trạng thái mới</p>
                <p class="flex items-center gap-xs font-body-medium text-body-medium font-semibold text-primary">{{ statusLabel }}</p>
            </div>
            <UiErrors :messages="errors.status" />
            <label class="block" for="task-status-reason">
                <span class="mb-xs block font-body-small text-body-small font-medium">
                    {{ reasonRequired ? 'Ghi chú lý do' : 'Ghi chú / kết quả (tùy chọn)' }}<span v-if="reasonRequired" class="text-error"> *</span>
                </span>
                <UiTextarea id="task-status-reason" name="reason" :rows="3" :required="reasonRequired" maxlength="1000" placeholder="Nhập lý do chi tiết khiến công việc bị chặn / kết quả..." />
            </label>
            <UiErrors :messages="errors.reason" />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="current = null">Hủy</UiButton>
            <UiButton type="submit" form="task-status-change-form">Xác nhận</UiButton>
        </template>
    </UiModal>
</template>
