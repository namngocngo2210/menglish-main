<script setup>
/**
 * Báo cáo Doanh thu tạm tính (Epic 13): Tổng thu hợp lệ − Chi vận hành (chi tự nhập + chi lương tự động),
 * bóc tách nguồn thu / cơ cấu chi và ma trận so sánh hiệu quả giữa các chi nhánh.
 */
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { formatNumber } from '@/lib/format';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Báo cáo doanh thu tạm tính' } });

const props = defineProps({
    branchScoped: { type: Boolean, default: false },
    month: { type: String, required: true },
    branchId: { type: String, default: 'all' },
    branches: { type: Array, default: () => [] },
    totalRevenue: { type: Number, default: 0 },
    validReceiptsCount: { type: Number, default: 0 },
    tuitionRevenue: { type: Number, default: 0 },
    surchargeRevenue: { type: Number, default: 0 },
    tuitionPercent: { type: Number, default: 0 },
    surchargePercent: { type: Number, default: 0 },
    revenueTransferPercent: { type: Number, default: 0 },
    revenueCashPercent: { type: Number, default: 0 },
    prevMonthLabel: { type: String, required: true },
    revenueDiffPercent: { type: Number, default: 0 },
    revenueDiffIsUp: { type: Boolean, default: true },
    totalExpense: { type: Number, default: 0 },
    expensePercentageOfRevenue: { type: Number, default: 0 },
    totalExpenseItemsCount: { type: Number, default: 0 },
    salaryTotal: { type: Number, default: 0 },
    rentUtilitiesExpense: { type: Number, default: 0 },
    curriculumOperationsExpense: { type: Number, default: 0 },
    otherExpense: { type: Number, default: 0 },
    salaryPercentOfExpense: { type: Number, default: 0 },
    rentPercentOfExpense: { type: Number, default: 0 },
    curriculumPercentOfExpense: { type: Number, default: 0 },
    otherPercentOfExpense: { type: Number, default: 0 },
    provisionalRevenue: { type: Number, default: 0 },
    isProfitPositive: { type: Boolean, default: true },
    grossProfitMargin: { type: Number, default: 0 },
    branchMatrix: { type: Array, default: () => [] },
    totalMatrixRevenue: { type: Number, default: 0 },
    totalMatrixExpense: { type: Number, default: 0 },
    totalMatrixProfit: { type: Number, default: 0 },
    totalMatrixMargin: { type: Number, default: 0 },
    totalMatrixStatus: { type: Object, required: true },
    monthOptions: { type: Array, default: () => [] },
});

// number_format($v, 0, ',', '.') / number_format($v, n) (dấu chấm thập phân, phẩy hàng nghìn) như trang cũ.
const vnd = (value) => formatNumber(value, 0);
const millions = (value, decimals) => formatNumber(value / 1000000, decimals, '.', ',');
const monthLabel = computed(() => props.month.split('-').reverse().join('/'));
const branchOptions = computed(() => [...(props.branchScoped ? [] : [{ value: 'all', label: 'Tất cả chi nhánh' }]), ...props.branches.map((b) => ({ value: String(b.id), label: b.name }))]);
const exportUrl = computed(() => route('finance.reports.revenue.export', { month: props.month, branch_id: props.branchId }));
const autoSubmit = (event) => event.target.form?.requestSubmit();
</script>

