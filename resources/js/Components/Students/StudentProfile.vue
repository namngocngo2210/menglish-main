<script setup>
/**
 * Hồ sơ học sinh — dùng chung cho "Chi tiết hồ sơ học sinh" và "Hồ sơ học sinh (phân quyền)". Thiết kế lại 27/10/2026 (bớt thông tin):
 *   đầu trang (tên, trạng thái, liên hệ chính, thao tác) · 4 số chính (lớp chính, chuyên cần, buổi tồn, công nợ) ·
 *   dòng chăm sóc tháng đầu (mở ra xem từng mốc) · 4 tab: Tổng quan / Buổi học & điểm danh / Học phí / Hồ sơ cá nhân.
 *   Sửa thông tin: popup (không còn form mở sẵn). Tab giữ trên URL (#buoi-hoc, #hoc-phi, #ho-so).
 * Server chỉ gửi phần người xem có quyền (StudentProfileController::profileProps):
 *   canEdit → nút "Sửa thông tin" · canChangeStatus → menu "Đổi trạng thái" (không có quyền: ẩn nút)
 *   canViewAcademic → lớp học, buổi học, chuyên cần, chăm sóc tháng đầu · canViewContact → SĐT, email, địa chỉ · canViewTuition → học phí
 * Không có trạng thái "Học thử" (A6 Q5: hồ sơ chỉ tồn tại sau khi chốt).
 */
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { can } from '@/lib/can';
import { openRemoteModal } from '@/lib/remoteModal';
import { route } from '@/lib/route';

// Trang truyền nguyên $attrs (mọi prop hồ sơ); chỉ nhận prop khai báo dưới đây, không đổ thuộc tính lạ ra HTML.
defineOptions({ inheritAttrs: false });

const props = defineProps({
    student: { type: Object, required: true },
    viewerRoleLabel: { type: String, default: 'Người dùng' },
    statusOptions: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
    canChangeStatus: { type: Boolean, default: false },
    canViewAcademic: { type: Boolean, default: false },
    canViewContact: { type: Boolean, default: false },
    canViewTuition: { type: Boolean, default: false },
    canAssignClass: { type: Boolean, default: false },
    scopedView: { type: Boolean, default: false },
    sessions: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    attendanceStats: { type: Object, default: null },
    attendances: { type: Array, default: () => [] },
    linkableClasses: { type: Array, default: () => [] },
    mainClass: { type: Object, default: null },
    care: { type: Object, default: null },
    tuition: { type: Object, default: null },
    results: { type: Object, default: null },
    feedback: { type: Object, default: null },
});

// Tab Kết quả học tập / Feedback.
const bigTestTone = { draft: 'neutral', pending_review: 'warning', approved: 'success', sent: 'success' };
const hasResults = computed(() => !!props.results && ['big_tests', 'mini_tests', 'homework', 'practice'].some((k) => props.results[k].length));
const remarkFields = [
    ['attitude', 'Tinh thần'],
    ['result', 'Kết quả'],
    ['hw_score', 'Điểm BTVN'],
    ['grammar', 'Ngữ pháp / thực hành'],
    ['monsters', 'Monsters'],
];
const stars = (n) => '★'.repeat(n ?? 0) + '☆'.repeat(Math.max(0, 5 - (n ?? 0)));
const ratingTone = (n) => (n == null ? 'text-on-surface-variant' : n <= 2 ? 'text-error' : n === 3 ? 'text-warning' : 'text-tertiary');
const scoreText = (v) => (v === null || v === undefined || v === '' ? '—' : v);

const card = 'rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm';
const sectionTitle = 'font-body-medium text-body-medium font-semibold text-on-surface';
const menuItem = 'flex w-full items-center gap-sm px-md py-sm text-left font-body-small text-body-small text-on-surface hover:bg-surface-container-low';
const genders = [{ value: 'Nam', label: 'Nam' }, { value: 'Nữ', label: 'Nữ' }, { value: 'Khác', label: 'Khác' }];
// Giá trị cũ ngoài danh sách (nhập Excel CRM ghi tự do) vẫn hiện để lưu form không làm mất.
const genderOptions = computed(() => (props.student.gender && !genders.some((g) => g.value === props.student.gender) ? [...genders, { value: props.student.gender, label: props.student.gender }] : genders));
const otherStatuses = computed(() => props.statusOptions.filter((s) => s.value !== props.student.status));
const taskTone = (status) => (status === 'completed' ? 'text-tertiary' : status === 'overdue' ? 'text-error' : 'text-warning');
const receiptUrl = computed(() => route('tuition.receipts.create', { student_id: props.student.id }));
const openReceipt = () => openRemoteModal(receiptUrl.value, { size: '4xl' });
// Lịch sử thu học phí đầy đủ (mọi đợt nộp, còn nợ sau từng đợt) — modal Tuition/StudentPayments.
const paymentsUrl = computed(() => route('tuition.students.payments', props.student.id));
const openPayments = () => openRemoteModal(paymentsUrl.value, { size: '2xl' });

// ── Popup ──
const editOpen = ref(false);
const linkOpen = ref(false);
// "Chuyển lớp": đổi lớp chính từ ngày chuyển (được chọn ngày đã qua khi cập nhật trễ), buổi tồn giữ nguyên.
const transferOpen = ref(false);
const classActions = computed(() => !props.scopedView && props.canAssignClass && props.canViewAcademic && !props.student.dropped);
const canReceipt = computed(() => props.canViewTuition && can('tuition.create'));

// ── Tab (giữ trên URL hash để tải lại / quay lại vẫn đúng tab) ──
const tabs = computed(() => [
    { key: 'overview', hash: '', label: 'Tổng quan', icon: 'dashboard' },
    ...(props.canViewAcademic ? [{ key: 'sessions', hash: '#buoi-hoc', label: 'Buổi học & điểm danh', icon: 'event_note', count: props.sessions.length }] : []),
    ...(props.canViewAcademic && props.results ? [{ key: 'results', hash: '#ket-qua', label: 'Kết quả học tập', icon: 'school' }] : []),
    ...(props.canViewAcademic && props.feedback ? [{ key: 'feedback', hash: '#feedback', label: 'Feedback', icon: 'forum', count: props.feedback.remarks.length + (props.feedback.parent ? props.feedback.parent.sessions.length + props.feedback.parent.stages.length : 0) || null }] : []),
    ...(props.canViewTuition ? [{ key: 'tuition', hash: '#hoc-phi', label: 'Học phí', icon: 'payments' }] : []),
    { key: 'profile', hash: '#ho-so', label: 'Hồ sơ cá nhân', icon: 'badge' },
]);
const tab = ref('overview');
function selectTab(t) {
    tab.value = t.key;
    window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search + t.hash);
}
onMounted(() => {
    const found = tabs.value.find((t) => t.hash && t.hash === window.location.hash);
    if (found) tab.value = found.key;
});

// ── Số chính ──
const mainClassCard = computed(() => props.classes.find((c) => c.is_main) ?? props.classes[0] ?? null);
const linkedCount = computed(() => props.classes.filter((c) => !c.is_main).length);
const statCols = computed(() => (props.canViewAcademic ? 2 : 0) + (props.canViewTuition && props.tuition ? 2 : 0));

