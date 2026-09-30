<script setup>
/**
 * Báo cáo tuyển sinh (mockup crm-ui-mockup/bao-cao-doanh-so): bộ lọc (khoảng thời gian, chi nhánh), 4 thẻ xu hướng,
 * giai đoạn chuyển đổi (8 bước A6), lý do không chốt, bảng hiệu suất theo người phụ trách, ghi chú nguồn dữ liệu.
 * Nút "Lọc" gửi preset=custom (theo khoảng ngày đã chọn); bấm nút chọn nhanh thì gửi preset của nút đó.
 */
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import CrmHeader from '@/Components/Crm/CrmHeader.vue';
import { route } from '@/lib/route';
import { compactQuery, urlWith } from '@/lib/url';
import { formatMoney } from '@/lib/format';

defineOptions({ layout: { title: 'Báo cáo tuyển sinh', workspaceTabs: false } });

const props = defineProps({
    preset: { type: String, default: 'last_30_days' },
    presetLabel: { type: String, default: '' },
    startDate: { type: String, required: true },
    endDate: { type: String, required: true },
    branchId: { type: String, default: null },
    branches: { type: Array, default: () => [] },
    updatedAt: { type: String, default: '' },
    metricTotalLeads: { type: Number, default: 0 },
    metricWonDeals: { type: Number, default: 0 },
    metricConversionRate: { type: Number, default: 0 },
    metricLostDeals: { type: Number, default: 0 },
    leadDeltaPercent: { type: Number, default: 0 },
    leadDiff: { type: Number, default: 0 },
    wonDeltaPercent: { type: Number, default: 0 },
    wonDiff: { type: Number, default: 0 },
    conversionDeltaPercent: { type: Number, default: 0 },
    lostDeltaPercent: { type: Number, default: 0 },
    lostDiff: { type: Number, default: 0 },
    funnelStages: { type: Array, default: () => [] },
    lostReasons: { type: Array, default: () => [] },
    lostDealsParams: { type: Object, default: () => ({}) },
    repsData: { type: Array, default: () => [] },
});

const presets = {
    today: 'Hôm nay', yesterday: 'Hôm qua', last_7_days: '7 ngày', last_week: 'Tuần trước', last_30_days: '30 ngày',
    last_60_days: '60 ngày', last_90_days: '90 ngày', last_6_months: '6 tháng', last_year: '1 năm',
};

/** Mũi tên + màu xu hướng: tăng là tốt (trừ "khách không chốt"). */
function trend(delta, upIsGood = true) {
    const good = delta == 0 ? null : (delta > 0) === upIsGood;
    return {
        arrow: delta > 0 ? 'trending_up' : delta < 0 ? 'trending_down' : 'trending_flat',
        tone: good === null ? 'text-on-surface-variant' : good ? 'text-tertiary' : 'text-error',
        sign: delta > 0 ? '+' : '',
    };
}
const diffHint = (diff, suffix) => (diff >= 0 ? 'Tăng ' : 'Giảm ') + Math.abs(diff) + suffix;

const cards = computed(() => [
    { label: 'Số lượng khách', value: props.metricTotalLeads, icon: 'group', tone: 'text-secondary', delta: props.leadDeltaPercent + '%', t: trend(props.leadDiff), hint: diffHint(props.leadDiff, ' khách so với kỳ trước') },
    { label: 'Khách đã chốt', value: props.metricWonDeals, icon: 'check_circle', tone: 'text-tertiary', delta: props.wonDeltaPercent + '%', t: trend(props.wonDiff), hint: diffHint(props.wonDiff, ' khách so với kỳ trước') },
    { label: 'Tỷ lệ chốt thành công', value: props.metricConversionRate + '%', icon: 'bookmark', tone: 'text-primary-container', delta: props.conversionDeltaPercent + ' điểm %', t: trend(props.conversionDeltaPercent), hint: 'So với tỷ lệ kỳ trước' },
    { label: 'Khách không chốt', value: props.metricLostDeals, icon: 'person_remove', tone: 'text-error', delta: props.lostDeltaPercent + '%', t: trend(props.lostDiff, false), hint: diffHint(props.lostDiff, ' khách thất bại so với kỳ trước') },
]);

/** Bấm nút chọn nhanh: gửi toàn bộ bộ lọc đang có trên form, thay preset bằng giá trị của nút. */
function applyPreset(event, key) {
    const data = {};
    new FormData(event.currentTarget.closest('form')).forEach((value, name) => { data[name] = value; });
    data.preset = key;
    // Mốc nhanh tự tính khoảng ngày ở server → bỏ ngày đang hiển thị khỏi URL.
    delete data.start_date;
    delete data.end_date;
    router.get(route('crm.reports'), compactQuery(data), { preserveScroll: true });
}

const exportUrl = (format) => urlWith({ export: format });
const funnelWidth = (index) => 100 - (index === 0 ? 0 : Math.min(42, index * 6)) + '%';
</script>

