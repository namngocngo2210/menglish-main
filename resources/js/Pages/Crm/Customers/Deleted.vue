<script setup>
/**
 * Khách đã xóa (chip "Đã xóa" của Danh sách): CRM không còn xóa khách; đây là các khách đã xóa trước đó — khôi phục bị chặn
 * nếu SĐT đã thuộc khách khác đang hoạt động (lỗi hiện ở khung lỗi đầu trang).
 */
import WorkspaceChips from '@/Components/WorkspaceChips.vue';
import CrmHeader from '@/Components/Crm/CrmHeader.vue';

defineOptions({ layout: { title: 'Khách đã xóa', workspaceTabs: false } });

defineProps({
    deletedCustomers: { type: Object, required: true },
    chipCounts: { type: Object, default: () => ({}) },
});
</script>

<template>
    <CrmHeader title="Khách đã xóa" />

    <div class="space-y-4">
        <UiAlert type="info">
            CRM không còn chức năng xóa khách (khách không theo nữa thì đánh "Thất bại" kèm lý do). Đây là các khách đã xóa trước đó: khôi phục sẽ bị chặn nếu SĐT đã thuộc khách khác đang hoạt động.
        </UiAlert>

        <UiFilterBar placeholder="Tìm tên, mã KH, SĐT khách đã xóa...">
            <template #quick><WorkspaceChips :counts="chipCounts" /></template>
        </UiFilterBar>

        <UiDataTable min-width="900px">
            <template #header>
                <h2 class="font-h3 text-h3 text-on-surface">Khách đã xóa</h2>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Khách hàng</th>
                        <th>Số điện thoại</th>
                        <th>Cơ sở</th>
                        <th>Giai đoạn khi xóa</th>
                        <th>Người phụ trách</th>
                        <th>Ngày xóa</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="dc in deletedCustomers.data" :key="dc.id">
                        <td>
                            <div class="font-semibold">{{ dc.name }}</div>
                            <div class="font-code text-caption text-on-surface-variant">{{ dc.short_code }}</div>
                        </td>
                        <td class="font-code">{{ dc.phone }}</td>
                        <td>{{ dc.branch ?? '—' }}</td>
                        <td><UiBadge :color="'stage-' + dc.stage">{{ dc.stage_label }}</UiBadge></td>
                        <td>{{ dc.assigned_user ?? 'Chưa phân công' }}</td>
                        <td class="font-code">{{ dc.deleted_at }}</td>
                        <td class="text-right">
                            <UiForm :action="route('crm.customers.restore', dc.id)" method="post" class="inline">
                                <UiButton type="submit" size="sm" variant="secondary" icon="restore_from_trash">Khôi phục</UiButton>
                            </UiForm>
                        </td>
                    </tr>
                    <tr v-if="!deletedCustomers.data.length">
                        <td colspan="7"><UiEmptyState icon="delete_sweep" title="Không có khách đã xóa" /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="deletedCustomers" unit="khách" /></template>
        </UiDataTable>
    </div>
</template>
