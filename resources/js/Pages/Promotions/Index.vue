<script setup>
/**
 * Danh mục ưu đãi học phí (CRM → Ưu đãi).
 * Tab "Danh mục": ưu đãi dùng lại khi chốt khách / lập phiếu thu (đánh dấu Mặc định để tự chọn sẵn).
 * Tab "Ưu đãi riêng": ưu đãi tạo cho từng khách ở ca đặc biệt, kèm lý do và người tạo.
 * Thêm / Sửa mở modal (Form.vue); không xóa, bấm trạng thái để ngừng / bật lại.
 */
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Ưu đãi học phí' } });

const props = defineProps({
    promotions: { type: Object, required: true },
    tab: { type: String, default: 'catalog' },
    counts: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const statusOptions = [
    { value: 'active', label: 'Đang hiệu lực' },
    { value: 'default', label: 'Ưu đãi mặc định' },
    { value: 'inactive', label: 'Đã ngừng' },
];
const special = props.tab === 'special';
const tabUrl = (tab) => route('crm.promotions.index', tab === 'special' ? { tab } : {});
</script>

<template>
    <UiPageHeader title="Ưu đãi học phí" description="Chọn lại ưu đãi có sẵn khi chốt khách và lập phiếu thu. Ca đặc biệt tạo ưu đãi riêng ngay trong màn chốt, bắt buộc ghi lý do.">
        <template #actions>
            <UiButton icon="add" :href="route('crm.promotions.create')" modal="lg">Thêm ưu đãi</UiButton>
        </template>
    </UiPageHeader>

    <UiTabs class="mb-lg">
        <UiTab :href="tabUrl('catalog')" :active="!special" icon="redeem" :count="counts.catalog">Danh mục</UiTab>
        <UiTab :href="tabUrl('special')" :active="special" icon="person_pin" :count="counts.special">Ưu đãi riêng</UiTab>
    </UiTabs>

    <UiFilterBar :action="route('crm.promotions.index')" search="q" :placeholder="special ? 'Tìm theo tên, mã, lý do...' : 'Tìm theo tên, mã ưu đãi...'">
        <input v-if="special" type="hidden" name="tab" value="special" />
        <UiSelect name="status" label="Trạng thái" placeholder="Tất cả trạng thái" :value="filters.status" :options="special ? statusOptions.filter((o) => o.value !== 'default') : statusOptions" />
    </UiFilterBar>

    <UiDataTable min-width="880px">
        <table>
            <thead>
                <tr>
                    <th>Ưu đãi</th>
                    <th class="text-right">Mức giảm</th>
                    <th>Áp dụng cho</th>
                    <th v-if="special">Lý do · Người tạo</th>
                    <th v-else>Thời gian</th>
                    <th class="text-center">Đã dùng</th>
                    <th class="text-center">Trạng thái</th>
                    <th class="text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in promotions.data" :key="p.id">
                    <td>
                        <div class="flex flex-wrap items-center gap-xs">
                            <span class="font-semibold text-on-surface">{{ p.name }}</span>
                            <UiBadge v-if="p.is_default" color="primary" :dot="false">Mặc định</UiBadge>
                        </div>
                        <div class="font-code text-xs text-on-surface-variant">{{ p.code }}</div>
                        <div v-if="p.description" class="max-w-xs truncate text-xs text-on-surface-subtle" :title="p.description">{{ p.description }}</div>
                    </td>
                    <td class="text-right">
                        <span class="font-code font-semibold text-on-surface">{{ p.value_label }}</span>
                        <div v-if="p.max_discount_amount" class="text-xs text-on-surface-variant">tối đa {{ formatMoney(p.max_discount_amount) }}</div>
                    </td>
                    <td class="text-on-surface-variant">{{ p.scope_label }}</td>
                    <td v-if="special" class="max-w-xs">
                        <div class="line-clamp-2 text-on-surface" :title="p.reason">{{ p.reason || '—' }}</div>
                        <div class="text-xs text-on-surface-variant">{{ p.creator_name ?? '—' }} · {{ p.created_at }}</div>
                    </td>
                    <td v-else class="whitespace-nowrap text-on-surface-variant">{{ p.period_label }}</td>
                    <td class="text-center font-code">{{ p.usage_label }}</td>
                    <td class="text-center">
                        <UiForm :action="route('crm.promotions.toggle', p.id)" method="post" back class="inline">
                            <button type="submit" class="rounded-full" :title="p.is_active ? 'Bấm để ngừng áp dụng' : 'Bấm để bật lại'">
                                <UiBadge :color="!p.is_active ? 'neutral' : p.is_available ? 'success' : 'warning'">
                                    {{ !p.is_active ? 'Đã ngừng' : p.is_available ? 'Đang áp dụng' : 'Hết hạn / hết lượt' }}
                                </UiBadge>
                            </button>
                        </UiForm>
                    </td>
                    <td class="text-right">
                        <UiButton size="sm" variant="ghost" icon="edit" :href="route('crm.promotions.edit', p.id)" modal="lg" title="Sửa ưu đãi" :aria-label="`Sửa ${p.name}`" />
                    </td>
                </tr>
                <tr v-if="!promotions.data.length">
                    <td colspan="7">
                        <UiEmptyState icon="redeem" :title="special ? 'Chưa có ưu đãi riêng nào.' : 'Chưa có ưu đãi nào trong danh mục.'">
                            <UiButton v-if="!special" variant="ghost" size="sm" :href="route('crm.promotions.create')" modal="lg">+ Thêm ưu đãi</UiButton>
                        </UiEmptyState>
                    </td>
                </tr>
            </tbody>
        </table>

        <template #footer><UiPagination :paginator="promotions" /></template>
    </UiDataTable>
</template>
