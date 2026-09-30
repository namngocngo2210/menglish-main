<script setup>
/**
 * Xử lý khất nợ / hoàn tiền (mockup hoan-tien-va-khat-no + A6 25/09/2026 "Hoàn phí").
 * Bảng tính hoàn phí / chuyển nhượng / bảo lưu theo số liệu thật (studentFinance) — chuyển từ Alpine refundTransferManager.
 */
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { formatMoney } from '@/lib/format';
import { openRemoteModal } from '@/lib/remoteModal';
import { compactQuery } from '@/lib/url';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Xử lý khất nợ / hoàn tiền', hideErrors: true } });

const props = defineProps({
    pendingRequests: { type: Array, default: () => [] },
    overdueCount: { type: Number, default: 0 },
    historyRequests: { type: Array, default: () => [] },
    totalCount: { type: Number, default: 0 },
    filters: { type: Object, required: true },
    typeOptions: { type: Object, default: () => ({}) },
    approvableTypes: { type: Array, default: () => [] },
    canRejectAny: { type: Boolean, default: false },
    clawbackHints: { type: [Object, Array], default: () => ({}) },
    studentFinance: { type: [Object, Array], default: () => ({}) },
    adminFeePercent: { type: Number, default: 10 },
    targets: { type: Array, default: () => [] },
    sourceOptions: { type: Array, default: () => [] },
    debtorOptions: { type: Array, default: () => [] },
    deadline: { type: String, required: true },
    currentMonth: { type: String, required: true },
    today: { type: String, required: true },
    tomorrow: { type: String, required: true },
});

const page = usePage();
const errorList = computed(() => Object.values(page.props.errors ?? {}).flat());
const typeBadge = { transfer: 'success', refund: 'secondary', deferral: 'info', extension: 'warning' };
const money = (v) => formatMoney(v || 0);
const statusOptions = [
    { value: 'pending', label: 'Chờ duyệt' },
    { value: 'overdue', label: 'Quá hạn xử lý' },
    { value: 'approved', label: 'Đã duyệt' },
    { value: 'rejected', label: 'Đã từ chối' },
];
const methods = [
    { value: 'transfer', icon: 'swap_horiz', label: 'Chuyển nhượng', hint: 'Ưu tiên' },
    { value: 'refund', icon: 'payments', label: 'Hoàn tiền', hint: 'Phương án cuối' },
    { value: 'deferral', icon: 'pause_circle', label: 'Bảo lưu', hint: null },
];

// ── Hộp thoại ────────────────────────────────────────────────────────────
const extensionOpen = ref(false);
const extensionKey = ref(0);
const requestOpen = ref(false);
const requestKey = ref(0);
const approving = ref(null);
const rejecting = ref(null);
const claw = ref('0');

function openExtension() {
    extensionKey.value++;
    extensionOpen.value = true;
}
function openApprove(rq) {
    claw.value = props.clawbackHints[rq.id]?.suggest ? '1' : '0';
    approving.value = rq;
}
const canApprove = (rq) => props.approvableTypes.includes(rq.type);
const hint = computed(() => (approving.value ? props.clawbackHints[approving.value.id] ?? null : null));

// ── Bảng tính hoàn phí / chuyển nhượng (refundTransferManager) ──────────
const empty = { has_tuition: false, paid: 0, contract: 0, debt: 0, total_sessions: null, attended_sessions: 0, remaining_sessions: null, unit_price: 0 };
const fold = (v) =>
    (v || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/đ/g, 'd');

const calc = reactive({
    actionType: 'transfer',
    selectedStudentId: '',
    targetId: '',
    targetQuery: '',
    attendedLessons: 0,
    refundAmount: 0,
});
const basis = computed(() => props.studentFinance[calc.selectedStudentId] || empty);
const currentStudent = computed(() => props.targets.find((t) => t.id === String(calc.selectedStudentId)) || null);
const studentName = computed(() => (currentStudent.value ? currentStudent.value.name : '—'));
const studentCode = computed(() => (currentStudent.value ? currentStudent.value.code : '—'));
// Học viên nhận: khác học viên nguồn, lọc theo tên / mã (không dấu).
const targetMatches = computed(() => {
    const q = fold(calc.targetQuery);
    return props.targets
        .filter((t) => t.id !== String(calc.selectedStudentId))
        .filter((t) => !q || t.search.includes(q))
        .slice(0, 30);
});
const totalLessons = computed(() => basis.value.total_sessions || 0);
const remainingLessons = computed(() => Math.max(0, totalLessons.value - (calc.attendedLessons || 0)));
const unitPrice = computed(() => (totalLessons.value > 0 ? basis.value.contract / totalLessons.value : 0));
// Giá trị còn lại = đã nộp − giá trị các buổi đã học (không âm).
const remainingValue = computed(() => {
    const used = Math.min(basis.value.paid, unitPrice.value * (calc.attendedLessons || 0));
    return Math.max(0, Math.round(basis.value.paid - used));
});
const adminFee = computed(() => Math.round((remainingValue.value * props.adminFeePercent) / 100));
const suggestedAmount = computed(() => {
    if (calc.actionType === 'transfer') return Math.min(remainingValue.value, Math.round(unitPrice.value * remainingLessons.value));
    if (calc.actionType === 'refund') return Math.max(0, remainingValue.value - adminFee.value);
    return 0;
});

