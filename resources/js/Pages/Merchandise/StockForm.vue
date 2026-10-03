<script setup>
/**
 * Nhập kho / Kiểm kê một mặt hàng tại chi nhánh (modal; mở thẳng URL → trang đầy đủ).
 * Nhập kho: cộng thêm số lượng (có thể lấy từ "tồn cũ chưa phân chi nhánh"). Kiểm kê: nhập số tồn thực tế đếm được,
 * hệ thống ghi phần chênh lệch.
 */
import { computed, ref } from 'vue';

defineOptions({ layout: { title: 'Tồn kho theo chi nhánh' } });

const props = defineProps({
    asModal: { type: Boolean, default: false },
    type: { type: String, default: 'import' },
    itemId: { type: Number, default: null },
    branchId: { type: Number, default: null },
    items: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    stockByBranch: { type: Object, default: () => ({}) },
});

const mode = ref(props.type);
const item = ref(props.itemId ? String(props.itemId) : '');
const branch = ref(props.branchId ? String(props.branchId) : '');
const fromLegacy = ref(false);
const picked = computed(() => props.items.find((i) => i.value === item.value) ?? null);
const current = computed(() => (item.value && branch.value ? (props.stockByBranch[branch.value]?.[item.value] ?? 0) : null));
const isCount = computed(() => mode.value === 'count');
</script>

<template>
    <UiModalFrame
        :title="isCount ? 'Kiểm kê tồn kho' : 'Nhập kho'"
        :description="isCount ? 'Nhập số lượng thực tế đếm được, hệ thống tự ghi phần chênh lệch.' : 'Cộng thêm hàng về kho của chi nhánh.'"
        :action="route('merchandise.stock.store')"
        :back="route('merchandise.stock.index')"
        :submit-label="isCount ? 'Lưu kiểm kê' : 'Nhập kho'"
        :submit-icon="isCount ? 'fact_check' : 'add_box'"
    >
        <input type="hidden" name="type" :value="mode" />
        <div class="grid grid-cols-2 gap-sm" role="radiogroup" aria-label="Loại thao tác">
            <UiButton :variant="isCount ? 'secondary' : 'primary'" icon="add_box" @click="mode = 'import'">Nhập kho</UiButton>
            <UiButton :variant="isCount ? 'primary' : 'secondary'" icon="fact_check" @click="mode = 'count'">Kiểm kê</UiButton>
        </div>

        <UiSelect v-model="item" name="merchandise_item_id" label="Mặt hàng" required :options="items" placeholder="-- Chọn sách / hàng hóa --" searchable />
        <UiSelect v-model="branch" name="branch_id" label="Chi nhánh" required :options="branches" placeholder="-- Chọn chi nhánh --" />

        <p v-if="current !== null" class="font-body-medium text-body-medium text-on-surface-variant">
            Tồn hiện tại: <strong :class="['font-code', current <= 0 ? 'text-error' : 'text-on-surface']">{{ current }}</strong> {{ picked?.unit }}
        </p>

        <UiInput
            type="number"
            name="quantity"
            :label="isCount ? 'Số tồn thực tế đếm được' : 'Số lượng nhập thêm'"
            required
            :min="isCount ? 0 : 1"
            step="1"
            :suffix="picked?.unit"
            class="font-code"
        />

        <UiCheckbox v-if="!isCount && picked?.legacy > 0" v-model="fromLegacy" name="from_legacy" value="1" :label="`Lấy từ tồn cũ chưa phân chi nhánh (còn ${picked.legacy})`" />

        <UiTextarea name="note" label="Ghi chú" :rows="2" placeholder="VD: Nhập lô sách tháng 10, phiếu nhập NCC số..." />
    </UiModalFrame>
</template>
