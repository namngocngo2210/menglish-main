<script setup>
/**
 * Quy trình Chốt & Xếp lớp (mockup quy-trinh-chot-xep-lop), 4 bước trên một form:
 *   1. Xác nhận khách + khóa đăng ký · 2. Học phí, ưu đãi (tạo nhanh ưu đãi), thu trước, thu khác
 *   3. Chọn lớp đang học / sắp khai giảng còn chỗ, hoặc "Xếp lớp sau" (Chờ xếp lớp) · 4. Thu phí: VietQR / tiền mặt, xem & in bill.
 * Đổi khách → tải lại trang theo customer_id (lớp, tài khoản nhận tiền, lớp gợi ý theo trình độ tính theo khách).
 * Mã học viên cấp sẵn (studentCodePreview) để nội dung CK / VietQR xem trước trùng với mã thật; server vẫn tự sinh transfer_memo.
 */
import { computed, reactive, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import ClosingBillPreview from '@/Components/Crm/ClosingBillPreview.vue';
import { can } from '@/lib/can';
import { formatMoney } from '@/lib/format';
import { route } from '@/lib/route';
import { toast } from '@/lib/toast';

defineOptions({ layout: { title: 'Quy trình Chốt & Xếp lớp', hideErrors: true } });

const props = defineProps({
    customers: { type: Array, default: () => [] },
    pickedCustomerId: { type: Number, default: null },
    branches: { type: Array, default: () => [] },
    courses: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    bankAccounts: { type: Array, default: () => [] },
    defaultBankAccountId: { type: Number, default: null },
    promotions: { type: Array, default: () => [] },
    merchandiseItems: { type: Array, default: () => [] },
    defaultClassId: { type: Number, default: null },
    defaultCourseId: { type: Number, default: null },
    studentCodePreview: { type: String, required: true },
    oldPaperInvoiceNumber: { type: String, default: '' },
    center: { type: Object, required: true },
    billDates: { type: Object, required: true },
});

const page = usePage();
const errorMessages = computed(() => Object.values(page.props.errors ?? {}).filter(Boolean));

const picked = props.customers.find((c) => c.id === props.pickedCustomerId) ?? null;
const defaultClass = props.classes.find((c) => c.id === props.defaultClassId) ?? null;
const defaultCourse = props.courses.find((c) => c.id === props.defaultCourseId) ?? null;
const defaultTuition = defaultClass ? defaultClass.tuition : (defaultCourse?.tuition ?? 0);

const step = ref(1);
const w = reactive({
    customerId: picked ? String(picked.id) : '',
    customerName: picked?.name ?? '',
    customerPhone: picked?.phone ?? '',
    customerBranchId: String(picked?.branch_id ?? ''),
    customerStage: picked?.stage_label ?? '',
    customerLevel: picked?.level_label ?? '',
    customerLevelKeys: picked?.level_keys ?? [],
    courseName: defaultClass?.course_name ?? defaultCourse?.name ?? '',
    classId: defaultClass ? String(defaultClass.id) : '',
    assignLater: props.classes.length === 0,
    feePaid: true,
    className: defaultClass?.name ?? '',
    classBranchId: String(defaultClass?.branch_id ?? ''),
    courseId: String(props.defaultCourseId ?? ''),
    baseTuition: defaultTuition,
    discount: 0,
    otherFees: 0,
    feeItems: [],
    prepaidAmount: 0,
    paidAmount: defaultTuition,
    selectedPromotionId: '',
    paymentMethod: 'transfer',
    paperInvoiceNumber: props.oldPaperInvoiceNumber,
    billNotes: '',
    selectedBankAccountId: String(props.defaultBankAccountId ?? ''),
    copiedField: '',
});
const promotionsList = ref([...props.promotions]);

// ── Số liệu tính toán ───────────────────────────────────────────────────────────────────────
/** Tổng thành tiền hợp đồng = Học phí - Giảm trừ + Thu khác. */
const contractTotal = computed(() => Math.max(0, (w.baseTuition || 0) - (w.discount || 0) + (w.otherFees || 0)));
/** Số tiền cần thanh toán sau khi trừ thu trước. */
const amountDue = computed(() => Math.max(0, contractTotal.value - (w.prepaidAmount || 0)));
/** Số tiền chuyển khoản dùng để sinh VietQR. */
const effectiveTransferAmount = computed(() => (w.paymentMethod === 'transfer' ? Math.max(0, parseInt(w.paidAmount || 0, 10)) : 0));
const needsBankAccount = computed(() => w.feePaid && w.paymentMethod === 'transfer');
const availablePromotions = computed(() =>
    promotionsList.value.filter(
        (p) => (!p.branch_id || String(p.branch_id) === String(w.assignLater ? w.customerBranchId : w.classBranchId)) && (!p.course_id || String(p.course_id) === String(w.courseId)),
    ),
);
const promotionOptions = computed(() => availablePromotions.value.map((p) => ({ value: p.id, label: p.name + ' (' + (p.type === 'percent' ? p.value + '%' : formatMoney(p.value)) + ')' })));
const selectedBank = computed(
    () => props.bankAccounts.find((b) => String(b.id) === String(w.selectedBankAccountId)) || props.bankAccounts[0] || { bank_code: '', bank_name: '', account_number: '', account_holder: '' },
);
const bankOptions = computed(() => props.bankAccounts.map((b) => ({ value: b.id, label: `${b.bank_name} - ${b.account_number} (${b.account_holder})` })));
const cleanAccountNumber = computed(() => (selectedBank.value.account_number || '').replace(/\s+/g, ''));

function removeVietnameseTones(str) {
    return str
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/đ/g, 'd')
        .replace(/Đ/g, 'D');
}
/** Nội dung CK: tên học sinh + mã học sinh + lớp (không dấu). Xếp lớp sau thì bỏ phần lớp. */
const transferMemo = computed(() => {
    const clean = (v) => removeVietnameseTones(v || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    return [clean(w.customerName || 'HOCVIEN'), clean(props.studentCodePreview), w.assignLater ? '' : clean(w.className)].filter(Boolean).join(' ');
});
const vietQrUrl = computed(() => {
    const bank = selectedBank.value;
    if (!needsBankAccount.value || effectiveTransferAmount.value <= 0 || !bank.account_number || !bank.bank_code) return '';
    const q = encodeURIComponent;
    return `https://img.vietqr.io/image/${q(bank.bank_code)}-${q(cleanAccountNumber.value)}-compact2.png?amount=${effectiveTransferAmount.value}&addInfo=${q(transferMemo.value)}&accountName=${q(bank.account_holder || '')}`;
});
const vnd = (num) => formatMoney(num || 0);

const canSubmit = computed(
    () =>
        !(
            !w.customerId ||
            (!w.assignLater && !w.classId) ||
            (w.assignLater && !w.courseId) ||
            (w.feePaid && w.paidAmount <= 0 && w.prepaidAmount <= 0) ||
            (needsBankAccount.value && !w.selectedBankAccountId) ||
            (w.feePaid && w.paidAmount > 0 && w.paymentMethod === 'cash' && !String(w.paperInvoiceNumber).trim())
        ),
);

// ── Lựa chọn ────────────────────────────────────────────────────────────────────────────────
const customerOptions = computed(() =>
    props.customers.map((c) => ({ value: c.id, label: `${c.name} (${c.short_code} - ${c.phone}) · ${c.course_interest ?? 'Chưa chọn khóa'} · ${c.stage_label}` })),
);
const courseOptions = computed(() => props.courses.map((c) => ({ value: c.id, label: `${c.name} (Học phí niêm yết: ${formatMoney(c.tuition)})` })));
const classOptions = computed(() =>
    props.classes.map((cl) => ({
        value: cl.id,
        label: `${cl.name} (${cl.code})${cl.status === 'upcoming' ? ' · Sắp khai giảng' : ''} · Cơ sở: ${cl.branch_name ?? ''} · Sĩ số: ${cl.active_enrollments_count}/${cl.max_capacity} · Lịch học: ${cl.schedule_text ?? ''}`,
    })),
);
const merchandiseOptions = computed(() => props.merchandiseItems.map((m) => ({ value: m.id, label: `[${m.category_label}] ${m.name} (${m.formatted_price})` })));

function updateCustomer(value) {
    // Lớp, tài khoản nhận tiền và lớp gợi ý theo trình độ được tính theo khách → đổi khách thì tải lại.
    if (value) {
        router.get(route('crm.closing-wizard'), { customer_id: value });
        return;
    }
    Object.assign(w, { customerId: '', customerName: '', customerPhone: '', customerBranchId: '', customerStage: '', customerLevel: '', customerLevelKeys: [] });
    w.paidAmount = w.feePaid ? amountDue.value : 0;
}

function discountFor(promo) {
    if (promo.type === 'percent') {
        let disc = (w.baseTuition * promo.value) / 100;
        if (promo.max_discount_amount && disc > promo.max_discount_amount) disc = promo.max_discount_amount;
        return Math.round(disc);
    }
    return Math.min(w.baseTuition, parseFloat(promo.value));
}

function applyPromotion(promoId) {
    w.selectedPromotionId = promoId ? String(promoId) : '';
    const promo = promoId ? promotionsList.value.find((p) => String(p.id) === String(promoId)) : null;
    if (!promoId) {
        w.discount = 0;
        w.paidAmount = amountDue.value;
        return;
    }
    if (promo) {
        w.discount = discountFor(promo);
        w.paidAmount = amountDue.value;
    }
}

/** Bỏ ưu đãi đang chọn nếu không còn áp dụng được cho lớp / khóa / cơ sở mới. */
function dropUnavailablePromotion() {
    if (!availablePromotions.value.some((p) => String(p.id) === String(w.selectedPromotionId))) {
        w.selectedPromotionId = '';
        w.discount = 0;
    }
}

function applyCourseTuition() {
    const course = props.courses.find((c) => String(c.id) === String(w.courseId));
    if (!course) return;
    w.courseName = course.name;
    w.baseTuition = course.tuition;
    dropUnavailablePromotion();
    applyPromotion(w.selectedPromotionId);
    w.paidAmount = w.feePaid ? amountDue.value : 0;
}

function updateCourse(value) {
    w.courseId = value;
    if (w.assignLater) applyCourseTuition();
}

function updateClass(value) {
    const cl = props.classes.find((c) => String(c.id) === String(value));
    w.classId = value ? String(value) : '';
    w.className = cl?.name ?? '— Chọn lớp —';
    w.classBranchId = String(cl?.branch_id ?? '');
    w.courseId = String(cl?.course_id ?? '');
    w.courseName = cl?.course_name ?? '';
    dropUnavailablePromotion();
    w.baseTuition = cl?.tuition ?? 0;
    applyPromotion(w.selectedPromotionId);
    w.paidAmount = amountDue.value;
}

function setAssignLater(value) {
    w.assignLater = value;
    if (value) {
        w.className = 'Xếp lớp sau';
        applyCourseTuition();
    } else {
        updateClass(w.classId);
    }
}

function levelMatches(haystack) {
    return w.customerLevelKeys.length > 0 && w.customerLevelKeys.some((key) => String(haystack || '').includes(key));
}

// ── Thu khác ────────────────────────────────────────────────────────────────────────────────
const merchandisePick = ref('');
function addPresetItem(value) {
    const item = props.merchandiseItems.find((m) => String(m.id) === String(value));
    merchandisePick.value = '';
    if (!item || w.feeItems.some((f) => Number(f.id) === Number(item.id))) return;
    w.feeItems.push({ id: item.id, name: item.name, amount: item.price });
    recalculateOtherFees();
}
function removeItem(idx) {
    w.feeItems.splice(idx, 1);
    recalculateOtherFees();
}
function recalculateOtherFees() {
    w.otherFees = w.feeItems.reduce((acc, item) => acc + (parseFloat(item.amount) || 0), 0);
    w.paidAmount = amountDue.value;
}
const toNumber = (value) => (value === '' || value === null ? 0 : Number(value));

function onFeePaidChange() {
    w.paidAmount = w.feePaid ? amountDue.value : 0;
}

// ── Tạo nhanh ưu đãi ────────────────────────────────────────────────────────────────────────
const promoOpen = ref(false);
const emptyPromo = () => ({ name: '', type: 'fixed', value: 0, description: '', branch_id: '', course_id: '', starts_at: '', ends_at: '', usage_limit: '' });
const newPromo = reactive(emptyPromo());
const promoErrors = ref({});
const promoSaving = ref(false);
const promoError = (field) => promoErrors.value[field]?.[0] ?? null;

async function saveNewPromotion() {
    if (!newPromo.name || !newPromo.value) {
        toast('Vui lòng nhập tên chương trình ưu đãi và giá trị giảm!', 'error');
        return;
    }
    promoSaving.value = true;
    promoErrors.value = {};
    try {
        const response = await fetch(route('crm.promotions.store'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': page.props.csrf ?? '', Accept: 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(newPromo),
        });
        const data = await response.json().catch(() => ({}));
        if (response.status === 422) {
            promoErrors.value = data.errors ?? {};
            return;
        }
        if (data.success && data.promotion) {
            const promo = {
                ...data.promotion,
                value: parseFloat(data.promotion.value),
                max_discount_amount: data.promotion.max_discount_amount !== null ? parseFloat(data.promotion.max_discount_amount) : null,
            };
            promotionsList.value.push(promo);
            w.selectedPromotionId = String(promo.id);
            w.discount = discountFor(promo);
            w.paidAmount = amountDue.value;
            promoOpen.value = false;
            Object.assign(newPromo, emptyPromo());
            toast('Đã tạo và áp dụng ưu đãi "' + promo.name + '" thành công!', 'success');
        } else {
            toast('Có lỗi xảy ra khi tạo ưu đãi.', 'error');
        }
    } catch (err) {
        toast('Lỗi kết nối máy chủ: ' + err.message, 'error');
    } finally {
        promoSaving.value = false;
    }
}

// ── VietQR & Bill ───────────────────────────────────────────────────────────────────────────
function copyText(text, fieldName) {
    navigator.clipboard?.writeText(text).then(() => {
        w.copiedField = fieldName;
        setTimeout(() => {
            if (w.copiedField === fieldName) w.copiedField = '';
        }, 2000);
    });
}

function downloadVietQr() {
    const url = vietQrUrl.value;
    if (!url) return;
    const filename = `VietQR_${w.customerPhone || 'HocVien'}_${effectiveTransferAmount.value}d.png`;
    fetch(url)
        .then((res) => res.blob())
        .then((blob) => {
            const blobUrl = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = blobUrl;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(blobUrl);
        })
        .catch(() => window.open(url, '_blank'));
}

const billOpen = ref(false);
const bill = computed(() => ({
    customerName: w.customerName,
    studentCode: props.studentCodePreview,
    className: w.className,
    baseTuition: w.baseTuition,
    discount: w.discount,
    otherFees: w.otherFees,
    feeItems: w.feeItems,
    prepaidAmount: w.prepaidAmount,
    amountDue: amountDue.value,
    notes: w.billNotes,
    needsBankAccount: needsBankAccount.value,
    paperInvoiceNumber: w.paperInvoiceNumber,
    bank: selectedBank.value,
    transferMemo: transferMemo.value,
    vietQrUrl: vietQrUrl.value,
}));

function printBill() {
    const printContent = document.getElementById('printableBillArea')?.innerHTML ?? '';
    const printWindow = window.open('', '_blank');
    if (!printWindow) return;
    printWindow.document.write(`<!DOCTYPE html><html><head><title>In Thông Báo Nộp Học Phí</title>
<style>@page { size: A4 portrait; margin: 10mm; } body { font-family: Arial, sans-serif; padding: 20px; } table { width: 100%; border-collapse: collapse; }</style>
</head><body>${printContent}</body></html>`);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => {
        printWindow.print();
        printWindow.close();
    }, 400);
}

