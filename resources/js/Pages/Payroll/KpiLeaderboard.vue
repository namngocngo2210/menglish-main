<script setup>
/**
 * BXH KPI & hoa hồng (mockup epic-7/bang-kpi-cong-khai) — dữ liệu công khai, không có lương cơ bản / khấu trừ / thực nhận:
 * 1. KPI giữ học sinh của GV (từ phiếu lương của kỳ), 2. hoa hồng tuyển sinh của tư vấn. Mỗi bảng phân trang riêng (kpi_page / sales_page).
 */
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { money, trimNumber } from './format';

defineOptions({ layout: { title: 'Bảng xếp hạng KPI & Hoa hồng' } });

const props = defineProps({
    retentionPage: { type: Object, required: true },
    salesPage: { type: Object, required: true },
    period: { type: Object, default: null },
    month: { type: Number, required: true },
    year: { type: Number, required: true },
    selectedPeriod: { type: String, required: true },
    branchId: { type: Number, default: null },
    periodOptions: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
});

const pad = (n, len = 2) => String(n).padStart(len, '0');
const monthLabel = `${pad(props.month)}/${pad(props.year, 4)}`;

function rankBadge(rank) {
    if (rank === 1) return ['bg-warning-container text-warning', 'emoji_events'];
    if (rank === 2) return ['bg-surface-container text-on-surface-variant', 'emoji_events'];
    if (rank === 3) return ['bg-warning-container text-on-warning-container', 'emoji_events'];
    return [null, null];
}
const rankOf = (paginator, i) => (Number(paginator.current_page ?? 1) - 1) * Number(paginator.per_page ?? 20) + i + 1;

function filter(event) {
    const form = event.target.form ?? event.target;
    const data = {};
    new FormData(form).forEach((value, key) => {
        if (value !== '') data[key] = value;
    });
    router.get(route('payroll.kpi-leaderboard'), data, { preserveScroll: true });
}
</script>

