<script setup>
/**
 * Danh sách ticket hỗ trợ: "Tạo Ticket Mới" mở modal 2xl (Create.vue), "Trao đổi" mở modal 3xl (Show.vue: hội thoại + trả lời).
 * Thao tác trong modal xong → danh sách tự cập nhật (giữ bộ lọc, trang hiện tại).
 */
defineOptions({ layout: { title: 'Yêu cầu hỗ trợ' } });

defineProps({
    tickets: { type: Object, required: true },
    stats: { type: Object, required: true },
});

const statusOptions = [
    { value: 'open', label: 'Mới tiếp nhận (Open)' },
    { value: 'in_progress', label: 'Đang xử lý (In Progress)' },
    { value: 'resolved', label: 'Đã giải quyết (Resolved)' },
    { value: 'closed', label: 'Đã đóng (Closed)' },
];
</script>

<template>
    <UiPageHeader title="Yêu cầu hỗ trợ" icon="confirmation_number">
        <template #actions>
            <UiButton v-if="can('support_ticket.update')" variant="secondary" icon="settings" :href="route('system-config.ticket-emails')">Cấu hình Email nhận</UiButton>
            <UiButton icon="add_circle" :href="route('tickets.create')" modal="2xl">Tạo Ticket Mới</UiButton>
        </template>
    </UiPageHeader>

    <div id="ticket-list" class="space-y-6">
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <UiStatCard label="Tổng Ticket" :value="stats.total" icon="inbox" />
            <UiStatCard label="Mới tiếp nhận" :value="stats.open" tone="warning" icon="hourglass_top" />
            <UiStatCard label="Đang xử lý" :value="stats.in_progress" tone="secondary" icon="pending" />
            <UiStatCard label="Đã giải quyết" :value="stats.resolved" tone="success" icon="check_circle" />
        </div>

        <UiFilterBar :action="route('tickets.index')" search="search" placeholder="Tìm theo mã TK, tiêu đề, nội dung..." class="!mb-0">
            <UiSelect name="status" label="Trạng thái" placeholder="Tất cả trạng thái" :options="statusOptions" />
        </UiFilterBar>

        <UiDataTable>
            <table class="text-xs">
                <thead>
                    <tr>
                        <th>Mã Ticket</th>
                        <th>Tiêu đề &amp; Danh mục</th>
                        <th>Mức độ ưu tiên</th>
                        <th>Người tạo</th>
                        <th>Người phụ trách</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Chi tiết</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="ticket in tickets.data" :key="ticket.id">
                        <td>
                            <span class="inline-flex items-center rounded-lg border border-primary-container/30 bg-primary-container/10 px-2.5 py-1 font-mono text-xs font-bold text-primary shadow-2xs">#{{ ticket.code }}</span>
                        </td>
                        <td>
                            <div class="font-bold text-on-surface">{{ ticket.title }}</div>
                            <div class="text-xs text-on-surface-subtle">{{ ticket.category_label }} · {{ formatDate(ticket.created_at, 'd/m/Y H:i') }}</div>
                        </td>
                        <td>
                            <span :class="['rounded-full border px-2.5 py-0.5 text-xs', ticket.priority_badge]">{{ ticket.priority.toUpperCase() }}</span>
                        </td>
                        <td class="font-medium">{{ ticket.creator ?? 'Hệ thống' }}</td>
                        <td class="font-medium">
                            <span v-if="ticket.assignee" class="font-bold text-primary">{{ ticket.assignee }}</span>
                            <span v-else class="italic text-on-surface-subtle">Chưa phân công</span>
                        </td>
                        <td>
                            <span :class="['rounded-full border px-2.5 py-1 text-xs font-bold', ticket.status_badge]">{{ ticket.status_label }}</span>
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <UiButton variant="ghost" size="sm" icon="forum" :href="route('tickets.show', ticket.id)" modal="3xl">Trao đổi</UiButton>
                        </td>
                    </tr>
                    <tr v-if="!tickets.data.length">
                        <td colspan="7"><UiEmptyState title="Chưa có ticket nào được tạo." /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="tickets" /></template>
        </UiDataTable>
    </div>
</template>
