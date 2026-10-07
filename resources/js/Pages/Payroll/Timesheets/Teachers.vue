<script setup>
/**
 * Chi tiết chấm công GV + đối soát (mockup epic-7/chi-tiet-cham-cong-theo-gv + roundcuoi 01_Web_Admin/10_doi_soat_chot_bang_cong):
 * chấm công theo lịch của một ngày, thẻ giáo viên + buổi thiếu chấm công, danh sách ca của kỳ với Duyệt / Từ chối /
 * Chỉnh tay bổ sung (hộp thoại) và "Chốt bảng công (N)" cho các ca chờ đối soát đã chọn.
 */
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { urlWith } from '@/lib/url';
import { can } from '@/lib/can';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Chấm công giáo viên' } });

const props = defineProps({
    canViewAll: { type: Boolean, default: false },
    month: { type: String, required: true },
    monthLabel: { type: String, required: true },
    period: { type: Object, default: null },
    periodLocked: { type: Boolean, default: false },
    summary: { type: Object, required: true },
    timesheets: { type: Object, required: true },
    teacher: { type: Object, default: null },
    missingSessions: { type: Array, default: () => [] },
    scheduleDay: { type: Object, default: null },
    scheduleSessions: { type: Array, default: () => [] },
    subPendingCount: { type: Number, default: 0 },
    filterBranches: { type: Array, default: () => [] },
    filterClasses: { type: Array, default: () => [] },
    filterTeachers: { type: Array, default: () => [] },
});

const punchColors = { full: 'success', adjusted: 'info', missing_in: 'warning', missing_out: 'error' };
const statusColors = { valid: 'success', invalid: 'error', pending_review: 'warning' };
const statusOptions = [
    { value: 'pending_review', label: 'Chờ đối soát' },
    { value: 'valid', label: 'Hợp lệ' },
    { value: 'invalid', label: 'Từ chối' },
];
const typeOptions = [
    { value: 'regular', label: 'Ca dạy chính khóa' },
    { value: 'sub', label: 'Dạy thay' },
    { value: '1on1', label: 'Kèm 1-1 / bổ trợ' },
    { value: 'grading', label: 'Chấm bài thi' },
    { value: 'workshop', label: 'Workshop / Sự kiện' },
];

const canReview = computed(() => props.canViewAll && can('attendance_staff.view'));
const canAdjust = computed(() => can('attendance_staff.manual_record'));
const selectable = computed(() => canReview.value && !props.periodLocked);
const pendingIds = computed(() => props.timesheets.data.filter((ts) => ts.status === 'pending_review').map((ts) => ts.id));
const selected = ref([]);
const allSelected = computed(() => pendingIds.value.length > 0 && selected.value.length === pendingIds.value.length);
const columns = computed(() => 10 + (selectable.value ? 1 : 0) - (props.teacher ? 1 : 0));

const adjustOpen = ref(false);
const edit = ref({ action: '', timeIn: '', timeOut: '', label: '', late: 0, early: 0, notified: false });
const rejectOpen = ref(false);
const reject = ref({ action: '', label: '' });

function toggleAll(event) {
    selected.value = event.target.checked ? [...pendingIds.value] : [];
}
function openAdjust(ts) {
    edit.value = { action: route('payroll.timesheets.adjust', ts.id), timeIn: ts.checkin_time ?? '', timeOut: ts.display_checkout ?? '', label: ts.edit_label, late: ts.late_minutes ?? 0, early: ts.early_leave_minutes ?? 0, notified: !!ts.late_notified };
    adjustOpen.value = true;
}
function openReject(ts) {
    reject.value = { action: route('payroll.timesheets.review', ts.id), label: ts.reject_label };
    rejectOpen.value = true;
}
function changeDay(event) {
    router.get(urlWith({ day: event.target.value, page: null }), {}, { preserveScroll: true });
}
</script>


