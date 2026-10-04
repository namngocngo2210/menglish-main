<script setup>
/**
 * Chi tiết dự án học thuật: tổng quan tiến độ, mốc & deadline (khối lượng, người nhận), lịch sử cập nhật tiến độ
 * (link sản phẩm, khó khăn, phản hồi), thông tin họp thống nhất, thành viên và biên bản trễ deadline.
 * Quản lý: sửa dự án, chốt tiến độ, thêm / sửa / xóa mốc, đổi trạng thái. Thành viên: cập nhật tiến độ mốc mình nhận.
 */
import { computed, ref } from 'vue';
import ProgressBar from './ProgressBar.vue';

defineOptions({ layout: (props) => ({ title: props.project?.name }) });

const props = defineProps({
    project: { type: Object, required: true },
    milestones: { type: Array, default: () => [] },
    updates: { type: Array, default: () => [] },
    penalties: { type: Array, default: () => [] },
    staff: { type: Array, default: () => [] },
    milestoneStatuses: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    canUpdate: { type: Boolean, default: false },
    canRespond: { type: Boolean, default: false },
    graceHours: { type: Number, default: 0 },
    slaEnabled: { type: Boolean, default: true },
});

const isOpen = computed(() => ['planning', 'active', 'paused'].includes(props.project.status));
const daysLeft = computed(() => {
    if (!props.project.deadline) return null;
    const end = new Date(props.project.deadline + 'T23:59:59');
    return Math.ceil((end - new Date()) / 86400000);
});
const deadlineBadge = { overdue: { color: 'error', label: 'Trễ hạn' }, soon: { color: 'warning', label: 'Sắp đến hạn' } };
const milestoneColor = { todo: 'neutral', in_progress: 'primary', done: 'success' };
const penaltyColor = { pending: 'warning', explained: 'info', confirmed: 'error', fined: 'error', deducted: 'error', paid: 'success', resolved: 'success', cancelled: 'neutral' };

// Mốc: thêm / sửa
const editingMilestone = ref(null);
const openMilestone = (m = {}) => (editingMilestone.value = m);

// Cập nhật tiến độ
const updating = ref(false);
const updateMilestoneId = ref('');
const quantity = ref('');
const updatable = computed(() => props.milestones.filter((m) => m.can_update && m.status !== 'done'));
const chosen = computed(() => props.milestones.find((m) => String(m.id) === String(updateMilestoneId.value)));
function openUpdate(m = null) {
    const first = m ?? (updatable.value.length === 1 ? updatable.value[0] : null);
    updateMilestoneId.value = first ? String(first.id) : '';
    quantity.value = first ? String(first.done_quantity) : '';
    updating.value = true;
}
function pickMilestone(value) {
    updateMilestoneId.value = value;
    quantity.value = chosen.value ? String(chosen.value.done_quantity) : '';
}

// Phản hồi khó khăn
const responding = ref(null);
const host = (url) => {
    try {
        return new URL(url).hostname.replace(/^www\./, '');
    } catch {
        return url;
    }
};
</script>