<template>
    <CrmHeader title="Báo cáo tuyển sinh" :description="'Cập nhật: ' + updatedAt" />

    <div class="flex flex-col gap-lg">
        <UiFilterBar :action="route('crm.reports')" :reset-url="route('crm.reports')" :search="false" class="!mb-0">
            <template #quick>
                <input type="hidden" name="preset" value="custom" />
                <div class="flex flex-wrap items-center gap-sm">
                    <span class="font-label text-label uppercase text-on-surface-variant">Chọn nhanh:</span>
                    <button
                        v-for="(label, key) in presets"
                        :key="key"
                        type="button"
                        :class="['rounded-full px-md py-xs font-body-small text-body-small transition-colors', preset === key ? 'bg-primary-container font-semibold text-white' : 'bg-surface-container-low text-on-surface-variant hover:bg-surface-container-high']"
                        @click="applyPreset($event, key)"
                    >{{ label }}</button>
                </div>
            </template>
            <UiDateRange label="Khoảng thời gian" from="start_date" to="end_date" :from-value="startDate.slice(0, 10)" :to-value="endDate.slice(0, 10)" />
            <UiSelect name="branch_id" label="Chi nhánh" :options="branches" :value="branchId ?? ''" placeholder="Tất cả chi nhánh" />
        </UiFilterBar>

        <div class="grid grid-cols-1 gap-md sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="card in cards" :key="card.label" class="rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="font-body-medium text-body-medium text-on-surface-variant">{{ card.label }}</span>
                    <span :class="['material-symbols-outlined', card.tone]">{{ card.icon }}</span>
                </div>
                <div class="mt-sm flex items-baseline justify-between">
                    <span :class="['font-h1 text-h1', card.tone]">{{ card.value }}</span>
                    <span :class="['flex items-center gap-xs font-body-small text-body-small font-semibold', card.t.tone]">
                        <span class="material-symbols-outlined text-[16px]">{{ card.t.arrow }}</span>{{ card.t.sign }}{{ card.delta }}
                    </span>
                </div>
                <p class="mt-xs font-caption text-caption text-on-surface-variant">{{ card.hint }}</p>
            </div>
        </div>

        <!-- Giai đoạn chuyển đổi (8 bước A6) -->
        <section class="space-y-md">
            <h2 class="font-h3 text-h3 text-on-surface">Giai đoạn chuyển đổi</h2>
            <div class="space-y-sm rounded-xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
                <div v-for="(stage, index) in funnelStages" :key="stage.name" class="flex justify-center">
                    <div class="flex w-full items-stretch overflow-hidden rounded-lg border border-surface-container-highest bg-surface-container-low" :title="stage.desc" :style="{ maxWidth: funnelWidth(index) }">
                        <div :class="['flex w-24 shrink-0 items-center justify-center py-sm font-h3 text-h3 text-white', stage.bar_color]">{{ formatNumber(stage.count) }}</div>
                        <div class="flex flex-1 items-center justify-between px-md">
                            <span class="font-body-semibold text-body-semibold text-on-surface">{{ stage.name }}</span>
                            <span class="flex items-center gap-sm font-body-small text-body-small text-on-surface-variant">
                                {{ stage.percent }}%
                                <span :class="['material-symbols-outlined', stage.text_color]">{{ stage.icon }}</span>
                            </span>
                        </div>
                    </div>
                </div>
                <p v-if="!funnelStages.length" class="py-md text-center font-body-small text-body-small text-on-surface-variant">Chưa có khách nào trong kỳ.</p>
            </div>
        </section>

        <!-- Lý do khách không chốt -->
        <section class="space-y-md">
            <div class="flex flex-wrap items-baseline justify-between gap-sm">
                <h2 class="font-h3 text-h3 text-on-surface">Lý do khách không chốt</h2>
                <p class="font-body-small text-body-small text-on-surface-variant">Tổng cộng <span class="font-semibold text-error">{{ metricLostDeals }}</span> hồ sơ thất bại trong kỳ</p>
            </div>
            <UiDataTable min-width="760px">
                <table>
                    <thead>
                        <tr>
                            <th>Khách hàng</th>
                            <th>Thời điểm ghi nhận</th>
                            <th>Nội dung lý do (Log chi tiết)</th>
                            <th>Nhân viên phụ trách</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="lost in lostReasons" :key="lost.id">
                            <td class="whitespace-nowrap"><Link :href="route('crm.customers.show', lost.id)" class="font-body-medium text-body-medium text-on-surface hover:text-primary">{{ lost.name }}</Link></td>
                            <td class="whitespace-nowrap font-code text-code text-on-surface-variant">{{ lost.at }}</td>
                            <td class="text-on-surface">{{ lost.reason ?? 'Chưa ghi nhận lý do' }}</td>
                            <td class="whitespace-nowrap text-on-surface-variant">{{ lost.user ?? 'Chưa phân công' }}</td>
                        </tr>
                        <tr v-if="!lostReasons.length">
                            <td colspan="4"><UiEmptyState icon="sentiment_satisfied" title="Không có khách thất bại trong kỳ" /></td>
                        </tr>
                    </tbody>
                </table>
                <template v-if="metricLostDeals > lostReasons.length" #footer>
                    <div class="flex justify-center p-sm">
                        <UiButton variant="ghost" icon="expand_more" :href="route('crm.lost-deals', lostDealsParams)">Xem thêm lý do không chốt</UiButton>
                    </div>
                </template>
            </UiDataTable>
        </section>

        <!-- Bảng hiệu suất & Tỷ lệ chốt theo người phụ trách -->
        <div class="space-y-md rounded-xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
            <div class="flex flex-col gap-2 border-b border-surface-container-highest pb-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="font-h3 text-h3 text-on-surface">Bảng hiệu suất &amp; Tỷ lệ chốt theo người phụ trách</h2>
                    <p class="text-xs text-on-surface-variant">Thống kê số lượng khách, doanh số và hoa hồng theo từng chuyên viên</p>
                </div>

                <div class="flex items-center gap-2">
                    <UiButton variant="secondary" size="sm" icon="download" :href="exportUrl('xlsx')" native>Xuất Excel</UiButton>
                    <UiButton variant="ghost" size="sm" :href="exportUrl('csv')" native>CSV</UiButton>
                </div>

                <Link v-if="can('commission_config.manage')" :href="route('payroll.config.commission-tiers')" class="inline-flex items-center gap-1 text-xs font-bold text-primary-container hover:underline">
                    <span class="material-symbols-outlined text-sm">settings</span>
                    <span>Cấu hình công thức hoa hồng &rarr;</span>
                </Link>
            </div>

            <UiDataTable>
                <table>
                    <thead>
                        <tr>
                            <th>Người phụ trách</th>
                            <th class="text-center">Số lượng khách</th>
                            <th class="text-center">SL chốt thành công</th>
                            <th class="text-center">% Chốt thành công</th>
                            <th class="text-right">Doanh thu</th>
                            <th class="bg-brand-surface text-right font-bold !text-on-primary-fixed-variant">Hoa hồng (Tier)</th>
                            <th class="text-center">Biến động % vs kỳ trước</th>
                            <th class="text-center">Đánh giá</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="rep in repsData" :key="rep.name">
                            <td class="font-bold">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-container text-xs font-bold text-white">{{ rep.avatar_letter }}</span>
                                    <div>
                                        <div class="font-bold text-on-surface">{{ rep.name }}</div>
                                        <div class="text-xs font-normal text-on-surface-subtle">{{ rep.role }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center font-mono font-bold">{{ rep.leads }}</td>
                            <td class="text-center font-mono font-bold !text-tertiary">{{ rep.won }}</td>
                            <td class="text-center font-mono font-bold">{{ rep.rate }}%</td>
                            <td class="whitespace-nowrap text-right font-mono font-bold">{{ formatMoney(rep.revenue) }}</td>
                            <td class="bg-brand-surface text-right">
                                <div class="whitespace-nowrap font-mono font-bold text-primary-container">{{ formatMoney(rep.commission_amount) }}</div>
                                <div class="font-sans text-xs text-on-surface-variant">{{ rep.tier_name }} ({{ rep.commission_percent }}%{{ rep.commission_bonus > 0 ? ' + ' + formatMoney(rep.commission_bonus) : '' }})</div>
                            </td>
                            <td :class="['text-center font-mono font-bold', rep.delta.startsWith('+') ? '!text-tertiary' : '!text-error']">{{ rep.delta }}</td>
                            <td class="text-center">
                                <span :class="['rounded-full border px-2.5 py-0.5 text-xs font-bold', rep.rating_badge]">{{ rep.rating }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </UiDataTable>
        </div>

        <footer class="flex items-start gap-md rounded-xl border border-surface-container-highest bg-surface-container-low p-md">
            <span class="material-symbols-outlined text-secondary">info</span>
            <div>
                <p class="font-body-semibold text-body-semibold text-on-surface">Ghi chú về nguồn dữ liệu</p>
                <p class="font-body-small text-body-small text-on-surface-variant">
                    Báo cáo được tổng hợp dựa trên số lượng hồ sơ thực tế trong CRM. Doanh thu = tiền thực thu của khách mới (phiếu thu đã duyệt trong kỳ). Các giai đoạn được sắp xếp theo quy trình 8 bước.
                    <template v-if="can('commission_config.manage')"> Hoa hồng tính tự động theo <Link :href="route('payroll.config.commission-tiers')" class="font-semibold text-primary hover:underline">Cấu hình mốc hoa hồng</Link>.</template>
                </p>
            </div>
        </footer>
    </div>
</template>
