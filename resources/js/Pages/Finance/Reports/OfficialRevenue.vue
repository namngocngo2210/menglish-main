<script setup>
/**
 * Báo cáo Doanh thu chính thức (TK-2026-0169): chỉ phiếu thu Đã duyệt; chuyển khoản phải có số hóa đơn (Chính thức),
 * tiền mặt chỉ cần Đã duyệt; phiếu Tạm thu / hóa đơn đã hủy không tính. Quy tắc nằm ở OfficialRevenueQuery.
 */
import { computed } from 'vue';
import { formatNumber } from '@/lib/format';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Báo cáo doanh thu chính thức' } });

const props = defineProps({
    branchScoped: { type: Boolean, default: false },
    month: { type: String, required: true },
    branchId: { type: String, default: 'all' },
    branches: { type: Array, default: () => [] },
    monthOptions: { type: Array, default: () => [] },
    totalRevenue: { type: Number, default: 0 },
    receiptsCount: { type: Number, default: 0 },
    transferRevenue: { type: Number, default: 0 },
    cashRevenue: { type: Number, default: 0 },
    surchargeRevenue: { type: Number, default: 0 },
    tuitionRevenue: { type: Number, default: 0 },
    branchRows: { type: Array, default: () => [] },
});

const vnd = (value) => formatNumber(value, 0);
const branchOptions = computed(() => [...(props.branchScoped ? [] : [{ value: 'all', label: 'Tất cả chi nhánh' }]), ...props.branches.map((b) => ({ value: String(b.id), label: b.name }))]);
const exportUrl = computed(() => route('finance.reports.official-revenue.export', { month: props.month, branch_id: props.branchId }));
const autoSubmit = (event) => event.target.form?.requestSubmit();
const cards = computed(() => [
    { label: 'Chuyển khoản (Chính thức)', value: props.transferRevenue },
    { label: 'Tiền mặt (Đã duyệt)', value: props.cashRevenue },
    { label: 'Học phí', value: props.tuitionRevenue },
    { label: 'Phụ thu', value: props.surchargeRevenue },
]);
</script>

<template>
    <UiPageHeader title="Báo cáo Doanh thu chính thức" icon="verified" description="Chỉ tính phiếu thu đã duyệt: chuyển khoản phải ở trạng thái Chính thức, tiền mặt đã duyệt; Tạm thu và hóa đơn đã hủy không tính">
        <template #actions>
            <UiButton variant="secondary" icon="download" :href="exportUrl" native>Xuất báo cáo</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <UiFilterBar :action="route('finance.reports.official-revenue')" :search="false" class="!mb-0">
            <UiSelect name="month" label="Kỳ tháng" :value="month" :options="monthOptions" @change="autoSubmit" />
            <UiSelect name="branch_id" label="Cơ sở" :value="branchId" :options="branchOptions" @change="autoSubmit" />
        </UiFilterBar>

        <div class="rounded-2xl border border-surface-container-highest/80 bg-surface-container-lowest p-6">
            <div class="text-xs font-bold uppercase tracking-wider text-on-surface-variant">Tổng doanh thu chính thức</div>
            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-3xl font-extrabold tracking-tight text-on-surface lg:text-4xl">{{ vnd(totalRevenue) }}</span>
                <span class="text-base font-bold text-on-surface-variant">VNĐ</span>
            </div>
            <div class="mt-2 text-xs text-on-surface-variant">Gồm {{ receiptsCount }} phiếu thu</div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="c in cards" :key="c.label" class="rounded-2xl border border-surface-container-highest/80 bg-surface-container-lowest p-4">
                <div class="text-xs font-bold uppercase tracking-wider text-on-surface-variant">{{ c.label }}</div>
                <div class="mt-1 text-xl font-bold text-on-surface">{{ vnd(c.value) }} <span class="text-sm text-on-surface-variant">VNĐ</span></div>
            </div>
        </div>

        <div class="overflow-x-auto rounded-2xl border border-surface-container-highest/80 bg-surface-container-lowest">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-surface-container-highest text-xs uppercase text-on-surface-variant">
                        <th class="px-4 py-3">Chi nhánh / Cơ sở</th>
                        <th class="px-4 py-3 text-right">Doanh thu chính thức (VNĐ)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in branchRows" :key="r.id" class="border-b border-surface-container-highest/60">
                        <td class="px-4 py-3">{{ r.name }}</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ vnd(r.revenue) }}</td>
                    </tr>
                    <tr v-if="!branchRows.length">
                        <td colspan="2" class="px-4 py-6 text-center text-on-surface-variant">Chưa có dữ liệu</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
