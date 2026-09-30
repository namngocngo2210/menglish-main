<script setup>
/**
 * Hồ sơ khách (mockup crm-ui-mockup/chi-tiet-khach-hang): trang đầy đủ duy nhất (Kanban / danh sách / nút sửa đều mở đây).
 * - Góc phải tiêu đề: bước tiếp theo của giai đoạn là nút cam duy nhất (Sang bước kế tiếp → Chốt & Xếp lớp → Xếp lớp);
 *   Phân công lại là nút phụ; In / Lùi giai đoạn / Thất bại nằm trong menu "⋯".
 * - Cột trái: liên hệ, Trạng thái & Hạn xử lý, Chăm sóc tháng đầu. Cột phải: tab Đặt lịch & Kết quả / Thông tin khách hàng
 *   (sửa trực tiếp) + Lịch sử hoạt động.
 * Modal dựng sẵn trong trang: Phân công lại, Thất bại, Lùi giai đoạn, Xếp học thử, Hẹn test, Nhập điểm test.
 */
import { computed, nextTick, reactive, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import OpsTab from './ShowOpsTab.vue';
import CustomerEditForm from './CustomerEditForm.vue';
import Timeline from './ShowTimeline.vue';
import CareChecklist from './ShowCareChecklist.vue';
import RubricScoreFields from '@/Components/PlacementTests/RubricScoreFields.vue';
import SlaCountdown from '@/Components/Crm/SlaCountdown.vue';

defineOptions({ layout: (props) => ({ title: 'Chi tiết khách — ' + props.customer.name }) });

const props = defineProps({
    customer: { type: Object, required: true },
    actions: { type: Object, required: true },
    stageControls: { type: Object, required: true },
    pendingTransfer: { type: Object, default: null },
    canReassign: { type: Boolean, default: false },
    reassignUsers: { type: Array, default: () => [] },
    test: { type: Object, required: true },
    rubric: { type: Object, default: null },
    result: { type: Object, default: null },
    skills: { type: Object, default: () => ({}) },
    noRubricNotice: { type: String, default: '' },
    resultLogs: { type: Array, default: () => [] },
    hasResultLogs: { type: Boolean, default: false },
    nowInput: { type: String, default: null },
    today: { type: String, default: null },
    tomorrow: { type: String, default: null },
    placementTests: { type: Array, default: () => [] },
    gradeLevels: { type: Array, default: () => [] },
    examiners: { type: Array, default: () => [] },
    scheduleDefaults: { type: Object, default: () => ({}) },
    portalTestLink: { type: String, default: null },
    linkTtlDays: { type: Number, default: 7 },
    scoreForm: { type: Object, required: true },
    unlinkedSubmissions: { type: Array, default: () => [] },
    canBookTrial: { type: Boolean, default: false },
    trial: { type: Object, required: true },
    trialSlots: { type: Object, required: true },
    trialBookings: { type: Array, default: () => [] },
    statusCard: { type: Object, required: true },
    careChecklist: { type: Array, default: () => [] },
    histories: { type: Array, default: () => [] },
    historyTotal: { type: Number, default: 0 },
    logType: { type: String, default: null },
    logTypeOptions: { type: Array, default: () => [] },
    tab: { type: String, default: 'ops' },
    editForm: { type: Object, default: null },
});

const card = 'rounded-xl border border-surface-container-highest bg-surface-container-lowest shadow-sm';
const menuItem = 'flex w-full items-center gap-sm px-md py-sm text-left font-body-small text-body-small hover:bg-surface-container-low';
const initials = computed(() => {
    const words = props.customer.name.trim().split(/\s+/u).filter(Boolean);
    return ((words[0]?.[0] ?? '?') + (words.length > 1 ? words[words.length - 1][0] : '')).toLocaleUpperCase('vi');
});

// Modal dựng sẵn: mở / đóng theo tên.
const modals = reactive({ reassign: false, lost: false, backward: false, trial: false, scheduleTest: false, score: false });
const open = (name) => (modals[name] = true);
const close = (name) => (modals[name] = false);

// Tab thao tác: "Đặt lịch & Kết quả" | "Thông tin khách hàng".
const activeTab = ref(props.tab);
// "Đặt hạn liên hệ": mở tab Thông tin, mở khối bổ sung, cuộn tới & focus ô "Hạn liên hệ tiếp theo".
async function focusFollowUp() {
    activeTab.value = 'info';
    await nextTick();
    const el = document.getElementById('next_follow_up_at');
    if (!el) return;
    const details = el.closest('details');
    if (details) details.open = true;
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    el.focus({ preventScroll: true });
}
if (typeof window !== 'undefined' && window.location.hash === '#next_follow_up_at') nextTick(focusFollowUp);

const tabClass = (name) => ['-mb-px border-b-2 px-lg py-md font-body-medium text-body-medium transition-colors', activeTab.value === name ? 'border-primary-container font-semibold text-primary' : 'border-transparent text-on-surface-variant hover:text-primary'];
const followUpTone = (status) => (status === 'overdue' ? 'bg-error-container/40' : status === 'due_soon' ? 'bg-warning-container' : status === 'on_time' ? 'bg-tertiary/10' : 'bg-surface-container-low');
const contactRows = computed(() => [
    ['Số điện thoại', props.customer.phone, true],
    ['Tên phụ huynh', props.customer.parent_name || '—', false],
    ['SĐT phụ huynh', props.customer.parent_phone || '—', true],
    ['Nguồn', props.customer.source ?? 'Trực tiếp', false],
    ['Người phụ trách', props.customer.assigned_user ?? 'Chưa phân công', false],
    ['Chi nhánh', props.customer.branch ?? 'Chưa gán cơ sở', false],
]);
const appointmentTypes = [
    { value: 'online', label: 'Trực tuyến (Online qua link Portal)' },
    { value: 'offline', label: 'Tại cơ sở (Offline tại trung tâm)' },
];
const testOptions = computed(() => props.placementTests.map((t) => ({ value: t.id, label: `${t.title} (${t.code})` })));
const scoreTestOptions = computed(() => props.placementTests.map((t) => ({ value: t.id, label: `[${t.code}] ${t.title}` })));
const trialHasSlots = computed(() => props.trialSlots.classes.some((row) => row.sessions.length > 0));
const trialTitle = computed(() => (props.trial.pending ? 'Xếp học thử' : `Xếp học thử — buổi ${props.trial.used + 1}/${props.trial.max}`));
</script>

<template>
    <!-- Tên khách làm tiêu đề (không lặp tiêu đề chung), mã ngắn ở breadcrumb -->
    <UiPageHeader :title="customer.name">
        <template #breadcrumbs>
            <Link :href="route('crm.customers.index')" class="hover:text-primary">Khách hàng</Link>
            <span class="material-symbols-outlined text-[14px]">chevron_right</span>
            <span class="font-code" :title="customer.code">{{ customer.short_code }}</span>
        </template>
        <template #badges>
            <span :class="['inline-flex items-center rounded-full border px-sm py-0.5 font-caption text-caption font-bold', customer.stage_badge]">{{ customer.stage_label }}</span>
        </template>
        <template #actions>
            <UiButton v-if="canReassign" variant="secondary" icon="person_add" @click="open('reassign')">Phân công lại</UiButton>
            <UiButton v-if="actions.canClose" :variant="actions.primary === 'close' ? 'primary' : 'secondary'" icon="how_to_reg" :href="route('crm.closing-wizard', { customer_id: customer.id })">Chốt &amp; Xếp lớp</UiButton>
            <UiButton v-if="actions.canPlace" icon="assignment_turned_in" :href="route('crm.waiting-list')">Xếp lớp</UiButton>
            <UiForm v-if="stageControls.next" :action="route('crm.customers.stage', customer.id)" method="post" class="inline">
                <input type="hidden" name="stage" :value="stageControls.next" />
                <UiButton type="submit" icon="arrow_forward" title="Chuyển tiến 1 bước">Sang bước: {{ stageControls.nextLabel }}</UiButton>
            </UiForm>
            <UiDropdown align="right" width="56">
                <template #trigger>
                    <UiButton variant="secondary" icon="more_horiz" title="Thao tác khác" aria-label="Thao tác khác" />
                </template>
                <template #content>
                    <a :href="route('crm.customers.print', customer.id)" target="_blank" :class="[menuItem, 'text-on-surface']">
                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">print</span>In hồ sơ
                    </a>
                    <button v-if="stageControls.backward.length" type="button" :class="[menuItem, 'text-on-surface']" @click="open('backward')">
                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">undo</span>Lùi giai đoạn
                    </button>
                    <button v-if="stageControls.canLose" type="button" :class="[menuItem, 'text-error']" @click="open('lost')">
                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">person_off</span>Thất bại
                    </button>
                </template>
            </UiDropdown>
        </template>
    </UiPageHeader>

    <UiAlert v-if="pendingTransfer" type="warning" title="Chờ Admin duyệt chuyển cơ sở" class="mb-md">
        {{ pendingTransfer.requester ?? 'Nhân viên' }} đề nghị chuyển khách sang <strong>{{ pendingTransfer.to_user }}</strong> phụ trách,
        cơ sở {{ pendingTransfer.from_branch ?? '(chưa có)' }} → <strong>{{ pendingTransfer.to_branch }}</strong>{{ customer.converted_student_id ? ' (học viên chuyển theo)' : '' }}.
        <Link v-if="can('lead.approve_transfer')" :href="pendingTransfer.approvals_url" class="font-bold underline">Duyệt / từ chối</Link>
    </UiAlert>

    <!-- Phân công lại người phụ trách -->
    <UiModal v-if="canReassign" :show="modals.reassign" title="Phân công lại người phụ trách" max-width="md" @close="close('reassign')">
        <UiForm id="reassign-form" :action="route('crm.customers.reassign', customer.id)" method="post" class="space-y-3" reset-on-success @success="close('reassign')">
            <p class="text-body-small text-on-surface-variant">Hiện tại: <strong>{{ customer.assigned_user ?? 'Chưa phân công' }}</strong>. Thay đổi được ghi vào lịch sử khách.</p>
            <UiSelect
                id="reassign_assigned_user_id"
                name="assigned_user_id"
                label="Người phụ trách mới"
                required
                placeholder="-- Chọn người phụ trách --"
                :options="reassignUsers"
                :hint="'Chọn Học vụ cơ sở khác (' + (customer.branch ?? 'khách chưa có cơ sở') + ' là cơ sở hiện tại) = chuyển cơ sở cho khách và học viên, cần Admin duyệt.'"
            />
            <UiTextarea id="reassign_reason" name="reason" label="Lý do phân công lại" required :rows="3" placeholder="VD: Học vụ cũ nghỉ phép, khách chuyển sang học cơ sở khác..." />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="close('reassign')">Hủy</UiButton>
            <UiButton type="submit" form="reassign-form" icon="assignment_ind">Phân công lại</UiButton>
        </template>
    </UiModal>

    <!-- Thất bại (Sales cũng được đánh — quyền lead.mark_lost) -->
    <UiModal v-if="stageControls.canLose" :show="modals.lost" title="Ghi nhận lý do thất bại" max-width="md" @close="close('lost')">
        <p class="mb-3 text-xs text-on-surface-variant">Khách thất bại được lưu để đối soát và không mở lại.</p>
        <UiForm id="mark-lost-form" :action="route('crm.customers.stage', customer.id)" method="post" class="space-y-3 text-xs" @success="close('lost')">
            <input type="hidden" name="stage" value="lost" />
            <UiTextarea name="lost_reason" :rows="4" required maxlength="255" placeholder="Ví dụ: chưa phù hợp học phí, lịch học, không liên hệ được..." aria-label="Lý do thất bại" />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="close('lost')">Hủy</UiButton>
            <UiButton type="submit" form="mark-lost-form" variant="danger">Xác nhận</UiButton>
        </template>
    </UiModal>

    <!-- Lùi giai đoạn (chỉ Admin, bắt buộc lý do) -->
    <UiModal v-if="stageControls.backward.length" :show="modals.backward" title="Lùi giai đoạn khách" max-width="md" @close="close('backward')">
        <p class="mb-3 text-xs text-on-surface-variant">Chỉ Admin được lùi giai đoạn; lý do được lưu vào lịch sử.</p>
        <UiForm id="stage-backward-form" :action="route('crm.customers.stage', customer.id)" method="post" class="space-y-3 text-xs" @success="close('backward')">
            <UiSelect name="stage" value="" required aria-label="Giai đoạn lùi về" :options="stageControls.backwardOptions" />
            <UiTextarea id="stage_backward_reason" name="reason" :rows="3" required placeholder="Lý do lùi giai đoạn (bắt buộc)" aria-label="Lý do lùi giai đoạn" />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="close('backward')">Hủy</UiButton>
            <UiButton type="submit" form="stage-backward-form" variant="danger">Lùi giai đoạn</UiButton>
        </template>
    </UiModal>

    <!-- Xếp học thử trước Chốt: lớp khớp trình độ đăng ký, buổi trong 7 ngày tới; mỗi lần 1 buổi, tối đa 2 lần.
         Không tạo ghi danh — khách chỉ vào lớp chính thức khi xếp lớp sau Chốt. -->
    <UiModal v-if="canBookTrial" :show="modals.trial" :title="trialTitle" max-width="lg" @close="close('trial')">
        <div class="mb-3 space-y-1 text-xs">
            <p class="text-on-surface-variant">Chọn 1 buổi học thật trong 7 ngày tới của lớp cùng trình độ{{ customer.branch ? ' tại ' + customer.branch : '' }}. Giáo viên buổi đó nhận ghi chú, nhận xét khách sau giờ học và Học vụ được báo để chăm sóc.</p>
            <p><span class="text-on-surface-variant">Trình độ đăng ký:</span> <span class="font-semibold text-on-surface">{{ trialSlots.level ?? 'Chưa có (hiện mọi lớp đang mở)' }}</span></p>
        </div>
        <UiAlert v-if="trial.pending" type="info">Khách đang có buổi học thử {{ trial.pending.class }} ngày {{ trial.pending.date }} {{ trial.pending.time }}. Buổi tiếp theo xếp lại sau khi buổi này kết thúc.</UiAlert>
        <UiForm v-else id="schedule-trial-form" :action="route('crm.customers.trial-bookings.store', customer.id)" method="post" class="space-y-3 text-xs" @success="close('trial')">
            <div class="max-h-[55vh] space-y-2 overflow-y-auto">
                <div v-for="row in trialSlots.classes" :key="row.class.id" class="rounded-xl border border-surface-container-highest p-2.5" data-testid="trial-class">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-2">
                        <span class="font-bold text-on-surface">{{ row.class.name }}</span>
                        <span class="text-on-surface-variant">{{ row.class.course ?? 'Chưa gán khóa' }}{{ row.class.level ? ' · ' + row.class.level : '' }}</span>
                    </div>
                    <p v-if="!row.sessions.length" class="mt-1 italic text-on-surface-subtle">Không có buổi học trong 7 ngày tới.</p>
                    <div v-else class="mt-2 flex flex-wrap gap-1.5">
                        <label v-for="slot in row.sessions" :key="slot.id" class="cursor-pointer">
                            <input type="radio" name="class_session_id" :value="slot.id" class="peer sr-only" required />
                            <span class="inline-flex flex-col rounded-lg border border-outline-variant px-2.5 py-1.5 leading-tight hover:border-secondary peer-checked:border-secondary peer-checked:bg-secondary/10 peer-checked:ring-1 peer-checked:ring-secondary peer-focus-visible:ring-2 peer-focus-visible:ring-secondary">
                                <span class="font-semibold text-on-surface">{{ slot.label }}</span>
                                <span class="text-xs text-on-surface-variant">GV: {{ slot.teacher }}</span>
                            </span>
                        </label>
                    </div>
                </div>
                <div v-if="!trialSlots.classes.length" class="rounded-xl border border-surface-container-highest p-4 text-center text-on-surface-subtle">
                    {{ trialSlots.filtered ? 'Không có lớp đang mở nào khớp trình độ ' + trialSlots.level + (customer.branch ? ' tại ' + customer.branch : '') + '.' : 'Chưa có lớp đang mở' + (customer.branch ? ' tại ' + customer.branch : '') + '.' }}
                    <span class="mt-1 block">Kiểm tra "Khóa học quan tâm" của khách hoặc lịch lớp.</span>
                </div>
            </div>
            <p v-if="$page.props.errors?.class_session_id" class="font-semibold text-error">{{ $page.props.errors.class_session_id }}</p>
            <UiTextarea name="notes" :rows="2" label="Ghi chú cho giáo viên" placeholder="Trình độ, mục tiêu, tính cách của bé..." />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="close('trial')">{{ trial.pending ? 'Đóng' : 'Hủy' }}</UiButton>
            <UiButton v-if="!trial.pending" type="submit" form="schedule-trial-form" :disabled="!trialHasSlots">Lưu lịch học thử</UiButton>
        </template>
    </UiModal>

    <!-- Hẹn lịch test đầu vào (hẹn lại) -->
    <UiModal :show="modals.scheduleTest" title="Hẹn lịch test đầu vào" max-width="md" @close="close('scheduleTest')">
        <UiForm id="schedule-test-form" :action="route('crm.customers.schedule-test', customer.id)" method="post" class="space-y-3 text-xs" @success="close('scheduleTest')">
            <div class="grid grid-cols-2 gap-3">
                <UiDate id="modal_appointment_date" name="appointment_date" label="Ngày hẹn test" :value="scheduleDefaults.date" required />
                <UiInput id="modal_appointment_time" type="time" name="appointment_time" label="Giờ hẹn" :value="scheduleDefaults.time" required />
            </div>
            <UiSelect id="modal_appointment_type" name="appointment_type" label="Hình thức làm bài" :value="customer.appointment_type ?? 'online'" required class="font-bold !text-primary" :options="appointmentTypes" />
            <UiSelect id="modal_assigned_test_id" name="assigned_test_id" label="Đề test gán cho khách" :value="customer.assigned_test_id ?? ''" :options="testOptions" />
            <UiSelect id="modal_examiner_id" name="examiner_id" label="Giáo viên / Giám thị phụ trách chấm" :value="customer.examiner_id ?? ''" placeholder="-- Tự động chấm AI / Chưa gán --" :options="examiners" />
            <UiTextarea id="modal_test_notes" name="notes" label="Ghi chú nhắc hẹn" :rows="2" placeholder="Nhắc học viên mang theo tai nghe, CMND..." />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" size="sm" @click="close('scheduleTest')">Hủy</UiButton>
            <UiButton type="submit" form="schedule-test-form" variant="info" size="sm" class="font-bold">Xác nhận lịch hẹn</UiButton>
        </template>
    </UiModal>

    <!-- Nhập / sửa điểm test đầu vào -->
    <UiModal :show="modals.score" title="Nhập điểm test đầu vào" max-width="2xl" @close="close('score')">
        <UiForm id="edit-test-score-form" :action="route('crm.customers.save-test-score', customer.id)" method="post" class="space-y-3.5 text-xs" @success="close('score')">
            <input v-if="scoreForm.submissionId" type="hidden" name="submission_id" :value="scoreForm.submissionId" />
            <UiAlert type="warning" class="text-xs">
                <strong>Học viên:</strong> {{ customer.name }} ({{ customer.phone }})<br />
                <span>Chấm theo <strong>thang điểm khối lớp</strong>: Tổng = Nghe + Đọc &amp; Viết + Nói → lớp đề xuất. Lưu điểm sẽ tự chuyển khách sang "Đã test".</span>
            </UiAlert>
            <UiSelect v-if="!scoreForm.submissionId" id="modal_placement_test_id" name="placement_test_id" label="Đề kiểm tra đã dùng" required placeholder="— Chọn đề —" :value="scoreForm.testId ?? ''" class="font-semibold" :options="scoreTestOptions" />
            <RubricScoreFields :key="JSON.stringify(scoreForm.rubric.initial)" :rubric="scoreForm.rubric" />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" size="sm" @click="close('score')">Hủy</UiButton>
            <UiButton v-if="scoreForm.canDraft" type="submit" form="edit-test-score-form" name="action" value="draft" variant="secondary">Lưu bản nháp</UiButton>
            <UiButton type="submit" form="edit-test-score-form" name="action" value="confirm" icon="check">Xác nhận kết quả</UiButton>
        </template>
    </UiModal>

    <UiAlert v-if="customer.stage === 'lost'" type="error" class="mb-lg" data-testid="lost-banner">
        <div class="font-semibold">Khách Thất bại{{ customer.lost_at ? ' từ ' + customer.lost_at : '' }} — không mở lại, giữ để đối soát.</div>
        <div v-if="customer.lost_reason">Lý do: {{ customer.lost_reason }}</div>
    </UiAlert>

    <UiAlert v-if="customer.stage === 'waiting_class'" type="warning" class="mb-lg" :title="'Đã chốt, chờ xếp lớp' + (customer.waiting_since ? ' từ ' + customer.waiting_since : '')">
        Khóa: {{ customer.waiting_course ?? 'Chưa chọn khóa' }} · {{ customer.waiting_branch ?? customer.branch }}
        · Học phí đăng ký: {{ customer.fee_paid_at_closing ? 'Đã đóng' : 'Chưa đóng (đã tạo task nhắc thu)' }}
    </UiAlert>

    <div class="grid grid-cols-1 gap-lg lg:grid-cols-12">
        <!-- ── Cột trái: thông tin, trạng thái & hạn xử lý, chăm sóc tháng đầu ── -->
        <div class="space-y-lg lg:col-span-4">
            <div :class="[card, 'p-lg']">
                <div class="mb-lg flex items-center gap-md">
                    <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-primary-fixed font-h2 text-h2 text-on-primary-fixed">{{ initials }}</div>
                    <div class="min-w-0">
                        <!-- Tên + giai đoạn đã ở tiêu đề trang: thẻ chỉ còn thông tin liên hệ -->
                        <h2 class="font-h3 text-h3 text-on-surface">Thông tin liên hệ</h2>
                        <div class="mt-xs flex flex-wrap items-center gap-xs">
                            <span v-if="test.hasTested" class="inline-flex items-center gap-xs rounded-full bg-tertiary/10 px-sm py-0.5 font-caption text-caption font-bold text-tertiary">
                                <span class="material-symbols-outlined text-[14px]">task_alt</span>{{ test.pending ? 'Đã làm bài test, chờ chấm' : 'Đã làm bài test' }}<template v-if="test.summary"> ({{ test.summary }})</template>
                            </span>
                        </div>
                    </div>
                </div>
                <dl class="space-y-md font-body-base text-body-base">
                    <div v-for="[label, value, mono] in contactRows" :key="label" class="flex items-start justify-between gap-md border-b border-surface-container pb-sm last:border-0 last:pb-0">
                        <dt class="text-on-surface-variant">{{ label }}</dt>
                        <dd :class="['text-right font-medium text-on-surface', mono ? 'font-code' : '']">{{ value }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Trạng thái & Hạn xử lý -->
            <div :class="[card, 'p-lg']">
                <h3 class="mb-md font-h3 text-h3 text-on-surface">Trạng thái &amp; Hạn xử lý</h3>
                <div class="space-y-md">
                    <div class="rounded-lg border-l-4 border-primary-container bg-surface-container-low p-md">
                        <p class="font-label text-label uppercase text-on-surface-variant">Giai đoạn hiện tại</p>
                        <p class="font-h3 text-h3 text-primary">{{ customer.stage_label }}</p>
                        <p class="font-caption text-caption text-on-surface-variant">{{ statusCard.days_in_stage }} ngày ở giai đoạn này (từ {{ statusCard.stage_since }})</p>
                    </div>
                    <div class="grid grid-cols-2 gap-md">
                        <div class="rounded-lg bg-surface-container-low p-md">
                            <p class="font-label text-label uppercase text-on-surface-variant">Liên hệ gần nhất</p>
                            <p class="font-body-medium text-body-medium text-on-surface">{{ statusCard.last_contact ?? 'Chưa có' }}</p>
                        </div>
                        <div :class="['rounded-lg p-md', followUpTone(statusCard.sla?.state)]">
                            <p class="font-label text-label uppercase text-on-surface-variant">{{ statusCard.sla?.label ?? 'Hạn liên hệ tiếp theo' }}</p>
                            <template v-if="statusCard.sla">
                                <SlaCountdown :sla="statusCard.sla" />
                                <p class="font-code text-caption text-on-surface-variant">{{ statusCard.sla.deadline_label }}</p>
                            </template>
                            <p v-else class="font-body-medium text-body-medium text-on-surface-variant">Không áp dụng</p>
                        </div>
                    </div>
                    <UiAlert v-if="statusCard.neglected" type="error">Khách chưa có hoạt động chăm sóc nào trong {{ statusCard.neglect_days }} ngày gần đây.</UiAlert>
                    <!-- Mở tab "Thông tin khách hàng" ngay trên trang, cuộn tới và focus ô "Hạn liên hệ tiếp theo" -->
                    <a
                        v-if="can('lead.update')"
                        :href="route('crm.customers.show', { id: customer.id, tab: 'info' }) + '#next_follow_up_at'"
                        data-testid="set-follow-up"
                        class="inline-flex items-center gap-xs font-body-small text-body-small font-semibold text-primary hover:underline"
                        @click.prevent="focusFollowUp"
                    >
                        <span class="material-symbols-outlined text-[16px]">event</span>Đặt hạn liên hệ
                    </a>
                </div>
            </div>

            <!-- Chăm sóc tháng đầu: chỉ khi đã chuyển đổi (có hồ sơ học viên) -->
            <CareChecklist v-if="customer.converted_student_id" :customer-id="customer.id" :items="careChecklist" :card="card" />
        </div>

        <!-- ── Cột phải: thao tác (tab) + lịch sử hoạt động ── -->
        <div class="space-y-lg lg:col-span-8">
            <div :class="[card, 'overflow-hidden']">
                <div class="flex border-b border-surface-container-highest" role="tablist">
                    <button type="button" role="tab" :aria-selected="activeTab === 'ops'" :class="tabClass('ops')" @click="activeTab = 'ops'">Đặt lịch &amp; Kết quả</button>
                    <button type="button" role="tab" :aria-selected="activeTab === 'info'" :class="tabClass('info')" @click="activeTab = 'info'">Thông tin khách hàng</button>
                </div>

                <OpsTab v-show="activeTab === 'ops'" v-bind="$props" @open="open" />

                <div v-show="activeTab === 'info'">
                    <!-- Thông tin hệ thống (không sửa) -->
                    <dl class="flex flex-wrap gap-x-lg gap-y-xs border-b border-surface-container-highest px-lg py-sm font-body-small text-body-small text-on-surface-variant">
                        <div>Mã khách: <span class="font-code text-on-surface" :title="customer.code">{{ customer.short_code }}</span></div>
                        <div>Tạo hồ sơ: <span class="text-on-surface">{{ customer.created_at }}</span></div>
                        <div>Ngày chốt: <span class="text-on-surface">{{ customer.converted_at ?? '—' }}</span></div>
                    </dl>
                    <!-- Sửa trực tiếp mọi thông tin khách ngay trong hồ sơ (không còn trang / modal sửa riêng) -->
                    <CustomerEditForm v-if="editForm" :customer="customer" :options="editForm" />
                    <div v-else class="p-lg">
                        <dl class="grid grid-cols-1 gap-md font-body-base text-body-base sm:grid-cols-2">
                            <div
                                v-for="[label, value] in [
                                    ['Mã khách hàng', customer.short_code],
                                    ['Email', customer.email ?? '—'],
                                    ['Ngày sinh / Giới tính', (customer.dob_label ?? '—') + ' (' + (customer.gender ?? 'Chưa rõ') + ')'],
                                    ['Khóa quan tâm', customer.course_interest ?? 'Chưa chọn'],
                                    ['Giá trị hợp đồng', formatMoney(customer.deal_value ?? 0)],
                                    ['Địa chỉ', customer.address ?? 'Chưa cập nhật'],
                                    ['Ngày tạo hồ sơ', customer.created_at],
                                    ['Ngày chốt', customer.converted_at ?? '—'],
                                ]"
                                :key="label"
                                class="rounded-lg bg-surface-container-low p-md"
                            >
                                <dt class="font-label text-label uppercase text-on-surface-variant">{{ label }}</dt>
                                <dd class="mt-xs font-medium text-on-surface">{{ value }}</dd>
                            </div>
                            <div v-if="customer.notes" class="rounded-lg bg-surface-container-low p-md sm:col-span-2">
                                <dt class="font-label text-label uppercase text-on-surface-variant">Ghi chú nhu cầu</dt>
                                <dd class="mt-xs whitespace-pre-line text-on-surface">{{ customer.notes }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>

            <Timeline :customer="customer" :histories="histories" :history-total="historyTotal" :log-type="logType" :log-type-options="logTypeOptions" :card="card" />
        </div>
    </div>
</template>