<template>
    <div>
        <UiPageHeader :title="canViewAll ? 'Chi tiết chấm công giáo viên' : 'Chấm công của tôi'" description="Đối soát ca dạy thực tế (check-in / chấm tay) trước khi chốt bảng công và tính lương.">
            <template #actions>
                <UiButton v-if="can('attendance_staff.sync')" variant="secondary" icon="sync" :href="route('payroll.timesheets.sync-history')">Lịch sử đồng bộ</UiButton>
                <UiButton v-if="canAdjust" variant="secondary" icon="timer" :href="route('payroll.timesheets.manual')">Chấm công thủ công</UiButton>
            </template>
        </UiPageHeader>

        <!-- Kỳ lương + trạng thái khóa -->
        <div class="mb-md flex flex-wrap items-center gap-sm">
            <span class="inline-flex items-center gap-xs rounded-lg bg-surface-container-low px-md py-xs font-body-medium text-body-medium text-on-surface">
                <span class="material-symbols-outlined text-[18px] text-primary-container" aria-hidden="true">calendar_month</span>
                Kỳ lương: Tháng {{ monthLabel }}
            </span>
            <span v-if="periodLocked" class="inline-flex items-center gap-xs rounded-lg bg-error-container px-md py-xs font-body-small text-body-small font-semibold text-on-error-container">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">lock</span>
                Kỳ lương đã chốt, không thể sửa
            </span>
            <UiBadge v-else-if="period" color="info">{{ period.status_label ?? 'Đang mở' }}</UiBadge>
            <UiBadge v-else color="neutral">Chưa khởi tạo bảng lương</UiBadge>
        </div>

        <UiFilterBar placeholder="Tìm giáo viên..." :search="canViewAll ? 'search' : false">
            <UiInput type="month" name="month" :value="month" label="Kỳ lương" />
            <template v-if="canViewAll">
                <UiSelect name="branch_id" :options="filterBranches" placeholder="Tất cả chi nhánh" label="Chi nhánh" />
                <UiSelect name="class_id" :options="filterClasses" placeholder="Tất cả lớp học" label="Lớp học" />
                <UiSelect name="user_id" :options="filterTeachers" placeholder="Tất cả giáo viên" label="Giáo viên" />
            </template>
            <UiSelect name="status" :options="statusOptions" placeholder="Tất cả trạng thái" label="Trạng thái" />
            <UiSelect name="type" :options="typeOptions" placeholder="Mọi loại ca" label="Loại ca" />
        </UiFilterBar>

        <UiAlert v-if="canViewAll && subPendingCount > 0" type="warning" class="mb-md">
            Có <strong>{{ subPendingCount }}</strong> buổi dạy thay chờ xác nhận.
            <Link :href="route('payroll.timesheets.teachers', { month, type: 'sub', status: 'pending_review' })" class="font-semibold underline">Xem danh sách buổi dạy thay chờ xác nhận</Link>
        </UiAlert>

        <UiDataTable v-if="scheduleDay" class="mb-lg" min-width="760px">
            <template #header>
                <div>
                    <h3 class="font-h3 text-h3 text-on-surface">Chấm công theo lịch</h3>
                    <p class="inline-flex items-center gap-xs font-body-small text-body-small text-on-surface-variant">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">today</span>
                        {{ scheduleDay.weekday }}, {{ formatDate(scheduleDay.date) }}
                    </p>
                </div>
                <form method="GET" class="flex items-center gap-sm" @submit.prevent>
                    <UiDate name="day" inline-label="Ngày:" :value="scheduleDay.date" @change="changeDay" />
                </form>
            </template>
            <table>
                <thead><tr><th>Lớp học</th><th>Giờ học</th><th>Giáo viên dự kiến</th><th>Trạng thái / Nguồn</th><th class="text-right">Thao tác</th></tr></thead>
                <tbody>
                    <tr v-for="session in scheduleSessions" :key="session.id">
                        <td class="font-semibold">{{ session.class_name }} <span class="font-caption text-caption text-on-surface-variant">{{ session.class_code }}</span></td>
                        <td class="font-mono">{{ session.start_time }} - {{ session.end_time }}</td>
                        <td><span class="inline-flex items-center gap-sm"><UiAvatar :name="session.teacher_name ?? '?'" size="sm" />{{ session.teacher_name }}</span></td>
                        <td>
                            <template v-if="session.state === 'valid'">
                                <span class="inline-flex items-center gap-xs text-tertiary"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">check_circle</span>Đã xác nhận</span>
                                <span class="block font-caption text-caption text-on-surface-variant">{{ session.state_note }}</span>
                            </template>
                            <span v-else-if="session.state === 'checkin'" class="inline-flex items-center gap-xs text-secondary"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">smartphone</span>GV tự check-in</span>
                            <UiBadge v-else-if="session.state === 'pending'" color="warning">{{ session.source_label }} — chờ duyệt</UiBadge>
                            <UiBadge v-else color="neutral">Tự động (pre-fill theo lịch)</UiBadge>
                        </td>
                        <td class="text-right">
                            <UiForm v-if="session.can_confirm" :action="route('payroll.timesheets.sessions.confirm', session.id)" method="post">
                                <UiButton type="submit" size="sm" variant="secondary" icon="check">Xác nhận</UiButton>
                            </UiForm>
                        </td>
                    </tr>
                    <tr v-if="!scheduleSessions.length"><td colspan="5"><UiEmptyState icon="event_busy" title="Không có buổi học nào trong ngày" /></td></tr>
                </tbody>
            </table>
            <template #footer><p class="p-sm font-body-small text-body-small text-on-surface-variant">Hiển thị {{ scheduleSessions.length }} buổi học ngày {{ formatDate(scheduleDay.date) }}. Xác nhận = tính công theo giờ lịch cho GV dự kiến (ca đã check-in / chấm tay thì duyệt ca đó).</p></template>
        </UiDataTable>

        <div v-if="teacher" class="mb-lg grid grid-cols-1 gap-md lg:grid-cols-3">
            <div class="flex items-center gap-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md lg:col-span-2">
                <UiAvatar :name="teacher.name" size="lg" />
                <div class="min-w-0">
                    <h2 class="font-h2 text-h2 text-on-surface">{{ teacher.name }}</h2>
                    <div class="mt-xs flex flex-wrap gap-md font-body-small text-body-small text-on-surface-variant">
                        <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">badge</span>Mã GV: {{ teacher.employee_code || 'Chưa có mã' }}</span>
                        <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">domain</span>Cơ sở: {{ teacher.branch_name ?? 'Chưa gán chi nhánh' }}</span>
                    </div>
                </div>
            </div>
            <div :class="['rounded-xl border p-md', missingSessions.length ? 'border-error/40 bg-error-container/30' : 'border-outline-variant bg-surface-container-lowest']">
                <p class="font-body-small text-body-small text-on-surface-variant">Số lần thiếu chấm công trong tháng</p>
                <p :class="['font-h1 text-h1', missingSessions.length ? 'text-error' : 'text-on-surface']">{{ missingSessions.length }} lần</p>
                <p class="font-body-small text-body-small text-on-surface-variant">Buổi được phân công đã diễn ra nhưng chưa có ca chấm công hợp lệ.</p>
            </div>
        </div>

        <div class="mb-md grid grid-cols-1 gap-md sm:grid-cols-3">
            <UiStatCard label="Chờ đối soát" :value="summary.pending_review" tone="warning" icon="pending_actions" />
            <UiStatCard label="Hợp lệ (tính lương)" :value="summary.valid" tone="success" icon="task_alt" />
            <UiStatCard label="Từ chối" :value="summary.invalid" tone="error" icon="block" />
        </div>

        <UiDataTable min-width="1000px">
            <template #header>
                <h3 class="font-h3 text-h3 text-on-surface">Ca dạy trong kỳ <span class="font-body-small text-body-small text-on-surface-variant">({{ timesheets.total }} bản ghi)</span></h3>
                <UiForm v-if="selectable" :action="route('payroll.timesheets.bulk-review')" method="post" class="flex items-center gap-sm" @success="selected = []">
                    <input v-for="id in selected" :key="id" type="hidden" name="ids[]" :value="id" />
                    <!-- Chỉ "Chốt bảng công" là nút chính; chưa chọn ca nào thì khóa và không hiện "(0)" -->
                    <UiButton type="submit" icon="task_alt" v-bind="selected.length === 0 ? { disabled: true } : {}">
                        Chốt bảng công<span v-show="selected.length > 0">&nbsp;({{ selected.length }})</span>
                    </UiButton>
                </UiForm>
            </template>
            <table>
                <thead>
                    <tr>
                        <th v-if="selectable" class="w-10"><input type="checkbox" aria-label="Chọn tất cả ca chờ đối soát" :checked="allSelected" class="rounded border-outline-variant text-primary-container" @change="toggleAll" /></th>
                        <th>Ngày</th>
                        <th>Ca học</th>
                        <th>Lớp</th>
                        <th v-if="!teacher">Giáo viên</th>
                        <th>Giờ vào</th>
                        <th>Giờ ra</th>
                        <th class="text-center">Số giờ</th>
                        <th>Tình trạng</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="ts in timesheets.data" :key="ts.id">
                        <td v-if="selectable">
                            <input v-if="ts.status === 'pending_review' && !ts.locked" v-model="selected" type="checkbox" :value="ts.id" :aria-label="`Chọn ca ${ts.id}`" class="rounded border-outline-variant text-primary-container" />
                        </td>
                        <td class="whitespace-nowrap font-mono">{{ formatDate(ts.teaching_date) }}</td>
                        <td>
                            <span class="block">{{ ts.scheduled_time ? (ts.shift_name ? ts.shift_name + ' · ' : 'Ca ') + ts.scheduled_time : 'Ngoài lịch' }}</span>
                            <span class="block font-body-small text-body-small text-on-surface-variant">{{ ts.type_label }} · {{ ts.source_label }}</span>
                        </td>
                        <td class="font-semibold text-primary">{{ ts.class_label }}</td>
                        <td v-if="!teacher">
                            <Link v-if="canViewAll" :href="urlWith({ user_id: ts.user_id, page: null })" class="font-semibold text-on-surface hover:text-primary">{{ ts.teacher_name }}</Link>
                            <template v-else>{{ ts.teacher_name }}</template>
                        </td>
                        <td class="font-mono">{{ ts.checkin_time || '--:--' }}<span v-if="ts.adjusted" class="text-primary" title="Chỉnh tay">*</span></td>
                        <td class="font-mono">
                            {{ ts.display_checkout || '--:--' }}<span v-if="ts.adjusted" class="text-primary" title="Chỉnh tay">*</span>
                            <span v-if="!ts.checkout_time && ts.display_checkout" class="block font-sans text-xs text-on-surface-variant">theo lịch</span>
                        </td>
                        <td class="text-center font-mono font-semibold">{{ ts.hours }}h</td>
                        <td>
                            <UiBadge :color="punchColors[ts.punch_state] ?? 'neutral'">{{ ts.punch_state_label }}</UiBadge>
                            <span v-if="ts.late_label" class="mt-xs block font-caption text-caption text-warning" data-testid="late-label">{{ ts.late_label }}</span>
                            <span v-if="ts.adjusted" class="mt-xs block max-w-[200px] font-body-small text-body-small italic text-on-surface-variant" :title="ts.adjustment_reason">{{ ts.adjustment_short }} — {{ ts.adjuster_name }}</span>
                            <span v-else-if="ts.notes_short" class="mt-xs block max-w-[200px] font-body-small text-body-small italic text-on-surface-variant" title="Lý do chấm tay">{{ ts.notes_short }}</span>
                        </td>
                        <td>
                            <UiBadge :color="statusColors[ts.status] ?? 'neutral'">{{ ts.status === 'pending_review' ? 'Chờ đối soát' : ts.status_label }}</UiBadge>
                            <span v-if="ts.rejection_short" class="mt-xs block max-w-[200px] font-body-small text-body-small text-error">{{ ts.rejection_short }}</span>
                            <span v-else-if="ts.reviewer_name && ts.status === 'valid'" class="mt-xs block font-body-small text-body-small text-on-surface-variant">Đã duyệt bởi {{ ts.reviewer_name }}</span>
                        </td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-xs">
                                <span v-if="ts.locked" class="inline-flex items-center gap-xs font-body-small text-body-small text-on-surface-variant"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">lock</span>Đã khóa</span>
                                <template v-else>
                                    <template v-if="canReview && ts.status === 'pending_review'">
                                        <UiForm :action="route('payroll.timesheets.review', ts.id)" method="post">
                                            <UiButton type="submit" name="decision" value="valid" variant="ghost" size="sm" icon="check">Duyệt</UiButton>
                                        </UiForm>
                                        <UiButton variant="danger-text" size="sm" icon="close" @click="openReject(ts)">Từ chối</UiButton>
                                    </template>
                                    <UiButton v-if="canAdjust" variant="ghost" size="sm" icon="edit" aria-label="Chỉnh tay bổ sung" @click="openAdjust(ts)" />
                                </template>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!timesheets.data.length">
                        <td :colspan="columns"><UiEmptyState icon="more_time" title="Chưa có dữ liệu chấm công" description="Không có ca dạy nào khớp bộ lọc trong kỳ lương này." /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="timesheets" unit="ca dạy" /></template>
        </UiDataTable>

        <UiDataTable v-if="teacher && missingSessions.length" class="mt-lg" min-width="720px">
            <template #header>
                <h3 class="font-h3 text-h3 text-error">Buổi thiếu chấm công ({{ missingSessions.length }})</h3>
            </template>
            <table>
                <thead><tr><th>Ngày</th><th>Ca học</th><th>Lớp</th><th>Giờ vào</th><th>Giờ ra</th><th>Tình trạng</th><th class="text-right">Thao tác</th></tr></thead>
                <tbody>
                    <tr v-for="session in missingSessions" :key="session.id">
                        <td class="font-mono">{{ formatDate(session.date) }}</td>
                        <td>Ca {{ session.start_time }} - {{ session.end_time }}</td>
                        <td class="font-semibold text-primary">{{ session.class_label }}</td>
                        <td class="font-mono">--:--</td>
                        <td class="font-mono">--:--</td>
                        <td><UiBadge color="error">Thiếu chấm công</UiBadge></td>
                        <td class="text-right">
                            <UiButton v-if="canAdjust && !periodLocked" variant="ghost" size="sm" icon="timer" :href="session.manual_url">Chấm công tay</UiButton>
                        </td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>

        <UiModal v-if="canAdjust" :show="adjustOpen" title="Chỉnh tay bổ sung" max-width="md" data-modal="ts-adjust" @close="adjustOpen = false">
            <UiForm id="ts-adjust-form" :key="edit.action" :action="edit.action || '#'" method="put" preserve-state="errors" class="space-y-md">
                <p class="font-body-small text-body-small text-on-surface-variant">{{ edit.label }}</p>
                <UiAlert type="info">Ca sau khi chỉnh chuyển về <strong>Chờ đối soát</strong>. Kỳ lương đã chốt thì không thể chỉnh sửa.</UiAlert>
                <div class="grid grid-cols-2 gap-md">
                    <UiInput v-model="edit.timeIn" type="time" name="time_in" label="Giờ vào" required />
                    <UiInput v-model="edit.timeOut" type="time" name="time_out" label="Giờ ra" required />
                </div>
                <div class="grid grid-cols-2 gap-md">
                    <UiInput v-model="edit.late" type="number" name="late_minutes" label="Đi muộn (phút)" min="0" max="600" step="1" />
                    <UiInput v-model="edit.early" type="number" name="early_leave_minutes" label="Về sớm (phút)" min="0" max="600" step="1" />
                </div>
                <UiCheckbox v-model="edit.notified" name="late_notified" value="1" label="Có báo trước (trả theo số phút thực dạy)" />
                <p class="font-caption text-caption text-on-surface-variant">Không báo trước: dưới ngưỡng trừ theo từng phút; từ ngưỡng trở lên không tính buổi (cấu hình ở Tham số lương).</p>
                <UiTextarea name="adjustment_reason" label="Lý do điều chỉnh" required rows="3" placeholder="Nhập lý do..." />
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="adjustOpen = false">Hủy</UiButton>
                <UiButton type="submit" form="ts-adjust-form">Lưu thay đổi</UiButton>
            </template>
        </UiModal>

        <UiModal v-if="canReview" :show="rejectOpen" title="Từ chối ca dạy" max-width="md" data-modal="ts-reject" @close="rejectOpen = false">
            <UiForm id="ts-reject-form" :key="reject.action" :action="reject.action || '#'" method="post" preserve-state="errors" class="space-y-md">
                <input type="hidden" name="decision" value="invalid" />
                <p class="font-body-small text-body-small text-on-surface-variant">{{ reject.label }}</p>
                <UiTextarea name="rejection_reason" label="Lý do từ chối" required rows="3" placeholder="VD: Không có buổi học trên lịch, trùng ca đã chấm..." />
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="rejectOpen = false">Hủy</UiButton>
                <UiButton type="submit" variant="danger" form="ts-reject-form">Từ chối ca dạy</UiButton>
            </template>
        </UiModal>
    </div>
</template>