// Làm lại số liệu theo hợp đồng thật của học viên được chọn.
function resetFromBasis() {
    calc.attendedLessons = basis.value.attended_sessions || 0;
    if (calc.targetId === String(calc.selectedStudentId)) calc.targetId = '';
    nextTick(() => (calc.refundAmount = suggestedAmount.value));
}
resetFromBasis();
watch(() => calc.selectedStudentId, resetFromBasis);
watch(
    () => calc.actionType,
    () => (calc.refundAmount = suggestedAmount.value),
);

function openRequest() {
    Object.assign(calc, { actionType: 'transfer', selectedStudentId: '', targetId: '', targetQuery: '' });
    resetFromBasis();
    requestKey.value++;
    requestOpen.value = true;
}

// ── Bộ lọc "Tất cả yêu cầu" ─────────────────────────────────────────────
function submitFilters(event) {
    const data = Object.fromEntries(new FormData(event.target));
    router.get(route('tuition.refunds', compactQuery(data)) + '#all-requests', {}, { preserveScroll: true });
}
const openProof = (rq) => openRemoteModal(route('tuition.refunds.proof', rq.id), { size: 'xl' });
const inputClass =
    'w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base text-on-surface placeholder:text-on-surface-subtle focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/50';
</script>

<template>
    <UiPageHeader title="Xử lý khất nợ / hoàn tiền" description="Quản lý các yêu cầu tài chính phát sinh trong quá trình học tập: khất nợ, bảo lưu, chuyển nhượng buổi dư và hoàn tiền.">
        <template #actions>
            <UiButton variant="secondary" icon="arrow_back" :href="route('tuition.students')">Công nợ học viên</UiButton>
            <template v-if="can('refund_transfer.request')">
                <UiButton variant="secondary" icon="event_busy" @click="openExtension">Đánh dấu khất nợ</UiButton>
                <UiButton icon="exit_to_app" @click="openRequest">Tạo yêu cầu nghỉ giữa khóa</UiButton>
            </template>
        </template>
    </UiPageHeader>

    <UiAlert v-if="errorList.length" type="error" title="Vui lòng kiểm tra lại thông tin" class="mb-4">
        <ul class="list-inside list-disc space-y-0.5">
            <li v-for="(message, i) in errorList" :key="i">{{ message }}</li>
        </ul>
    </UiAlert>

    <UiAlert type="info" class="mb-lg" title="Quy định hoàn phí (A6)">
        Xử lý <strong>trong 1 tuần</strong> kể từ ngày lập và <strong>trong cùng tháng phát sinh</strong> để khớp sổ sách. <strong>Ưu tiên chuyển nhượng</strong> buổi dư cho học viên khác, hoàn tiền là phương án cuối. Hoàn tiền do <strong>Admin</strong> duyệt và
        <strong>bắt buộc ảnh bằng chứng</strong> chi tiền. Hồ sơ quá hạn được gắn cờ "Quá hạn xử lý" nhưng vẫn duyệt được.
    </UiAlert>

    <!-- Yêu cầu chờ phê duyệt -->
    <div class="grid grid-cols-12 gap-lg">
        <div class="col-span-12">
            <section class="flex h-full flex-col rounded-xl border border-outline-variant bg-surface-container-lowest p-lg shadow-sm">
                <div class="mb-lg flex items-center justify-between gap-sm">
                    <div class="flex items-center gap-sm">
                        <span class="material-symbols-outlined text-tertiary" aria-hidden="true">fact_check</span>
                        <h3 class="font-h3 text-h3 text-on-surface">Yêu cầu chờ phê duyệt</h3>
                    </div>
                    <UiBadge v-if="overdueCount > 0" color="error" pill>{{ overdueCount }} QUÁ HẠN</UiBadge>
                </div>
                <UiDataTable class="flex-1">
                    <table>
                        <thead>
                            <tr>
                                <th>Học viên nguồn</th>
                                <th>Nội dung</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="rq in pendingRequests" :key="rq.id" class="align-top">
                                <td>
                                    <p class="font-body-medium text-body-medium text-on-surface">{{ rq.student?.name }}</p>
                                    <p class="font-code text-caption text-on-surface-variant"><UiCode :value="rq.student?.code" /></p>
                                    <UiBadge v-if="rq.overdue" color="error" class="mt-xs">Quá hạn xử lý · {{ rq.overdue_days }} ngày</UiBadge>
                                    <span v-else-if="rq.processing_deadline" class="mt-xs block font-caption text-caption text-on-surface-variant">Hạn xử lý {{ rq.processing_deadline }}</span>
                                </td>
                                <td>
                                    <UiBadge :color="typeBadge[rq.type] ?? 'neutral'" pill :dot="false">{{ rq.type_label }}</UiBadge>
                                    <p class="mt-xs font-body-small text-body-small text-on-surface">
                                        <template v-if="rq.type === 'transfer'">{{ money(rq.refund_amount) }} → <UiCode :value="rq.target?.code" /></template>
                                        <strong v-else-if="rq.type === 'refund'">{{ money(rq.refund_amount) }}</strong>
                                        <template v-else-if="rq.type === 'deferral'">{{ rq.defer_from }} – {{ rq.defer_to }}</template>
                                        <template v-else>Hạn mới {{ rq.extended_due_date }}</template>
                                    </p>
                                    <p class="mt-[2px] font-caption text-caption text-on-surface-variant">{{ rq.created_date }} · {{ rq.requester_name ?? '—' }}</p>
                                </td>
                                <td>
                                    <div v-if="canApprove(rq) || canRejectAny" class="flex gap-sm">
                                        <UiButton v-if="canApprove(rq)" variant="success" size="sm" icon="check" title="Duyệt" aria-label="Duyệt" @click="openApprove(rq)" />
                                        <span v-else class="font-caption text-caption text-on-surface-variant" :title="'Cần quyền ' + rq.approve_permission">{{ rq.type === 'refund' ? 'Chờ Admin duyệt' : 'Chờ người có quyền duyệt' }}</span>
                                        <UiButton v-if="canRejectAny" variant="danger-text" size="sm" icon="close" title="Từ chối" aria-label="Từ chối" @click="rejecting = rq" />
                                    </div>
                                    <span v-else class="font-caption text-caption text-on-surface-variant">Chờ duyệt</span>
                                </td>
                            </tr>
                            <tr v-if="!pendingRequests.length">
                                <td colspan="3"><UiEmptyState icon="task_alt" title="Không có yêu cầu chờ phê duyệt" /></td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>
                <UiButton variant="ghost" href="#all-requests" native class="mt-md w-full">Xem tất cả yêu cầu</UiButton>
            </section>
        </div>
    </div>

    <template v-if="can('refund_transfer.request')">
        <!-- Đánh dấu khất nợ: dời hạn đóng, vẫn giữ lịch học; duyệt xong tạm dừng nhắc nợ tới hạn mới. -->
        <UiModal :show="extensionOpen" title="Đánh dấu khất nợ" max-width="xl" @close="extensionOpen = false">
            <UiForm id="refund-extension-form" :key="extensionKey" :action="route('tuition.refunds.store')" method="post" @success="extensionOpen = false">
                <input type="hidden" name="type" value="extension" />
                <div class="mb-md flex items-start gap-sm rounded-lg bg-secondary/5 p-md font-body-small text-body-small text-secondary">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">info</span>
                    <p>Tiếp tục quy trình nhắc nợ chuẩn, không khóa lịch học của học viên. Khi được duyệt, hạn đóng được dời sang ngày mới và nhắc nợ tạm dừng tới ngày đó.</p>
                </div>
                <div class="grid grid-cols-1 gap-md md:grid-cols-3">
                    <div class="md:col-span-2">
                        <UiSelect
                            id="extension_student_id"
                            name="student_id"
                            label="Học viên đang nợ"
                            required
                            :options="debtorOptions"
                            :placeholder="debtorOptions.length ? '— Chọn học viên —' : 'Không có học viên còn nợ'"
                        />
                    </div>
                    <UiDate name="extended_due_date" label="Hạn đóng mới" :min="tomorrow" required />
                    <UiField label="Lý do khất nợ (Bắt buộc)" for="extension_reason" class="md:col-span-3">
                        <textarea id="extension_reason" name="reason" rows="2" required placeholder="Nhập chi tiết lý do học viên xin gia hạn thời gian nộp học phí..." :class="inputClass"></textarea>
                    </UiField>
                </div>
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="extensionOpen = false">Hủy</UiButton>
                <UiButton type="submit" form="refund-extension-form" icon="event_available">Xác nhận khất nợ</UiButton>
            </template>
        </UiModal>

        <!-- Tạo yêu cầu xử lý nghỉ giữa khóa: chuyển nhượng (ưu tiên) / hoàn tiền (phương án cuối) / bảo lưu -->
        <UiModal :show="requestOpen" title="Tạo yêu cầu xử lý nghỉ giữa khóa" max-width="3xl" @close="requestOpen = false">
            <UiForm id="refund-request-form" :key="requestKey" :action="route('tuition.refunds.store')" method="post" @success="requestOpen = false">
                <input type="hidden" name="type" :value="calc.actionType" />
                <input type="hidden" name="total_paid" :value="basis.paid" />
                <input type="hidden" name="attended_lessons" :value="calc.attendedLessons" />
                <input type="hidden" name="admin_fee" :value="calc.actionType === 'refund' ? adminFee : 0" />

                <div class="mb-md">
                    <UiSelect
                        id="refund_student_id"
                        v-model="calc.selectedStudentId"
                        name="student_id"
                        label="Học viên nguồn"
                        required
                        placeholder="— Chọn học viên —"
                        hint="Chỉ liệt kê học viên đã có hồ sơ học phí."
                        :options="sourceOptions"
                    />
                </div>

                <!-- Tóm tắt học viên (số liệu thật từ hợp đồng & điểm danh) -->
                <div class="mb-lg grid grid-cols-2 gap-md rounded-lg border border-outline-variant bg-surface-container-low p-md sm:grid-cols-4">
                    <div>
                        <p class="font-label text-label uppercase text-on-surface-variant">Học viên</p>
                        <p class="font-body-medium text-body-medium text-on-surface">{{ studentName }}</p>
                    </div>
                    <div>
                        <p class="font-label text-label uppercase text-on-surface-variant">Mã HV</p>
                        <p class="font-code text-code text-on-surface">{{ studentCode }}</p>
                    </div>
                    <div>
                        <p class="font-label text-label uppercase text-on-surface-variant">Buổi dư</p>
                        <p class="font-body-medium text-body-medium text-primary">{{ basis.total_sessions ? remainingLessons + ' buổi' : '—' }}</p>
                        <p v-show="basis.total_sessions" class="font-caption text-caption text-on-surface-variant">Đã học {{ calc.attendedLessons }} / {{ basis.total_sessions }}</p>
                    </div>
                    <div>
                        <p class="font-label text-label uppercase text-on-surface-variant">Đã thu</p>
                        <p class="font-body-medium text-body-medium text-tertiary">{{ money(basis.paid) }}</p>
                    </div>
                    <p v-if="calc.selectedStudentId && !basis.has_tuition" class="col-span-full font-body-small text-body-small text-error">Học viên chưa có hồ sơ học phí — không thể hoàn / chuyển nhượng / bảo lưu.</p>
                </div>

                <!-- Hình thức xử lý: chuyển nhượng đứng đầu (ưu tiên theo A6) -->
                <fieldset class="mb-lg">
                    <legend class="mb-sm font-label text-label uppercase text-on-surface-variant">Hình thức xử lý</legend>
                    <div class="grid grid-cols-1 gap-sm sm:grid-cols-3">
                        <label
                            v-for="m in methods"
                            :key="m.value"
                            class="flex cursor-pointer items-center gap-sm rounded-lg border p-md transition-colors"
                            :class="calc.actionType === m.value ? 'border-primary-container bg-primary-fixed/30' : 'border-outline-variant hover:bg-surface-container-low'"
                        >
                            <input v-model="calc.actionType" type="radio" :value="m.value" class="text-primary-container focus:ring-primary-container/30" />
                            <span class="material-symbols-outlined text-[20px] text-on-surface-variant" aria-hidden="true">{{ m.icon }}</span>
                            <span class="min-w-0">
                                <span class="block font-body-medium text-body-medium text-on-surface">{{ m.label }}</span>
                                <span v-if="m.hint" class="block font-caption text-caption text-on-surface-variant">{{ m.hint }}</span>
                            </span>
                        </label>
                    </div>
                </fieldset>

                <!-- Chuyển nhượng: tìm học viên nhận -->
                <div v-show="calc.actionType === 'transfer'" class="mb-lg space-y-sm">
                    <span class="block font-label text-label uppercase text-on-surface-variant">Học viên nhận chuyển nhượng <span class="text-error">*</span></span>
                    <input type="hidden" name="target_student_id" :value="calc.actionType === 'transfer' ? calc.targetId : ''" />
                    <div class="relative">
                        <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">search</span>
                        <input
                            v-model="calc.targetQuery"
                            type="search"
                            placeholder="Tìm tên hoặc mã học viên..."
                            aria-label="Tìm học viên nhận chuyển nhượng"
                            class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-sm pl-10 pr-md font-body-base text-body-base text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/50"
                        />
                    </div>
                    <ul class="custom-scrollbar max-h-48 divide-y divide-surface-container overflow-y-auto rounded-lg border border-outline-variant">
                        <li v-for="t in targetMatches" :key="t.id" class="flex items-center justify-between gap-sm p-sm" :class="calc.targetId === t.id ? 'bg-tertiary/5' : ''">
                            <span class="min-w-0">
                                <span class="block truncate font-body-medium text-body-medium text-on-surface">{{ t.name }}</span>
                                <span class="block font-code text-caption text-on-surface-variant">{{ t.code + (t.class ? ' · ' + t.class : '') + ' · Còn nợ ' + money(t.debt) }}</span>
                            </span>
                            <button
                                type="button"
                                class="rounded-lg px-sm py-xs font-body-medium text-body-small"
                                :class="calc.targetId === t.id ? 'bg-tertiary text-white' : 'border border-outline-variant text-on-surface hover:bg-surface-container-low'"
                                @click="calc.targetId = t.id"
                            >
                                {{ calc.targetId === t.id ? 'Đã chọn' : 'Chọn' }}
                            </button>
                        </li>
                        <li v-if="!targetMatches.length" class="p-sm font-body-small text-body-small text-on-surface-variant">Không tìm thấy học viên phù hợp.</li>
                    </ul>
                    <p v-show="calc.targetId" class="flex items-center gap-xs font-body-small text-body-small text-tertiary">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">verified</span>
                        Toàn bộ số buổi dư sẽ được chuyển sang học viên đích kèm giá trị tiền tương ứng (cấn trừ công nợ người nhận).
                    </p>
                </div>

                <!-- Hoàn tiền: gợi ý chuyển nhượng trước + bắt buộc lý do không chuyển nhượng -->
                <div v-show="calc.actionType === 'refund'" class="mb-lg space-y-sm rounded-lg border border-warning/30 bg-warning-container p-md">
                    <p class="flex items-start gap-sm font-body-small text-body-small text-on-warning-container">
                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">lightbulb</span>
                        <span>
                            Hoàn tiền là <strong>phương án cuối</strong>. Hãy ưu tiên <strong>chuyển nhượng</strong> {{ basis.total_sessions ? remainingLessons + ' buổi dư' : 'số buổi dư' }} cho học viên khác (không thu hồi hoa hồng, không phát sinh chi tiền).
                        </span>
                    </p>
                    <UiButton size="sm" variant="secondary" icon="swap_horiz" @click="calc.actionType = 'transfer'">Chuyển sang chuyển nhượng</UiButton>
                    <UiTextarea
                        name="no_transfer_reason"
                        label="Lý do không chuyển nhượng (Bắt buộc)"
                        rows="2"
                        :required="calc.actionType === 'refund'"
                        :disabled="calc.actionType !== 'refund'"
                        placeholder="VD: gia đình chuyển nơi ở, không có học viên nhận, phụ huynh yêu cầu hoàn tiền..."
                    />
                </div>

                <!-- Bảng tính hoàn phí / chuyển nhượng -->
                <div v-show="calc.actionType !== 'deferral'" class="mb-lg space-y-sm rounded-lg border border-outline-variant p-md">
                    <div class="grid grid-cols-2 gap-md sm:grid-cols-4">
                        <UiInput id="attendedLessons" :model-value="calc.attendedLessons" type="number" label="Số buổi đã học (điểm danh)" min="0" class="font-code text-code" @update:model-value="calc.attendedLessons = $event === '' ? 0 : Number($event)" />
                        <div>
                            <span class="mb-xs block font-caption text-caption text-on-surface-variant">Đơn giá / buổi</span>
                            <span class="font-code text-code text-on-surface">{{ money(unitPrice) }}</span>
                        </div>
                        <div>
                            <span class="mb-xs block font-caption text-caption text-on-surface-variant">Giá trị buổi còn lại</span>
                            <span class="font-code text-code text-on-surface">{{ money(remainingValue) }}</span>
                        </div>
                        <div v-show="calc.actionType === 'refund'">
                            <span class="mb-xs block font-caption text-caption text-on-surface-variant">{{ 'Phí quản trị (' + adminFeePercent + '%)' }}</span>
                            <span class="font-code text-code text-on-surface">{{ money(adminFee) }}</span>
                        </div>
                    </div>
                    <div class="flex flex-col gap-sm border-t border-surface-container pt-sm sm:flex-row sm:items-center sm:justify-between">
                        <label for="refundAmount" class="font-body-medium text-body-medium text-on-surface">{{ calc.actionType === 'transfer' ? 'Số tiền chuyển nhượng' : 'Số tiền hoàn trả' }}</label>
                        <div class="flex items-center gap-sm">
                            <input
                                id="refundAmount"
                                v-model.number="calc.refundAmount"
                                type="number"
                                name="refund_amount"
                                min="0"
                                :max="basis.paid"
                                :disabled="calc.actionType === 'deferral'"
                                class="w-44 rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm text-right font-code text-code text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/50"
                            />
                            <span class="font-body-small text-body-small text-on-surface-variant">VND</span>
                            <UiButton size="sm" variant="ghost" icon="calculate" title="Tính lại theo chính sách" @click="calc.refundAmount = suggestedAmount">Theo chính sách</UiButton>
                        </div>
                    </div>
                    <p class="font-caption text-caption text-on-surface-variant">
                        Số dư khả dụng tối đa: <strong class="font-code">{{ money(basis.paid) }}</strong> · Đề xuất theo chính sách: <strong class="font-code">{{ money(suggestedAmount) }}</strong>. Khi duyệt hệ thống chặn số tiền vượt số đã nộp.
                    </p>
                </div>

                <!-- Bảo lưu -->
                <div v-show="calc.actionType === 'deferral'" class="mb-lg grid grid-cols-1 gap-md rounded-lg border border-info/30 bg-info-container/60 p-md sm:grid-cols-2">
                    <UiDate name="defer_from" label="Bảo lưu từ ngày" :value="today" :disabled="calc.actionType !== 'deferral'" />
                    <UiDate name="defer_to" label="Đến ngày (học lại từ ngày kế tiếp)" :disabled="calc.actionType !== 'deferral'" />
                    <p class="font-body-small text-body-small text-on-info-container sm:col-span-2">
                        Khi được duyệt: học viên chuyển trạng thái <strong>Bảo lưu</strong>, đóng băng <strong>{{ basis.total_sessions ? remainingLessons + ' buổi còn lại' : 'số buổi còn lại' }}</strong> và công nợ
                        <strong class="font-code">{{ money(basis.debt) }}</strong>; nhắc nợ tạm dừng tới hết ngày bảo lưu.
                    </p>
                </div>

                <UiField label="Lý do nghỉ giữa khóa (Bắt buộc)" for="refund_reason">
                    <textarea id="refund_reason" name="reason" rows="3" required placeholder="Nhập chi tiết nguyên nhân học viên dừng học..." :class="inputClass"></textarea>
                </UiField>

                <p v-show="calc.actionType !== 'deferral'" class="mt-md font-caption text-caption text-on-surface-variant">
                    Hạn xử lý: <strong>{{ deadline }}</strong> (1 tuần, trong tháng {{ currentMonth }})<span v-show="calc.actionType === 'refund'"> · Admin duyệt</span>
                </p>
            </UiForm>
            <template #footer>
                <!-- Lý do nút bị khóa, đặt ngay cạnh nút. -->
                <p v-show="!basis.has_tuition" class="mr-auto self-center font-caption text-caption text-on-surface-variant">
                    {{ calc.selectedStudentId ? 'Học viên này chưa có hồ sơ học phí nên chưa gửi được yêu cầu.' : 'Chọn học viên nguồn để gửi yêu cầu.' }}
                </p>
                <UiButton variant="secondary" @click="requestOpen = false">Hủy</UiButton>
                <UiButton type="submit" form="refund-request-form" icon="send" :disabled="!basis.has_tuition">Gửi yêu cầu phê duyệt</UiButton>
            </template>
        </UiModal>
    </template>

    <!-- Hộp duyệt / từ chối từng hồ sơ chờ -->
    <template v-for="rq in pendingRequests" :key="'actions-' + rq.id">
        <UiModal v-if="canApprove(rq)" :show="approving?.id === rq.id" :title="'Duyệt ' + rq.type_label.toLowerCase() + ' — ' + (rq.student?.name ?? '')" max-width="md" @close="approving = null">
            <UiForm :id="'approve-refund-form-' + rq.id" :action="route('tuition.refunds.approve', rq.id)" method="post" class="space-y-md" @success="approving = null">
                <UiAlert v-if="rq.overdue" type="warning">Hồ sơ đã <strong>quá hạn xử lý</strong> (hạn {{ rq.processing_deadline }}). Vẫn duyệt được; hồ sơ giữ cờ "Quá hạn xử lý".</UiAlert>
                <dl class="grid grid-cols-2 gap-sm font-body-small text-body-small">
                    <dt class="text-on-surface-variant">Học viên</dt>
                    <dd class="text-on-surface">{{ rq.student?.name }} · <UiCode :value="rq.student?.code" class="text-on-surface-variant" /></dd>
                    <template v-if="rq.type === 'refund' || rq.type === 'transfer'">
                        <dt class="text-on-surface-variant">Số tiền</dt>
                        <dd class="font-code text-on-surface">{{ money(rq.refund_amount) }}</dd>
                    </template>
                    <template v-if="rq.target">
                        <dt class="text-on-surface-variant">Học viên nhận</dt>
                        <dd class="text-on-surface">{{ rq.target.name }} · <UiCode :value="rq.target.code" class="text-on-surface-variant" /></dd>
                    </template>
                    <dt class="text-on-surface-variant">Lý do</dt>
                    <dd class="text-on-surface">{{ rq.reason }}</dd>
                    <template v-if="rq.no_transfer_reason">
                        <dt class="text-on-surface-variant">Lý do không chuyển nhượng</dt>
                        <dd class="text-on-surface">{{ rq.no_transfer_reason }}</dd>
                    </template>
                </dl>
                <template v-if="rq.type === 'refund'">
                    <!-- A6: người duyệt chọn thu hồi hoa hồng; gợi ý "Có" nếu học viên học chưa tới 1 tháng -->
                    <div v-if="clawbackHints[rq.id]" class="space-y-xs rounded-lg border border-outline-variant p-sm">
                        <span class="block font-label text-label uppercase text-on-surface-variant">Thu hồi hoa hồng{{ clawbackHints[rq.id].owner ? ' (' + clawbackHints[rq.id].owner + ')' : '' }}</span>
                        <div class="flex flex-wrap items-center gap-sm">
                            <UiSelect
                                :id="'clawback_commission_' + rq.id"
                                v-model="claw"
                                name="clawback_commission"
                                aria-label="Thu hồi hoa hồng"
                                :options="[
                                    { value: '1', label: 'Có thu hồi' },
                                    { value: '0', label: 'Không thu hồi' },
                                ]"
                            />
                            <input
                                v-show="claw === '1'"
                                type="number"
                                name="clawback_amount"
                                min="0"
                                step="1000"
                                :value="clawbackHints[rq.id].amount"
                                aria-label="Số hoa hồng thu hồi (VNĐ)"
                                class="w-32 rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs font-code text-code text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/50"
                            />
                        </div>
                        <span class="block font-caption text-caption text-on-surface-variant">
                            {{ clawbackHints[rq.id].start ? 'Bắt đầu học ' + clawbackHints[rq.id].start : 'Chưa bắt đầu học' }} · gợi ý: {{ clawbackHints[rq.id].suggest ? 'có' : 'không' }} thu hồi
                        </span>
                    </div>
                    <UiField label="Ảnh bằng chứng chi tiền" name="proof_image" :for="'proof_image_' + rq.id" required hint="Ủy nhiệm chi / biên nhận đã ký (JPG, PNG, WEBP, tối đa 10MB).">
                        <input
                            :id="'proof_image_' + rq.id"
                            type="file"
                            name="proof_image"
                            accept="image/jpeg,image/png,image/webp"
                            required
                            class="block w-full font-body-small text-body-small file:mr-sm file:rounded-lg file:border-0 file:bg-surface-container-high file:px-sm file:py-xs"
                        />
                    </UiField>
                </template>
                <p v-else-if="rq.type === 'transfer'" class="font-caption text-caption text-on-surface-variant">Chuyển nhượng phí không thu hồi hoa hồng.</p>
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="approving = null">Hủy</UiButton>
                <UiButton type="submit" icon="check" :form="'approve-refund-form-' + rq.id">Duyệt</UiButton>
            </template>
        </UiModal>
        <UiModal v-if="canRejectAny" :show="rejecting?.id === rq.id" :title="'Từ chối hồ sơ — ' + (rq.student?.name ?? '')" max-width="md" @close="rejecting = null">
            <UiForm :id="'reject-refund-form-' + rq.id" :action="route('tuition.refunds.reject', rq.id)" method="post" @success="rejecting = null">
                <UiTextarea :id="'rejection_reason_' + rq.id" name="rejection_reason" label="Lý do từ chối" rows="3" placeholder="VD: đề nghị chuyển nhượng cho học viên khác thay vì hoàn tiền..." />
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="rejecting = null">Hủy</UiButton>
                <UiButton variant="danger" type="submit" icon="close" :form="'reject-refund-form-' + rq.id">Từ chối</UiButton>
            </template>
        </UiModal>
    </template>

    <!-- Tất cả yêu cầu -->
    <div id="all-requests" class="mt-lg">
        <UiDataTable sticky="first">
            <template #header>
                <h3 class="flex items-center gap-sm font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-primary" aria-hidden="true">history_edu</span>
                    Tất cả yêu cầu
                    <span class="font-caption text-caption text-on-surface-variant">{{ historyRequests.length }} / {{ totalCount }} hồ sơ</span>
                </h3>
                <form method="GET" :action="route('tuition.refunds')" class="flex flex-wrap items-center gap-sm" @submit.prevent="submitFilters">
                    <div class="w-48"><UiInput id="refund_filter_search" type="search" name="search" :value="filters.search" placeholder="Tìm học viên / mã HV..." /></div>
                    <UiSelect id="refund_filter_type" name="type" aria-label="Loại yêu cầu" placeholder="Tất cả loại" :value="filters.type" :options="typeOptions" />
                    <UiSelect id="refund_filter_status" name="status" aria-label="Trạng thái" placeholder="Tất cả trạng thái" :value="filters.status" :options="statusOptions" />
                    <UiButton type="submit" size="sm" variant="secondary" icon="filter_list">Lọc</UiButton>
                </form>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Ngày lập</th>
                        <th>Học viên nguồn</th>
                        <th>Loại yêu cầu</th>
                        <th>Học viên thụ hưởng</th>
                        <th class="text-right">Số tiền</th>
                        <th>Lý do &amp; căn cứ</th>
                        <th>Hạn xử lý</th>
                        <th>Người duyệt</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="rq in historyRequests" :key="rq.id">
                        <td class="whitespace-nowrap font-code text-code text-on-surface-variant">{{ rq.created_at }}</td>
                        <td>
                            <div class="font-body-medium text-body-medium">{{ rq.student?.name }}</div>
                            <div class="font-code text-caption text-on-surface-variant"><UiCode :value="rq.student?.code" /> ({{ rq.student?.class_name ?? '—' }})</div>
                        </td>
                        <td>
                            <UiBadge :color="typeBadge[rq.type] ?? 'neutral'">{{ rq.type_label }}</UiBadge>
                            <div v-if="rq.type === 'deferral'" class="mt-xs font-code text-caption text-on-surface-variant">{{ rq.defer_from }} – {{ rq.defer_to }}</div>
                            <div v-else-if="rq.type === 'extension' && rq.extended_due_date" class="mt-xs font-code text-caption text-on-surface-variant">Hạn mới {{ rq.extended_due_date }}</div>
                        </td>
                        <td>
                            <template v-if="rq.target">
                                <div class="font-body-medium text-body-medium">{{ rq.target.name }}</div>
                                <div class="font-code text-caption text-on-surface-variant"><UiCode :value="rq.target.code" /></div>
                            </template>
                            <span v-else class="text-on-surface-variant">—</span>
                        </td>
                        <td><UiMoney :value="rq.type === 'refund' || rq.type === 'transfer' ? rq.refund_amount : null" /></td>
                        <td class="max-w-[260px]">
                            <div class="truncate" :title="rq.reason">{{ rq.reason }}</div>
                            <div v-if="rq.no_transfer_reason" class="truncate font-caption text-caption text-on-surface-variant" :title="rq.no_transfer_reason">Không chuyển nhượng: {{ rq.no_transfer_reason }}</div>
                            <div v-if="rq.rejection_reason" class="truncate font-caption text-caption text-error" :title="rq.rejection_reason">Từ chối: {{ rq.rejection_reason }}</div>
                        </td>
                        <td class="whitespace-nowrap">
                            <template v-if="rq.processing_deadline">
                                <span class="font-code text-code">{{ rq.processing_deadline }}</span>
                                <UiBadge v-if="rq.overdue" color="error" class="mt-xs">{{ rq.status === 'pending' ? 'Quá hạn xử lý' : 'Xử lý trễ hạn' }}</UiBadge>
                            </template>
                            <span v-else class="text-on-surface-variant">—</span>
                        </td>
                        <td class="font-body-small text-body-small">
                            {{ rq.approver_name ?? '—' }}
                            <div
                                v-if="rq.status === 'approved' && rq.type === 'refund' && rq.clawback_commission !== null"
                                class="font-caption text-caption"
                                :class="rq.clawback_commission ? 'text-error' : 'text-on-surface-variant'"
                            >
                                {{ rq.clawback_commission ? 'Thu hồi HH ' + money(rq.clawback_amount) + (rq.clawback_user_name ? ' (' + rq.clawback_user_name + ')' : '') : 'Không thu hồi HH' }}
                            </div>
                            <a
                                v-if="rq.has_proof"
                                :href="route('tuition.refunds.proof', rq.id)"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-xs font-caption text-caption text-primary hover:underline"
                                @click.prevent="openProof(rq)"
                            >
                                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">image</span> Ảnh bằng chứng
                            </a>
                        </td>
                        <td>
                            <UiBadge v-if="rq.status === 'approved'" color="success">Đã duyệt</UiBadge>
                            <UiBadge v-else-if="rq.status === 'rejected'" color="error">Đã từ chối</UiBadge>
                            <UiBadge v-else color="warning">{{ rq.type === 'refund' ? 'Chờ Admin duyệt' : 'Chờ duyệt' }}</UiBadge>
                        </td>
                    </tr>
                    <tr v-if="!historyRequests.length">
                        <td colspan="9"><UiEmptyState icon="inbox" title="Chưa có hồ sơ phù hợp" description="Chưa có hồ sơ khất nợ / bảo lưu / chuyển nhượng / hoàn tiền nào khớp bộ lọc." /></td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </div>
</template>