// ── Buổi học ──
const sessionFilter = ref('all');
const showAll = ref(false);
const sessionCounts = computed(() => ({
    all: props.sessions.length,
    done: props.sessions.filter((s) => s.state === 'done').length,
    upcoming: props.sessions.filter((s) => s.state === 'upcoming').length,
    cancelled: props.sessions.filter((s) => s.state === 'cancelled').length,
}));
const sessionFilters = [
    { value: 'all', label: 'Tất cả' },
    { value: 'done', label: 'Đã học' },
    { value: 'upcoming', label: 'Sắp tới' },
    { value: 'cancelled', label: 'Đã hủy' },
];
const filteredSessions = computed(() => props.sessions.filter((s) => sessionFilter.value === 'all' || s.state === sessionFilter.value));
const visibleSession = (session) => {
    if (showAll.value) return true;
    return filteredSessions.value.indexOf(session) < 15;
};
// Tổng quan: buổi sắp tới gần nhất + 3 buổi đã học gần nhất.
const recentSessions = computed(() => {
    const next = props.sessions.find((s) => s.is_next);
    const past = props.sessions.filter((s) => s.state === 'done').slice(-3).reverse();
    return next ? [next, ...past] : past;
});
// Điểm danh không gắn buổi học nào trong lộ trình (dữ liệu cũ / nhập tay) — vẫn hiện để không mất lịch sử.
const offScheduleAttendances = computed(() => props.attendances.filter((a) => a.off_schedule));
</script>

