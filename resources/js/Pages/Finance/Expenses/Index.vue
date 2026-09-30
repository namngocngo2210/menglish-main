<script setup>
/**
 * Sổ khoản chi vận hành (Epic 13): 4 thẻ KPI, bộ lọc kỳ tháng / chi nhánh, dòng chi lương tự động (khóa, không sửa/xóa)
 * + các khoản chi tự nhập. Thêm / Sửa trong hộp thoại dựng sẵn (lưu ngay, không cần duyệt).
 */
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { formatNumber } from '@/lib/format';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Sổ khoản chi vận hành' } });

const props = defineProps({
    branchScoped: { type: Boolean, default: false },
    month: { type: String, required: true },
    branchId: { type: String, default: 'all' },
    search: { type: String, default: '' },
    branches: { type: Array, default: () => [] },
    manualExpenses: { type: Array, default: () => [] },
    autoSalaryRow: { type: Object, default: null },
    grandTotalExpense: { type: Number, default: 0 },
    autoSalaryAmount: { type: Number, default: 0 },
    autoSalaryStaffCount: { type: Number, default: 0 },
    totalManualExpense: { type: Number, default: 0 },
    manualExpensesCount: { type: Number, default: 0 },
    totalItemsCount: { type: Number, default: 0 },
    prevMonthLabel: { type: String, required: true },
    percentDiff: { type: Number, default: 0 },
    isDecreased: { type: Boolean, default: false },
    totalTransfer: { type: Number, default: 0 },
    totalCash: { type: Number, default: 0 },
    transferPercent: { type: Number, default: 0 },
    cashPercent: { type: Number, default: 0 },
    monthOptions: { type: Array, default: () => [] },
    today: { type: String, required: true },
});

const page = usePage();
const userName = computed(() => page.props.shell?.user?.name ?? 'Admin');
const vnd = (value) => formatNumber(value, 0);
const exportUrl = computed(() => route('finance.expenses.export', { month: props.month, branch_id: props.branchId, search: props.search }));
const branchFilterOptions = computed(() => [...(props.branchScoped ? [] : [{ value: 'all', label: 'Tất cả chi nhánh' }]), ...props.branches.map((b) => ({ value: String(b.id), label: b.name }))]);
const branchOptions = computed(() => [{ value: '', label: '-- Chọn chi nhánh cơ sở --', disabled: true }, ...props.branches.map((b) => ({ value: String(b.id), label: b.name }))]);
const paymentOptions = [
    { value: 'chuyen_khoan', label: 'Chuyển khoản' },
    { value: 'tien_mat', label: 'Tiền mặt' },
];
const categoryOptions = [
    { value: '', label: 'Tự động theo nội dung' },
    { value: 'mat_bang_tien_ich', label: 'Mặt bằng & Tiện ích (Thuê nhà, điện, nước, internet...)' },
    { value: 'giao_trinh_van_hanh', label: 'In ấn & Vận hành lớp (Giáo trình, VPP, điều hòa, nước uống...)' },
    { value: 'khac', label: 'Chi phí khác' },
];
const autoSubmit = (event) => event.target.form?.requestSubmit();

// Hộp thoại Thêm / Sửa: dựng lại form mỗi lần mở (key) để lấy giá trị ban đầu mới.
const open = ref(false);
const formKey = ref(0);
const editing = ref(null);
const form = computed(() => {
    const exp = editing.value;
    return {
        action: exp ? route('finance.expenses.update', exp.id) : route('finance.expenses.store'),
        method: exp ? 'put' : 'post',
        title: exp ? 'Chỉnh sửa khoản chi vận hành' : 'Thêm khoản chi vận hành mới',
        submit: exp ? 'Cập nhật khoản chi' : 'Lưu khoản chi',
        values: exp
            ? { expense_date: exp.expense_date, title: exp.title, amount: exp.amount ? Math.trunc(exp.amount) : '', payment_method: exp.payment_method || 'chuyen_khoan', branch_id: exp.branch_id ? String(exp.branch_id) : '', category: exp.category || '', notes: exp.notes || '' }
            : { expense_date: props.today, title: '', amount: '', payment_method: 'chuyen_khoan', branch_id: '', category: '', notes: '' },
    };
});
function openModal(exp = null) {
    editing.value = exp;
    formKey.value++;
    open.value = true;
}
</script>

