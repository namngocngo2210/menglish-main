<script setup>
/**
 * Hàng hóa & tồn kho: danh mục Hàng hóa & Vật phẩm và tồn theo chi nhánh trên cùng một bảng (gộp 27/10/2026).
 * - Admin (canCatalog): thêm / sửa / xóa / bật tắt kinh doanh, thấy thêm cột tổng tồn mọi chi nhánh.
 * - Học vụ / Quản lý cơ sở / Kế toán: xem giá, tồn chi nhánh mình; Nhập kho / Kiểm kê (canManageStock) mở modal StockForm.vue.
 * Bấm dòng → modal nhật ký xuất nhập của mặt hàng tại chi nhánh đang xem. Xuất kho tự động khi phiếu thu được duyệt,
 * hoàn kho khi hủy hóa đơn.
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { formatNumber } from '@/lib/format';
import { openRemoteModal } from '@/lib/remoteModal';
import { route } from '@/lib/route';
import StockMovements from './StockMovements.vue';

defineOptions({ layout: { title: 'Hàng hóa & tồn kho' } });

const props = defineProps({
    items: { type: Object, required: true },
    metrics: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    legacyTotal: { type: Number, default: 0 },
    branches: { type: Array, default: () => [] },
    branch: { type: Object, default: null },
    movements: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    lowThreshold: { type: Number, default: 5 },
    canCatalog: { type: Boolean, default: false },
    canManageStock: { type: Boolean, default: false },
});

// number_format() của PHP (dấu phẩy hàng nghìn) như trang cũ.
const count = (value) => formatNumber(value, 0, '.', ',');
const statusOptions = [
    { value: 'active', label: 'Đang kinh doanh' },
    { value: 'inactive', label: 'Tạm ngừng' },
];
const LEVELS = {
    negative: { label: 'Âm kho', color: 'error', icon: 'error' },
    out: { label: 'Hết hàng', color: 'error', icon: 'remove_shopping_cart' },
    low: { label: 'Sắp hết', color: 'warning', icon: 'warning' },
    ok: { label: 'Còn hàng', color: 'success', icon: 'check_circle' },
};
const levelChips = computed(() => [
    { level: 'negative', label: `Âm kho (${props.counts.negative ?? 0})` },
    { level: 'out', label: `Hết hàng (${props.counts.out ?? 0})` },
    { level: 'low', label: `Sắp hết (${props.counts.low ?? 0})` },
]);
const listUrl = (overrides) =>
    route(
        'merchandise.index',
        Object.fromEntries(
            Object.entries({ branch_id: props.branch?.id, q: props.filters.q, category: props.filters.category, status: props.filters.status, level: props.filters.level, ...overrides }).filter(([, v]) => v),
        ),
    );
const pillClass = (active) => (active ? 'bg-primary-container text-white font-bold' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high');
const qtyClass = (level) => (level === 'negative' || level === 'out' ? 'text-error' : level === 'low' ? 'text-warning' : 'text-on-surface');
const formUrl = (type, item = null) => route('merchandise.stock.create', Object.fromEntries(Object.entries({ type, branch_id: props.branch?.id, item: item?.id }).filter(([, v]) => v)));
const openHistory = (row) => props.branch && openRemoteModal(route('merchandise.stock.history', { item: row.id, branch_id: props.branch.id }), { size: '3xl' });
const columns = computed(() => 3 + (props.branch ? 1 : 0) + (props.canCatalog ? 2 : 0) + (props.canCatalog || props.canManageStock ? 1 : 0));

const del = ref({ url: '', name: '' });
const delOpen = ref(false);
const confirmDelete = (item) => {
    del.value = { url: route('merchandise.destroy', item.id), name: item.name };
    delOpen.value = true;
};
const autoSubmit = (event) => event.target.form?.requestSubmit();
const hasDel = computed(() => !!del.value.url);
</script>

<template>
    <UiPageHeader title="Hàng hóa & tồn kho" icon="inventory_2" description="Danh mục sách, đồng phục, vật phẩm và số tồn ở từng chi nhánh. Tồn tự trừ khi phiếu thu kèm sách được duyệt, tự hoàn khi hủy hóa đơn.">
        <template #actions>
            <template v-if="canManageStock && branch">
                <UiButton variant="secondary" icon="fact_check" :href="formUrl('count')" modal="md">Kiểm kê</UiButton>
                <UiButton :variant="canCatalog ? 'secondary' : 'primary'" icon="add_box" :href="formUrl('import')" modal="md">Nhập kho</UiButton>
            </template>
            <UiButton v-if="canCatalog" icon="add_circle" :href="route('merchandise.create')" modal="xl">Thêm Hàng hóa mới</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <!-- Thẻ số liệu -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <UiStatCard label="Tổng mặt hàng" :value="count(metrics.total)" icon="category" :hint="metrics.active + ' đang bán'" />
            <UiStatCard label="Sách & Giáo trình" :value="count(metrics.books)" tone="secondary" icon="menu_book" hint="Giáo trình + Bài tập" />
            <UiStatCard label="Đồng phục & Balo" :value="count(metrics.uniforms)" tone="success" icon="apparel" hint="Áo polo, balo, túi" />
            <UiStatCard v-if="branch" label="Tồn tại chi nhánh" :value="count(metrics.branch_stock)" tone="warning" icon="warehouse" :hint="branch.name" />
            <UiStatCard v-if="branch" label="Cần nhập thêm" :value="count(metrics.need_restock)" tone="error" icon="production_quantity_limits" hint="Âm kho, hết hoặc sắp hết" class="col-span-2 sm:col-span-1" />
        </div>

        <UiAlert v-if="branch && legacyTotal > 0" type="warning" title="Còn tồn cũ chưa phân chi nhánh">
            Trước đây tồn kho là một số chung cho cả hệ thống. Còn <strong class="font-code">{{ legacyTotal }}</strong> sản phẩm chưa biết nằm ở chi nhánh nào: khi
            <strong>Nhập kho</strong>, tick “Lấy từ tồn cũ chưa phân chi nhánh” để chuyển về đúng chi nhánh.
        </UiAlert>

        <!-- Bộ lọc & tìm kiếm -->
        <UiFilterBar :action="route('merchandise.index')" search="q" placeholder="Tìm kiếm theo mã hàng, tên sách, đồng phục..." class="!mb-0" :keep="['level']">
            <template #quick>
                <div class="flex flex-wrap gap-1.5 text-xs">
                    <Link :href="listUrl({ category: null })" :class="['rounded-lg px-3 py-1 font-medium transition', pillClass(!filters.category)]">Tất cả ({{ metrics.total }})</Link>
                    <Link v-for="cat in categories" :key="cat.value" :href="listUrl({ category: cat.value })" :class="['flex items-center gap-1.5 rounded-lg px-3 py-1 font-medium transition', pillClass(filters.category === cat.value)]">
                        <span class="material-symbols-outlined text-sm">{{ cat.icon }}</span>
                        <span>{{ cat.label }}</span>
                    </Link>
                    <template v-if="branch">
                        <span class="mx-1 self-center text-on-surface-variant" aria-hidden="true">|</span>
                        <Link v-for="chip in levelChips" :key="chip.level" :href="listUrl({ level: filters.level === chip.level ? null : chip.level, page: null })" :class="['rounded-lg px-3 py-1 font-medium transition', pillClass(filters.level === chip.level)]">{{ chip.label }}</Link>
                    </template>
                </div>
            </template>

            <template v-if="branch">
                <UiSelect v-if="branches.length > 1" name="branch_id" label="Chi nhánh" :options="branches" :value="String(branch.id)" @change="autoSubmit" />
                <div v-else class="flex items-center gap-xs rounded-lg border border-outline-variant bg-surface-container-low px-md py-sm font-body-medium text-body-medium text-on-surface" title="Chi nhánh của bạn">
                    <span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">lock</span>{{ branch.name }}
                </div>
            </template>
            <UiSelect name="category" label="Nhóm hàng" placeholder="Tất cả nhóm hàng" :value="filters.category" :options="categories" @change="autoSubmit" />
            <UiSelect v-if="canCatalog" name="status" label="Trạng thái" placeholder="Tất cả trạng thái" :value="filters.status" :options="statusOptions" @change="autoSubmit" />
        </UiFilterBar>

        <!-- Bảng hàng hóa + tồn chi nhánh -->
        <UiDataTable id="merchandise-list">
            <table class="text-xs">
                <thead>
                    <tr>
                        <th>Hàng hóa &amp; Vật phẩm</th>
                        <th>Nhóm</th>
                        <th class="text-right">Đơn giá</th>
                        <th v-if="branch" class="text-right">Tồn chi nhánh</th>
                        <th v-if="canCatalog" class="text-center" title="Cộng mọi chi nhánh + tồn cũ chưa phân">Tổng tồn</th>
                        <th v-if="canCatalog" class="text-center">Trạng thái</th>
                        <th v-if="canCatalog || canManageStock" class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in items.data"
                        :key="item.id"
                        :class="branch ? 'cursor-pointer hover:bg-surface-container-low' : ''"
                        :title="branch ? 'Xem nhật ký xuất nhập' : undefined"
                        :tabindex="branch ? 0 : undefined"
                        @click="openHistory(item)"
                        @keydown.enter.self="openHistory(item)"
                    >
                        <td class="min-w-[220px]">
                            <div class="font-bold text-on-surface">{{ item.name }}</div>
                            <div class="font-mono text-xs text-on-surface-variant">
                                {{ item.code }} · {{ item.unit }}<span v-if="!canCatalog && !item.is_active"> · ngừng bán</span><span v-if="branch && item.legacy > 0"> · tồn cũ chưa phân: {{ item.legacy }}</span>
                            </div>
                            <div v-if="item.description" class="max-w-[260px] truncate text-xs text-on-surface-subtle">{{ item.description }}</div>
                        </td>
                        <td>
                            <UiBadge :dot="false" pill>
                                <span class="material-symbols-outlined text-[13px]">{{ item.category_icon }}</span>
                                <span>{{ item.category_label }}</span>
                            </UiBadge>
                        </td>
                        <td class="whitespace-nowrap text-right font-mono text-sm font-black text-primary-container">{{ formatMoney(item.price) }}</td>
                        <td v-if="branch" class="whitespace-nowrap text-right">
                            <div :class="['font-code text-sm font-bold', qtyClass(item.level)]">{{ count(item.quantity) }}</div>
                            <UiBadge v-if="item.level !== 'ok'" :color="LEVELS[item.level].color" :dot="false">
                                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">{{ LEVELS[item.level].icon }}</span>{{ LEVELS[item.level].label }}
                            </UiBadge>
                        </td>
                        <td v-if="canCatalog" class="text-center">
                            <span class="font-mono font-bold text-on-surface-variant">{{ count(item.total_stock) }}</span>
                        </td>
                        <td v-if="canCatalog" class="text-center" @click.stop>
                            <UiForm :action="route('merchandise.toggle', item.id)" method="post" class="inline">
                                <button
                                    type="submit"
                                    :class="['inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-bold transition', item.is_active ? 'bg-tertiary/10 text-on-tertiary-container hover:bg-tertiary/20' : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest']"
                                    title="Bấm để bật/tắt kinh doanh"
                                >
                                    <span :class="['h-1.5 w-1.5 rounded-full', item.is_active ? 'bg-tertiary' : 'bg-on-surface-variant']"></span>
                                    <span>{{ item.is_active ? 'Đang bán' : 'Tạm ngừng' }}</span>
                                </button>
                            </UiForm>
                        </td>
                        <td v-if="canCatalog || canManageStock" class="whitespace-nowrap text-right" @click.stop>
                            <div class="flex items-center justify-end gap-1">
                                <template v-if="canManageStock && branch">
                                    <UiButton size="sm" variant="ghost" icon="add_box" :href="formUrl('import', item)" modal="md" title="Nhập kho" :aria-label="`Nhập kho ${item.name}`" />
                                    <UiButton size="sm" variant="ghost" icon="fact_check" :href="formUrl('count', item)" modal="md" title="Kiểm kê" :aria-label="`Kiểm kê ${item.name}`" />
                                </template>
                                <template v-if="canCatalog">
                                    <UiButton size="sm" variant="ghost" icon="edit" :href="route('merchandise.edit', item.id)" modal="xl" title="Sửa mặt hàng" :aria-label="`Sửa ${item.name}`" />
                                    <UiButton size="sm" variant="danger-text" icon="delete" title="Xóa mặt hàng" :aria-label="`Xóa ${item.name}`" @click="confirmDelete(item)" />
                                </template>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!items.data.length">
                        <td :colspan="columns">
                            <UiEmptyState icon="inventory_2" title="Không tìm thấy hàng hóa / vật phẩm nào phù hợp điều kiện lọc.">
                                <UiButton v-if="canCatalog" variant="ghost" size="sm" :href="route('merchandise.create')" modal="xl">+ Thêm hàng hóa mới ngay</UiButton>
                            </UiEmptyState>
                        </td>
                    </tr>
                </tbody>
            </table>

            <template #footer><UiPagination :paginator="items" /></template>
        </UiDataTable>
        <p v-if="branch" class="font-body-small text-body-small text-on-surface-variant">
            Bấm một dòng để xem nhật ký xuất nhập. Sắp hết: còn từ {{ lowThreshold }} trở xuống. Âm kho: đã giao cho học viên nhiều hơn số đã nhập, cần nhập kho bổ sung hoặc kiểm kê lại.
        </p>

        <section v-if="branch" class="space-y-sm">
            <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary" aria-hidden="true">history</span>Xuất nhập gần đây · {{ branch.name }}</h2>
            <StockMovements :movements="movements" show-item />
        </section>

        <!-- Xác nhận xóa (dùng chung cho mọi dòng; url/tên lấy từ nút Xóa) -->
        <UiModal v-if="canCatalog" :show="delOpen" title="Xóa mặt hàng?" max-width="md" @close="delOpen = false">
            <p>Xóa mặt hàng <strong class="font-semibold">{{ del.name }}</strong>?</p>
            <p class="mt-xs font-body-small text-body-small text-on-surface-variant">Mặt hàng được chuyển vào thùng rác, không còn chọn được khi lập hóa đơn.</p>
            <UiForm v-if="hasDel" id="delete-merchandise-form" :action="del.url" method="delete" back @success="delOpen = false" />
            <template #footer>
                <UiButton variant="secondary" @click="delOpen = false">Hủy</UiButton>
                <UiButton variant="danger" type="submit" form="delete-merchandise-form" icon="delete">Xóa</UiButton>
            </template>
        </UiModal>
    </div>
</template>
