<script setup>
/**
 * Order học liệu: giáo viên tạo (nút trên đầu trang → modal), Học vụ / Trưởng Học thuật xử lý.
 * Bấm dòng mở chi tiết trong modal (Show.vue) — nhận xử lý / hoàn thành / từ chối làm ở đó.
 */
defineOptions({ layout: { title: 'Order học liệu' } });

defineProps({
    orders: { type: Object, required: true },
    statuses: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    canCreate: { type: Boolean, default: false },
});

const statusColors = { pending: 'status-new', processing: 'status-progress', done: 'status-done', rejected: 'status-canceled', overdue: 'status-overdue' };
// Badge hạn xử lý (chỉ với order chưa xong): còn hạn / sắp hết hạn (< 24h) / quá hạn.
const deadline = {
    ok: { color: 'success', label: 'Còn hạn' },
    soon: { color: 'warning', label: 'Sắp hết hạn' },
    overdue: { color: 'error', label: 'Quá hạn' },
};
</script>

<template>
    <UiPageHeader title="Order học liệu" description="Đạo cụ, in ấn, học liệu GVNN và học liệu học thuật — Học vụ / Học thuật xử lý trước hạn.">
        <template v-if="canCreate" #actions>
            <UiButton icon="add" :href="route('material-orders.create')" modal="lg">Tạo order</UiButton>
        </template>
    </UiPageHeader>

    <UiFilterBar placeholder="Tìm mã, tên order..." :action="route('material-orders.index')">
        <UiSelect name="status" label="Trạng thái" :options="statuses" placeholder="Mọi trạng thái" />
        <UiSelect name="category" label="Loại order" :options="categories" placeholder="Mọi loại" />
        <UiSelect v-if="branches.length > 1" name="branch_id" label="Chi nhánh" :options="branches" placeholder="Mọi chi nhánh" />
    </UiFilterBar>

    <UiDataTable min-width="960px">
        <table>
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Loại</th>
                    <th>Người tạo</th>
                    <th>Ngày sử dụng</th>
                    <th>Hạn xử lý</th>
                    <th>Trạng thái</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="order in orders.data" :key="order.id" :data-href="route('material-orders.show', order.id)" data-modal="lg" class="cursor-pointer">
                    <td>
                        <div class="font-semibold text-on-surface">{{ order.title }}</div>
                        <div class="font-caption text-caption text-on-surface-variant">
                            <span class="font-code">{{ order.code }}</span>
                            <template v-if="order.branch"> · {{ order.branch }}</template>
                            <template v-if="order.class_name"> · {{ order.class_name }}</template>
                            <template v-if="order.quantity"> · SL {{ order.quantity }}</template>
                        </div>
                    </td>
                    <td><UiBadge color="secondary" :dot="false">{{ order.category_label }}</UiBadge></td>
                    <td>{{ order.requester }}</td>
                    <td class="whitespace-nowrap font-code text-body-small">{{ formatDate(order.use_date) }}</td>
                    <td>
                        <div class="flex flex-wrap items-center gap-xs">
                            <span class="whitespace-nowrap font-code text-body-small">{{ formatDate(order.due_at, 'd/m/Y H:i') }}</span>
                            <UiBadge v-if="order.deadline_state" :color="deadline[order.deadline_state].color">{{ deadline[order.deadline_state].label }}</UiBadge>
                            <UiBadge v-if="order.created_late" color="warning" :dot="false">Tạo trễ</UiBadge>
                        </div>
                    </td>
                    <td><UiBadge :color="statusColors[order.status] ?? 'neutral'">{{ order.status_label }}</UiBadge></td>
                </tr>
                <tr v-if="!orders.data.length">
                    <td colspan="6">
                        <UiEmptyState icon="inventory_2" title="Chưa có order học liệu" :description="canCreate ? 'Bấm “Tạo order” để đặt đạo cụ, in ấn hoặc học liệu.' : 'Không có order nào khớp bộ lọc.'" />
                    </td>
                </tr>
            </tbody>
        </table>
        <template #footer><UiPagination :paginator="orders" unit="order" /></template>
    </UiDataTable>
</template>
