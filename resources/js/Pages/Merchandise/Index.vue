<script setup>
/**
 * Danh mục Hàng hóa & Vật phẩm: thẻ số liệu, lọc nhanh theo nhóm, bảng hàng hóa.
 * Thêm/Sửa mở modal (Form.vue); Xóa qua hộp xác nhận chung cho mọi dòng; bấm trạng thái để bật/tắt kinh doanh.
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { formatNumber } from '@/lib/format';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Danh mục Hàng hóa & Vật phẩm' } });

const props = defineProps({
    items: { type: Object, required: true },
    metrics: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    selectedCategory: { type: String, default: null },
    selectedStatus: { type: String, default: null },
    search: { type: String, default: null },
});

// number_format() của PHP (dấu phẩy hàng nghìn) như trang cũ.
const count = (value) => formatNumber(value, 0, '.', ',');
const statusOptions = [
    { value: 'active', label: 'Đang kinh doanh' },
    { value: 'inactive', label: 'Tạm ngừng' },
];
const pillUrl = (category) => route('merchandise.index', Object.fromEntries(Object.entries({ q: props.search, category, status: props.selectedStatus }).filter(([, v]) => v)));
const pillClass = (active) => (active ? 'bg-primary-container text-white font-bold' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high');

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
    <UiPageHeader title="Danh mục Hàng hóa & Vật phẩm" icon="inventory_2">
        <template #actions>
            <UiButton v-if="can('merchandise_stock.view')" variant="secondary" icon="warehouse" :href="route('merchandise.stock.index')">Tồn kho theo chi nhánh</UiButton>
            <UiButton icon="add_circle" :href="route('merchandise.create')" modal="xl">Thêm Hàng hóa mới</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <!-- Thẻ số liệu -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <UiStatCard label="Tổng mặt hàng" :value="count(metrics.total)" icon="category" :hint="metrics.active + ' đang bán'" />
            <UiStatCard label="Sách & Giáo trình" :value="count(metrics.books)" tone="secondary" icon="menu_book" hint="Giáo trình + Bài tập" />
            <UiStatCard label="Đồng phục & Balo" :value="count(metrics.uniforms)" tone="success" icon="apparel" hint="Áo polo, balo, túi" />
            <UiStatCard label="Tổng tồn kho" :value="count(metrics.total_stock)" tone="warning" icon="warehouse" hint="Cộng mọi chi nhánh" />
            <UiStatCard label="Tích hợp Hoá đơn" value="Bóc tách tự động" tone="secondary" icon="receipt_long" hint="Đồng bộ Closing Wizard" class="col-span-2 sm:col-span-1" />
        </div>

        <!-- Bộ lọc & tìm kiếm -->
        <UiFilterBar :action="route('merchandise.index')" search="q" placeholder="Tìm kiếm theo mã hàng, tên sách, đồng phục..." class="!mb-0">
            <template #quick>
                <div class="flex flex-wrap gap-1.5 text-xs">
                    <Link :href="pillUrl(null)" :class="['rounded-lg px-3 py-1 font-medium transition', pillClass(!selectedCategory)]">Tất cả ({{ metrics.total }})</Link>
                    <Link v-for="cat in categories" :key="cat.value" :href="pillUrl(cat.value)" :class="['flex items-center gap-1.5 rounded-lg px-3 py-1 font-medium transition', pillClass(selectedCategory === cat.value)]">
                        <span class="material-symbols-outlined text-sm">{{ cat.icon }}</span>
                        <span>{{ cat.label }}</span>
                    </Link>
                </div>
            </template>

            <UiSelect name="category" label="Nhóm hàng" placeholder="Tất cả nhóm hàng" :value="selectedCategory" :options="categories" @change="autoSubmit" />
            <UiSelect name="status" label="Trạng thái" placeholder="Tất cả trạng thái" :value="selectedStatus" :options="statusOptions" @change="autoSubmit" />
        </UiFilterBar>

        <!-- Bảng hàng hóa -->
        <UiDataTable id="merchandise-list">
            <table class="text-xs">
                <thead>
                    <tr>
                        <th>Mã hàng</th>
                        <th>Tên hàng hóa &amp; Vật phẩm</th>
                        <th>Nhóm phân loại</th>
                        <th class="text-center">ĐVT</th>
                        <th class="text-right">Đơn giá niêm yết</th>
                        <th class="text-center">Tồn (mọi chi nhánh)</th>
                        <th class="text-center">Trạng thái</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in items.data" :key="item.id">
                        <td class="font-mono font-bold">
                            <span class="rounded border border-surface-container-highest/80 bg-surface-container px-2 py-0.5">{{ item.code }}</span>
                        </td>
                        <td>
                            <div class="font-bold text-on-surface">{{ item.name }}</div>
                            <div v-if="item.description" class="max-w-sm truncate text-xs text-on-surface-subtle">{{ item.description }}</div>
                        </td>
                        <td>
                            <UiBadge :dot="false" pill>
                                <span class="material-symbols-outlined text-[13px]">{{ item.category_icon }}</span>
                                <span>{{ item.category_label }}</span>
                            </UiBadge>
                        </td>
                        <td class="text-center font-medium text-on-surface-variant">{{ item.unit }}</td>
                        <td class="text-right font-mono text-sm font-black text-primary-container">{{ formatMoney(item.price) }}</td>
                        <td class="text-center">
                            <span :class="['font-mono font-bold', item.stock_quantity <= 10 ? 'rounded bg-error/10 px-2 py-0.5 text-error' : 'text-on-surface-variant']">{{ count(item.stock_quantity) }}</span>
                        </td>
                        <td class="text-center">
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
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                <UiButton size="sm" variant="ghost" icon="edit" :href="route('merchandise.edit', item.id)" modal="xl" title="Sửa mặt hàng" :aria-label="`Sửa ${item.name}`" />
                                <UiButton size="sm" variant="danger-text" icon="delete" title="Xóa mặt hàng" :aria-label="`Xóa ${item.name}`" @click="confirmDelete(item)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!items.data.length">
                        <td colspan="8">
                            <UiEmptyState icon="inventory_2" title="Không tìm thấy hàng hóa / vật phẩm nào phù hợp điều kiện lọc.">
                                <UiButton variant="ghost" size="sm" :href="route('merchandise.create')" modal="xl">+ Thêm hàng hóa mới ngay</UiButton>
                            </UiEmptyState>
                        </td>
                    </tr>
                </tbody>
            </table>

            <template #footer><UiPagination :paginator="items" /></template>
        </UiDataTable>

        <!-- Xác nhận xóa (dùng chung cho mọi dòng; url/tên lấy từ nút Xóa) -->
        <UiModal :show="delOpen" title="Xóa mặt hàng?" max-width="md" @close="delOpen = false">
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