<template>
    <UiPageHeader title="Sổ khoản chi vận hành" icon="payments" description="Quản lý và ghi nhận các khoản chi phí hành chính, cơ sở vật chất và chi lương tự động">
        <template #actions>
            <div class="hidden items-center gap-2 rounded-xl border border-surface-container-highest bg-surface-container-low px-3.5 py-2 text-xs font-medium text-on-surface-variant sm:flex">
                <span class="h-2 w-2 animate-pulse rounded-full bg-tertiary"></span>
                Quyền thao tác: <span class="font-semibold text-on-surface">Quản trị nhân sự &amp; Tài chính</span>
            </div>
            <UiButton variant="secondary" icon="download" :href="exportUrl" native title="Xuất dữ liệu Excel (CSV)">Xuất Excel</UiButton>
            <UiButton icon="add_circle" @click="openModal()">Thêm khoản chi mới</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <!-- 4 thẻ KPI -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="relative overflow-hidden rounded-2xl border border-surface-container-highest/80 bg-surface-container-lowest p-5 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-on-surface-subtle">Tổng chi kỳ này</span>
                    <span class="material-symbols-outlined rounded-lg bg-primary-container/10 p-2 text-[20px] text-primary-container">account_balance_wallet</span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-extrabold tracking-tight text-on-surface">{{ vnd(grandTotalExpense) }} <span class="text-sm font-semibold text-on-surface-subtle">VNĐ</span></div>
                    <div class="mt-1 flex items-center gap-1.5 text-xs text-on-surface-variant">
                        <template v-if="percentDiff != 0">
                            <span :class="['flex items-center font-semibold', isDecreased ? 'text-tertiary' : 'text-error']">
                                <span class="material-symbols-outlined text-[14px]">{{ isDecreased ? 'arrow_downward' : 'arrow_upward' }}</span>
                                {{ Math.abs(percentDiff) }}%
                            </span>
                            <span>so với tháng {{ prevMonthLabel }}</span>
                        </template>
                        <span v-else class="text-on-surface-subtle">Tương đương tháng trước</span>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-surface-container-highest/80 bg-surface-container-lowest p-5 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-on-surface-subtle">Chi lương tự động (bảng lương)</span>
                    <span class="material-symbols-outlined rounded-lg bg-secondary/10 p-2 text-[20px] text-secondary">badge</span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-extrabold tracking-tight text-secondary">{{ vnd(autoSalaryAmount) }} <span class="text-sm font-semibold text-on-surface-subtle">VNĐ</span></div>
                    <div class="mt-1 flex items-center gap-1.5 text-xs text-on-surface-variant">
                        <span v-if="autoSalaryAmount > 0" class="inline-flex items-center gap-1 font-medium text-secondary">
                            <span class="material-symbols-outlined text-[14px]">sync_alt</span> Đã chốt &amp; Đã trả ({{ autoSalaryStaffCount }} nhân sự)
                        </span>
                        <span v-else class="italic text-on-surface-subtle">Chưa có bảng lương chốt</span>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-surface-container-highest/80 bg-surface-container-lowest p-5 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-on-surface-subtle">Chi phí vận hành tự nhập</span>
                    <span class="material-symbols-outlined rounded-lg bg-tertiary/10 p-2 text-[20px] text-tertiary">shopping_bag</span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-extrabold tracking-tight text-on-surface">{{ vnd(totalManualExpense) }} <span class="text-sm font-semibold text-on-surface-subtle">VNĐ</span></div>
                    <div class="mt-1 flex items-center gap-1.5 text-xs text-on-surface-variant">
                        <span>Tổng số <strong>{{ manualExpensesCount }}</strong> phiếu chi tự nhập</span>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-surface-container-highest/80 bg-surface-container-lowest p-5 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-on-surface-subtle">Cơ cấu hình thức</span>
                    <span class="material-symbols-outlined rounded-lg bg-info-container p-2 text-[20px] text-info">credit_card</span>
                </div>
                <div class="mt-3">
                    <div class="flex items-center justify-between text-xs font-medium text-on-surface-variant">
                        <span>Chuyển khoản ({{ transferPercent }}%)</span>
                        <span class="font-bold text-on-surface">{{ formatMoney(totalTransfer) }}</span>
                    </div>
                    <div class="mt-1.5 flex h-2 w-full overflow-hidden rounded-full bg-surface-container">
                        <div class="h-full rounded-full bg-info transition-all" :style="{ width: transferPercent + '%' }"></div>
                        <div class="h-full rounded-full bg-warning/70 transition-all" :style="{ width: cashPercent + '%' }"></div>
                    </div>
                    <div class="mt-1 flex items-center justify-between text-xs text-on-surface-subtle">
                        <span>Tiền mặt: {{ formatMoney(totalCash) }} ({{ cashPercent }}%)</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bộ lọc & tìm kiếm -->
        <UiFilterBar :action="route('finance.expenses.index')" placeholder="Tìm theo nội dung, người lập..." class="!mb-0">
            <template #quick>
                <p class="flex items-center gap-xs font-body-small text-body-small text-on-surface-variant">
                    <span class="material-symbols-outlined text-[16px] text-on-surface-subtle" aria-hidden="true">info</span>
                    Dòng lương tự động ẩn khi kỳ chưa có bảng lương chốt/trả
                </p>
            </template>
            <UiSelect name="month" label="Kỳ tháng" :options="monthOptions" :value="month" @change="autoSubmit" />
            <UiSelect name="branch_id" label="Chi nhánh" :options="branchFilterOptions" :value="branchId" @change="autoSubmit" />
        </UiFilterBar>

        <!-- Bảng các khoản chi -->
        <UiDataTable>
            <template #header>
                <div class="flex w-full items-center justify-between">
                    <div class="flex items-center gap-2">
                        <h2 class="font-bold text-on-surface">Danh sách các khoản chi</h2>
                        <UiBadge pill :dot="false">{{ totalItemsCount }} khoản chi</UiBadge>
                    </div>
                    <div class="flex items-center gap-1.5 text-xs text-on-surface-variant">
                        <span class="h-2.5 w-2.5 rounded-xs border border-secondary/30 bg-secondary/10"></span> Dòng tự động tổng hợp từ hệ thống Lương
                    </div>
                </div>
            </template>

            <table>
                <thead>
                    <tr>
                        <th>Ngày chi</th>
                        <th>Nội dung khoản chi</th>
                        <th class="text-right">Số tiền (VNĐ)</th>
                        <th class="text-center">Hình thức</th>
                        <th>Chi nhánh</th>
                        <th>Ghi chú &amp; Chứng từ</th>
                        <th class="w-28 text-center">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Dòng chi lương tự động: khác biệt visual, không có sửa/xóa -->
                    <tr v-if="autoSalaryRow" class="border-l-4 border-l-secondary bg-secondary/10 hover:bg-secondary/10">
                        <td class="whitespace-nowrap font-semibold text-secondary">
                            <div class="flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[18px] text-secondary">event_repeat</span>
                                {{ autoSalaryRow.expense_date }}
                            </div>
                            <div class="pl-6 text-xs font-normal text-secondary">{{ autoSalaryRow.date_sub }}</div>
                        </td>
                        <td>
                            <div class="flex items-center gap-2">
                                <UiBadge color="secondary" :dot="false">
                                    <span class="material-symbols-outlined text-[15px]">lock</span>
                                    TỰ ĐỘNG
                                </UiBadge>
                                <span class="text-[15px] font-bold text-on-surface">{{ autoSalaryRow.title }}</span>
                            </div>
                            <p class="mt-1 flex items-center gap-1 text-xs text-secondary/80">
                                <span class="material-symbols-outlined text-[14px]">info</span>
                                {{ autoSalaryRow.description }}
                            </p>
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <UiMoney :value="autoSalaryRow.amount" suffix="" tone="secondary" class="font-extrabold" />
                            <div class="text-xs text-on-surface-variant">{{ autoSalaryRow.staff_count }} nhân sự đủ điều kiện</div>
                        </td>
                        <td class="text-center">
                            <UiBadge color="info" pill :dot="false">
                                <span class="material-symbols-outlined text-[14px]">account_balance</span>
                                Chuyển khoản
                            </UiBadge>
                        </td>
                        <td class="whitespace-nowrap">
                            <UiBadge :dot="false">{{ autoSalaryRow.branch_name }}</UiBadge>
                        </td>
                        <td>
                            <div class="max-w-xs truncate text-xs text-on-surface-variant" :title="autoSalaryRow.notes">{{ autoSalaryRow.notes }}</div>
                        </td>
                        <td class="text-center">
                            <div class="inline-flex cursor-help items-center gap-1 rounded-lg border border-surface-container-highest bg-surface-container px-2.5 py-1 text-xs font-medium text-on-surface-variant" title="Số liệu tự động từ bảng lương đã chốt — không sửa được tại đây">
                                <span class="material-symbols-outlined text-[16px] text-on-surface-subtle">lock</span>
                                <span>Cố định</span>
                            </div>
                        </td>
                    </tr>

                    <!-- Các dòng chi tự nhập -->
                    <tr v-for="exp in manualExpenses" :key="exp.id" class="transition-colors hover:bg-surface-container-low">
                        <td class="whitespace-nowrap font-medium text-on-surface-variant">{{ formatDate(exp.expense_date, 'd/m/Y') }}</td>
                        <td>
                            <div class="font-semibold text-on-surface">{{ exp.title }}</div>
                            <div class="text-xs text-on-surface-subtle">Người lập: {{ exp.creator_name ?? '—' }} • {{ exp.created_at ?? '' }}</div>
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <UiMoney :value="exp.amount" suffix="" class="font-bold" />
                        </td>
                        <td class="text-center">
                            <UiBadge v-if="exp.payment_method === 'chuyen_khoan'" color="info" pill :dot="false">
                                <span class="material-symbols-outlined text-[13px]">account_balance</span>
                                Chuyển khoản
                            </UiBadge>
                            <UiBadge v-else color="warning" pill :dot="false">
                                <span class="material-symbols-outlined text-[13px]">payments</span>
                                Tiền mặt
                            </UiBadge>
                        </td>
                        <td class="whitespace-nowrap text-on-surface-variant">{{ exp.branch_name ?? 'Toàn hệ thống' }}</td>
                        <td class="text-xs text-on-surface-variant">{{ exp.notes || '—' }}</td>
                        <td class="text-center">
                            <div class="flex items-center justify-center gap-1">
                                <UiButton variant="ghost" size="sm" icon="edit" title="Sửa khoản chi" aria-label="Sửa khoản chi" @click="openModal(exp)" />
                                <UiForm :action="route('finance.expenses.destroy', exp.id)" method="delete" :confirm="`Xóa khoản chi “${exp.title}” khỏi sổ chi vận hành?`" confirm-label="Xóa" danger back class="inline">
                                    <UiButton type="submit" variant="ghost" size="sm" icon="delete" title="Xóa khoản chi" aria-label="Xóa khoản chi" />
                                </UiForm>
                            </div>
                        </td>
                    </tr>

                    <tr v-if="!manualExpenses.length && !autoSalaryRow">
                        <td colspan="7">
                            <UiEmptyState icon="receipt_long" title="Chưa có khoản chi nào trong kỳ tháng này" description="Bấm &quot;+ Thêm khoản chi mới&quot; để ghi nhận chi phí" />
                        </td>
                    </tr>
                </tbody>
            </table>

            <template #footer>
                <div class="flex flex-col items-center justify-between gap-3 text-xs text-on-surface-variant sm:flex-row">
                    <div class="flex items-center gap-2">
                        <span>Hiển thị toàn bộ <strong>{{ totalItemsCount }}</strong> bản ghi của tháng</span>
                        <span class="text-on-surface-subtle">|</span>
                        <span class="text-on-surface-variant">Tổng cộng thực chi: <strong class="text-sm text-on-surface">{{ formatMoney(grandTotalExpense) }}</strong></span>
                    </div>
                </div>
            </template>
        </UiDataTable>
    </div>

    <!-- Hộp thoại Thêm / Sửa khoản chi (6 ô theo spec) -->
    <UiModal :show="open" :title="form.title" max-width="lg" @close="open = false">
        <p class="mb-4 text-xs text-on-surface-variant">Lưu ngay vào sổ chi, không cần duyệt</p>
        <UiForm id="expenseForm" :key="formKey" :action="form.action" :method="form.method" class="space-y-4" @success="open = false">
            <UiDate name="expense_date" label="Ngày chi" :value="form.values.expense_date" required />
            <UiInput name="title" label="Nội dung khoản chi" :value="form.values.title" placeholder="Ví dụ: Mua rèm cửa phòng học, Nạp mực máy in..." required />
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <UiInput type="number" name="amount" label="Số tiền (VNĐ)" :value="form.values.amount" required suffix="đ" min="1000" step="1000" placeholder="0" class="font-semibold" />
                <UiSelect name="payment_method" label="Hình thức chi" required :value="form.values.payment_method" :options="paymentOptions" />
            </div>
            <UiSelect name="branch_id" label="Chi nhánh áp dụng" required :value="form.values.branch_id" :options="branchOptions" />
            <UiSelect name="category" label="Phân loại chi phí" hint="(Tự động nhận diện nếu để trống)" :value="form.values.category" :options="categoryOptions" />
            <UiTextarea name="notes" label="Ghi chú & Thông tin chứng từ" hint="(Tùy chọn)" rows="3" :value="form.values.notes" placeholder="Nhập mã hóa đơn, thông tin nhà cung cấp hoặc lưu ý nội bộ..." class="resize-none" />
            <div class="flex items-center justify-between rounded-xl border border-surface-container-highest/60 bg-surface-container-low p-3 text-xs text-on-surface-variant">
                <span>Người lập: <strong class="text-on-surface-variant">{{ userName }}</strong></span>
                <span>Thời điểm: <strong class="text-on-surface-variant">Tự động khi lưu</strong></span>
            </div>
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">Hủy bỏ</UiButton>
            <UiButton type="submit" form="expenseForm" icon="save">{{ form.submit }}</UiButton>
        </template>
    </UiModal>
</template>