<template>
    <UiPageHeader title="Báo cáo Doanh thu tạm tính" icon="query_stats" description="Tổng hợp đối soát Thu (học phí & phụ thu hợp lệ) trừ Chi vận hành (chi thường xuyên & chi lương)">
        <template #badges>
            <UiBadge color="success" pill>Tính toán thời gian thực</UiBadge>
        </template>
        <template #actions>
            <UiButton variant="secondary" icon="sync" title="Làm mới số liệu thời gian thực" aria-label="Làm mới số liệu thời gian thực" @click="router.reload()" />
            <UiButton variant="secondary" icon="download" :href="exportUrl" native>Xuất báo cáo</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <!-- Bộ lọc kỳ tháng / chi nhánh -->
        <UiFilterBar :action="route('finance.reports.revenue')" :search="false" class="!mb-0">
            <UiSelect name="month" label="Kỳ tháng" :value="month" :options="monthOptions" @change="autoSubmit" />
            <UiSelect name="branch_id" label="Cơ sở" :value="branchId" :options="branchOptions" @change="autoSubmit" />
        </UiFilterBar>

        <!-- Thẻ 3 số liệu cốt lõi: THU - CHI - DOANH THU TẠM TÍNH -->
        <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
            <!-- Tổng thu thực tế -->
            <div class="group relative flex flex-col justify-between overflow-hidden rounded-2xl border border-surface-container-highest/80 bg-surface-container-lowest p-6 shadow-xs transition-all hover:border-tertiary/30">
                <div class="pointer-events-none absolute right-0 top-0 -mr-10 -mt-10 h-32 w-32 rounded-full bg-tertiary/10 blur-2xl"></div>
                <div>
                    <div class="mb-3 flex items-center justify-between">
                        <span class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-on-surface-variant">
                            <span class="h-2 w-2 rounded-full bg-tertiary"></span>
                            Tổng thu thực tế
                        </span>
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-tertiary/10 text-tertiary">
                            <span class="material-symbols-outlined text-xl">payments</span>
                        </div>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <span class="text-3xl font-extrabold tracking-tight text-on-surface lg:text-4xl">{{ vnd(totalRevenue) }}</span>
                        <span class="text-base font-bold text-on-surface-variant">VNĐ</span>
                    </div>
                    <div :class="['mt-2.5 flex items-center gap-2 text-xs font-medium', revenueDiffIsUp ? 'text-tertiary' : 'text-error']">
                        <span class="material-symbols-outlined text-sm font-bold">{{ revenueDiffIsUp ? 'trending_up' : 'trending_down' }}</span>
                        <span>{{ revenueDiffIsUp ? 'Tăng' : 'Giảm' }} {{ revenueDiffPercent }}% so với tháng {{ prevMonthLabel }}</span>
                    </div>
                </div>
                <div class="mt-6 flex items-center justify-between border-t border-surface-container-highest pt-4 text-xs text-on-surface-variant">
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm text-on-surface-subtle">receipt_long</span>
                        Gồm {{ validReceiptsCount }} phiếu thu hợp lệ
                    </span>
                    <span class="italic text-on-surface-subtle">Đã gồm phụ thu</span>
                </div>
            </div>

            <!-- Chi phí vận hành -->
            <div class="group relative flex flex-col justify-between overflow-hidden rounded-2xl border border-surface-container-highest/80 bg-surface-container-lowest p-6 shadow-xs transition-all hover:border-error/30">
                <div class="pointer-events-none absolute right-0 top-0 -mr-10 -mt-10 h-32 w-32 rounded-full bg-error/10 blur-2xl"></div>
                <div>
                    <div class="mb-3 flex items-center justify-between">
                        <span class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-on-surface-variant">
                            <span class="h-2 w-2 rounded-full bg-error"></span>
                            Chi phí vận hành
                        </span>
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-error/10 text-error">
                            <span class="material-symbols-outlined text-xl">receipt</span>
                        </div>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <span class="text-3xl font-extrabold tracking-tight text-on-surface lg:text-4xl">{{ vnd(totalExpense) }}</span>
                        <span class="text-base font-bold text-on-surface-variant">VNĐ</span>
                    </div>
                    <div class="mt-2.5 flex items-center gap-2 text-xs font-medium text-on-surface-variant">
                        <span class="material-symbols-outlined text-sm text-on-surface-subtle">info</span>
                        <span>Khoản tự nhập + Lương kỳ {{ monthLabel }}</span>
                    </div>
                </div>
                <div class="mt-6 flex items-center justify-between border-t border-surface-container-highest pt-4 text-xs text-on-surface-variant">
                    <span class="font-medium text-error">Chiếm {{ expensePercentageOfRevenue }}% tổng thu</span>
                    <span class="text-on-surface-subtle">{{ totalExpenseItemsCount }} mục chi</span>
                </div>
            </div>

            <!-- Doanh thu tạm tính (= Thu - Chi) -->
            <div class="relative flex flex-col justify-between overflow-hidden rounded-2xl border border-primary-container/30 bg-gradient-to-br from-primary-container via-primary-container to-sidebar p-6 text-white shadow-lg shadow-primary-container/15">
                <div class="pointer-events-none absolute -bottom-8 -right-8 h-40 w-40 rounded-full bg-white/10 blur-2xl"></div>
                <div class="pointer-events-none absolute -left-6 -top-6 h-32 w-32 rounded-full bg-primary-fixed-dim/20 blur-xl"></div>
                <div>
                    <div class="mb-3 flex items-center justify-between">
                        <div class="inline-flex items-center gap-1.5 rounded-full bg-white/20 px-2.5 py-1 text-xs font-bold uppercase tracking-wider text-primary-fixed backdrop-blur-xs">
                            <span class="material-symbols-outlined text-sm">stars</span>
                            Doanh thu tạm tính
                        </div>
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-white/15 text-white backdrop-blur-xs">
                            <span class="material-symbols-outlined text-xl">account_balance_wallet</span>
                        </div>
                    </div>
                    <div class="mt-1 flex items-baseline gap-2">
                        <span class="text-3xl font-black tracking-tight text-white drop-shadow-xs lg:text-4xl">{{ isProfitPositive ? '+' : '' }}{{ vnd(provisionalRevenue) }}</span>
                        <span class="text-base font-bold text-primary-fixed-dim">VNĐ</span>
                    </div>
                    <div class="mt-2.5 flex items-center gap-2 text-xs font-medium text-primary-fixed/90">
                        <span class="material-symbols-outlined text-sm">calculate</span>
                        <span>= Tổng thu ({{ millions(totalRevenue, 1) }}M) − Chi vận hành ({{ millions(totalExpense, 2) }}M)</span>
                    </div>
                </div>
                <div class="mt-6 flex items-center justify-between border-t border-white/20 pt-4 text-xs text-primary-fixed">
                    <span class="font-semibold">Tỷ suất lợi nhuận gộp ước tính:</span>
                    <span class="rounded-md bg-white/20 px-2 py-0.5 text-sm font-black text-white">{{ grossProfitMargin }}%</span>
                </div>
            </div>
        </div>

        <!-- Bóc tách nguồn thu & khoản chi -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Cơ cấu nguồn thu -->
            <div class="space-y-4 rounded-2xl border border-surface-container-highest/80 bg-surface-container-lowest p-6 shadow-xs">
                <div class="flex items-center justify-between border-b border-surface-container-highest pb-3">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-tertiary/10 text-tertiary">
                            <span class="material-symbols-outlined text-lg">pie_chart</span>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-on-surface">Cơ cấu Nguồn Thu</h3>
                            <p class="text-xs text-on-surface-variant">Tất cả phiếu thu còn hiệu lực (đã loại bỏ phiếu bị hủy hóa đơn)</p>
                        </div>
                    </div>
                    <UiMoney :value="totalRevenue" tone="success" class="font-bold" />
                </div>

                <div class="space-y-3 pt-1">
                    <div class="flex items-center justify-between rounded-xl border border-surface-container-highest bg-surface-container-low/80 p-3.5">
                        <div class="flex items-center gap-3">
                            <div class="h-2.5 w-2.5 rounded-full bg-tertiary"></div>
                            <div>
                                <p class="text-sm font-semibold text-on-surface">Học phí các khóa học</p>
                                <p class="text-xs text-on-surface-variant">Phần học phí của các phiếu thu đã duyệt trong tháng</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <UiMoney :value="tuitionRevenue" class="text-sm font-bold" />
                            <p class="text-xs font-medium text-on-surface-subtle">{{ tuitionPercent }}%</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between rounded-xl border border-surface-container-highest bg-surface-container-low/80 p-3.5">
                        <div class="flex items-center gap-3">
                            <div class="h-2.5 w-2.5 rounded-full bg-tertiary"></div>
                            <div>
                                <p class="text-sm font-semibold text-on-surface">Phụ thu phát sinh</p>
                                <p class="text-xs text-on-surface-variant">Giáo trình in ấn bổ sung, lệ phí thi thử, thẻ học viên</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <UiMoney :value="surchargeRevenue" class="text-sm font-bold" />
                            <p class="text-xs font-medium text-on-surface-subtle">{{ surchargePercent }}%</p>
                        </div>
                    </div>

                    <div class="pt-2">
                        <div class="mb-1.5 flex justify-between text-xs font-semibold text-on-surface-variant">
                            <span>Hình thức: Chuyển khoản ({{ revenueTransferPercent }}%)</span>
                            <span>Tiền mặt ({{ revenueCashPercent }}%)</span>
                        </div>
                        <div class="flex h-2 w-full overflow-hidden rounded-full bg-surface-container">
                            <div class="h-full rounded-full bg-tertiary transition-all" :style="{ width: revenueTransferPercent + '%' }"></div>
                            <div class="h-full rounded-full bg-tertiary/40 transition-all" :style="{ width: revenueCashPercent + '%' }"></div>
                        </div>
                    </div>
                </div>

                <UiAlert type="success" title="Nguyên tắc ghi nhận doanh thu:" class="text-xs">
                    <p>Chỉ cộng phiếu thu <strong>đã duyệt</strong> theo ngày thu (phiếu nháp / chờ duyệt / bị từ chối chưa là doanh thu). Phiếu bị <strong class="underline">Hủy hóa đơn</strong> tự động bị loại trừ; phiếu hoàn phí / chuyển nhượng (số âm) được trừ vào tổng thu.</p>
                </UiAlert>
            </div>

            <!-- Cơ cấu chi vận hành -->
            <div class="space-y-4 rounded-2xl border border-surface-container-highest/80 bg-surface-container-lowest p-6 shadow-xs">
                <div class="flex items-center justify-between border-b border-surface-container-highest pb-3">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-error/10 text-error">
                            <span class="material-symbols-outlined text-lg">donut_small</span>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-on-surface">Cơ cấu Chi Vận Hành</h3>
                            <p class="text-xs text-on-surface-variant">Khoản chi tự nhập thực tế + Chi lương tự động từ bảng lương đã chốt</p>
                        </div>
                    </div>
                    <UiMoney :value="totalExpense" tone="error" class="font-bold" />
                </div>

                <div class="space-y-3 pt-1">
                    <div class="flex items-center justify-between rounded-xl border border-secondary/20 bg-secondary/5 p-3.5">
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-secondary/10 text-secondary">
                                <span class="material-symbols-outlined text-base">lock</span>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-bold text-on-secondary-fixed">Chi trả lương nhân sự (Kỳ {{ monthLabel }})</p>
                                    <span class="rounded bg-secondary/20 px-1.5 py-0.5 text-xs font-bold uppercase tracking-tight text-on-secondary-fixed">Tự động</span>
                                </div>
                                <p class="text-xs text-secondary/80">Tổng thực nhận của toàn bộ GV, TA, Học vụ đã chốt bảng</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-black text-on-secondary-fixed">{{ formatMoney(salaryTotal) }}</p>
                            <p class="text-xs font-semibold text-secondary">{{ salaryPercentOfExpense }}% chi phí</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between rounded-xl border border-surface-container-highest bg-surface-container-low/80 p-3.5">
                        <div class="flex items-center gap-3">
                            <div class="h-2.5 w-2.5 rounded-full bg-error/40"></div>
                            <div>
                                <p class="text-sm font-semibold text-on-surface">Mặt bằng &amp; Tiện ích</p>
                                <p class="text-xs text-on-surface-variant">Tiền thuê trụ sở, điện nước, internet cáp quang</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <UiMoney :value="rentUtilitiesExpense" class="text-sm font-bold" />
                            <p class="text-xs font-medium text-on-surface-subtle">{{ rentPercentOfExpense }}%</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between rounded-xl border border-surface-container-highest bg-surface-container-low/80 p-3.5">
                        <div class="flex items-center gap-3">
                            <div class="h-2.5 w-2.5 rounded-full bg-warning/40"></div>
                            <div>
                                <p class="text-sm font-semibold text-on-surface">In ấn giáo trình &amp; Vận hành lớp</p>
                                <p class="text-xs text-on-surface-variant">Sách bổ trợ, văn phòng phẩm, nước uống, bảo dưỡng thiết bị</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <UiMoney :value="curriculumOperationsExpense" class="text-sm font-bold" />
                            <p class="text-xs font-medium text-on-surface-subtle">{{ curriculumPercentOfExpense }}%</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between rounded-xl border border-surface-container-highest bg-surface-container-low/80 p-3.5">
                        <div class="flex items-center gap-3">
                            <div class="h-2.5 w-2.5 rounded-full bg-outline"></div>
                            <div>
                                <p class="text-sm font-semibold text-on-surface">Chi khác</p>
                                <p class="text-xs text-on-surface-variant">Các khoản chi không thuộc 2 nhóm trên</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <UiMoney :value="otherExpense" class="text-sm font-bold" />
                            <p class="text-xs font-medium text-on-surface-subtle">{{ otherPercentOfExpense }}%</p>
                        </div>
                    </div>
                </div>

                <UiAlert type="info" title="Quy tắc chuẩn hóa kỳ lương:" class="text-xs">
                    <p>Chi lương tự động tính theo <strong class="underline">kỳ lương khớp tháng đã chọn</strong>, kể cả khi ngày thanh toán thực tế lệch sang tháng dương lịch tiếp theo.</p>
                </UiAlert>
            </div>
        </div>

        <!-- Ma trận chi nhánh -->
        <UiDataTable>
            <template #header>
                <div>
                    <h3 class="text-base font-bold text-on-surface">So sánh Hiệu quả Doanh thu giữa các Chi nhánh</h3>
                    <p class="mt-0.5 text-xs text-on-surface-variant">Tổng hợp đối soát chi tiết theo từng cơ sở đào tạo</p>
                </div>
                <div class="flex items-center gap-2 rounded-lg bg-surface-container/80 px-3 py-1.5 text-xs font-semibold text-on-surface-variant">
                    <span class="material-symbols-outlined text-sm text-on-surface-variant">domain</span>
                    <span>Đang hiển thị {{ branchMatrix.length }} chi nhánh đang hoạt động</span>
                </div>
            </template>

            <table class="text-sm">
                <thead>
                    <tr>
                        <th>Chi nhánh / Cơ sở</th>
                        <th class="text-right">Tổng thu</th>
                        <th class="text-right">Chi vận hành</th>
                        <th class="text-right">Doanh thu tạm tính</th>
                        <th class="text-center">Tỷ suất gộp</th>
                        <th class="text-center">Tình trạng</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in branchMatrix" :key="row.branch.id">
                        <td class="font-semibold">
                            <div class="flex items-center gap-2.5">
                                <span class="h-2 w-2 rounded-full bg-primary-container"></span>
                                <span>{{ row.branch.name }}</span>
                            </div>
                        </td>
                        <td class="text-right font-bold"><UiMoney :value="row.revenue" tone="success" /></td>
                        <td class="text-right font-semibold"><UiMoney :value="row.expense" tone="error" /></td>
                        <td class="text-right font-extrabold"><UiMoney :value="row.profit" sign /></td>
                        <td class="text-center">
                            <span :class="['inline-block rounded-full px-2.5 py-0.5 text-xs font-bold', row.status.badge_bg]">{{ row.margin }}%</span>
                        </td>
                        <td class="text-center">
                            <span :class="['inline-flex items-center gap-1 text-xs font-semibold', row.status.class]">
                                <span :class="['h-1.5 w-1.5 rounded-full', row.status.dot]"></span>
                                {{ row.status.label }}
                            </span>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-surface-container-highest bg-surface-container-low font-bold text-on-surface">
                        <td class="text-xs uppercase tracking-wider">{{ branchScoped ? 'Tổng cộng' : 'Tổng cộng toàn hệ thống' }}</td>
                        <td class="text-right font-extrabold"><UiMoney :value="totalMatrixRevenue" tone="success" /></td>
                        <td class="text-right font-extrabold"><UiMoney :value="totalMatrixExpense" tone="error" /></td>
                        <td class="text-right font-black"><UiMoney :value="totalMatrixProfit" sign tone="primary" /></td>
                        <td class="text-center text-xs font-black">{{ totalMatrixMargin }}%</td>
                        <td :class="['text-center text-xs font-semibold', totalMatrixStatus.class]">{{ totalMatrixStatus.label }}</td>
                    </tr>
                </tfoot>
            </table>
        </UiDataTable>
    </div>
</template>
