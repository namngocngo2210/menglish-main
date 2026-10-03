<script setup>
/** Nhật ký xuất nhập của một mặt hàng tại một chi nhánh (modal từ trang Tồn kho; mở thẳng URL → trang đầy đủ). */
import StockMovements from './StockMovements.vue';

defineOptions({ layout: { title: 'Tồn kho theo chi nhánh' } });

defineProps({
    asModal: { type: Boolean, default: false },
    item: { type: Object, required: true },
    branch: { type: Object, required: true },
    quantity: { type: Number, default: 0 },
    movements: { type: Array, default: () => [] },
});
</script>

<template>
    <UiModalFrame :title="`${item.name} · ${branch.name}`" :description="`Mã ${item.code} · 100 lần xuất nhập gần nhất`" :submit-label="false" cancel="Đóng" :back="route('merchandise.stock.index', { branch_id: branch.id })" page-width="max-w-5xl">
        <div class="flex items-baseline gap-sm">
            <span class="font-body-medium text-body-medium text-on-surface-variant">Tồn hiện tại:</span>
            <span :class="['font-code text-2xl font-bold', quantity <= 0 ? 'text-error' : 'text-on-surface']">{{ quantity }}</span>
            <span class="text-on-surface-variant">{{ item.unit }}</span>
        </div>
        <StockMovements :movements="movements" />
    </UiModalFrame>
</template>