<template>
    <div class="space-y-md">
        <!-- 1. Đầu trang: tên, trạng thái, liên hệ chính, thao tác -->
        <section :class="[card, 'flex flex-col gap-md p-md sm:flex-row sm:items-start sm:p-lg']" data-section="header">
            <UiAvatar :name="student.name" size="lg" />
            <div class="min-w-0 flex-1 space-y-xs">
                <div class="flex flex-wrap items-center gap-sm">
                    <h2 class="font-h2 text-h2 text-on-surface">{{ student.name }}</h2>
                    <UiBadge :color="student.status_color" pill>{{ student.status_label }}</UiBadge>
                    <UiBadge v-if="student.long_term" color="primary" pill :dot="false">Học viên lâu năm · {{ student.tenure_label }}</UiBadge>
                </div>
                <p class="flex flex-wrap gap-x-md gap-y-xs font-body-small text-body-small text-on-surface-variant">
                    <span class="font-code text-on-surface">{{ student.code }}</span>
                    <span>{{ student.dob_label ?? 'Chưa có ngày sinh' }}<template v-if="student.gender"> · {{ student.gender }}</template></span>
                    <span v-if="student.school_grade || student.school">
                        <template v-if="student.school_grade">Lớp {{ student.school_grade }}</template><template v-if="student.school_grade && student.school"> · </template>{{ student.school }}
                    </span>
                    <span v-if="student.branch">{{ student.branch }}</span>
                </p>
                <p v-if="canViewContact" data-section="contact" class="flex flex-wrap gap-x-lg gap-y-xs font-body-small text-body-small">
                    <span v-if="student.phone"><span class="text-on-surface-variant">Học viên:</span> <a :href="`tel:${student.phone}`" class="font-medium tabular-nums text-primary hover:underline">{{ student.phone }}</a></span>
                    <span v-if="student.parent_phone">
                        <span class="text-on-surface-variant">{{ student.parent_name ? `Phụ huynh ${student.parent_name}:` : 'Phụ huynh:' }}</span>
                        <a :href="`tel:${student.parent_phone}`" class="font-medium tabular-nums text-primary hover:underline">{{ student.parent_phone }}</a>
                    </span>
                </p>
                <p v-else class="font-caption text-caption italic text-on-surface-variant">Thông tin liên hệ ẩn theo phân quyền</p>
            </div>
            <div class="flex flex-wrap items-center gap-sm sm:justify-end">
                <UiButton v-if="canEdit" variant="secondary" icon="edit" data-testid="open-student-edit" @click="editOpen = true">Sửa thông tin</UiButton>
                <template v-if="canChangeStatus">
                    <!-- Kết thúc bảo lưu: về Đang học (lớp đã khai giảng) / Chờ khai giảng, bỏ đóng băng học phí, báo Học vụ. -->
                    <UiForm
                        v-if="student.status === 'deferred'"
                        :action="route('students.end-deferral', student.id)"
                        method="post"
                        confirm="Kết thúc bảo lưu cho học viên này? Học viên sẽ về Đang học (hoặc Chờ khai giảng nếu lớp chưa khai giảng) và công nợ, nhắc nợ chạy lại."
                        confirm-label="Kết thúc bảo lưu"
                    >
                        <UiButton type="submit" variant="secondary" icon="play_circle">
                            Kết thúc bảo lưu
                            <span v-if="student.deferred_until" class="text-on-surface-variant">(hạn {{ student.deferred_until }})</span>
                        </UiButton>
                    </UiForm>
                    <UiDropdown width="56">
                        <template #trigger="{ open }">
                            <UiButton variant="secondary" icon="sync" :aria-expanded="open ? 'true' : 'false'">
                                Đổi trạng thái <span class="material-symbols-outlined text-[18px]" aria-hidden="true">expand_more</span>
                            </UiButton>
                        </template>
                        <template #content>
                            <UiForm
                                v-for="option in otherStatuses"
                                :key="option.value"
                                :action="route('students.status.update', student.id)"
                                method="put"
                                :confirm="option.value === 'dropped' ? 'Chuyển sang Thôi học sẽ đưa học viên ra khỏi danh sách lớp đang học (vẫn giữ lịch sử). Tiếp tục?' : null"
                                :confirm-label="option.value === 'dropped' ? 'Chuyển sang Thôi học' : 'Đồng ý'"
                                :danger="option.value === 'dropped'"
                            >
                                <input type="hidden" name="status" :value="option.value" />
                                <button type="submit" role="menuitem" :class="menuItem">
                                    <UiBadge :color="option.color" class="!bg-transparent !px-0">{{ option.label }}</UiBadge>
                                </button>
                            </UiForm>
                        </template>
                    </UiDropdown>
                </template>
                <UiDropdown v-if="classActions && (linkableClasses.length || mainClass)" width="56">
                    <template #trigger="{ open }">
                        <UiButton variant="secondary" icon="class" :aria-expanded="open ? 'true' : 'false'">
                            Lớp học <span class="material-symbols-outlined text-[18px]" aria-hidden="true">expand_more</span>
                        </UiButton>
                    </template>
                    <template #content>
                        <button v-if="mainClass && linkableClasses.length" type="button" role="menuitem" :class="menuItem" data-testid="open-transfer-class" @click="transferOpen = true">
                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">swap_horiz</span>Chuyển lớp
                        </button>
                        <button v-if="linkableClasses.length" type="button" role="menuitem" :class="menuItem" data-testid="open-link-class" @click="linkOpen = true">
                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">add_link</span>Liên kết lớp khác
                        </button>
                        <p v-if="!linkableClasses.length" class="px-md py-sm font-caption text-caption text-on-surface-variant">Không còn lớp phù hợp để chuyển / liên kết.</p>
                    </template>
                </UiDropdown>
                <UiButton v-if="canReceipt" icon="add" @click="openReceipt">Thêm phiếu thu</UiButton>
            </div>
        </section>

        <!-- 2. Số chính -->
        <section v-if="statCols" :class="['grid grid-cols-2 gap-sm', statCols === 4 ? 'lg:grid-cols-4' : '']" data-section="stats">
            <template v-if="canViewAcademic">
                <div :class="[card, 'p-md']" data-section="classes">
                    <p class="font-body-small text-body-small text-on-surface-variant">Lớp chính</p>
                    <template v-if="mainClassCard">
                        <p class="truncate font-h3 text-h3 font-bold text-on-surface" :title="mainClassCard.name">{{ mainClassCard.name }}</p>
                        <p class="font-caption text-caption text-on-surface-variant">
                            {{ mainClassCard.remaining ?? mainClassCard.period }}<template v-if="linkedCount"> · +{{ linkedCount }} lớp liên kết</template>
                        </p>
                    </template>
                    <p v-else class="font-h3 text-h3 font-bold text-on-surface-variant">Chưa xếp lớp</p>
                </div>
                <div :class="[card, 'p-md']" data-section="academic">
                    <p class="font-body-small text-body-small text-on-surface-variant">Chuyên cần</p>
                    <template v-if="attendanceStats?.rate !== null && attendanceStats?.rate !== undefined">
                        <p :class="['font-h3 text-h3 font-bold', attendanceStats.rate >= 80 ? 'text-tertiary' : attendanceStats.rate >= 60 ? 'text-warning' : 'text-error']">{{ attendanceStats.rate }}%</p>
                        <div class="my-xs h-1.5 rounded-full bg-surface-container-high" aria-hidden="true">
                            <div class="h-1.5 rounded-full bg-tertiary" :style="{ width: `${attendanceStats.rate}%` }"></div>
                        </div>
                        <p class="font-caption text-caption text-on-surface-variant">{{ attendanceStats.present }} có mặt · {{ attendanceStats.absent }} vắng / {{ attendanceStats.recorded }} buổi</p>
                    </template>
                    <template v-else>
                        <p class="font-h3 text-h3 font-bold text-on-surface-variant">—</p>
                        <p class="font-caption text-caption text-on-surface-variant">Chưa có điểm danh</p>
                    </template>
                </div>
            </template>
            <template v-if="canViewTuition && tuition">
                <button type="button" :class="[card, 'p-md text-left transition-colors hover:border-primary']" title="Xem học phí" @click="selectTab({ key: 'tuition', hash: '#hoc-phi' })">
                    <p class="font-body-small text-body-small text-on-surface-variant">Buổi tồn</p>
                    <template v-if="tuition.sessions">
                        <p :class="['font-h3 text-h3 font-bold', tuition.sessions.balance < 0 ? 'text-error' : 'text-on-surface']">{{ tuition.sessions.balance }} buổi</p>
                        <p class="font-caption text-caption text-on-surface-variant">Đã đóng {{ tuition.sessions.paid }} · đã trừ {{ tuition.sessions.used }}</p>
                    </template>
                    <p v-else class="font-h3 text-h3 font-bold text-on-surface-variant">—</p>
                </button>
                <button
                    type="button"
                    :class="[card, 'p-md text-left transition-colors hover:border-primary', tuition.debt_amount > 0 ? '!border-error/40' : '']"
                    title="Xem học phí"
                    @click="selectTab({ key: 'tuition', hash: '#hoc-phi' })"
                >
                    <p class="font-body-small text-body-small text-on-surface-variant">Công nợ</p>
                    <p :class="['font-h3 text-h3 font-bold', tuition.debt_amount > 0 ? 'text-error' : 'text-tertiary']">{{ formatMoney(tuition.debt_amount) }}</p>
                    <p class="font-caption text-caption text-on-surface-variant">Đã nộp {{ formatMoney(tuition.paid_amount) }} / {{ formatMoney(tuition.final_amount) }}</p>
                </button>
            </template>
        </section>

        <!-- 3. Chăm sóc tháng đầu: 3 mốc gate hoa hồng A6 — một dòng, mở ra xem từng mốc -->
        <details
            v-if="canViewAcademic && care"
            :class="['group rounded-xl border', care.overdue ? 'border-error/40 bg-error-container/30' : care.completed >= 3 ? 'border-outline-variant bg-surface-container-lowest' : 'border-warning/40 bg-warning-container/30']"
            :open="care.overdue > 0"
            data-section="first-month-care"
        >
            <summary class="flex cursor-pointer list-none flex-wrap items-center gap-sm px-md py-sm font-body-small text-body-small">
                <span class="material-symbols-outlined text-[18px] text-primary" aria-hidden="true">volunteer_activism</span>
                <span class="font-semibold text-on-surface">Chăm sóc tháng đầu</span>
                <UiBadge :color="care.completed >= 3 ? 'success' : 'warning'" pill>{{ care.completed }}/3 mốc</UiBadge>
                <UiBadge v-if="care.overdue" color="error" pill>Quá hạn chăm sóc</UiBadge>
                <span class="text-on-surface-variant">
                    <template v-for="item in care.items" :key="item.key">{{ item.done ? '✓' : '○' }} {{ item.label.split(' — ')[0] }}&nbsp;&nbsp;</template>
                </span>
                <span class="ml-auto inline-flex items-center gap-xs font-semibold text-primary">
                    Chi tiết <span class="material-symbols-outlined text-[18px] transition-transform group-open:rotate-180" aria-hidden="true">expand_more</span>
                </span>
            </summary>
            <div class="border-t border-surface-container">
                <p class="px-md pt-sm font-caption text-caption text-on-surface-variant">
                    <template v-if="care.closing">Ngày chốt: <strong class="text-on-surface">{{ care.closing }}</strong> · </template>
                    <template v-if="care.start">Bắt đầu học: <strong class="text-on-surface">{{ care.start }}</strong></template>
                    <template v-else>Chưa có buổi học/xếp lớp</template>
                    <template v-if="care.customer_id && can('lead.view')">
                        · <Link :href="route('crm.customers.show', care.customer_id)" class="font-semibold text-primary hover:underline">Checklist bên CRM</Link>
                    </template>
                </p>
                <ul class="divide-y divide-surface-container">
                    <li v-for="item in care.items" :key="item.key" class="flex flex-col justify-between gap-sm px-md py-sm font-body-small text-body-small sm:flex-row sm:items-center">
                        <div class="flex items-start gap-sm">
                            <span :class="['material-symbols-outlined text-[18px]', item.done ? 'text-tertiary' : 'text-outline-variant']" aria-hidden="true">{{ item.done ? 'check_circle' : 'radio_button_unchecked' }}</span>
                            <div>
                                <div class="flex flex-wrap items-center gap-xs font-semibold text-on-surface">
                                    {{ item.label }}
                                    <UiBadge v-if="item.overdue" color="error" :dot="false">Quá hạn chăm sóc</UiBadge>
                                </div>
                                <div class="font-caption text-caption text-on-surface-variant">
                                    {{ item.due ? 'Hạn SLA: ' + item.due : item.waiting }}
                                    <template v-if="item.crm_done_at"> · Đã đánh dấu bên CRM {{ item.crm_done_at }}</template>
                                </div>
                            </div>
                        </div>
                        <div class="font-caption text-caption sm:text-right">
                            <template v-if="item.task">
                                <div class="text-on-surface-variant">
                                    Đã giao task cho <span class="font-semibold text-on-surface" :title="item.task.assignee_email">{{ item.task.assignee }}</span>
                                    · <span :class="['font-semibold', taskTone(item.task.status)]">{{ item.task.status_label }}</span>
                                </div>
                                <div v-if="item.task.penalty_code" class="font-semibold text-error">
                                    Quá SLA ·
                                    <Link v-if="can('violation.view')" :href="route('penalties.index', { search: item.task.penalty_code })" class="hover:underline">biên bản {{ item.task.penalty_code }}</Link>
                                    <template v-else>biên bản {{ item.task.penalty_code }}</template>
                                </div>
                            </template>
                            <span v-else class="text-on-surface-variant">Chưa giao task</span>
                        </div>
                    </li>
                </ul>
            </div>
        </details>

        <!-- 4. Tab -->
        <section :class="[card, 'overflow-hidden']">
            <div class="px-md" role="tablist">
                <UiTabs>
                    <UiTab v-for="t in tabs" :key="t.key" :active="tab === t.key" :icon="t.icon" :count="t.count ?? null" role="tab" :aria-selected="tab === t.key ? 'true' : 'false'" @click="selectTab(t)">
                        {{ t.label }}
                    </UiTab>
                </UiTabs>
            </div>

            <!-- Tab Tổng quan -->
            <div v-show="tab === 'overview'" class="grid grid-cols-1 lg:grid-cols-3" data-tab="overview">
                <div class="space-y-lg p-md lg:col-span-2 lg:border-r lg:border-surface-container lg:p-lg">
                    <template v-if="canViewAcademic">
                        <div class="space-y-sm">
                            <h3 :class="sectionTitle">Lớp đang học</h3>
                            <div
                                v-for="cls in classes"
                                :key="cls.id"
                                class="grid grid-cols-1 gap-sm rounded-lg border border-outline-variant p-md font-body-small text-body-small sm:grid-cols-3 sm:items-center"
                            >
                                <div>
                                    <p class="font-semibold text-on-surface">
                                        {{ cls.name }}
                                        <span :class="['font-caption text-caption', cls.is_main ? 'text-primary' : 'text-on-surface-variant']">({{ cls.is_main ? 'Lớp chính' : 'Liên kết' }})</span>
                                    </p>
                                    <p class="font-caption text-caption text-on-surface-variant">{{ cls.teacher ? 'GV ' + cls.teacher : 'Chưa có GV' }} · {{ cls.branch ?? 'Chưa phân cơ sở' }}</p>
                                </div>
                                <div>
                                    <p class="font-caption text-caption text-on-surface-variant">Lịch học</p>
                                    <p class="text-on-surface">{{ cls.schedule_text || 'Chưa xếp lịch' }}</p>
                                </div>
                                <div>
                                    <p class="font-caption text-caption text-on-surface-variant">Thời gian</p>
                                    <p class="text-on-surface"><span class="font-code">{{ cls.period }}</span><template v-if="cls.remaining"> · {{ cls.remaining }}</template></p>
                                </div>
                            </div>
                            <UiEmptyState v-if="!classes.length" icon="group_off" title="Chưa xếp lớp" description="Học viên chưa thuộc lớp nào đang hoạt động." />
                            <p v-if="classActions && !linkableClasses.length && classes.length" class="font-caption text-caption text-on-surface-variant">Không còn lớp phù hợp để liên kết thêm.</p>
                        </div>

                        <div class="space-y-sm">
                            <div class="flex items-center justify-between gap-sm">
                                <h3 :class="sectionTitle">Buổi học gần đây</h3>
                                <button v-if="sessions.length" type="button" class="font-body-small text-body-small font-semibold text-primary hover:underline" @click="selectTab({ key: 'sessions', hash: '#buoi-hoc' })">
                                    Xem tất cả {{ sessions.length }} buổi
                                </button>
                            </div>
                            <ul v-if="recentSessions.length" class="divide-y divide-surface-container overflow-hidden rounded-lg border border-outline-variant font-body-small text-body-small">
                                <li v-for="s in recentSessions" :key="s.id" :class="['grid grid-cols-[minmax(0,1fr)_auto] gap-sm px-md py-sm sm:grid-cols-[180px_minmax(0,1fr)_auto]', s.is_next ? 'bg-primary-fixed/40' : '']">
                                    <span class="font-semibold text-on-surface">{{ s.date }} · {{ s.time.split(' - ')[0] }}</span>
                                    <span class="col-span-2 row-start-2 text-on-surface-variant sm:col-span-1 sm:row-start-auto">{{ s.heading }}<template v-if="s.sub"> — {{ s.sub }}</template> · {{ s.class_name }}</span>
                                    <span class="whitespace-nowrap text-right font-semibold">
                                        <span v-if="s.is_next" class="text-primary">Sắp diễn ra</span>
                                        <span v-else-if="s.attendance" :class="s.attendance.tone">{{ s.attendance.label }}</span>
                                        <span v-else class="text-warning">Chưa điểm danh</span>
                                    </span>
                                </li>
                            </ul>
                            <p v-else class="rounded-lg border border-dashed border-outline-variant p-md text-center font-body-small text-body-small text-on-surface-variant">
                                {{ !classes.length ? 'Học viên chưa được xếp lớp nên chưa có buổi học.' : 'Lớp của học viên chưa được sinh lịch buổi học.' }}
                            </p>
                        </div>
                    </template>
                    <div v-else class="flex items-center gap-md rounded-lg bg-surface-container-low p-md">
                        <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">lock</span>
                        <p class="font-body-small text-body-small text-on-surface-variant">Lớp học, buổi học và chuyên cần ẩn theo phân quyền (cần quyền xem điểm danh học viên).</p>
                    </div>
                </div>

                <div class="space-y-lg bg-surface-container-low/40 p-md lg:p-lg">
                    <div v-if="canViewContact && (student.parent_name || student.parent_phone || student.parent2_name || student.parent2_phone)" class="space-y-sm">
                        <h3 :class="sectionTitle">Phụ huynh</h3>
                        <div v-if="student.parent_name || student.parent_phone" class="font-body-small text-body-small">
                            <p class="font-semibold text-on-surface">{{ student.parent_name || 'Phụ huynh' }}</p>
                            <p class="text-on-surface-variant">{{ [student.parent_phone, student.parent_email].filter(Boolean).join(' · ') || '—' }}</p>
                        </div>
                        <div v-if="student.parent2_name || student.parent2_phone" class="font-body-small text-body-small">
                            <p class="font-semibold text-on-surface">{{ student.parent2_name || 'Phụ huynh 2' }}</p>
                            <p class="text-on-surface-variant">{{ student.parent2_phone || '—' }}</p>
                        </div>
                    </div>
                    <div class="space-y-sm">
                        <h3 :class="sectionTitle">Học tập</h3>
                        <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-md gap-y-xs font-body-small text-body-small">
                            <dt class="text-on-surface-variant">Mục tiêu</dt>
                            <dd class="text-on-surface">{{ student.target || '—' }}</dd>
                            <dt class="text-on-surface-variant">Test đầu vào</dt>
                            <dd class="text-on-surface" data-field="entrance-score">{{ student.entrance_score || 'Chưa có' }}</dd>
                            <dt class="text-on-surface-variant">Bắt đầu học</dt>
                            <dd class="text-on-surface" data-field="study-started">
                                {{ student.study_started_on ?? 'Chưa vào học' }}
                                <span v-if="student.tenure_label" class="text-on-surface-variant">(đã học {{ student.tenure_label }})</span>
                            </dd>
                        </dl>
                    </div>
                    <div v-if="student.notes" class="space-y-sm">
                        <h3 :class="sectionTitle">Ghi chú</h3>
                        <p class="whitespace-pre-line rounded-lg bg-surface-container-low p-sm font-body-small text-body-small text-on-surface">{{ student.notes }}</p>
                    </div>
                </div>
            </div>

            <!-- Tab Buổi học & điểm danh (gộp lộ trình buổi học + lịch sử điểm danh) -->
            <div v-if="canViewAcademic" v-show="tab === 'sessions'" id="roadmap" class="space-y-md p-md lg:p-lg" data-section="roadmap" data-tab="sessions">
                <div class="flex flex-wrap items-center gap-sm">
                    <button
                        v-for="f in sessionFilters"
                        :key="f.value"
                        type="button"
                        :aria-pressed="sessionFilter === f.value ? 'true' : 'false'"
                        :class="[
                            'inline-flex items-center gap-xs rounded-full border px-md py-xs font-body-small text-body-small transition',
                            sessionFilter === f.value ? 'border-primary-container bg-primary-container/10 font-semibold text-primary' : 'border-outline-variant text-on-surface-variant hover:text-primary',
                        ]"
                        @click="sessionFilter = f.value; showAll = false"
                    >
                        {{ f.label }} <span class="font-code">{{ sessionCounts[f.value] }}</span>
                    </button>
                    <span v-if="attendanceStats?.recorded" class="font-body-small text-body-small text-on-surface-variant sm:ml-md">
                        Điểm danh: <strong class="text-tertiary">{{ attendanceStats.present }}</strong> có mặt / muộn ·
                        <strong class="text-error">{{ attendanceStats.absent }}</strong> vắng
                    </span>
                    <UiButton
                        v-if="!scopedView && sessions.length"
                        variant="secondary"
                        size="sm"
                        icon="download"
                        class="sm:ml-auto"
                        :href="route('students.show', { id: student.id, export: 'roadmap' })"
                        native
                    >
                        Tải Excel
                    </UiButton>
                </div>

                <UiEmptyState
                    v-if="!sessions.length"
                    icon="event_busy"
                    title="Chưa có buổi học nào"
                    :description="!classes.length ? 'Học viên chưa được xếp lớp nên chưa có lộ trình buổi học.' : 'Lớp của học viên chưa được sinh lịch buổi học.'"
                />
                <UiDataTable v-else min-width="820px">
                    <table class="font-body-small text-body-small">
                        <thead>
                            <tr>
                                <th>Ngày · giờ</th>
                                <th>Nội dung bài học</th>
                                <th>Lớp · giáo viên</th>
                                <th>Điểm danh</th>
                                <th>Ghi chú</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="session in filteredSessions" v-show="visibleSession(session)" :key="session.id" :class="session.is_next ? 'bg-primary-fixed/30' : ''">
                                <td class="whitespace-nowrap">
                                    <span :class="['block', session.state === 'cancelled' ? 'text-on-surface-variant' : 'font-semibold']">{{ session.date }}</span>
                                    <span class="font-code text-on-surface-variant">{{ session.time }}</span>
                                </td>
                                <td>
                                    <span class="block font-semibold">{{ session.heading }}</span>
                                    <span v-if="session.sub !== null" class="block text-on-surface-variant">{{ session.sub }}</span>
                                    <span v-if="session.type === 'makeup'" class="font-caption text-caption font-semibold text-warning">Học bù</span>
                                    <span v-else-if="session.type === 'support'" class="font-caption text-caption font-semibold text-secondary">Phụ đạo</span>
                                </td>
                                <td>
                                    <span class="block">{{ session.class_name }}<template v-if="session.room"> · {{ session.room }}</template></span>
                                    <span class="block text-on-surface-variant">{{ session.teacher ?? 'Chưa gán GV' }}</span>
                                </td>
                                <td class="whitespace-nowrap font-semibold">
                                    <span v-if="session.state === 'cancelled'" class="font-normal text-on-surface-variant">Đã hủy</span>
                                    <span v-else-if="session.attendance" :class="session.attendance.tone">{{ session.attendance.label }}</span>
                                    <span v-else-if="session.is_next" class="text-primary">Sắp diễn ra</span>
                                    <span v-else-if="session.state === 'upcoming'" class="font-normal text-on-surface-variant">Sắp tới</span>
                                    <span v-else class="text-warning">Chưa điểm danh</span>
                                </td>
                                <td class="text-on-surface-variant">{{ session.attendance?.note || '—' }}</td>
                            </tr>
                            <tr v-if="!filteredSessions.length">
                                <td colspan="5" class="text-center text-on-surface-variant">Không có buổi nào ở mục này.</td>
                            </tr>
                        </tbody>
                    </table>
                    <template v-if="filteredSessions.length > 15" #footer>
                        <div class="p-sm text-center">
                            <button type="button" class="inline-flex items-center gap-xs font-body-small text-body-small font-semibold text-primary hover:underline" @click="showAll = !showAll">
                                <span>{{ showAll ? 'Thu gọn' : `Xem thêm ${filteredSessions.length - 15} buổi` }}</span>
                                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ showAll ? 'expand_less' : 'expand_more' }}</span>
                            </button>
                        </div>
                    </template>
                </UiDataTable>

                <div v-if="offScheduleAttendances.length" id="attendance-history" class="space-y-sm">
                    <h3 :class="sectionTitle">Điểm danh ngoài lịch lớp</h3>
                    <ul class="divide-y divide-surface-container rounded-lg border border-outline-variant font-body-small text-body-small">
                        <li v-for="att in offScheduleAttendances" :key="att.id" class="flex flex-wrap items-center gap-md px-md py-sm">
                            <span class="font-code">{{ att.date }}</span>
                            <span class="font-semibold">{{ att.class_name }}</span>
                            <span :class="['font-semibold', att.tone]">{{ att.status_label }}</span>
                            <span class="text-on-surface-variant">{{ att.note || '' }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Tab Kết quả học tập: Big Test, mini test, bài tập về nhà đã chấm, đề luyện tập -->
            <div v-if="canViewAcademic && results" v-show="tab === 'results'" class="space-y-lg p-md lg:p-lg" data-section="results" data-tab="results">
                <UiEmptyState v-if="!hasResults" icon="school" title="Chưa có kết quả học tập" description="Điểm Big Test, mini test, bài tập về nhà đã chấm và đề luyện tập của học viên sẽ hiện ở đây." />
                <template v-else>
                    <section v-if="results.big_tests.length" class="space-y-sm" data-testid="results-big-tests">
                        <h3 :class="sectionTitle">Big Test</h3>
                        <UiDataTable min-width="720px">
                            <table class="font-body-small text-body-small">
                                <thead>
                                    <tr>
                                        <th>Bài test</th>
                                        <th class="text-center">Nghe</th>
                                        <th class="text-center">Đọc</th>
                                        <th class="text-center">Viết</th>
                                        <th class="text-center">Nói</th>
                                        <th class="text-center">Tổng</th>
                                        <th>Trạng thái</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="bt in results.big_tests" :key="bt.id">
                                        <td>
                                            <span class="block font-semibold">{{ bt.title }}</span>
                                            <span class="block text-on-surface-variant">{{ bt.date ?? '—' }}</span>
                                            <span v-if="bt.note" class="mt-xs block text-on-surface-variant">{{ bt.note }}</span>
                                        </td>
                                        <template v-if="bt.is_absent">
                                            <td colspan="5" class="text-center font-semibold text-error">Vắng thi</td>
                                        </template>
                                        <template v-else>
                                            <td class="text-center font-code">{{ scoreText(bt.listening) }}</td>
                                            <td class="text-center font-code">{{ scoreText(bt.reading) }}</td>
                                            <td class="text-center font-code">{{ scoreText(bt.writing) }}</td>
                                            <td class="text-center font-code">{{ scoreText(bt.speaking) }}</td>
                                            <td class="text-center font-code font-bold text-primary">{{ scoreText(bt.overall) }}</td>
                                        </template>
                                        <td><UiBadge :color="bigTestTone[bt.status] ?? 'neutral'" pill>{{ bt.status_label }}</UiBadge></td>
                                    </tr>
                                </tbody>
                            </table>
                        </UiDataTable>
                    </section>

                    <div class="grid grid-cols-1 gap-lg lg:grid-cols-2">
                        <section v-if="results.mini_tests.length" class="space-y-sm" data-testid="results-mini-tests">
                            <h3 :class="sectionTitle">Mini test</h3>
                            <ul class="divide-y divide-surface-container rounded-lg border border-outline-variant font-body-small text-body-small">
                                <li v-for="m in results.mini_tests" :key="m.id" class="flex items-start justify-between gap-md px-md py-sm">
                                    <span class="min-w-0">
                                        <span class="block font-semibold text-on-surface">{{ m.name }}</span>
                                        <span class="block text-on-surface-variant">{{ [m.date, m.class_name].filter(Boolean).join(' · ') }}</span>
                                        <span v-if="m.skills" class="block text-on-surface-variant">{{ m.skills }}</span>
                                        <span v-if="m.note" class="block text-on-surface-variant">{{ m.note }}</span>
                                    </span>
                                    <span class="shrink-0 font-code font-bold text-primary">{{ scoreText(m.score) }}<span v-if="m.max_score" class="font-normal text-on-surface-variant">/{{ m.max_score }}</span></span>
                                </li>
                            </ul>
                        </section>

                        <section v-if="results.practice.length" class="space-y-sm" data-testid="results-practice">
                            <h3 :class="sectionTitle">Đề luyện tập</h3>
                            <ul class="divide-y divide-surface-container rounded-lg border border-outline-variant font-body-small text-body-small">
                                <li v-for="a in results.practice" :key="a.id" class="flex items-start justify-between gap-md px-md py-sm">
                                    <span class="min-w-0">
                                        <span class="block font-semibold text-on-surface">{{ a.title }}</span>
                                        <span class="block text-on-surface-variant">{{ a.date ?? '—' }}<template v-if="a.total"> · đúng {{ a.correct }}/{{ a.total }} câu</template></span>
                                    </span>
                                    <span class="shrink-0 font-code font-bold text-primary">{{ scoreText(a.score) }}</span>
                                </li>
                            </ul>
                        </section>
                    </div>

                    <section v-if="results.homework.length" class="space-y-sm" data-testid="results-homework">
                        <h3 :class="sectionTitle">Bài tập về nhà đã chấm <span class="font-normal text-on-surface-variant">· đã nộp {{ results.homework_submitted }} bài</span></h3>
                        <ul class="divide-y divide-surface-container rounded-lg border border-outline-variant font-body-small text-body-small">
                            <li v-for="h in results.homework" :key="h.id" class="flex items-start justify-between gap-md px-md py-sm">
                                <span class="min-w-0">
                                    <span class="block font-semibold text-on-surface">{{ h.title }}</span>
                                    <span class="block text-on-surface-variant">{{ [h.basket, h.date].filter(Boolean).join(' · ') }}</span>
                                    <span v-if="h.feedback" class="mt-xs block text-on-surface">“{{ h.feedback }}”</span>
                                </span>
                                <span class="shrink-0 font-code font-bold text-primary">{{ scoreText(h.score) }}</span>
                            </li>
                        </ul>
                    </section>
                </template>
            </div>

            <!-- Tab Feedback: nhận xét của GV theo buổi + (người có quyền) feedback phụ huynh gửi từ cổng học viên -->
            <div v-if="canViewAcademic && feedback" v-show="tab === 'feedback'" class="grid grid-cols-1 gap-lg p-md lg:grid-cols-5 lg:p-lg" data-section="feedback" data-tab="feedback">
                <section :class="['space-y-sm', feedback.parent ? 'lg:col-span-3' : 'lg:col-span-5']" data-testid="feedback-remarks">
                    <h3 :class="sectionTitle">Nhận xét của giáo viên</h3>
                    <ul v-if="feedback.remarks.length" class="space-y-sm">
                        <li v-for="r in feedback.remarks" :key="r.session_id" class="rounded-lg border border-outline-variant p-md font-body-small text-body-small">
                            <div class="flex flex-wrap items-baseline justify-between gap-sm">
                                <span class="font-semibold text-on-surface">{{ r.date }} · {{ r.class_name }}</span>
                                <span class="text-on-surface-variant">{{ r.teacher ?? '—' }}</span>
                            </div>
                            <p v-if="r.comment" class="mt-xs text-on-surface">{{ r.comment }}</p>
                            <dl class="mt-xs flex flex-wrap gap-x-md gap-y-xs">
                                <template v-for="[key, label] in remarkFields" :key="key">
                                    <div v-if="r[key]" class="flex gap-xs">
                                        <dt class="text-on-surface-variant">{{ label }}:</dt>
                                        <dd class="font-medium text-on-surface">{{ r[key] }}</dd>
                                    </div>
                                </template>
                            </dl>
                        </li>
                    </ul>
                    <p v-else class="rounded-lg border border-dashed border-outline-variant p-md text-center font-body-small text-body-small text-on-surface-variant">Giáo viên chưa gửi nhận xét buổi học nào cho học viên.</p>
                </section>

                <section v-if="feedback.parent" class="space-y-sm lg:col-span-2" data-testid="feedback-parent">
                    <div class="flex flex-wrap items-baseline justify-between gap-sm">
                        <h3 :class="sectionTitle">Phụ huynh đánh giá</h3>
                        <span v-if="feedback.parent.average !== null" class="font-body-small text-body-small text-on-surface-variant">
                            TB <strong :class="ratingTone(Math.round(feedback.parent.average))">{{ feedback.parent.average }}★</strong>
                            <template v-if="feedback.parent.low"> · <strong class="text-error">{{ feedback.parent.low }}</strong> buổi sao thấp</template>
                        </span>
                    </div>
                    <template v-if="feedback.parent.sessions.length || feedback.parent.stages.length">
                        <ul class="space-y-sm">
                            <li v-for="st in feedback.parent.stages" :key="`stage-${st.id}`" class="rounded-lg border border-primary-container/40 bg-primary-fixed/20 p-md font-body-small text-body-small">
                                <div class="flex flex-wrap items-baseline justify-between gap-sm">
                                    <span class="font-semibold text-on-surface">Feedback chặng<template v-if="st.stage_name"> · {{ st.stage_name }}</template></span>
                                    <span v-if="st.rating" :class="['tracking-tight', ratingTone(st.rating)]" :aria-label="`${st.rating} trên 5 sao`">{{ stars(st.rating) }}</span>
                                </div>
                                <p class="text-on-surface-variant">{{ [st.class_name, st.date].filter(Boolean).join(' · ') }}</p>
                                <p v-if="st.categories.length" class="mt-xs text-on-surface-variant">{{ st.categories.join(' · ') }}</p>
                                <p v-if="st.content" class="mt-xs text-on-surface">“{{ st.content }}”</p>
                            </li>
                            <li v-for="f in feedback.parent.sessions" :key="f.id" class="rounded-lg border border-outline-variant p-md font-body-small text-body-small">
                                <div class="flex flex-wrap items-baseline justify-between gap-sm">
                                    <span class="font-semibold text-on-surface">Buổi {{ f.date ?? '—' }}</span>
                                    <span :class="['tracking-tight', ratingTone(f.rating)]" :aria-label="`${f.rating ?? 0} trên 5 sao`">{{ stars(f.rating) }}</span>
                                </div>
                                <p class="text-on-surface-variant">{{ [f.class_name, f.teacher].filter(Boolean).join(' · ') }}</p>
                                <p v-if="f.categories.length" class="mt-xs text-on-surface-variant">{{ f.categories.join(' · ') }}</p>
                                <p v-if="f.content" class="mt-xs text-on-surface">“{{ f.content }}”</p>
                            </li>
                        </ul>
                    </template>
                    <p v-else class="rounded-lg border border-dashed border-outline-variant p-md text-center font-body-small text-body-small text-on-surface-variant">Phụ huynh chưa gửi đánh giá nào trên cổng học viên.</p>
                </section>
            </div>

            <!-- Tab Học phí -->
            <div v-if="canViewTuition" v-show="tab === 'tuition'" class="p-md lg:p-lg" data-section="tuition" data-tab="tuition">
                <template v-if="tuition">
                    <div class="grid grid-cols-1 gap-lg lg:grid-cols-5">
                        <div class="space-y-md lg:col-span-2">
                            <h3 :class="sectionTitle">Tổng quan học phí</h3>
                            <dl class="grid grid-cols-[1fr_auto] gap-x-md gap-y-sm rounded-lg border border-outline-variant p-md font-body-small text-body-small">
                                <dt class="text-on-surface-variant">Tổng học phí</dt>
                                <dd class="text-right font-code font-semibold text-on-surface">{{ formatMoney(tuition.final_amount) }}</dd>
                                <dt class="text-on-surface-variant">Đã nộp</dt>
                                <dd class="text-right font-code font-semibold text-tertiary">{{ formatMoney(tuition.paid_amount) }}</dd>
                                <dt class="border-t border-surface-container pt-sm text-on-surface-variant">Công nợ</dt>
                                <dd class="border-t border-surface-container pt-sm text-right font-code font-bold text-error">{{ formatMoney(tuition.debt_amount) }}</dd>
                            </dl>
                            <template v-if="tuition.sessions">
                                <h3 :class="sectionTitle">Sổ buổi</h3>
                                <div class="grid grid-cols-4 gap-sm text-center font-caption text-caption" data-testid="session-ledger-card">
                                    <div class="rounded-lg bg-surface-container-low p-sm"><p class="text-on-surface-variant">Đã đóng</p><p class="font-code text-body-medium font-semibold text-on-surface">{{ tuition.sessions.paid }}</p></div>
                                    <div class="rounded-lg bg-surface-container-low p-sm" title="Mỗi buổi lớp diễn ra trừ 1, vắng vẫn trừ"><p class="text-on-surface-variant">Đã trừ</p><p class="font-code text-body-medium font-semibold text-on-surface">{{ tuition.sessions.used }}</p></div>
                                    <div class="rounded-lg bg-primary-fixed/50 p-sm"><p class="text-on-surface-variant">Buổi tồn</p><p :class="['font-code text-body-medium font-semibold', tuition.sessions.balance < 0 ? 'text-error' : 'text-primary']">{{ tuition.sessions.balance }}</p></div>
                                    <div class="rounded-lg bg-surface-container-low p-sm"><p class="text-on-surface-variant">Cần thu</p><p class="font-code text-body-medium font-semibold text-on-surface">{{ tuition.sessions.needed }} buổi</p></div>
                                </div>
                            </template>
                        </div>
                        <div class="space-y-md lg:col-span-3">
                            <div class="flex flex-wrap items-center justify-between gap-sm">
                                <h3 :class="sectionTitle">Phiếu thu</h3>
                                <UiButton variant="ghost" size="sm" icon="history" :href="paymentsUrl" modal="2xl">Xem toàn bộ lịch sử</UiButton>
                            </div>
                            <ul v-if="tuition.receipts.length" class="divide-y divide-surface-container overflow-hidden rounded-lg border border-outline-variant">
                                <li v-for="receipt in tuition.receipts" :key="receipt.id">
                                    <button
                                        type="button"
                                        class="grid w-full grid-cols-[minmax(0,1fr)_auto] items-center gap-sm px-md py-sm text-left font-body-small text-body-small hover:bg-surface-container-low sm:grid-cols-[120px_100px_minmax(0,1fr)_auto]"
                                        title="Xem toàn bộ lịch sử thu học phí"
                                        @click="openPayments"
                                    >
                                        <span class="font-code font-semibold">#{{ receipt.number }}</span>
                                        <span class="text-on-surface-variant">{{ receipt.date }}</span>
                                        <span :class="['font-semibold', receipt.tone]">{{ receipt.label }}</span>
                                        <span class="text-right font-code font-semibold">{{ formatMoney(receipt.amount) }}</span>
                                    </button>
                                </li>
                            </ul>
                            <p v-else class="rounded-lg border border-dashed border-outline-variant p-md text-center font-body-small text-body-small text-on-surface-variant">Chưa có phiếu thu nào được ghi nhận cho học viên.</p>
                        </div>
                    </div>
                </template>
                <UiEmptyState v-else icon="receipt_long" title="Chưa có sổ học phí" description="Học viên chưa được lập sổ học phí." />
            </div>

            <!-- Tab Hồ sơ cá nhân (chỉ xem; sửa trong popup) -->
            <div v-show="tab === 'profile'" class="space-y-lg p-md lg:p-lg" data-tab="profile">
                <div class="flex flex-wrap items-center justify-between gap-sm">
                    <p class="font-body-small text-body-small text-on-surface-variant">Tạo hồ sơ {{ student.created_at ?? '—' }} · Cập nhật {{ student.updated_label }}</p>
                    <UiButton v-if="canEdit" variant="secondary" size="sm" icon="edit" @click="editOpen = true">Sửa thông tin</UiButton>
                </div>
                <div class="grid grid-cols-1 gap-lg md:grid-cols-3">
                    <div class="space-y-sm">
                        <h3 class="font-label-caps text-label-caps uppercase text-on-surface-variant">Học viên</h3>
                        <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-md gap-y-xs font-body-small text-body-small">
                            <dt class="text-on-surface-variant">Họ tên</dt><dd>{{ student.name }}</dd>
                            <dt class="text-on-surface-variant">Ngày sinh</dt><dd>{{ student.dob_label ?? '—' }}</dd>
                            <dt class="text-on-surface-variant">Giới tính</dt><dd>{{ student.gender ?? '—' }}</dd>
                            <template v-if="canViewContact">
                                <dt class="text-on-surface-variant">SĐT</dt><dd class="font-code">{{ student.phone || '—' }}</dd>
                                <dt class="text-on-surface-variant">Email</dt><dd class="break-all">{{ student.email || '—' }}</dd>
                                <dt class="text-on-surface-variant">Facebook</dt><dd class="break-all">{{ student.facebook || '—' }}</dd>
                                <dt class="text-on-surface-variant">CCCD</dt><dd>{{ student.id_card_number || '—' }}</dd>
                            </template>
                        </dl>
                    </div>
                    <div class="space-y-sm">
                        <h3 class="font-label-caps text-label-caps uppercase text-on-surface-variant">Phụ huynh</h3>
                        <dl v-if="canViewContact" class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-md gap-y-xs font-body-small text-body-small">
                            <dt class="text-on-surface-variant">Họ tên PH</dt><dd>{{ student.parent_name || '—' }}</dd>
                            <dt class="text-on-surface-variant">SĐT PH</dt><dd class="font-code">{{ student.parent_phone || '—' }}</dd>
                            <dt class="text-on-surface-variant">Email PH</dt><dd class="break-all">{{ student.parent_email || '—' }}</dd>
                            <dt class="text-on-surface-variant">Facebook PH</dt><dd class="break-all">{{ student.parent_facebook || '—' }}</dd>
                            <template v-if="student.parent2_name || student.parent2_phone">
                                <dt class="text-on-surface-variant">PH 2</dt><dd>{{ [student.parent2_name, student.parent2_phone].filter(Boolean).join(' · ') }}</dd>
                            </template>
                            <dt class="text-on-surface-variant">Địa chỉ</dt><dd>{{ student.address || '—' }}</dd>
                        </dl>
                        <p v-else class="font-caption text-caption italic text-on-surface-variant">Thông tin liên hệ ẩn theo phân quyền</p>
                    </div>
                    <div class="space-y-sm">
                        <h3 class="font-label-caps text-label-caps uppercase text-on-surface-variant">Học tập</h3>
                        <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-md gap-y-xs font-body-small text-body-small">
                            <dt class="text-on-surface-variant">Trường học</dt><dd>{{ student.school || '—' }}</dd>
                            <dt class="text-on-surface-variant">Khối</dt><dd>{{ student.school_grade ?? '—' }}</dd>
                            <dt class="text-on-surface-variant">Mục tiêu</dt><dd>{{ student.target || '—' }}</dd>
                            <dt class="text-on-surface-variant">Test đầu vào</dt><dd>{{ student.entrance_score || 'Chưa có' }}</dd>
                            <dt class="text-on-surface-variant">Bắt đầu học</dt><dd>{{ student.study_started_on ?? 'Chưa vào học' }}</dd>
                            <dt class="text-on-surface-variant">Lớp đang học</dt><dd>{{ student.current_class ?? 'Chưa xếp lớp' }}</dd>
                        </dl>
                    </div>
                </div>
                <div v-if="student.notes" class="space-y-sm">
                    <h3 class="font-label-caps text-label-caps uppercase text-on-surface-variant">Ghi chú đặc biệt</h3>
                    <p class="whitespace-pre-line rounded-lg bg-surface-container-low p-sm font-body-small text-body-small text-on-surface">{{ student.notes }}</p>
                </div>
            </div>
        </section>

        <!-- Popup "Sửa thông tin": form chia 3 nhóm Học viên / Phụ huynh / Học tập -->
        <UiModal v-if="canEdit" :show="editOpen" title="Sửa thông tin học viên" max-width="4xl" @close="editOpen = false">
            <UiForm id="student-edit-form" :action="route('students.update', student.id)" method="put" class="space-y-lg" data-testid="student-edit-form" @success="editOpen = false">
                <fieldset class="space-y-sm">
                    <legend class="mb-sm font-label-caps text-label-caps uppercase text-on-surface-variant">1. Học viên</legend>
                    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 lg:grid-cols-3">
                        <UiInput name="name" label="Họ và tên" required :value="student.name" />
                        <UiInput name="phone" type="tel" label="Số điện thoại" required :value="student.phone" />
                        <UiDate name="dob" label="Ngày sinh" :value="student.dob_value" :max="student.today" />
                        <UiSelect name="gender" label="Giới tính" placeholder="-- Chọn --" :value="student.gender" :options="genderOptions" />
                        <UiInput name="email" type="email" label="Email liên hệ" :value="student.email" placeholder="hocvien@menglish.edu.vn" />
                        <UiInput name="facebook" label="Facebook học viên" :value="student.facebook" placeholder="Link hoặc tên Facebook" />
                    </div>
                </fieldset>
                <fieldset class="space-y-sm">
                    <legend class="mb-sm font-label-caps text-label-caps uppercase text-on-surface-variant">2. Phụ huynh</legend>
                    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 lg:grid-cols-4">
                        <UiInput name="parent_name" label="Họ tên phụ huynh" :value="student.parent_name" placeholder="VD: Nguyễn Thị Hoa" />
                        <UiInput name="parent_phone" type="tel" label="SĐT phụ huynh" :value="student.parent_phone" placeholder="VD: 0987 654 321" />
                        <UiInput name="parent_email" type="email" label="Email phụ huynh" :value="student.parent_email" />
                        <UiInput name="parent_facebook" label="Facebook phụ huynh" :value="student.parent_facebook" placeholder="Link hoặc tên Facebook" />
                        <UiInput name="parent2_name" label="Họ tên phụ huynh 2" :value="student.parent2_name" />
                        <UiInput name="parent2_phone" type="tel" label="SĐT phụ huynh 2" :value="student.parent2_phone" />
                        <div class="sm:col-span-2">
                            <UiInput name="address" label="Địa chỉ liên hệ" :value="student.address" placeholder="Nhập địa chỉ của học viên..." />
                        </div>
                    </div>
                </fieldset>
                <fieldset class="space-y-sm">
                    <legend class="mb-sm font-label-caps text-label-caps uppercase text-on-surface-variant">3. Học tập</legend>
                    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 lg:grid-cols-3">
                        <UiInput name="school" label="Trường học" :value="student.school" placeholder="VD: Trường THCS Đoàn Thị Điểm" />
                        <UiInput
                            name="school_grade"
                            type="number"
                            min="1"
                            max="12"
                            label="Khối hiện tại"
                            :value="student.school_grade"
                            :hint="`Tự tính từ ngày sinh (năm học ${student.school_year}); chỉ sửa khi học sớm / muộn.`"
                        />
                        <UiInput name="target" label="Mục tiêu học tập" :value="student.target" placeholder="VD: IELTS 6.5, Cambridge Starters..." />
                        <UiInput
                            name="study_started_on"
                            type="date"
                            label="Ngày bắt đầu học"
                            :value="student.study_started_on_value"
                            :max="student.today"
                            hint="Tự điền theo buổi có mặt đầu tiên; sửa tay nếu học viên học từ trước khi dùng hệ thống."
                        />
                        <UiInput name="id_card_number" label="Số CCCD / mã định danh" :value="student.id_card_number" />
                    </div>
                    <UiTextarea name="notes" label="Ghi chú đặc biệt" rows="2" :value="student.notes" placeholder="Nhập ghi chú về học sinh (ví dụ: dị ứng, sở thích, mục tiêu học tập...)" />
                </fieldset>
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="editOpen = false">Hủy</UiButton>
                <UiButton type="submit" form="student-edit-form">Lưu thay đổi</UiButton>
            </template>
        </UiModal>

        <!-- Popup "Liên kết lớp khác" (học song song, lớp chính giữ nguyên) -->
        <UiModal v-if="classActions && linkableClasses.length" :show="linkOpen" title="Liên kết lớp khác" max-width="md" @close="linkOpen = false">
            <UiForm id="link-class-form" :action="route('students.link-class', student.id)" method="post" class="space-y-md" data-testid="link-class-form" @success="linkOpen = false">
                <p class="font-body-small text-body-small text-on-surface-variant">Học viên <strong>{{ student.name }}</strong> học thêm lớp này, lớp chính giữ nguyên.</p>
                <UiSelect id="link_class_id" label="Lớp liên kết" name="class_id" required placeholder="-- Chọn lớp --" :options="linkableClasses" />
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="linkOpen = false">Hủy</UiButton>
                <UiButton type="submit" form="link-class-form" icon="add_link">Liên kết lớp</UiButton>
            </template>
        </UiModal>

        <!-- Popup "Chuyển lớp": lớp chính cũ ngừng trừ buổi và ra khỏi danh sách lớp từ ngày chuyển, buổi tồn giữ nguyên -->
        <UiModal v-if="classActions && mainClass" :show="transferOpen" title="Chuyển lớp" max-width="md" @close="transferOpen = false">
            <UiForm id="transfer-class-form" :action="route('students.transfer-class', student.id)" method="post" class="space-y-md" data-testid="transfer-class-form" @success="transferOpen = false">
                <p class="font-body-small text-body-small text-on-surface-variant">
                    Chuyển <strong>{{ student.name }}</strong> khỏi lớp <strong>{{ mainClass.name }}</strong>. Từ ngày chuyển, lớp cũ ngừng trừ buổi và học viên ra khỏi danh sách lớp cũ; lớp mới bắt đầu trừ buổi.
                </p>
                <UiSelect id="transfer_class_id" name="class_id" label="Lớp mới" required placeholder="-- Chọn lớp cùng chi nhánh --" :options="linkableClasses" value="" />
                <UiInput
                    id="transfer_on"
                    name="transferred_on"
                    type="date"
                    label="Ngày chuyển lớp"
                    required
                    :value="mainClass.today"
                    :max="mainClass.today"
                    :min="mainClass.joined_on"
                    hint="Ngày học buổi đầu ở lớp mới. Cập nhật trễ thì chọn đúng ngày đã chuyển."
                />
                <p v-if="tuition?.sessions" class="rounded-lg bg-surface-container-low p-sm font-caption text-caption text-on-surface-variant">
                    Buổi tồn hiện tại: <strong class="font-code text-on-surface">{{ tuition.sessions.balance }}</strong> buổi (đã đóng {{ tuition.sessions.paid }}, đã trừ {{ tuition.sessions.used }}). Số buổi tồn được giữ nguyên khi chuyển lớp.
                </p>
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="transferOpen = false">Hủy</UiButton>
                <UiButton type="submit" form="transfer-class-form" icon="swap_horiz">Chuyển lớp</UiButton>
            </template>
        </UiModal>
    </div>
</template>