<template>
    <div>
        <UiPageHeader title="Bảng xếp hạng KPI & Hoa hồng" description="Dữ liệu công khai nhằm mục đích thi đua khen thưởng. Thông tin không bao gồm lương cơ bản, các khoản khấu trừ và thực nhận cá nhân.">
            <template v-if="can('commission_config.manage')" #actions>
                <UiButton variant="secondary" icon="settings" :href="route('payroll.config.commission-tiers')">Cấu hình mốc hoa hồng</UiButton>
            </template>
        </UiPageHeader>

        <form method="GET" :action="route('payroll.kpi-leaderboard')" class="mb-lg flex flex-wrap items-end gap-md rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm" @submit.prevent="filter">
            <UiSelect name="period" label="Kỳ lương" :options="periodOptions" :value="selectedPeriod" @change="filter" />
            <UiSelect name="branch_id" label="Chi nhánh" :options="branches" placeholder="Tất cả" :value="branchId ?? ''" @change="filter" />
            <UiButton type="submit" variant="secondary" icon="filter_list">Xem</UiButton>
        </form>

        <!-- 1. KPI giữ học sinh (GV Part-time) — từ phiếu lương của kỳ -->
        <UiDataTable min-width="760px" class="mb-lg">
            <template #header>
                <div>
                    <h3 class="font-h3 text-h3 text-on-surface">KPI giữ học sinh — giáo viên</h3>
                    <p class="font-body-small text-body-small text-on-surface-variant">Số HS giữ được × đơn giá bậc (đ/HS/tháng), cùng số liệu phiếu lương kỳ {{ monthLabel }}.</p>
                </div>
                <UiBadge v-if="period && !period.locked" color="warning">Số liệu tạm tính — kỳ chưa chốt</UiBadge>
                <UiBadge v-else-if="period" color="success">Kỳ đã chốt</UiBadge>
            </template>
            <table>
                <thead>
                    <tr>
                        <th class="w-16 text-center">Hạng</th>
                        <th>Nhân viên</th>
                        <th>Chi nhánh</th>
                        <th class="text-right">Số HS Giữ</th>
                        <th class="text-right">Đơn giá (VNĐ/hs)</th>
                        <th class="text-right">Tổng KPI (VNĐ)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in retentionPage.data" :key="r.id">
                        <td class="text-center">
                            <span v-if="rankBadge(rankOf(retentionPage, i))[0]" :class="['inline-flex h-8 w-8 items-center justify-center rounded-full', rankBadge(rankOf(retentionPage, i))[0]]" role="img" :aria-label="`Hạng ${rankOf(retentionPage, i)}`"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ rankBadge(rankOf(retentionPage, i))[1] }}</span></span>
                            <span v-else class="font-mono font-semibold text-on-surface-variant">{{ rankOf(retentionPage, i) }}</span>
                        </td>
                        <td>
                            <div class="flex items-center gap-sm">
                                <UiAvatar :name="r.name ?? '?'" size="sm" />
                                <div>
                                    <p class="font-semibold text-on-surface">{{ r.name }}</p>
                                    <p class="font-caption text-caption text-on-surface-variant">{{ r.branch }}</p>
                                </div>
                            </div>
                        </td>
                        <td>{{ r.branch }}</td>
                        <td class="text-right font-mono">{{ money(r.retention_students) }} <span class="font-caption text-caption text-on-surface-variant">/ {{ r.retention_base_students }}</span></td>
                        <td class="text-right font-mono">{{ r.retention_tier !== null ? money(r.retention_tier) : 'Chưa chọn bậc' }}</td>
                        <td class="text-right font-mono font-bold text-primary">{{ money(r.kpi_bonus) }}</td>
                    </tr>
                    <tr v-if="!retentionPage.data.length">
                        <td colspan="6">
                            <UiEmptyState icon="leaderboard" title="Chưa có KPI giữ học sinh" :description="period ? 'Kỳ này chưa có giáo viên Part-time được tính KPI giữ học sinh.' : `Chưa khởi tạo bảng lương tháng ${monthLabel}.`" />
                        </td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="retentionPage" unit="nhân viên" :options="[]" page-name="kpi_page" /></template>
        </UiDataTable>

        <!-- 2. Hoa hồng tuyển sinh (Sale) -->
        <UiDataTable min-width="980px">
            <template #header>
                <div>
                    <h3 class="font-h3 text-h3 text-on-surface">Hoa hồng tuyển sinh — người phụ trách</h3>
                    <p class="font-body-small text-body-small text-on-surface-variant">
                        Hoa hồng tháng {{ month }}/{{ year }} = học phí thu được của khách mới (phiếu thu đã duyệt trong tháng, không tính tiền sách / Thu khác, không tính tái tục)
                        × % mốc theo thứ tự chốt của từng HS (VD mốc 1–5: 4%, từ HS thứ 6: 3%). Hoa hồng phát sinh trước gate kép (30 ngày + 3/3 mốc chăm sóc) — số trả thực tế theo phiếu lương.
                    </p>
                </div>
            </template>
            <table>
                <thead>
                    <tr>
                        <th class="w-16 text-center">Hạng</th>
                        <th>Nhân viên</th>
                        <th>Chi nhánh</th>
                        <th class="text-right">Số HS chốt</th>
                        <th class="text-right">Mốc hiện tại</th>
                        <th class="text-right">Thực thu khách mới</th>
                        <th class="text-right">Học phí tính HH</th>
                        <th class="text-right">Tổng hoa hồng (VNĐ)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(item, i) in salesPage.data" :key="item.id">
                        <td class="text-center">
                            <span v-if="rankBadge(rankOf(salesPage, i))[0]" :class="['inline-flex h-8 w-8 items-center justify-center rounded-full', rankBadge(rankOf(salesPage, i))[0]]" role="img" :aria-label="`Hạng ${rankOf(salesPage, i)}`"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ rankBadge(rankOf(salesPage, i))[1] }}</span></span>
                            <span v-else class="font-mono font-semibold text-on-surface-variant">{{ rankOf(salesPage, i) }}</span>
                        </td>
                        <td>
                            <div class="flex items-center gap-sm">
                                <UiAvatar :name="item.name" size="sm" />
                                <div>
                                    <p class="font-semibold text-on-surface">{{ item.name }}</p>
                                    <p class="font-caption text-caption text-on-surface-variant">{{ item.tier_name }} · {{ item.deals }} HV mới đóng phí</p>
                                </div>
                            </div>
                        </td>
                        <td>{{ item.branch }}</td>
                        <td class="text-right font-mono">{{ item.closed }}</td>
                        <td class="text-right">
                            <span class="font-mono">{{ trimNumber(item.percent, 2, '.', ',') }}%</span>
                            <span v-if="item.to_next" class="block font-caption text-caption text-on-surface-variant">còn {{ item.to_next }} HS → {{ trimNumber(item.next_percent, 2, '.', ',') }}%</span>
                        </td>
                        <td class="text-right font-mono">{{ money(item.revenue) }}</td>
                        <td class="text-right font-mono">{{ money(item.base) }}</td>
                        <td class="text-right font-mono font-bold text-primary">{{ money(item.commission) }}</td>
                    </tr>
                    <tr v-if="!salesPage.data.length">
                        <td colspan="8"><UiEmptyState icon="leaderboard" title="Chưa có người phụ trách nào trong phạm vi lọc" /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="salesPage" unit="nhân viên" :options="[]" page-name="sales_page" /></template>
        </UiDataTable>
    </div>
</template>
