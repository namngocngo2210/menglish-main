<script setup>
/** Danh sách báo cáo định kỳ (staff_reports) dùng cho tab tuần / tháng của dashboard báo cáo đào tạo. */
defineProps({
    records: { type: Array, default: () => [] },
    empty: { type: String, required: true },
});
</script>

<template>
    <table>
        <thead>
            <tr>
                <th>Báo cáo</th>
                <th>Người gửi</th>
                <th>Loại</th>
                <th>Thời gian</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="record in records" :key="record.id">
                <td>
                    <span class="font-bold">{{ record.title }}</span>
                    <span v-if="record.record_code" class="block font-code text-xs text-on-surface-variant">{{ record.record_code }}</span>
                </td>
                <td class="font-semibold">{{ record.user ?? 'Chưa cập nhật' }}</td>
                <td>{{ record.status_label }}</td>
                <td class="font-code text-xs text-on-surface-variant">{{ record.created_at }}</td>
            </tr>
            <tr v-if="!records.length">
                <td colspan="4"><UiEmptyState :title="empty" /></td>
            </tr>
        </tbody>
    </table>
</template>
