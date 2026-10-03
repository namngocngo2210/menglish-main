<script setup>
/**
 * Tồn kho sách / hàng hóa theo chi nhánh. Lọc chi nhánh (trong phạm vi), nhóm hàng, tìm tên; chip nhanh Âm kho / Hết
 * hàng / Sắp hết. Bấm dòng → modal nhật ký xuất nhập của mặt hàng. Nhập kho / Kiểm kê mở modal (StockForm.vue).
 * Xuất kho tự động khi phiếu thu được duyệt, hoàn kho khi hủy hóa đơn.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { openRemoteModal } from '@/lib/remoteModal';
import { route } from '@/lib/route';
import StockMovements from './StockMovements.vue';

defineOptions({ layout: { title: 'Tồn kho theo chi nhánh' } });

const props = defineProps({
    branches: { type: Array, default: () => [] },
    branch: { type: Object, default: null },
    rows: { type: Array, default: () => [] },
    counts: { type: Object, default: () => ({}) },
    legacyTotal: { type: Number, default: 0 },
    movements: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    lowThreshold: { type: Number, default: 5 },
    canManage: { type: Boolean, default: false },
});

const LEVELS = {
    negative: { label: 'Âm kho', color: 'error', icon: 'error' },
    out: { label: 'Hết hàng', color: 'error', icon: 'remove_shopping_cart' },
    low: { label: 'Sắp hết', color: 'warning', icon: 'warning' },
    ok: { label: 'Còn hàng', color: 'success', icon: 'check_circle' },
};
const chips = computed(() => [
    { level: null, label: 'Tất cả' },
    { level: 'negative', label: `Âm kho (${props.counts.negative ?? 0})` },
    { level: 'out', label: `Hết hàng (${props.counts.out ?? 0})` },
    { level: 'low', label: `Sắp hết (${props.counts.low ?? 0})` },
]);
const chipUrl = (level) =>
    route('merchandise.stock.index', Object.fromEntries(Object.entries({ branch_id: props.branch?.id, q: props.filters.q, category: props.filters.category, level }).filter(([, v]) => v)));
const chipClass = (active) => (active ? 'bg-primary-container text-white font-bold' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high');
const qtyClass = (level) => (level === 'negative' || level === 'out' ? 'text-error' : level === 'low' ? 'text-warning' : 'text-on-surface');
const formUrl = (type, item = null) => route('merchandise.stock.create', Object.fromEntries(Object.entries({ type, branch_id: props.branch?.id, item: item?.id }).filter(([, v]) => v)));
const openHistory = (row) => openRemoteModal(route('merchandise.stock.history', { item: row.id, branch_id: props.branch.id }), { size: '3xl' });
</script>

<template>
    <UiPageHeader title="Tồn kho theo chi nhánh" icon="warehouse" description="Số sách / hàng hóa còn ở từng chi nhánh. Tự trừ khi phiếu thu kèm sách được duyệt, tự hoàn khi hủy hóa đơn.">
        <template #actions>
            <UiButton v-if="can('system_category.manage')" variant="secondary" icon="inventory_2" :href="route('merchandise.index')">Danh mục hàng hóa</UiButton>
            <template v-if="canManage && branch">
                <UiButton variant="secondary" icon="fact_check" :href="formUrl('count')" modal="md">Kiểm kê</UiButton>
                <UiButton icon="add_box" :href="formUrl('import')" modal="md">Nhập kho</UiButton>
            </template>
        </template>
    </UiPageHeader>

    <UiEmptyState v-if="!branch" icon="warehouse" title="Tài khoản chưa được gán chi nhánh" description="Liên hệ Admin gán chi nhánh để xem tồn kho." />

    <div v-else class="space-y-lg">
        <UiAlert v-if="legacyTotal > 0" type="warning" title="Còn tồn cũ chưa phân chi nhánh">
            Trước đây tồn kho là một số chung cho cả hệ thống. Còn <strong class="font-code">{{ legacyTotal }}</strong> sản phẩm chưa biết nằm ở chi nhánh nào: khi
            <strong>Nhập kho</strong>, tick “Lấy từ tồn cũ chưa phân chi nhánh” để chuyển về đúng chi nhánh.
        </UiAlert>

        <UiFilterBar search="q" placeholder="Tìm mã hoặc tên sách, đồng phục..." class="!mb-0" :keep="['level']">
            <template #quick>
                <div class="flex flex-wrap gap-1.5 text-xs">
                    <Link v-for="chip in chips" :key="chip.level ?? 'all'" :href="chipUrl(chip.level)" :class="['rounded-lg px-3 py-1 font-medium transition', chipClass(filters.level === chip.level)]">{{ chip.label }}</Link>
                </div>
            </template>
            <UiSelect v-if="branches.length > 1" name="branch_id" label="Chi nhánh" :options="branches" :value="String(branch.id)" />
            <div v-else class="flex items-center gap-xs rounded-lg border border-outline-variant bg-surface-container-low px-md py-sm font-body-medium text-body-medium text-on-surface" title="Chi nhánh của bạn">
                <span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">lock</span>{{ branch.name }}
            </div>
            <UiSelect name="category" label="Nhóm hàng" :options="categories" placeholder="Tất cả nhóm hàng" />
        </UiFilterBar>

        <UiDataTable min-width="720px">
            <table>
                <thead>
                    <tr>
                        <th>Mặt hàng</th>
                        <th>Nhóm</th>
                        <th class="text-right">Tồn tại {{ branch.name }}</th>
                        <th>Tình trạng</th>
                        <th v-if="canManage" class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id" class="cursor-pointer hover:bg-surface-container-low" title="Xem nhật ký xuất nhập" @click="openHistory(row)">
                        <td>
                            <div class="font-semibold text-on-surface">{{ row.name }}</div>
                            <div class="font-code text-xs text-on-surface-variant">
                                {{ row.code }}<span v-if="!row.is_active"> · ngừng bán</span><span v-if="row.legacy > 0"> · tồn cũ chưa phân: {{ row.legacy }}</span>
                            </div>
                        </td>
                        <td>
                            <span class="inline-flex items-center gap-1 text-on-surface-variant"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ row.category_icon }}</span>{{ row.category_label }}</span>
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <span :class="['font-code text-base font-bold', qtyClass(row.level)]">{{ row.quantity }}</span>
                            <span class="ml-1 text-xs text-on-surface-variant">{{ row.unit }}</span>
                        </td>
                        <td>
                            <UiBadge :color="LEVELS[row.level].color" :dot="false">
                                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">{{ LEVELS[row.level].icon }}</span>{{ LEVELS[row.level].label }}
                            </UiBadge>
                        </td>
                        <td v-if="canManage" class="whitespace-nowrap text-right" @click.stop>
                            <div class="inline-flex items-center gap-xs">
                                <UiButton variant="ghost" size="sm" icon="add_box" :href="formUrl('import', row)" modal="md" :aria-label="`Nhập kho ${row.name}`">Nhập</UiButton>
                                <UiButton variant="ghost" size="sm" icon="fact_check" :href="formUrl('count', row)" modal="md" :aria-label="`Kiểm kê ${row.name}`">Kiểm kê</UiButton>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td :colspan="canManage ? 5 : 4">
                            <UiEmptyState icon="inventory_2" title="Không có mặt hàng nào khớp bộ lọc" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
        <p class="font-body-small text-body-small text-on-surface-variant">Sắp hết: còn từ {{ lowThreshold }} trở xuống. Âm kho: đã giao cho học viên nhiều hơn số đã nhập, cần nhập kho bổ sung hoặc kiểm kê lại.</p>

        <section class="space-y-sm">
            <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary" aria-hidden="true">history</span>Xuất nhập gần đây · {{ branch.name }}</h2>
            <StockMovements :movements="movements" show-item />
        </section>
    </div>
</template>
