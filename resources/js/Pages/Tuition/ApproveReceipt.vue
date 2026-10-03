<script setup>
/**
 * Duyệt phiếu thu học phí (mockup duyet-phieu-thu-hoc-phi): thống kê, bộ lọc, hàng đợi phiếu thu.
 * Bấm dòng → chi tiết mở trong hộp thoại (?selected_id=; đóng thì bỏ query). Duyệt / Từ chối / Phóng to minh chứng: hộp thoại con.
 * Tự làm mới 90 giây/lần, trừ khi đang mở hộp thoại hoặc đang gõ.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { anyModalOpen } from '@/Components/ui/modalStack';
import { compactQuery, urlWith } from '@/lib/url';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Duyệt phiếu thu học phí', hideErrors: true } });

const props = defineProps({
    filters: { type: Object, required: true },
    branches: { type: Array, default: () => [] },
    pendingCount: { type: Number, default: 0 },
    pendingTotal: { type: Number, default: 0 },
    approvedTodayCount: { type: Number, default: 0 },
    rejectedTodayCount: { type: Number, default: 0 },
    receipts: { type: Array, default: () => [] },
    selected: { type: Object, default: null },
    sepayWarnings: { type: Array, default: () => [] },
    reconciliation: { type: Object, default: null },
});

const page = usePage();
const errorList = computed(() => Object.values(page.props.errors ?? {}).flat());

const branchOptions = computed(() => [{ value: 'all', label: 'Tất cả Cơ sở' }, ...props.branches]);
const methodOptions = [
    { value: 'all', label: 'Hình thức: Tất cả' },
    { value: 'transfer', label: 'Chuyển khoản' },
    { value: 'cash', label: 'Tiền mặt' },
];
const tabUrl = (status) => urlWith({ status, selected_id: null });
const detailUrl = (id) => urlWith({ selected_id: id });
const dismissUrl = computed(() => urlWith({ selected_id: null }));

function submitFilters(event) {
    const data = Object.fromEntries(new FormData(event.target ?? event));
    router.get(route('tuition.receipts.approve'), compactQuery(data), { preserveScroll: true });
}
const autoSubmit = (event) => event.target.form && submitFilters({ target: event.target.form });

function openDetail(rc, event) {
    if (event.target.closest('a, button, input, select, textarea, label')) return;
    router.get(detailUrl(rc.id), {}, { preserveScroll: true, preserveState: true });
}

// Hộp thoại chi tiết: mở theo selected_id; đóng → tải lại trang không có selected_id (giữ cuộn, bộ lọc).
const detailOpen = ref(!!props.selected);
watch(
    () => props.selected?.id,
    (id) => (detailOpen.value = !!id),
);
function closeDetail() {
    detailOpen.value = false;
    router.get(dismissUrl.value, {}, { preserveScroll: true, preserveState: true, replace: true });
}

const approveOpen = ref(false);
const rejectOpen = ref(false);
const zoomOpen = ref(false);
const onRejectError = (errors) => {
    if (!errors.rejection_reason) rejectOpen.value = false;
};

// Tự làm mới 90 giây/lần, trừ khi đang mở hộp thoại hoặc đang gõ.
let timer = null;
onMounted(() => {
    timer = setInterval(() => {
        if (!anyModalOpen() && document.visibilityState === 'visible' && !document.querySelector('input:focus, textarea:focus')) {
            router.reload({ preserveScroll: true });
        }
    }, 90000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <UiPageHeader title="Duyệt phiếu thu học phí" description="Kiểm tra, đối chiếu chứng từ và phê duyệt các phiếu thu học phí & phụ thu từ nhân viên tư vấn/học vụ">
        <template #breadcrumbs>
            <Link :href="route('tuition.students')" class="hover:text-primary">Học phí &amp; Hóa đơn</Link>
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
            <span>Duyệt phiếu thu</span>
        </template>
    </UiPageHeader>

    <UiAlert v-if="!selected && errorList.length" type="error" title="Vui lòng kiểm tra lại thông tin" class="mb-4">
        <ul class="list-inside list-disc space-y-0.5">
            <li v-for="(message, i) in errorList" :key="i">{{ message }}</li>
        </ul>
    </UiAlert>

    <div class="mx-auto max-w-[1520px] space-y-5">
        <!-- Thống kê & bộ lọc -->
        <header class="space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm">
            <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                <div>
                    <h2 class="flex items-center gap-3 text-lg font-bold tracking-tight text-on-surface">
                        <span>Hàng đợi duyệt</span>
                        <UiBadge color="warning" pill>{{ pendingCount }} phiếu chờ xử lý</UiBadge>
                    </h2>
                    <p class="mt-1 text-xs text-on-surface-variant">Kiểm tra, đối chiếu chứng từ và phê duyệt các phiếu thu học phí &amp; phụ thu từ nhân viên tư vấn/học vụ</p>
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <UiStatCard label="Chờ duyệt" :value="pendingCount + ' phiếu'" tone="warning" icon="schedule" :hint="'(' + formatMoney(pendingTotal) + ')'" />
                    <UiStatCard label="Đã duyệt hôm nay" :value="approvedTodayCount + ' phiếu'" tone="success" icon="check_circle" />
                    <UiStatCard label="Đã từ chối hôm nay" :value="rejectedTodayCount + ' phiếu'" tone="error" icon="cancel" />
                </div>
            </div>

            <form method="GET" :action="route('tuition.receipts.approve')" class="flex flex-wrap items-center justify-between gap-3 border-t border-surface-container-highest pt-4" @submit.prevent="submitFilters">
                <div class="flex w-full flex-wrap items-center gap-2.5 lg:w-auto">
                    <div class="min-w-[160px]">
                        <UiSelect name="branch_id" aria-label="Cơ sở" class="cursor-pointer" :options="branchOptions" :value="filters.branch_id" @change="autoSubmit" />
                    </div>
                    <div class="min-w-[150px]">
                        <UiSelect name="payment_method" aria-label="Hình thức" class="cursor-pointer" :options="methodOptions" :value="filters.payment_method" @change="autoSubmit" />
                    </div>
                    <UiTabs>
                        <UiTab :href="tabUrl('pending')" :active="filters.status === 'pending'">Chờ duyệt ({{ pendingCount }})</UiTab>
                        <UiTab :href="tabUrl('approved')" :active="filters.status_param === 'approved'">Đã duyệt</UiTab>
                        <UiTab :href="tabUrl('rejected')" :active="filters.status_param === 'rejected'">Từ chối</UiTab>
                        <UiTab :href="tabUrl('all')" :active="filters.status === 'all'">Tất cả</UiTab>
                    </UiTabs>
                </div>
                <div class="relative w-full lg:w-80">
                    <UiInput name="q" icon="search" :value="filters.q" placeholder="Tìm theo tên học viên, mã phiếu..." class="pr-8" />
                    <Link v-if="filters.q" :href="urlWith({ q: null, selected_id: null })" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-on-surface-subtle hover:text-on-surface-variant" aria-label="Xóa từ khóa">
                        <span class="material-symbols-outlined text-base">close</span>
                    </Link>
                </div>
            </form>
        </header>

        <!-- Hàng đợi phiếu thu: bấm dòng → chi tiết trong hộp thoại -->
        <UiDataTable min-width="960px" sticky="last">
            <template #header>
                <div class="flex items-center gap-2">
                    <h3 class="font-h3 text-h3 text-on-surface">Hàng đợi phiếu thu</h3>
                    <UiBadge color="warning" pill :dot="false" class="font-code">{{ receipts.length }} phiếu</UiBadge>
                </div>
                <span class="flex items-center gap-1 text-xs text-on-surface-subtle">
                    <span class="material-symbols-outlined text-xs" aria-hidden="true">autorenew</span>
                    Tự động làm mới (90 giây)
                </span>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Mã phiếu</th>
                        <th>Học viên</th>
                        <th>Lớp · Cơ sở</th>
                        <th>Hình thức</th>
                        <th class="text-right">Số tiền</th>
                        <th>Người lập · Gửi lúc</th>
                        <th>Trạng thái</th>
                        <th class="text-right"><span class="sr-only">Thao tác</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="rc in receipts" :key="rc.id" :class="['cursor-pointer', selected?.id === rc.id ? 'bg-primary-container/5' : '']" @click="openDetail(rc, $event)">
                        <td class="whitespace-nowrap">
                            <Link :href="detailUrl(rc.id)" preserve-scroll preserve-state class="font-code font-semibold text-on-surface hover:text-primary"><UiCode :value="rc.receipt_number" /></Link>
                            <span v-if="rc.has_proof" class="material-symbols-outlined align-middle text-sm text-on-surface-variant" title="Có minh chứng" aria-label="Có minh chứng">attach_file</span>
                            <span v-else-if="rc.paper_invoice_number" class="block text-xs text-on-surface-variant">Biên lai số {{ rc.paper_invoice_number }}</span>
                        </td>
                        <td class="min-w-[10rem]">
                            <div class="font-semibold">{{ rc.student_name }}</div>
                            <UiCode :value="rc.student_code" class="font-code text-xs text-on-surface-subtle" />
                        </td>
                        <td>
                            <div>{{ rc.class_name }}</div>
                            <div class="text-xs text-on-surface-subtle">{{ rc.branch_name }}</div>
                        </td>
                        <td class="whitespace-nowrap">
                            <UiBadge v-if="rc.payment_method === 'cash'" color="success" :dot="false">Tiền mặt</UiBadge>
                            <UiBadge v-else color="secondary" :dot="false">Chuyển khoản</UiBadge>
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <UiMoney :value="rc.amount" class="font-bold" />
                            <UiBadge v-if="rc.has_surcharge" color="warning" :dot="false">+ Phụ thu</UiBadge>
                        </td>
                        <td>
                            <div>{{ rc.creator_name ?? '—' }}</div>
                            <div class="whitespace-nowrap text-xs text-on-surface-subtle" :title="rc.created_at">{{ rc.created_ago }}</div>
                        </td>
                        <td class="whitespace-nowrap"><UiBadge :color="rc.status_color" :dot="false">{{ rc.status_label }}</UiBadge></td>
                        <td class="text-right">
                            <UiButton variant="secondary" size="sm" icon="visibility" :href="detailUrl(rc.id)" preserve-scroll preserve-state>Xem</UiButton>
                        </td>
                    </tr>
                    <tr v-if="!receipts.length">
                        <td colspan="8"><UiEmptyState icon="task" title="Không có phiếu thu nào theo bộ lọc đã chọn." /></td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </div>

    <template v-if="selected">
        <!-- Chi tiết phiếu thu -->
        <UiModal :show="detailOpen" :title="'Chi tiết phiếu thu ' + selected.receipt_number_short" max-width="4xl" @close="closeDetail">
            <div class="space-y-5" :data-receipt-detail="selected.id">
                <UiAlert v-if="errorList.length" type="error" title="Vui lòng kiểm tra lại thông tin" class="mb-4">
                    <ul class="list-inside list-disc space-y-0.5">
                        <li v-for="(message, i) in errorList" :key="i">{{ message }}</li>
                    </ul>
                </UiAlert>

                <UiAlert v-if="sepayWarnings.length" type="warning" title="Có thể trùng giao dịch SePay đã tự động gạch nợ">
                    <ul class="list-disc space-y-0.5 pl-md">
                        <li v-for="tx in sepayWarnings" :key="tx.sepay_id">
                            SePay #{{ tx.sepay_id }} — {{ formatMoney(tx.amount) }} ngày {{ tx.date }}
                            <template v-if="tx.receipt_number"> (phiếu <UiCode :value="tx.receipt_number" />) </template>
                        </li>
                    </ul>
                    <p class="mt-xs">Đối chiếu sao kê trước khi duyệt. Nếu đúng là khoản chuyển khác, tick xác nhận trong hộp thoại duyệt.</p>
                </UiAlert>

                <!-- Trạng thái, người lập, mẫu in -->
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <UiBadge v-if="selected.status === 'approved'" color="success" pill>Đã duyệt (HĐ: {{ selected.invoice_number ?? 'Auto' }})</UiBadge>
                            <UiBadge v-else-if="selected.status !== 'pending'" :color="selected.status_color" pill :dot="false">{{ selected.status_label }}</UiBadge>
                            <UiBadge v-else color="warning" pill>Chờ duyệt</UiBadge>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-on-surface-variant">
                            <span>Thời gian gửi: <strong>{{ selected.created_at ?? '—' }}</strong></span>
                            <span aria-hidden="true">•</span>
                            <span>Người lập: <strong>{{ selected.creator_name ?? 'Chưa cập nhật' }}</strong> ({{ selected.branch_name }})</span>
                        </div>
                    </div>
                    <UiButton v-if="selected.student_tuition_id" variant="secondary" size="sm" icon="print" :href="route('crm.tuition-bill', selected.student_tuition_id)" target="_blank">Xem trước mẫu in</UiButton>
                </div>

                <!-- 1. Học viên -->
                <div class="rounded-xl border border-surface-container-highest bg-surface-container-low/40 p-4 lg:p-5">
                    <div class="mb-3.5 flex items-center gap-2">
                        <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-container/10 text-xs font-bold text-primary">
                            <span class="material-symbols-outlined text-base">person</span>
                        </div>
                        <h3 class="text-sm font-bold uppercase tracking-wide text-on-surface">1. Thông tin học viên &amp; Phụ huynh</h3>
                    </div>
                    <div class="grid grid-cols-1 gap-4 text-xs md:grid-cols-3">
                        <div class="space-y-0.5 rounded-lg border border-surface-container-highest bg-surface-container-lowest p-3.5">
                            <span class="mb-0.5 block text-xs uppercase text-on-surface-subtle">Học viên</span>
                            <div class="text-sm font-bold text-on-surface">{{ selected.student_name ?? 'Học viên' }}</div>
                            <UiCode :value="selected.student_code" class="font-code text-xs font-semibold text-primary" />
                        </div>
                        <div class="space-y-0.5 rounded-lg border border-surface-container-highest bg-surface-container-lowest p-3.5">
                            <span class="mb-0.5 block text-xs uppercase text-on-surface-subtle">Lớp học hiện tại</span>
                            <div class="text-sm font-bold text-on-surface">{{ selected.class_name }}</div>
                            <span class="text-xs text-on-surface-variant">{{ selected.branch_name }}</span>
                        </div>
                        <div class="space-y-0.5 rounded-lg border border-surface-container-highest bg-surface-container-lowest p-3.5">
                            <span class="mb-0.5 block text-xs uppercase text-on-surface-subtle">Người nộp tiền (Phụ huynh)</span>
                            <div class="text-sm font-bold text-on-surface">{{ selected.payer_name }}</div>
                            <span class="font-code text-xs font-semibold text-on-surface-variant">{{ selected.payer_phone }}</span>
                        </div>
                    </div>
                </div>

                <!-- 2. Bảng kê -->
                <div class="rounded-xl border border-surface-container-highest p-4 lg:p-5">
                    <div class="mb-3.5 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-tertiary/10 text-xs font-bold text-tertiary">
                                <span class="material-symbols-outlined text-base">calculate</span>
                            </div>
                            <h3 class="text-sm font-bold uppercase tracking-wide text-on-surface">2. Bảng kê chi tiết các khoản thu</h3>
                        </div>
                        <span class="text-xs text-on-surface-variant">
                            Phương thức: <strong class="font-semibold text-secondary">{{ selected.payment_method === 'cash' ? 'Tiền mặt' : 'Chuyển khoản Ngân hàng' }}</strong>
                        </span>
                    </div>
                    <UiDataTable min-width="480px">
                        <table>
                            <thead>
                                <tr>
                                    <th>Khoản mục</th>
                                    <th>Nội dung diễn giải</th>
                                    <th class="text-right">Số tiền</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="font-semibold">
                                        Học phí
                                        <span v-if="selected.tuition_due_date" class="block text-xs font-normal text-on-surface-variant">(Hạn {{ selected.tuition_due_date }})</span>
                                    </td>
                                    <td class="text-on-surface-variant">{{ selected.fee_label ?? 'Không gắn khoản học phí (chỉ phụ thu)' }} (Đã miễn giảm {{ formatMoney(selected.discount_amount) }})</td>
                                    <td><UiMoney :value="selected.tuition_portion" suffix="đ" class="font-bold" /></td>
                                </tr>
                                <tr v-if="selected.surcharge_amount > 0" class="bg-warning-container/40">
                                    <td class="flex items-center gap-1.5 font-semibold text-on-warning-container">
                                        <span class="h-1.5 w-1.5 rounded-full bg-warning"></span>
                                        Phụ thu phát sinh
                                    </td>
                                    <td class="text-on-warning-container">{{ selected.surcharge_reason || '—' }}</td>
                                    <td class="text-right font-code font-bold text-on-warning-container">+ {{ formatMoney(selected.surcharge_amount) }}</td>
                                </tr>
                            </tbody>
                            <tfoot class="border-t border-primary-container/30 bg-primary-container/5">
                                <tr>
                                    <td class="text-xs font-bold" colspan="2">TỔNG SỐ TIỀN THỰC THU</td>
                                    <td class="text-right"><span class="font-code text-base font-bold text-primary">{{ formatMoney(selected.amount) }}</span></td>
                                </tr>
                            </tfoot>
                        </table>
                    </UiDataTable>

                    <div class="mt-4 grid grid-cols-1 gap-3 text-xs md:grid-cols-2">
                        <div class="space-y-1 rounded-lg border border-surface-container-highest bg-surface-container-low p-3.5">
                            <span class="block text-xs font-bold uppercase text-on-surface-variant">Trạng thái đối soát &amp; Hóa đơn VAT</span>
                            <div class="flex flex-wrap items-center gap-2">
                                <UiBadge :color="reconciliation?.tone ?? 'neutral'">{{ reconciliation?.label ?? '—' }}</UiBadge>
                                <UiBadge v-if="selected.is_vat_invoice" color="info" :dot="false">Yêu cầu hóa đơn đỏ (VAT)</UiBadge>
                                <UiBadge v-else color="neutral" :dot="false">Không yêu cầu hóa đơn đỏ</UiBadge>
                            </div>
                        </div>
                        <div class="space-y-1 rounded-lg border border-surface-container-highest bg-surface-container-low p-3.5">
                            <span class="block text-xs font-bold uppercase text-on-surface-variant">Ghi chú từ nhân viên tạo phiếu (CM)</span>
                            <p class="text-xs italic leading-relaxed text-on-surface-variant">{{ selected.notes ? '"' + selected.notes + '"' : 'Không có ghi chú.' }}</p>
                        </div>
                    </div>

                    <!-- Sách / hàng hóa xuất kho chi nhánh khi duyệt -->
                    <div v-if="selected.stock_out?.length" class="mt-4 space-y-2 rounded-lg border border-surface-container-highest p-3.5 text-xs" data-testid="stock-out-preview">
                        <span class="flex items-center gap-1.5 font-bold uppercase text-on-surface-variant">
                            <span class="material-symbols-outlined text-base text-primary" aria-hidden="true">inventory_2</span>Duyệt phiếu sẽ xuất kho {{ selected.branch_name }}
                        </span>
                        <div v-for="line in selected.stock_out" :key="line.item_id + line.source" class="flex flex-wrap items-center justify-between gap-2 border-b border-dashed border-surface-container-highest py-1 last:border-0">
                            <span class="text-on-surface">
                                {{ line.name }} <strong class="font-code">×{{ line.quantity }}</strong>
                                <span class="text-on-surface-subtle">· {{ line.source === 'contract' ? 'sách trong hợp đồng' : 'phụ thu' }}</span>
                            </span>
                            <span :class="['font-code', line.stock_after < 0 ? 'font-bold text-error' : 'text-on-surface-variant']">
                                Tồn {{ line.stock }} → {{ line.stock_after }}<template v-if="line.stock_after < 0"> (âm kho)</template>
                            </span>
                        </div>
                        <p v-if="selected.stock_out.some((l) => l.stock_after < 0)" class="text-error">Kho chi nhánh không đủ hàng: vẫn duyệt được, nhắc chi nhánh nhập kho bổ sung hoặc kiểm kê lại.</p>
                    </div>
                </div>

                <!-- 3. Minh chứng -->
                <div class="rounded-xl border border-surface-container-highest p-4 lg:p-5">
                    <div class="mb-3.5 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-secondary/10 text-xs font-bold text-secondary">
                                <span class="material-symbols-outlined text-base">receipt</span>
                            </div>
                            <div>
                                <h3 class="text-sm font-bold uppercase tracking-wide text-on-surface">3. Minh chứng chuyển khoản (UNC) / Biên lai</h3>
                                <p class="text-xs text-on-surface-subtle">Đối chiếu mã giao dịch và số tài khoản nhận</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5 rounded-lg border border-surface-container-highest bg-surface-container p-1">
                            <UiButton variant="ghost" size="sm" icon="zoom_in" title="Phóng to" aria-label="Phóng to" @click="zoomOpen = true" />
                            <UiButton v-if="selected.proof_image" variant="ghost" size="sm" icon="download" :href="selected.proof_image" target="_blank" download title="Tải ảnh gốc" aria-label="Tải ảnh gốc" />
                        </div>
                    </div>

                    <UiAlert v-if="selected.issued_paper_invoice" type="info" class="mb-3" title="Đối chiếu số hóa đơn giấy">
                        Hệ thống đã cấp số <strong class="font-code">{{ selected.issued_paper_invoice }}</strong> cho phiếu tiền mặt này. Số ghi trên ảnh hóa đơn giấy phải trùng số này;
                        sai số thì từ chối và để người lập tạo yêu cầu hủy hóa đơn.
                    </UiAlert>
                    <div class="flex flex-col items-center gap-5 rounded-xl border border-inverse-surface bg-inverse-surface p-5 md:flex-row">
                        <div class="group/img relative flex h-52 w-full shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded-lg border border-white/10 bg-white/5 md:w-64" @click="zoomOpen = true">
                            <img v-if="selected.proof_image" :src="selected.proof_image" alt="Minh chứng" class="h-full w-full object-contain" />
                            <div v-else class="flex h-full w-full flex-col items-center justify-center gap-2 text-xs text-inverse-on-surface/70">
                                <span class="material-symbols-outlined text-3xl text-inverse-on-surface/60">image_not_supported</span>
                                <span class="font-bold text-inverse-on-surface">Chưa có minh chứng</span>
                                <span class="px-3 text-center text-xs text-inverse-on-surface/60">Người lập chưa đính kèm ảnh chuyển khoản / biên lai.</span>
                            </div>
                            <div class="absolute inset-0 flex items-center justify-center gap-1 bg-black/40 text-xs font-bold text-white opacity-0 transition group-hover/img:opacity-100">
                                <span class="material-symbols-outlined text-base">zoom_in</span> Bấm để phóng to
                            </div>
                        </div>

                        <div class="w-full flex-1 space-y-2.5 text-xs">
                            <div class="space-y-2 rounded-lg border border-white/10 bg-white/5 p-3.5">
                                <div class="flex items-center justify-between">
                                    <span class="text-inverse-on-surface/70">Mã tham chiếu:</span>
                                    <UiCode :value="selected.reference" class="font-code font-bold text-warning-container" />
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-inverse-on-surface/70">Tài khoản thụ hưởng:</span>
                                    <span class="font-medium text-inverse-on-surface">{{ selected.beneficiary ?? '—' }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-inverse-on-surface/70">Thời gian giao dịch:</span>
                                    <span class="font-code text-inverse-on-surface">{{ selected.payment_date ?? '—' }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-inverse-on-surface/70">Trạng thái đối soát:</span>
                                    <span :class="['flex items-center gap-1 text-xs font-bold', reconciliation?.tone === 'success' ? 'text-tertiary-fixed-dim' : reconciliation?.tone === 'error' ? 'text-error-container' : 'text-warning-container']">
                                        <span class="material-symbols-outlined text-sm">{{ reconciliation?.tone === 'success' ? 'verified' : 'pending' }}</span>
                                        {{ reconciliation?.label ?? 'Cần đối chiếu thủ công' }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/20 p-3 text-xs text-warning-container">
                                <span class="material-symbols-outlined mt-0.5 shrink-0 text-base text-warning-container">info</span>
                                <span>
                                    {{ reconciliation?.detail ?? '' }}
                                    <template v-if="selected.proof_image">
                                        Vui lòng đối chiếu minh chứng với sao kê ngân hàng / quỹ tiền mặt: số tiền <strong>{{ formatMoney(selected.amount) }}</strong> trước khi duyệt.
                                    </template>
                                    <template v-else>
                                        Chưa có minh chứng. Chỉ duyệt khi đã xác nhận nhận đủ <strong>{{ formatMoney(selected.amount) }}</strong> trên sao kê / quỹ tiền mặt.
                                    </template>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <template #footer>
                <template v-if="selected.status === 'pending'">
                    <p class="mr-auto hidden items-center gap-1.5 self-center text-xs text-on-surface-variant md:flex">
                        <span class="material-symbols-outlined text-base text-on-surface-subtle" aria-hidden="true">info</span>
                        Duyệt sẽ hạch toán công nợ và cấp số hóa đơn.
                    </p>
                    <UiButton v-if="can('tuition.reject')" variant="danger-text" icon="close" @click="rejectOpen = true">Từ chối phiếu thu</UiButton>
                    <UiButton v-if="can('tuition.approve')" icon="check" @click="approveOpen = true">Duyệt phiếu thu ({{ formatMoney(selected.amount) }})</UiButton>
                    <UiBadge v-else color="warning" pill>Chờ Kế toán duyệt</UiBadge>
                </template>
                <UiBadge v-else-if="selected.status === 'approved'" color="success" pill>Đã duyệt bởi {{ selected.approver_name ?? 'Admin' }}</UiBadge>
                <template v-else-if="selected.can_resubmit">
                    <UiBadge :color="selected.status_color" :dot="false">{{ selected.status_label }}</UiBadge>
                    <UiButton variant="secondary" icon="edit" :href="route('tuition.receipts.edit', selected.id)">Sửa phiếu</UiButton>
                    <!-- Người lập gửi duyệt lại (giữ nguyên số liệu; sửa chi tiết qua màn Sửa phiếu) -->
                    <UiForm :action="route('tuition.receipts.update', selected.id)" method="put">
                        <input type="hidden" name="amount" :value="selected.amount" />
                        <input type="hidden" name="tuition_amount" :value="selected.tuition_portion" />
                        <input type="hidden" name="discount_amount" :value="selected.discount_amount" />
                        <input type="hidden" name="surcharge_amount" :value="selected.surcharge_amount" />
                        <input type="hidden" name="surcharge_reason" :value="selected.surcharge_reason ?? ''" />
                        <input type="hidden" name="payment_method" :value="selected.payment_method" />
                        <input type="hidden" name="submit_action" value="submit" />
                        <UiButton type="submit" icon="send">Gửi duyệt lại</UiButton>
                    </UiForm>
                </template>
                <UiBadge v-else :color="selected.status_color" :dot="false">{{ selected.status_label }}</UiBadge>
            </template>
        </UiModal>

        <!-- Xác nhận duyệt -->
        <UiModal :show="approveOpen" title="Xác nhận duyệt phiếu thu" max-width="md" @close="approveOpen = false">
            <p class="mb-3 font-code text-xs text-on-surface-variant">Mã: {{ selected.receipt_number }}</p>
            <UiForm id="approve-receipt-form" :action="route('tuition.receipts.approve.action', selected.id)" method="post" class="space-y-3 text-xs leading-relaxed text-on-surface-variant" @finish="approveOpen = false">
                <p>
                    Bạn có chắc chắn muốn duyệt phiếu thu <strong class="font-code text-on-surface">{{ selected.receipt_number }}</strong> với tổng số tiền
                    <strong class="font-code text-sm font-bold text-primary">{{ formatMoney(selected.amount) }}</strong> cho học viên <strong class="text-on-surface">{{ selected.student_name }}</strong>?
                </p>
                <label v-if="sepayWarnings.length" class="flex w-full items-start gap-2 rounded-xl border border-warning/30 bg-warning-container p-2.5 text-xs text-on-warning-container">
                    <input type="checkbox" name="confirm_not_duplicate" value="1" class="mt-0.5 rounded text-primary focus:ring-primary-container" />
                    <span>Xác nhận không trùng giao dịch SePay: tôi đã đối chiếu sao kê, đây là một khoản chuyển khác.</span>
                </label>
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="approveOpen = false">Hủy bỏ</UiButton>
                <UiButton type="submit" icon="check" form="approve-receipt-form">Xác nhận phê duyệt</UiButton>
            </template>
        </UiModal>

        <!-- Từ chối (nhập lý do) -->
        <UiModal :show="rejectOpen" title="Từ chối duyệt phiếu thu" max-width="md" @close="rejectOpen = false">
            <p class="mb-3 font-code text-xs text-on-surface-variant">Phiếu: {{ selected.receipt_number }} • Học viên: {{ selected.student_name }}</p>
            <UiForm id="reject-receipt-form" :action="route('tuition.receipts.reject.action', selected.id)" method="post" class="space-y-3" @success="rejectOpen = false" @error="onRejectError">
                <UiTextarea name="rejection_reason" label="Lý do từ chối duyệt (Bắt buộc)" required rows="3" placeholder="Ví dụ: Ảnh chụp ủy nhiệm chi bị mất góc mã tham chiếu, số tiền chuyển khoản không khớp với phiếu..." />
                <UiAlert type="error">
                    <span class="text-xs leading-relaxed"><strong>Thông báo hệ thống:</strong> Phiếu thu này sẽ chuyển về trạng thái <strong>"Bị từ chối"</strong> kèm thông báo lý do từ chối gửi trả lại nhân viên phụ trách <strong>{{ selected.creator_name ?? 'CM' }}</strong> để bổ sung minh chứng.</span>
                </UiAlert>
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="rejectOpen = false">Quay lại</UiButton>
                <UiButton variant="danger" type="submit" icon="close" form="reject-receipt-form">Xác nhận từ chối</UiButton>
            </template>
        </UiModal>

        <!-- Phóng to minh chứng -->
        <UiModal :show="zoomOpen" :title="'Minh chứng đối soát: ' + selected.receipt_number" max-width="3xl" @close="zoomOpen = false">
            <div class="flex min-h-[300px] items-center justify-center">
                <img v-if="selected.proof_image" :src="selected.proof_image" alt="Minh chứng" class="max-h-[70vh] rounded-lg object-contain" />
                <UiEmptyState v-else icon="receipt_long" title="Chưa có minh chứng" />
            </div>
        </UiModal>
    </template>
</template>
