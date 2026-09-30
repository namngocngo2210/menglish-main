<script setup>
/**
 * Trường form Thêm / Sửa danh mục — dùng chung cho modal sửa mở sẵn (?edit=), trang thêm riêng và modal (Form.vue).
 * typeSelect: cho chọn nhóm (mặc định khi thêm mới); showTypeLabel: hiện tên nhóm khi không cho chọn (trong modal).
 */
import { computed } from 'vue';

const props = defineProps({
    category: { type: Object, required: true },
    typeLabels: { type: Array, default: () => [] },
    typeSelect: { type: Boolean, default: null },
    showTypeLabel: { type: Boolean, default: false },
    nextOrder: { type: Number, default: null },
    idPrefix: { type: String, default: null },
});
const exists = computed(() => !!props.category.id);
const withType = computed(() => props.typeSelect ?? !exists.value);
const fid = (field) => (props.idPrefix ? props.idPrefix + field : undefined);
const typeLabel = computed(() => props.typeLabels.find((t) => t.value === props.category.type)?.label ?? props.category.type);
</script>

<template>
    <UiSelect v-if="withType" name="type" :id="fid('type')" label="Nhóm danh mục" required :options="typeLabels" :value="category.type" />
    <template v-else>
        <input type="hidden" name="type" :value="category.type" />
        <p v-if="showTypeLabel" class="font-body-small text-body-small text-on-surface-variant">Nhóm: <span class="font-semibold text-on-surface">{{ typeLabel }}</span></p>
    </template>
    <UiInput name="code" :id="fid('code')" label="Mã danh mục" required maxlength="50" :value="category.code" class="font-code" :placeholder="exists ? undefined : `Vd: ${category.code}`" />
    <UiInput name="name" :id="fid('name')" label="Tên danh mục" required maxlength="255" :value="category.name" placeholder="Nhập tên..." />
    <UiInput type="number" name="sort_order" :id="fid('sort_order')" label="Thứ tự hiển thị" min="0" :value="category.sort_order ?? nextOrder" :placeholder="nextOrder !== null ? String(nextOrder) : 'Để trống = cuối danh sách'" />
    <label class="flex items-center gap-sm font-body-small text-body-small">
        <input type="hidden" name="is_active" value="0" />
        <input type="checkbox" name="is_active" value="1" :checked="exists ? category.is_active : true" class="rounded border-outline-variant text-primary-container focus:ring-primary-container" />
        Đang sử dụng
    </label>
</template>