const classFill = (cl) => {
    const cap = cl.max_capacity > 0 ? cl.max_capacity : Math.max(cl.min_students, cl.active_enrollments_count, 1);
    return Math.min(100, Math.round((cl.active_enrollments_count / Math.max(1, cap)) * 100));
};
const steps = [
    { n: 1, title: 'Xác nhận Chốt', hint: 'Khách & khóa đăng ký' },
    { n: 2, title: 'Học phí & Ưu đãi', hint: 'Thu trước & Thu khác' },
    { n: 3, title: 'Danh sách lớp', hint: 'Lớp đề xuất / Xếp lớp sau' },
    { n: 4, title: 'Chốt & Thu phí', hint: 'VietQR, Quẹt thẻ & Bill' },
];

// Không còn lớp nào còn chỗ → mặc định "Xếp lớp sau", học phí theo khóa.
if (w.assignLater) setAssignLater(true);
</script>

<template>
    <UiPageHeader title="Quy trình Chốt & Xếp lớp" description="Chốt khách → tạo học viên, tài khoản, học phí → xếp lớp (hoặc Chờ xếp lớp) → thu phí đăng ký" :back="route('crm.pipeline')">
        <template #actions>
            <UiButton v-if="can('bank_account.manage')" variant="secondary" icon="account_balance" :href="route('system-config.bank-accounts')" target="_blank" title="Cài đặt tài khoản ngân hàng thụ hưởng & SePay">Cài đặt STK &amp; SePay</UiButton>
        </template>
    </UiPageHeader>

    <div class="max-w-4xl space-y-6">
        <UiAlert v-if="errorMessages.length" type="error" title="Không thể hoàn tất chốt khách:">
            <ul class="list-disc pl-5">
                <li v-for="(error, i) in errorMessages" :key="i">{{ error }}</li>
            </ul>
        </UiAlert>
        <UiAlert v-if="!bankAccounts.length" type="warning" class="font-semibold">Chưa có tài khoản ngân hàng hoạt động. Bạn vẫn có thể thu tiền mặt; chuyển khoản sẽ cần cấu hình tài khoản trước.</UiAlert>
        <UiAlert v-if="!customers.length" type="info" class="font-semibold">Chưa có khách nào sẵn sàng chốt (Đang tư vấn, Đã test hoặc Gửi kết quả).</UiAlert>
        <UiAlert v-if="!classes.length" type="warning" class="font-semibold">Không còn lớp đang học / sắp khai giảng nào còn chỗ. Bạn vẫn chốt được với "Xếp lớp sau" — học viên vào danh sách Chờ xếp lớp.</UiAlert>

        <!-- Chỉ báo bước -->
        <div class="flex items-center justify-between rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm">
            <template v-for="(s, i) in steps" :key="s.n">
                <div v-if="i > 0" class="h-0.5 w-12 bg-surface-container-high"></div>
                <div class="flex cursor-pointer items-center gap-3" @click="step = s.n">
                    <div :class="['flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold', step >= s.n ? (s.n === 4 ? 'bg-tertiary text-white' : 'bg-primary-container text-white') : 'bg-surface-container text-on-surface-variant']">{{ s.n }}</div>
                    <div class="hidden text-left sm:block">
                        <div class="text-xs font-bold text-on-surface">{{ s.title }}</div>
                        <div class="text-xs text-on-surface-subtle">{{ s.hint }}</div>
                    </div>
                </div>
            </template>
        </div>

        <UiForm :action="route('crm.closing-wizard.store')" method="post">
            <!-- Dữ liệu gửi server -->
            <input type="hidden" name="customer_id" :value="w.customerId" />
            <input type="hidden" name="class_id" :value="w.assignLater ? '' : w.classId" />
            <input type="hidden" name="course_id" :value="w.courseId" />
            <input type="hidden" name="fee_paid_at_closing" :value="w.feePaid ? 1 : 0" />
            <input type="hidden" name="course_name" :value="w.courseName" />
            <input type="hidden" name="base_tuition" :value="w.baseTuition" />
            <input type="hidden" name="discount" :value="w.discount" />
            <input type="hidden" name="promotion_id" :value="w.selectedPromotionId" />
            <input type="hidden" name="other_fees" :value="w.otherFees" />
            <input type="hidden" name="fee_items" :value="JSON.stringify(w.feeItems)" />
            <input type="hidden" name="prepaid_amount" :value="w.prepaidAmount" />
            <input type="hidden" name="paid_amount" :value="w.feePaid ? w.paidAmount : 0" />
            <input type="hidden" name="payment_method" :value="w.paymentMethod" />
            <input type="hidden" name="bank_account_id" :value="w.selectedBankAccountId" />
            <!-- Mã học viên cấp sẵn → nội dung CK / VietQR xem trước trùng với mã thật; transfer_memo do server sinh -->
            <input type="hidden" name="student_code" :value="studentCodePreview" />

            <!-- BƯỚC 1: XÁC NHẬN CHỐT KHÁCH -->
            <div v-show="step === 1" class="space-y-lg rounded-xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
                <h2 class="flex items-center gap-sm border-b border-surface-container-highest pb-sm font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-base text-primary-container">person_search</span>
                    Bước 1: Xác nhận Chốt khách
                </h2>

                <div class="space-y-4">
                    <UiSelect
                        id="closing_customer"
                        label="Khách cần chốt"
                        required
                        class="font-bold"
                        :options="customerOptions"
                        :model-value="w.customerId"
                        :placeholder="pickedCustomerId ? null : '— Chọn khách cần chốt —'"
                        @update:model-value="updateCustomer"
                    />

                    <!-- Thẻ khách: tên, trạng thái học phí, SĐT, giai đoạn, trình độ -->
                    <div v-show="w.customerId" class="rounded-xl border border-surface-container-highest bg-surface-container-low p-md" data-customer-summary>
                        <div class="flex items-start gap-md">
                            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary-fixed text-primary">
                                <span class="material-symbols-outlined">person</span>
                            </div>
                            <div class="min-w-0 flex-1 space-y-xs">
                                <div class="flex flex-wrap items-center justify-between gap-sm">
                                    <h2 class="font-h2 text-h2 text-on-surface">{{ w.customerName }}</h2>
                                    <!-- Trạng thái thật ở bước này: khách chưa đóng học phí (thu / hẹn thu được chọn ở Bước 4). -->
                                    <span class="inline-flex items-center gap-xs rounded-full border border-outline-variant bg-surface-container-lowest px-sm py-0.5 font-body-small text-body-small text-on-surface-variant" data-fee-status>
                                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">schedule</span>Chưa đóng học phí đăng ký
                                    </span>
                                </div>
                                <p class="flex flex-wrap items-center gap-sm font-body-small text-body-small text-on-surface-variant">
                                    <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]">phone</span><span class="font-code">{{ w.customerPhone }}</span></span>
                                    <span>|</span>
                                    <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]">analytics</span>Giai đoạn: {{ w.customerStage }}</span>
                                    <span>|</span>
                                    <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]">school</span>Trình độ: {{ w.customerLevel || 'Chưa có kết quả test' }}</span>
                                </p>
                            </div>
                        </div>
                        <!-- Chỉ hiện khi đã bỏ chọn "Đã đóng học phí" ở Bước 4 rồi quay lại -->
                        <div v-show="!w.feePaid" class="mt-md flex items-start gap-sm rounded-lg border-l-4 border-warning bg-warning-container p-sm font-body-small text-body-small text-on-warning-container">
                            <span class="material-symbols-outlined text-warning">info</span>
                            <div>
                                <p class="font-semibold">Chưa hoàn thành phí đăng ký</p>
                                <p>Hệ thống sẽ tự động tạo nhắc việc thu phí sau khi Chốt.</p>
                            </div>
                        </div>
                        <div class="mt-md flex items-center gap-sm font-body-small text-body-small text-on-surface-variant">
                            <span class="material-symbols-outlined text-secondary">upgrade</span>
                            <p>Khi Chốt, hồ sơ khách sẽ được nâng cấp thành tài khoản học viên chính thức.</p>
                        </div>
                    </div>

                    <UiSelect
                        id="closing_course"
                        label="Khóa học đăng ký"
                        class="font-bold !text-primary-container"
                        :options="courseOptions"
                        :model-value="w.courseId"
                        placeholder="— Chọn khóa học —"
                        hint="Khi chọn lớp ở Bước 3, khóa học lấy theo lớp. Khi &quot;Xếp lớp sau&quot;, học phí tính theo giá niêm yết của khóa này (trừ ưu đãi)."
                        @update:model-value="updateCourse"
                    />
                </div>

                <div class="flex items-center justify-end border-t border-surface-container-highest pt-4">
                    <UiButton :disabled="!w.customerId" @click="step = 2">
                        <span>Tiếp tục: Tính học phí &amp; Ưu đãi</span>
                        <span class="material-symbols-outlined text-base">arrow_forward</span>
                    </UiButton>
                </div>
            </div>

            <!-- BƯỚC 2: HỌC PHÍ, ƯU ĐÃI, THU TRƯỚC, THU KHÁC -->
            <div v-show="step === 2" class="space-y-lg rounded-xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
                <div class="flex items-center justify-between border-b border-surface-container-highest pb-2">
                    <h2 class="flex items-center gap-sm font-h3 text-h3 text-on-surface">
                        <span class="material-symbols-outlined text-base text-primary-container">percent</span>
                        Bước 2: Học phí, Ưu đãi, Thu trước &amp; Thu khác
                    </h2>
                    <UiButton v-if="can('promotion.manage')" variant="secondary" size="sm" icon="add_circle" class="!border-primary-container/30 !bg-primary-container/10 font-bold !text-primary-container hover:!bg-primary-container/20" @click="promoOpen = true">
                        <span>Tạo mới ưu đãi</span>
                    </UiButton>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <UiInput id="closing_base_tuition" type="number" label="Học phí niêm yết (VNĐ)" required readonly :model-value="w.baseTuition" class="cursor-not-allowed !bg-surface-container-low font-mono font-bold" />
                    <div>
                        <label class="mb-1 block flex items-center justify-between text-xs font-semibold text-on-surface-variant">
                            <span>Chương trình Ưu đãi / Voucher</span>
                            <span class="text-xs text-on-surface-subtle">Chọn hoặc nhập trực tiếp</span>
                        </label>
                        <UiSelect :options="promotionOptions" :model-value="w.selectedPromotionId" placeholder="-- Tùy chỉnh / Không áp dụng --" class="font-semibold" aria-label="Chương trình Ưu đãi / Voucher" @update:model-value="applyPromotion" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <UiInput id="closing_discount" type="number" label="Tiền Ưu đãi giảm trừ (VNĐ)" readonly placeholder="0" :model-value="w.discount" class="cursor-not-allowed !bg-surface-container-low font-mono font-bold !text-error" />
                    <div>
                        <label class="mb-1 block flex items-center justify-between text-xs font-semibold text-on-surface-variant">
                            <span>Khoản thu khác (VNĐ)</span>
                            <span class="text-xs font-bold text-secondary">{{ w.feeItems.length }} mục đã chọn</span>
                        </label>
                        <UiInput type="number" readonly placeholder="0" :model-value="w.otherFees" class="cursor-not-allowed !bg-surface-container-low font-mono font-bold !text-secondary" aria-label="Khoản thu khác (VNĐ)" />
                    </div>
                    <div>
                        <label class="mb-1 block flex items-center justify-between text-xs font-semibold text-on-surface-variant">
                            <span>Thu trước (VNĐ)</span>
                            <span class="text-xs text-on-surface-subtle">Đã đóng trước</span>
                        </label>
                        <UiInput v-if="can('tuition.approve')" type="number" placeholder="0" :model-value="w.prepaidAmount" class="font-mono font-bold !text-warning" aria-label="Thu trước (VNĐ)" @update:model-value="w.prepaidAmount = toNumber($event)" />
                        <template v-else>
                            <!-- Khoản thu trước cần người duyệt phiếu thu xác nhận (server từ chối nếu không có quyền). -->
                            <UiInput type="number" value="0" disabled class="font-mono" aria-label="Thu trước (VNĐ)" />
                            <p class="mt-1 text-xs text-on-surface-variant">Chỉ Kế toán / Quản lý nhập được khoản thu trước.</p>
                        </template>
                    </div>
                </div>

                <!-- Bóc tách chi tiết các khoản Thu khác -->
                <div class="space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-low p-4">
                    <div>
                        <span class="flex items-center gap-1.5 text-xs font-bold text-on-surface">
                            <span class="material-symbols-outlined text-base text-secondary">receipt_long</span>
                            Bóc tách chi tiết các khoản Thu khác (Hiển thị trên Hóa đơn phụ huynh)
                        </span>
                        <p class="mt-0.5 text-xs text-on-surface-variant">Phụ huynh muốn nhìn rõ từng khoản mục cần tính tiền trong phiếu báo học phí.</p>
                    </div>

                    <div class="flex flex-col items-center gap-2 pt-1 sm:flex-row">
                        <div class="w-full sm:flex-1">
                            <UiSelect
                                :options="merchandiseOptions"
                                :model-value="merchandisePick"
                                :placeholder="`-- Chọn nhanh từ Danh mục Hàng hóa & Thu khác (${merchandiseItems.length} mặt hàng) --`"
                                class="font-semibold"
                                @update:model-value="addPresetItem"
                            />
                        </div>
                        <a v-if="can('system_category.manage')" :href="route('merchandise.index')" target="_blank" class="flex shrink-0 items-center gap-0.5 text-xs font-bold text-secondary hover:text-secondary hover:underline" title="Mở quản lý danh mục hàng hóa trong tab mới">
                            <span class="material-symbols-outlined text-sm">open_in_new</span>
                            <span>Quản lý danh mục</span>
                        </a>
                    </div>

                    <div v-if="w.feeItems.length" class="space-y-2 border-t border-surface-container-highest pt-2">
                        <div v-for="(item, idx) in w.feeItems" :key="idx" class="shadow-2xs flex items-center gap-2 rounded-xl border border-surface-container-highest bg-surface-container-lowest p-2">
                            <div class="flex-1">
                                <input type="text" :value="item.name" readonly class="w-full rounded-lg border-surface-container-highest bg-surface-container-low p-1.5 text-xs font-semibold text-on-surface" :aria-label="'Mục thu khác ' + (idx + 1)" />
                            </div>
                            <div class="w-36">
                                <div class="relative">
                                    <input type="number" :value="item.amount" readonly class="w-full rounded-lg border-surface-container-highest bg-surface-container-low p-1.5 pr-7 text-right font-mono text-xs font-bold text-secondary" :aria-label="'Số tiền mục ' + (idx + 1)" />
                                    <span class="absolute right-2 top-1.5 text-xs font-bold text-on-surface-subtle">đ</span>
                                </div>
                            </div>
                            <UiButton variant="danger-text" size="sm" icon="delete" title="Xóa mục này" aria-label="Xóa mục này" @click="removeItem(idx)" />
                        </div>
                        <div class="flex items-center justify-between px-1 text-xs text-on-surface-variant">
                            <span>Tổng cộng các mục thu khác:</span>
                            <span class="font-mono font-bold text-secondary">{{ vnd(w.otherFees) }}</span>
                        </div>
                    </div>
                    <div v-else class="py-1 text-xs italic text-on-surface-subtle">Chưa chọn mục thu khác nào (hoặc nhập trực tiếp vào ô Khoản thu khác ở trên).</div>
                </div>

                <!-- Bảng tổng hợp thành tiền -->
                <div class="space-y-2 rounded-2xl border border-surface-container-highest bg-surface-container-low p-4 text-xs">
                    <div class="flex items-center justify-between text-on-surface-variant"><span>Học phí niêm yết:</span><span class="font-mono font-semibold">{{ vnd(w.baseTuition) }}</span></div>
                    <div class="flex items-center justify-between text-error"><span>- Giảm trừ ưu đãi:</span><span class="font-mono font-semibold">- {{ vnd(w.discount) }}</span></div>
                    <div class="flex items-center justify-between text-secondary"><span>+ Thu khác (Giáo trình / Phụ phí):</span><span class="font-mono font-semibold">+ {{ vnd(w.otherFees) }}</span></div>
                    <div class="flex items-center justify-between border-t border-surface-container-highest pt-2 font-bold text-on-surface">
                        <span>= Tổng giá trị hợp đồng (Thành tiền):</span><span class="font-mono text-sm text-primary-container">{{ vnd(contractTotal) }}</span>
                    </div>
                    <div class="flex items-center justify-between pt-1 text-warning"><span>- Thu trước (đã thanh toán trước):</span><span class="font-mono font-semibold">- {{ vnd(w.prepaidAmount) }}</span></div>
                    <div class="flex items-center justify-between border-t border-surface-container-highest pt-2 text-sm font-black text-tertiary">
                        <span>Số tiền thực thu cần nộp đợt này:</span><span class="font-mono text-base">{{ vnd(amountDue) }}</span>
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-surface-container-highest pt-4">
                    <UiButton variant="secondary" @click="step = 1">Quay lại</UiButton>
                    <UiButton @click="w.paidAmount = amountDue; step = 3">
                        <span>Tiếp tục: Xếp lớp</span>
                        <span class="material-symbols-outlined text-base">arrow_forward</span>
                    </UiButton>
                </div>
            </div>

            <!-- BƯỚC 3: XẾP LỚP -->
            <div v-show="step === 3" class="space-y-lg rounded-xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
                <h2 class="flex items-center gap-sm border-b border-surface-container-highest pb-sm font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-base text-primary-container">meeting_room</span>
                    Bước 3: Lớp học phù hợp đề xuất
                </h2>
                <p v-show="w.customerLevel" class="-mt-md font-body-small text-body-small text-on-surface-variant">Dựa trên trình độ <strong>{{ w.customerLevel }}</strong> của học viên</p>

                <div class="space-y-4">
                    <div class="flex flex-wrap gap-3 text-xs font-semibold">
                        <label :class="['inline-flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2', !w.assignLater ? 'border-primary-container bg-primary-container/10 text-primary-container' : 'border-surface-container-highest text-on-surface-variant']">
                            <input type="radio" name="class_mode" value="class" :checked="!w.assignLater" :disabled="!classes.length" @change="setAssignLater(false)" />
                            <span>Chọn lớp</span>
                        </label>
                        <label :class="['inline-flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2', w.assignLater ? 'border-primary-container bg-primary-container/10 text-primary-container' : 'border-surface-container-highest text-on-surface-variant']">
                            <input type="radio" name="class_mode" value="later" :checked="w.assignLater" @change="setAssignLater(true)" />
                            <span class="flex flex-col">
                                <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]">event_busy</span>Xếp lớp sau</span>
                                <span class="text-xs font-normal">Khách sẽ xuất hiện trong mục "Chờ xếp lớp"</span>
                            </span>
                        </label>
                    </div>

                    <UiAlert v-show="w.assignLater" type="warning" class="text-xs">
                        Học viên vẫn được tạo hồ sơ, tài khoản và học phí (theo khóa <strong>{{ w.courseName }}</strong>), nhưng chưa ghi danh vào lớp. Khách chuyển sang <strong>Chờ xếp lớp</strong>; Học vụ gán lớp sau ở mục "Chờ xếp lớp".
                    </UiAlert>

                    <div v-show="!w.assignLater">
                        <UiSelect
                            id="closing_class"
                            label="Chọn lớp đang học hoặc sắp khai giảng (còn chỗ)"
                            required
                            class="font-bold !text-primary-container"
                            :options="classOptions"
                            :model-value="w.classId"
                            placeholder="— Chọn lớp —"
                            @update:model-value="updateClass"
                        />

                        <!-- Thẻ gợi ý lớp: còn chỗ + ngưỡng khai giảng -->
                        <div v-if="classes.length" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2" data-class-suggestions>
                            <button
                                v-for="cl in classes"
                                :key="cl.id"
                                type="button"
                                :class="['space-y-1 rounded-xl border p-3 text-left text-xs transition', String(w.classId) === String(cl.id) ? 'border-primary-container bg-primary-container/10 ring-1 ring-primary-container' : 'border-surface-container-highest bg-surface-container-lowest hover:border-primary-container/60']"
                                @click="updateClass(cl.id)"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-bold text-on-surface">{{ cl.name }}</span>
                                    <UiBadge v-if="cl.status === 'upcoming'" color="info" pill :dot="false" class="font-bold">Sắp khai giảng{{ cl.start_label ? ' ' + cl.start_label : '' }}</UiBadge>
                                    <UiBadge v-else color="success" pill :dot="false" class="font-bold">Đang học</UiBadge>
                                </div>
                                <div class="font-code text-xs text-on-surface-subtle">{{ cl.code }}</div>
                                <span v-show="levelMatches(cl.level_haystack)" class="inline-block rounded bg-tertiary/10 px-1.5 py-0.5 text-xs font-bold text-tertiary">Phù hợp trình độ</span>
                                <div class="text-on-surface-variant">{{ cl.course_name ?? 'Chưa gán khóa' }} · {{ cl.branch_name }}</div>
                                <div class="flex items-center gap-1 text-on-surface-variant"><span class="material-symbols-outlined text-[14px]">calendar_today</span>Lịch học: {{ cl.schedule_text || 'Chưa có lịch' }}</div>
                                <div class="flex items-center gap-1 text-on-surface-variant"><span class="material-symbols-outlined text-[14px]">account_circle</span>Giáo viên: {{ cl.teacher ?? 'Chưa phân công' }}</div>
                                <div class="h-1.5 w-full overflow-hidden rounded-full bg-surface-container-high">
                                    <div :class="['h-full rounded-full', cl.needed_to_open > 0 ? 'bg-warning' : 'bg-tertiary']" :style="{ width: classFill(cl) + '%' }"></div>
                                </div>
                                <div class="text-on-surface-variant">
                                    Số học viên hiện có: <span class="font-semibold text-on-surface">{{ cl.active_enrollments_count }} / {{ cl.max_capacity > 0 ? cl.max_capacity : '∞' }}</span> (ngưỡng khai giảng {{ cl.min_students }})
                                </div>
                                <div class="flex flex-wrap items-center gap-2 pt-1">
                                    <span class="font-semibold text-on-surface">Sĩ số {{ cl.active_enrollments_count }}/{{ cl.max_capacity > 0 ? cl.max_capacity : '∞' }}</span>
                                    <span class="text-on-surface-subtle">·</span>
                                    <span :class="['font-semibold', (cl.remaining_seats ?? 99) <= 2 ? 'text-error' : 'text-tertiary']">Còn {{ cl.remaining_seats ?? 'không giới hạn' }} chỗ</span>
                                </div>
                                <template v-if="cl.status === 'upcoming'">
                                    <div v-if="cl.needed_to_open > 0" class="font-semibold text-warning">Cần thêm {{ cl.needed_to_open }} học viên để khai giảng (ngưỡng {{ cl.min_students }})</div>
                                    <div v-else class="font-semibold text-tertiary">Đã đủ ngưỡng khai giảng ({{ cl.min_students }} học viên)</div>
                                </template>
                                <div :class="['pt-1 text-right font-semibold', String(w.classId) === String(cl.id) ? 'text-primary' : 'text-on-surface-subtle']">
                                    <span>{{ String(w.classId) === String(cl.id) ? 'Đã chọn lớp này' : 'Chọn lớp này' }}</span>
                                </div>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-surface-container-highest pt-4">
                    <UiButton variant="secondary" @click="step = 2">Quay lại</UiButton>
                    <UiButton :disabled="!w.assignLater && !w.classId" @click="step = 4">
                        <span>Tiếp tục: Xác nhận &amp; Thu tiền</span>
                        <span class="material-symbols-outlined text-base">arrow_forward</span>
                    </UiButton>
                </div>
            </div>

            <!-- BƯỚC 4: XÁC NHẬN, PHƯƠNG THỨC THANH TOÁN & VIETQR -->
            <div v-show="step === 4" class="space-y-lg rounded-xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
                <div class="flex items-center justify-between border-b border-surface-container-highest pb-2">
                    <h2 class="flex items-center gap-sm font-h3 text-h3 text-on-surface">
                        <span class="material-symbols-outlined text-base text-tertiary">verified</span>
                        Bước 4: Xác nhận Hợp đồng, Chọn Phương thức Thanh toán &amp; Xuất Phiếu thu
                    </h2>
                </div>

                <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-12">
                    <!-- Cột trái: tóm tắt hợp đồng & phương thức thanh toán -->
                    <div class="space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-low p-5 text-xs lg:col-span-7">
                        <h3 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-on-surface">
                            <span class="material-symbols-outlined text-sm text-on-surface-variant">receipt_long</span>
                            <span>Tóm tắt hợp đồng đào tạo</span>
                        </h3>

                        <div class="space-y-2 border-b border-surface-container-highest pb-3 pt-1">
                            <div class="flex justify-between py-1"><span class="text-on-surface-variant">Học viên:</span><span class="font-bold text-on-surface">{{ w.customerName }} ({{ studentCodePreview }})</span></div>
                            <div class="flex justify-between py-1"><span class="text-on-surface-variant">Số điện thoại:</span><span class="font-mono font-semibold text-on-surface">{{ w.customerPhone }}</span></div>
                            <div class="flex justify-between py-1"><span class="text-on-surface-variant">Lớp học:</span><span class="font-bold text-primary-container">{{ w.className }}</span></div>
                            <div class="flex justify-between py-1"><span class="text-on-surface-variant">Tổng giá trị hợp đồng:</span><span class="font-mono font-bold text-on-surface">{{ vnd(contractTotal) }}</span></div>
                            <div v-if="w.feeItems.length" class="my-1 space-y-1 rounded-xl border border-secondary/30 bg-secondary/10 p-2 text-xs">
                                <div class="flex justify-between font-bold text-secondary"><span>Bao gồm Thu khác:</span><span class="font-mono">{{ vnd(w.otherFees) }}</span></div>
                                <div class="space-y-0.5 pl-1">
                                    <div v-for="(item, idx) in w.feeItems" :key="idx" class="flex justify-between text-on-surface-variant">
                                        <span>• {{ item.name || 'Mục khác' }}</span>
                                        <span class="font-mono font-semibold">{{ vnd(item.amount) }}</span>
                                    </div>
                                </div>
                            </div>
                            <div v-if="w.prepaidAmount > 0" class="flex justify-between py-1 text-warning"><span>Đã thu trước:</span><span class="font-mono font-bold">{{ vnd(w.prepaidAmount) }}</span></div>
                            <div class="flex justify-between py-1 font-bold text-tertiary"><span>Cần thanh toán đợt 1:</span><span class="font-mono text-sm">{{ vnd(amountDue) }}</span></div>
                        </div>

                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-tertiary/30 bg-tertiary/10 p-3 text-xs font-bold text-tertiary">
                            <input v-model="w.feePaid" type="checkbox" class="rounded border-tertiary/30 text-tertiary" @change="onFeePaidChange" />
                            <span>Đã đóng học phí đăng ký</span>
                        </label>
                        <p v-show="!w.feePaid" class="text-xs text-warning">Chưa thu tiền: hệ thống tạo task "Nhắc thu học phí" cho người phụ trách khách (hạn 3 ngày).</p>

                        <div v-show="w.feePaid">
                            <label for="closing_paid_amount" class="mb-1 block text-xs font-bold text-on-surface">Số tiền thu thực tế đợt 1 (VNĐ) <span class="text-error">*</span></label>
                            <UiInput id="closing_paid_amount" type="number" :model-value="w.paidAmount" aria-label="Số tiền thu thực tế đợt 1" class="font-mono font-black !text-tertiary" @update:model-value="w.paidAmount = toNumber($event)" />
                        </div>

                        <!-- Tài khoản ngân hàng nhận tiền -->
                        <div v-show="needsBankAccount">
                            <label class="mb-1 block flex items-center justify-between text-xs font-bold text-on-surface">
                                <span>Tài khoản Ngân hàng nhận tiền <span class="text-error">*</span></span>
                                <a v-if="can('bank_account.manage')" :href="route('system-config.bank-accounts')" target="_blank" class="text-xs font-normal text-primary-container hover:underline">Đổi STK trong Admin &rarr;</a>
                            </label>
                            <UiSelect v-model="w.selectedBankAccountId" :options="bankOptions" class="font-semibold" aria-label="Tài khoản Ngân hàng nhận tiền" />
                        </div>

                        <!-- Phương thức thanh toán: chỉ chuyển khoản hoặc tiền mặt (không POS, không kết hợp) -->
                        <div class="space-y-3 border-t border-surface-container-highest pt-2">
                            <div>
                                <span class="mb-1.5 block text-xs font-bold text-on-surface">Phương thức thanh toán giao dịch</span>
                                <div class="grid grid-cols-2 gap-2">
                                    <label :class="['flex cursor-pointer items-center gap-2 rounded-xl border bg-surface-container-lowest p-2.5 text-xs font-semibold transition', w.paymentMethod === 'transfer' ? 'border-primary-container bg-primary-container/10 text-primary-container' : 'border-surface-container-highest text-on-surface-variant']">
                                        <input v-model="w.paymentMethod" type="radio" name="pay_mode" value="transfer" class="text-primary-container focus:ring-primary-container" />
                                        <span>Chuyển khoản (VietQR)</span>
                                    </label>
                                    <label :class="['flex cursor-pointer items-center gap-2 rounded-xl border bg-surface-container-lowest p-2.5 text-xs font-semibold transition', w.paymentMethod === 'cash' ? 'border-primary-container bg-primary-container/10 text-primary-container' : 'border-surface-container-highest text-on-surface-variant']">
                                        <input v-model="w.paymentMethod" type="radio" name="pay_mode" value="cash" class="text-primary-container focus:ring-primary-container" />
                                        <span>Tiền mặt tại quầy</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Tiền mặt: ghi số hóa đơn giấy vào phiếu thu để Kế toán đối soát khi duyệt -->
                            <div v-show="w.feePaid && w.paymentMethod === 'cash'" class="space-y-1 rounded-xl border border-surface-container-highest bg-surface-container-low p-3.5">
                                <UiInput id="closing_paper_invoice_number" v-model="w.paperInvoiceNumber" name="paper_invoice_number" label="Số hóa đơn giấy thu tiền mặt (bắt buộc)" placeholder="Ví dụ: HĐG-0824/PTM-042..." class="font-code font-bold" />
                                <p class="text-xs text-on-surface-variant">Xuất hóa đơn giấy cho khách rồi ghi số vào đây. Phiếu thu tiền mặt được gửi Kế toán/Admin duyệt kèm số hóa đơn này.</p>
                            </div>
                        </div>

                        <UiInput id="closing_bill_notes" v-model="w.billNotes" name="bill_notes" label="Ghi chú trên Phiếu thu / Hóa đơn" placeholder="Ghi chú thêm về học viên, phụ huynh hoặc cam kết..." />
                    </div>

                    <!-- Cột phải: VietQR + nội dung chuyển tiền -->
                    <div class="shadow-xs flex flex-col items-center space-y-4 rounded-2xl border border-primary-container/30 bg-surface-container-lowest p-5 text-center lg:col-span-5">
                        <div v-show="needsBankAccount" class="flex w-full items-center justify-between border-b border-surface-container-highest pb-2">
                            <div class="flex items-center gap-1.5 text-xs font-black text-on-surface">
                                <span class="material-symbols-outlined text-lg text-primary-container">qr_code_scanner</span>
                                <span>MÃ VIETQR CHUYỂN KHOẢN</span>
                            </div>
                            <UiBadge color="success" pill :dot="false" class="font-bold">Chuẩn NAPAS 247</UiBadge>
                        </div>

                        <div v-show="needsBankAccount" class="group relative rounded-2xl border-2 border-primary-container/20 bg-surface-container-lowest p-2.5 shadow-md">
                            <img v-if="vietQrUrl" :src="vietQrUrl" alt="Mã VietQR Chuyển khoản" class="h-48 w-48 rounded-lg object-contain sm:h-52 sm:w-52" loading="lazy" />
                            <div v-else class="flex h-48 w-48 items-center justify-center rounded-lg text-center text-xs text-on-surface-subtle sm:h-52 sm:w-52">Chưa đủ thông tin để tạo mã QR</div>
                        </div>

                        <div v-show="needsBankAccount" class="w-full space-y-2 rounded-xl border border-surface-container-highest bg-surface-container-low p-3 text-left text-xs">
                            <div class="flex items-center justify-between">
                                <span class="text-on-surface-variant">Ngân hàng:</span>
                                <span class="max-w-[170px] truncate text-right font-bold text-on-surface">{{ selectedBank.bank_name }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-on-surface-variant">Số tài khoản:</span>
                                <div class="flex items-center gap-1">
                                    <span class="font-mono font-black text-on-surface">{{ selectedBank.account_number }}</span>
                                    <button type="button" class="rounded p-0.5 text-on-surface-variant transition hover:bg-surface-container-high hover:text-on-surface" title="Sao chép STK" aria-label="Sao chép STK" @click="copyText(cleanAccountNumber, 'acc')">
                                        <span class="material-symbols-outlined text-sm">{{ w.copiedField === 'acc' ? 'check' : 'content_copy' }}</span>
                                    </button>
                                </div>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-on-surface-variant">Chủ tài khoản:</span>
                                <span class="max-w-[170px] truncate text-right text-xs font-bold uppercase text-on-surface">{{ selectedBank.account_holder }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-on-surface-variant">Số tiền QR:</span>
                                <span class="font-mono text-xs font-black text-primary-container">{{ vnd(effectiveTransferAmount) }}</span>
                            </div>

                            <!-- Nội dung chuyển khoản: tên học sinh + mã học sinh + lớp -->
                            <div class="space-y-1 border-t border-surface-container-highest pt-1.5">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-on-surface-variant">Nội dung CK (Cấu trúc chuẩn):</span>
                                    <button type="button" class="inline-flex items-center gap-0.5 text-xs font-bold text-primary-container hover:text-primary" title="Sao chép nội dung CK" @click="copyText(transferMemo, 'memo')">
                                        <span class="material-symbols-outlined text-sm">{{ w.copiedField === 'memo' ? 'check' : 'content_copy' }}</span>
                                        <span>{{ w.copiedField === 'memo' ? 'Đã chép' : 'Chép' }}</span>
                                    </button>
                                </div>
                                <div class="select-all break-all rounded-lg border border-primary-container/30 bg-primary-container/10 p-2 text-left font-mono text-xs font-bold text-primary">{{ transferMemo }}</div>
                                <p class="text-xs text-on-surface-subtle">Tên học sinh + mã học sinh + lớp (mã học viên được cấp sẵn, giữ nguyên khi chốt).</p>
                            </div>
                        </div>

                        <div class="flex w-full items-center gap-2">
                            <UiButton v-show="needsBankAccount" variant="secondary" size="sm" icon="download" class="flex-1 font-bold" @click="downloadVietQr">Tải mã QR</UiButton>
                            <div v-show="!needsBankAccount" class="flex-1 rounded-xl border border-secondary/30 bg-secondary/10 px-3 py-2 text-xs font-bold text-secondary">Không phát sinh VietQR cho phương thức này</div>
                            <UiButton size="sm" icon="print" class="flex-1 font-bold" @click="billOpen = true">Xem &amp; In Bill</UiButton>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-surface-container-highest pt-4">
                    <UiButton variant="secondary" @click="step = 3">Quay lại</UiButton>
                    <UiButton type="submit" variant="success" icon="check_circle" :disabled="!canSubmit">
                        <span>Hoàn tất Chốt Deal, Xếp Lớp &amp; Xuất Phiếu Thu</span>
                    </UiButton>
                </div>
            </div>
        </UiForm>

        <!-- Tạo mới ưu đãi tại chỗ -->
        <UiModal :show="promoOpen" title="Tạo Mới Chương Trình Ưu Đãi / Voucher" max-width="md" @close="promoOpen = false">
            <div class="space-y-3.5">
                <UiInput id="promo_name" v-model="newPromo.name" label="Tên chương trình ưu đãi" required placeholder="Voucher khai giảng / Ưu đãi bạn mới" class="font-bold" :error="promoError('name')" />
                <div class="grid grid-cols-2 gap-3">
                    <UiSelect id="promo_type" v-model="newPromo.type" label="Loại giảm giá" class="font-semibold" :error="promoError('type')" :options="[{ value: 'fixed', label: 'Số tiền cố định (VNĐ)' }, { value: 'percent', label: 'Phần trăm (%)' }]" />
                    <UiInput id="promo_value" type="number" label="Giá trị" required placeholder="1000000 hoặc 10" class="font-mono font-bold" :model-value="newPromo.value" :error="promoError('value')" @update:model-value="newPromo.value = toNumber($event)" />
                </div>
                <UiTextarea id="promo_description" v-model="newPromo.description" label="Mô tả / Điều kiện áp dụng" rows="2" placeholder="Áp dụng cho học viên đăng ký sớm..." :error="promoError('description')" />
                <div class="grid grid-cols-2 gap-3">
                    <UiSelect v-model="newPromo.branch_id" :options="branches" placeholder="Mọi cơ sở" aria-label="Cơ sở áp dụng" />
                    <UiSelect v-model="newPromo.course_id" :options="courses.map((c) => ({ value: c.id, label: c.name }))" placeholder="Mọi khóa học" aria-label="Khóa học áp dụng" />
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <UiInput v-model="newPromo.starts_at" type="datetime-local" title="Bắt đầu" aria-label="Bắt đầu" />
                    <UiInput v-model="newPromo.ends_at" type="datetime-local" title="Kết thúc" aria-label="Kết thúc" />
                    <UiInput v-model="newPromo.usage_limit" type="number" min="1" placeholder="Lượt dùng" aria-label="Lượt dùng" />
                </div>
                <UiErrors :messages="['starts_at', 'ends_at', 'usage_limit', 'branch_id', 'course_id', 'max_discount_amount'].map(promoError).filter(Boolean)" />
            </div>
            <template #footer>
                <UiButton variant="secondary" @click="promoOpen = false">Hủy</UiButton>
                <UiButton :disabled="promoSaving" @click="saveNewPromotion">Lưu &amp; Áp Dụng Ngay</UiButton>
            </template>
        </UiModal>

        <!-- Xem trước và in Thông báo nộp học phí -->
        <UiModal :show="billOpen" title="Xem trước Thông Báo Nộp Học Phí (Chuẩn A4)" max-width="4xl" @close="billOpen = false">
            <ClosingBillPreview :bill="bill" :center="center" :dates="billDates" />
            <template #footer>
                <UiButton variant="secondary" @click="billOpen = false">Đóng</UiButton>
                <UiButton size="sm" icon="print" class="font-bold" @click="printBill">In Ngay</UiButton>
            </template>
        </UiModal>
    </div>
</template>
