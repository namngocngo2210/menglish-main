<script setup>
/** Bảng nhật ký xuất nhập kho (dùng ở trang Tồn kho và modal lịch sử một mặt hàng). */
defineProps({
    movements: { type: Array, default: () => [] },
    showItem: { type: Boolean, default: false },
});

const TYPE_COLORS = { import: 'success', opening: 'success', return: 'secondary', sale: 'warning', count: 'neutral' };
</script>

<template>
    <UiDataTable min-width="640px">
        <table>
            <thead>
                <tr>
                    <th>Thời gian</th>
                    <th v-if="showItem">Mặt hàng</th>
                    <th>Loại</th>
                    <th class="text-right">SL</th>
                    <th class="text-right">Tồn sau</th>
                    <th>Chứng từ / Ghi chú</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="m in movements" :key="m.id">
                    <td class="whitespace-nowrap font-code text-xs">{{ m.date }}</td>
                    <td v-if="showItem">{{ m.item_name }}</td>
                    <td><UiBadge :color="TYPE_COLORS[m.type] ?? 'neutral'" :dot="false">{{ m.type_label }}</UiBadge></td>
                    <td :class="['whitespace-nowrap text-right font-code font-bold', m.change < 0 ? 'text-error' : 'text-tertiary']">{{ m.change > 0 ? '+' + m.change : m.change }}</td>
                    <td :class="['text-right font-code', m.balance < 0 ? 'text-error font-bold' : '']">{{ m.balance }}</td>
                    <td class="text-xs">
                        <div v-if="m.receipt_number" class="font-code text-on-surface">{{ m.receipt_number }}</div>
                        <div v-if="m.note" class="text-on-surface-variant">{{ m.note }}</div>
                        <div v-if="m.user_name" class="text-on-surface-subtle">{{ m.user_name }}</div>
                    </td>
                </tr>
                <tr v-if="!movements.length">
                    <td :colspan="showItem ? 6 : 5">
                        <UiEmptyState icon="history" title="Chưa có lần xuất nhập nào" />
                    </td>
                </tr>
            </tbody>
        </table>
    </UiDataTable>
</template>