<template>
    <UiPageHeader :title="project.name" icon="auto_stories" :back="route('academic-projects.index')">
        <template #actions>
            <UiButton v-if="canUpdate" icon="edit_note" @click="openUpdate()">Cập nhật tiến độ</UiButton>
            <template v-if="canManage">
                <UiForm v-if="project.status === 'planning'" :action="route('academic-projects.lock', project.id)" method="post" confirm="Chốt tiến độ dự án? Từ lúc chốt, mốc chưa hoàn thành khi quá deadline sẽ tự lập biên bản chờ giải trình." confirm-label="Chốt tiến độ">
                    <UiButton type="submit" variant="secondary" icon="lock_clock">Chốt tiến độ</UiButton>
                </UiForm>
                <UiButton variant="secondary" icon="edit" :href="route('academic-projects.edit', project.id)" modal="2xl">Sửa dự án</UiButton>
                <UiDropdown v-if="isOpen" align="right" width="56">
                    <template #trigger><UiButton variant="ghost" icon="more_vert" aria-label="Thao tác khác" /></template>
                    <template #content><div class="flex flex-col p-xs">
                        <UiForm v-if="project.status === 'active'" :action="route('academic-projects.status', project.id)" method="post" confirm="Tạm dừng dự án? Khi tạm dừng, mốc không bị tính trễ." confirm-label="Tạm dừng">
                            <input type="hidden" name="action" value="pause" />
                            <UiButton type="submit" variant="ghost" icon="pause_circle" class="w-full justify-start">Tạm dừng</UiButton>
                        </UiForm>
                        <UiForm v-if="project.status === 'paused'" :action="route('academic-projects.status', project.id)" method="post">
                            <input type="hidden" name="action" value="resume" />
                            <UiButton type="submit" variant="ghost" icon="play_circle" class="w-full justify-start">Tiếp tục thực hiện</UiButton>
                        </UiForm>
                        <UiForm v-if="['active', 'paused'].includes(project.status)" :action="route('academic-projects.status', project.id)" method="post" confirm="Đóng dự án: hoàn thành? Thành viên sẽ không cập nhật tiến độ được nữa." confirm-label="Hoàn thành">
                            <input type="hidden" name="action" value="complete" />
                            <UiButton type="submit" variant="ghost" icon="task_alt" class="w-full justify-start">Hoàn thành dự án</UiButton>
                        </UiForm>
                        <UiForm :action="route('academic-projects.status', project.id)" method="post" confirm="Hủy dự án này?" confirm-label="Hủy dự án" danger>
                            <input type="hidden" name="action" value="cancel" />
                            <UiButton type="submit" variant="danger-text" icon="block" class="w-full justify-start">Hủy dự án</UiButton>
                        </UiForm>
                        <UiForm :action="route('academic-projects.destroy', project.id)" method="delete" :confirm="`Xóa dự án ${project.code}? Dữ liệu được giữ trong thùng rác (xóa mềm).`" confirm-label="Xóa" danger>
                            <UiButton type="submit" variant="danger-text" icon="delete" class="w-full justify-start">Xóa dự án</UiButton>
                        </UiForm>
                    </div></template>
                </UiDropdown>
            </template>
        </template>
    </UiPageHeader>

    <div class="mb-md flex flex-wrap items-center gap-sm">
        <UiBadge :color="project.status_color">{{ project.status_label }}</UiBadge>
        <UiBadge color="secondary" :dot="false">{{ project.type_label }}</UiBadge>
        <span class="font-code text-body-small text-on-surface-variant">{{ project.code }}</span>
    </div>

    <UiAlert v-if="project.status === 'planning'" type="info" title="Đang lên kế hoạch" class="mb-md">
        Sau buổi họp thống nhất, thêm đủ các mốc (deadline, khối lượng, người nhận) rồi bấm “Chốt tiến độ”. Mốc chỉ bị tính trễ sau khi chốt.
    </UiAlert>
    <UiAlert v-else-if="project.status === 'active' && slaEnabled" type="warning" class="mb-md">
        Đã chốt tiến độ {{ formatDate(project.plan_locked_at, 'd/m/Y H:i') }}<template v-if="project.locker"> ({{ project.locker }})</template>.
        Mốc chưa hoàn thành sau 23:59 ngày deadline<template v-if="graceHours"> + {{ graceHours }} giờ ân hạn</template> sẽ tự lập biên bản chờ giải trình cho người nhận mốc.
    </UiAlert>
    <UiAlert v-else-if="project.status === 'paused'" type="warning" class="mb-md">Dự án đang tạm dừng: mốc không bị tính trễ cho đến khi tiếp tục.</UiAlert>

    <div class="mb-lg grid grid-cols-1 gap-md sm:grid-cols-2 lg:grid-cols-4">
        <UiStatCard label="Tiến độ dự án" :value="project.progress + '%'" icon="donut_large" tone="primary" />
        <UiStatCard label="Mốc hoàn thành" :value="`${project.milestones_done}/${project.milestones_total}`" icon="flag" tone="success" />
        <UiStatCard label="Mốc trễ hạn" :value="project.milestones_overdue" icon="alarm" :tone="project.milestones_overdue ? 'error' : 'default'" />
        <UiStatCard
            label="Deadline dự án"
            :value="project.deadline ? formatDate(project.deadline) : '—'"
            icon="event"
            :tone="daysLeft !== null && daysLeft < 0 && isOpen ? 'error' : 'default'"
            :hint="daysLeft === null || !isOpen ? null : daysLeft >= 0 ? `Còn ${daysLeft} ngày` : `Quá ${-daysLeft} ngày`"
        />
    </div>

    <!-- Mốc & deadline -->
    <section class="rounded-xl border border-surface-variant bg-surface-container-lowest">
        <div class="flex flex-wrap items-center justify-between gap-sm border-b border-surface-variant px-md py-sm">
            <h2 class="font-h3 text-h3 text-on-surface">Mốc tiến độ & deadline</h2>
            <UiButton v-if="canManage && isOpen" size="sm" variant="secondary" icon="add" @click="openMilestone()">Thêm mốc</UiButton>
        </div>
        <!-- Điện thoại: mỗi mốc một thẻ -->
        <ul class="divide-y divide-surface-variant md:hidden">
            <li v-for="m in milestones" :key="'card' + m.id" :class="['space-y-sm px-md py-md', m.mine ? 'bg-primary-fixed/20' : '']">
                <div class="flex items-start justify-between gap-sm">
                    <div class="min-w-0">
                        <p class="font-semibold text-on-surface">{{ m.title }}</p>
                        <p class="font-body-small text-body-small text-on-surface-variant">{{ m.assignee ?? 'Chưa giao' }} · hạn <span class="font-code">{{ formatDate(m.due_date) }}</span></p>
                    </div>
                    <UiBadge :color="milestoneColor[m.status]">{{ m.status_label }}</UiBadge>
                </div>
                <div class="flex flex-wrap items-center gap-sm">
                    <ProgressBar :value="m.progress" :overdue="m.deadline_state === 'overdue'" :label="m.quantity_label" width="w-40" />
                    <UiBadge v-if="m.deadline_state" :color="deadlineBadge[m.deadline_state].color">{{ deadlineBadge[m.deadline_state].label }}</UiBadge>
                </div>
                <div v-if="(canUpdate && m.can_update && m.status !== 'done') || (canManage && isOpen)" class="flex flex-wrap gap-xs">
                    <UiButton v-if="canUpdate && m.can_update && m.status !== 'done'" size="sm" variant="secondary" icon="edit_note" @click="openUpdate(m)">Cập nhật</UiButton>
                    <UiButton v-if="canManage && isOpen" size="sm" variant="ghost" icon="edit" @click="openMilestone(m)">Sửa</UiButton>
                </div>
            </li>
            <li v-if="!milestones.length" class="p-md"><UiEmptyState compact icon="flag" title="Chưa có mốc nào" /></li>
        </ul>
        <UiDataTable min-width="860px" class="!rounded-none !border-0 max-md:hidden">
            <table>
                <thead>
                    <tr>
                        <th>Mốc</th>
                        <th>Người nhận</th>
                        <th>Deadline</th>
                        <th>Khối lượng</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in milestones" :key="m.id" :class="m.mine ? 'bg-primary-fixed/20' : ''">
                        <td class="min-w-[220px] max-w-[360px]">
                            <span class="font-semibold text-on-surface">{{ m.title }}</span>
                            <span v-if="m.description" class="block line-clamp-2 font-body-small text-body-small text-on-surface-variant">{{ m.description }}</span>
                        </td>
                        <td>{{ m.assignee ?? '—' }}<span v-if="m.mine" class="block text-xs font-semibold text-primary">Việc của bạn</span></td>
                        <td class="whitespace-nowrap">
                            <span class="font-code">{{ formatDate(m.due_date) }}</span>
                            <UiBadge v-if="m.deadline_state" :color="deadlineBadge[m.deadline_state].color" class="mt-[2px] flex w-fit">{{ deadlineBadge[m.deadline_state].label }}</UiBadge>
                        </td>
                        <td><ProgressBar :value="m.progress" :overdue="m.deadline_state === 'overdue'" :label="m.quantity_label" /></td>
                        <td>
                            <UiBadge :color="milestoneColor[m.status]">{{ m.status_label }}</UiBadge>
                            <span v-if="m.completed_at" class="mt-[2px] block font-code text-xs text-on-surface-variant">{{ formatDate(m.completed_at, 'd/m H:i') }}</span>
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <div class="inline-flex items-center gap-xs">
                                <UiButton v-if="canUpdate && m.can_update && m.status !== 'done'" size="sm" variant="ghost" icon="edit_note" @click="openUpdate(m)">Cập nhật</UiButton>
                                <template v-if="canManage && isOpen">
                                    <UiButton size="sm" variant="ghost" icon="edit" :aria-label="`Sửa mốc ${m.title}`" @click="openMilestone(m)" />
                                    <UiForm :action="route('academic-projects.milestones.destroy', m.id)" method="delete" :confirm="`Xóa mốc “${m.title}”?`" confirm-label="Xóa" danger back>
                                        <UiButton type="submit" size="sm" variant="danger-text" icon="delete" :aria-label="`Xóa mốc ${m.title}`" />
                                    </UiForm>
                                </template>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!milestones.length">
                        <td colspan="6">
                            <UiEmptyState icon="flag" title="Chưa có mốc nào" :description="canManage ? 'Chia dự án thành các mốc: deadline, khối lượng (unit, trang…) và người nhận.' : 'Người quản lý dự án chưa thêm mốc.'" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </section>

    <div class="mt-lg grid grid-cols-1 gap-lg xl:grid-cols-3">
        <div class="xl:col-span-2">
            <!-- Lịch sử cập nhật -->
            <section class="rounded-xl border border-surface-variant bg-surface-container-lowest">
                <div class="flex items-center justify-between gap-sm border-b border-surface-variant px-md py-sm">
                    <h2 class="font-h3 text-h3 text-on-surface">Báo cáo tiến độ</h2>
                    <span class="font-caption text-caption text-on-surface-variant">{{ updates.length }} lần cập nhật</span>
                </div>
                <ol class="divide-y divide-surface-variant">
                    <li v-for="u in updates" :key="u.id" class="space-y-sm px-md py-md" data-update>
                        <div class="flex flex-wrap items-center gap-x-sm gap-y-xs">
                            <span class="font-semibold text-on-surface">{{ u.user ?? '—' }}</span>
                            <span class="font-code text-xs text-on-surface-variant">{{ formatDate(u.created_at, 'd/m/Y H:i') }}</span>
                            <UiBadge v-if="u.milestone" color="secondary" :dot="false">{{ u.milestone }}</UiBadge>
                            <UiBadge v-if="u.quantity" color="primary" :dot="false">Đã xong {{ u.quantity }}</UiBadge>
                            <UiBadge v-if="u.marks_complete" color="success">Báo hoàn thành mốc</UiBadge>
                        </div>
                        <p class="whitespace-pre-line text-on-surface">{{ u.content }}</p>
                        <ul v-if="u.links.length" class="flex flex-wrap gap-sm">
                            <li v-for="link in u.links" :key="link">
                                <a :href="link" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-xs rounded-full bg-surface-container-low px-sm py-0.5 font-body-small text-body-small text-primary hover:underline">
                                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">link</span>{{ host(link) }}
                                </a>
                            </li>
                        </ul>
                        <div v-if="u.difficulties" class="rounded-lg bg-warning-container px-md py-sm">
                            <p class="font-caption text-caption font-semibold uppercase text-warning">Khó khăn / cần hỗ trợ</p>
                            <p class="whitespace-pre-line text-on-surface">{{ u.difficulties }}</p>
                        </div>
                        <div v-if="u.response" class="rounded-lg bg-secondary-fixed/50 px-md py-sm">
                            <p class="font-caption text-caption font-semibold uppercase text-secondary">Phản hồi · {{ u.responder }} · {{ formatDate(u.responded_at, 'd/m H:i') }}</p>
                            <p class="whitespace-pre-line text-on-surface">{{ u.response }}</p>
                        </div>
                        <UiButton v-else-if="canRespond" size="sm" variant="ghost" icon="reply" @click="responding = u">Phản hồi</UiButton>
                    </li>
                    <li v-if="!updates.length" class="p-md">
                        <UiEmptyState compact icon="edit_note" title="Chưa có cập nhật tiến độ" description="Thành viên bấm “Cập nhật tiến độ” để báo việc đã làm, khối lượng xong, link sản phẩm và khó khăn." />
                    </li>
                </ol>
            </section>
        </div>

        <aside class="space-y-lg">
            <section class="space-y-md rounded-xl border border-surface-variant bg-surface-container-lowest p-md">
                <h2 class="font-h3 text-h3 text-on-surface">Thông tin dự án</h2>
                <dl class="grid grid-cols-2 gap-md">
                    <div><dt class="font-caption text-caption text-on-surface-variant">Người phụ trách</dt><dd class="font-semibold">{{ project.owner ?? '—' }}</dd></div>
                    <div><dt class="font-caption text-caption text-on-surface-variant">Người tạo</dt><dd>{{ project.creator ?? '—' }}</dd></div>
                    <div><dt class="font-caption text-caption text-on-surface-variant">Bắt đầu</dt><dd class="font-code">{{ project.start_date ? formatDate(project.start_date) : '—' }}</dd></div>
                    <div><dt class="font-caption text-caption text-on-surface-variant">Deadline</dt><dd class="font-code">{{ project.deadline ? formatDate(project.deadline) : '—' }}</dd></div>
                </dl>
                <div v-if="project.description">
                    <p class="font-caption text-caption text-on-surface-variant">Mục tiêu / phạm vi</p>
                    <p class="whitespace-pre-line text-on-surface">{{ project.description }}</p>
                </div>
                <div>
                    <p class="mb-xs font-caption text-caption text-on-surface-variant">Thành viên ({{ project.members.length }})</p>
                    <ul class="flex flex-wrap gap-xs">
                        <li v-for="m in project.members" :key="m.id" class="rounded-full bg-surface-container-low px-sm py-0.5 font-body-small text-body-small text-on-surface">{{ m.name }}</li>
                    </ul>
                </div>
            </section>

            <section class="space-y-sm rounded-xl border border-surface-variant bg-surface-container-lowest p-md">
                <h2 class="font-h3 text-h3 text-on-surface">Họp thống nhất</h2>
                <p v-if="project.kickoff_notes" class="whitespace-pre-line text-on-surface">{{ project.kickoff_notes }}</p>
                <p v-else class="font-body-small text-body-small text-on-surface-variant">Chưa ghi nội dung họp.</p>
                <a v-if="project.kickoff_link" :href="project.kickoff_link" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-xs text-primary hover:underline">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">description</span>Biên bản họp
                </a>
            </section>

            <section class="space-y-sm rounded-xl border border-surface-variant bg-surface-container-lowest p-md">
                <h2 class="font-h3 text-h3 text-on-surface">Biên bản trễ deadline</h2>
                <ul v-if="penalties.length" class="divide-y divide-surface-variant">
                    <li v-for="p in penalties" :key="p.id" class="py-sm">
                        <a :href="p.url" class="font-code font-semibold text-primary hover:underline">{{ p.code }}</a>
                        <UiBadge :color="penaltyColor[p.status] ?? 'neutral'" class="ml-xs">{{ p.status_label }}</UiBadge>
                        <span class="block font-body-small text-body-small text-on-surface-variant">{{ p.user }} · mốc “{{ p.milestone }}”<template v-if="p.amount"> · {{ formatMoney(p.amount) }}</template></span>
                    </li>
                </ul>
                <p v-else class="font-body-small text-body-small text-on-surface-variant">Chưa có biên bản nào.</p>
            </section>
        </aside>
    </div>

    <!-- Thêm / sửa mốc -->
    <UiModal :show="!!editingMilestone" :title="editingMilestone?.id ? 'Sửa mốc' : 'Thêm mốc'" max-width="xl" @close="editingMilestone = null">
        <UiForm
            v-if="editingMilestone"
            id="milestone-form"
            :action="editingMilestone.id ? route('academic-projects.milestones.update', editingMilestone.id) : route('academic-projects.milestones.store', project.id)"
            :method="editingMilestone.id ? 'put' : 'post'"
            class="space-y-md"
            @success="editingMilestone = null"
        >
            <UiInput name="title" label="Tên mốc" required :value="editingMilestone.title" placeholder="VD: Hoàn thành bản thảo Unit 1–4" />
            <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <UiSelect name="assignee_id" label="Người nhận" :options="staff" placeholder="-- Chọn người nhận --" :value="editingMilestone.assignee_id" searchable />
                <UiSelect v-if="editingMilestone.id" name="status" label="Trạng thái" :options="milestoneStatuses" :value="editingMilestone.status" />
            </div>
            <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <UiDate name="start_date" label="Bắt đầu" :value="editingMilestone.start_date" />
                <UiDate name="due_date" label="Deadline" required :value="editingMilestone.due_date" />
            </div>
            <div class="grid grid-cols-2 gap-md">
                <UiInput name="target_quantity" type="number" step="0.01" min="0.01" label="Khối lượng cần làm" required :value="editingMilestone.target_quantity ?? 1" />
                <UiInput name="unit" label="Đơn vị" required :value="editingMilestone.unit ?? 'unit'" placeholder="unit, trang, bài…" />
            </div>
            <UiTextarea name="description" label="Yêu cầu / tiêu chí nghiệm thu" :rows="3" :value="editingMilestone.description" />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="editingMilestone = null">Hủy</UiButton>
            <UiButton type="submit" form="milestone-form" icon="save">Lưu mốc</UiButton>
        </template>
    </UiModal>

    <!-- Cập nhật tiến độ -->
    <UiModal :show="updating" title="Cập nhật tiến độ" max-width="xl" @close="updating = false">
        <UiForm v-if="updating" id="update-form" :action="route('academic-projects.updates.store', project.id)" method="post" class="space-y-md" @success="updating = false">
            <UiSelect
                name="milestone_id"
                label="Mốc"
                :options="updatable.map((m) => ({ value: m.id, label: `${m.title} · hạn ${formatDate(m.due_date)}` }))"
                placeholder="Báo cáo chung (không gắn mốc)"
                :model-value="updateMilestoneId"
                @update:model-value="pickMilestone"
            />
            <div v-if="chosen" class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <UiInput v-model="quantity" name="quantity_done" type="number" step="0.01" min="0" label="Khối lượng đã xong đến nay" :hint="`Cần làm ${chosen.quantity_label.split(' / ')[1]}`" />
                <div class="flex items-end pb-sm">
                    <UiCheckbox name="marks_complete" value="1" label="Đã hoàn thành mốc này" />
                </div>
            </div>
            <UiTextarea name="content" label="Đã làm được gì" required :rows="3" placeholder="VD: Xong bản thảo Unit 3, đang soạn bài tập Unit 4" />
            <UiTextarea name="links_text" label="Link sản phẩm đã làm" :rows="2" hint="Mỗi dòng một link (Google Drive, Docs…), tối đa 5" placeholder="https://drive.google.com/…" />
            <UiTextarea name="difficulties" label="Khó khăn / cần hỗ trợ" :rows="2" hint="Để trống nếu không có" />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="updating = false">Hủy</UiButton>
            <UiButton type="submit" form="update-form" icon="send">Gửi cập nhật</UiButton>
        </template>
    </UiModal>

    <!-- Phản hồi -->
    <UiModal :show="!!responding" title="Phản hồi cập nhật" max-width="lg" @close="responding = null">
        <template v-if="responding">
            <p class="mb-sm font-body-small text-body-small text-on-surface-variant">{{ responding.user }} · {{ formatDate(responding.created_at, 'd/m/Y H:i') }}</p>
            <p v-if="responding.difficulties" class="mb-md whitespace-pre-line rounded-lg bg-warning-container px-md py-sm">{{ responding.difficulties }}</p>
            <UiForm id="respond-form" :action="route('academic-projects.updates.respond', responding.id)" method="post" @success="responding = null">
                <UiTextarea name="response" label="Phản hồi / hướng xử lý" required :rows="3" />
            </UiForm>
        </template>
        <template #footer>
            <UiButton variant="secondary" @click="responding = null">Hủy</UiButton>
            <UiButton type="submit" form="respond-form" icon="send">Gửi phản hồi</UiButton>
        </template>
    </UiModal>
</template>
