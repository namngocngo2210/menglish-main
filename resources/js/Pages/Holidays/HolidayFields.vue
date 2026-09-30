<script setup>
/** Trường form Thêm/Sửa ngày nghỉ — dùng chung cho modal (Form.vue) và cột phải của trang đầy đủ (Index.vue). */
import { computed, ref } from 'vue';

const props = defineProps({
    holiday: { type: Object, default: null },
    branches: { type: Array, default: () => [] },
    selectedBranchIds: { type: Array, default: () => [] },
});
const picked = ref(props.selectedBranchIds.map(Number));
const scopeText = computed(() => (picked.value.length ? `Chi nhánh đã chọn: ${picked.value.length}` : 'Toàn hệ thống (Mặc định)'));
</script>

<template>
    <UiInput name="name" label="Tên ngày nghỉ" required placeholder="Ví dụ: Tết Trung Thu" :value="holiday?.name" />
    <div class="grid grid-cols-2 gap-sm">
        <UiDate name="start_date" label="Từ ngày" required :value="holiday?.start_date" />
        <UiDate name="end_date" label="Đến ngày" required :value="holiday?.end_date" />
    </div>
    <UiInput name="code" label="Mã ngày nghỉ" :value="holiday?.code" placeholder="Tự sinh HOL-YYYY-NNN" hint="Để trống để hệ thống tự sinh mã." />

    <UiField label="Phạm vi áp dụng" name="branch_ids">
        <div class="max-h-48 space-y-xs overflow-y-auto rounded-lg border border-outline-variant p-sm">
            <p :class="['font-body-small text-body-small font-semibold', picked.length ? 'text-on-surface-variant' : 'text-primary']">{{ scopeText }}</p>
            <UiCheckbox v-for="branch in branches" :key="branch.value" v-model="picked" name="branch_ids[]" :value="Number(branch.value)" :label="branch.label" />
        </div>
        <p class="font-caption text-caption text-on-surface-variant">* Để trống nếu muốn áp dụng cho tất cả chi nhánh.</p>
    </UiField>

    <div class="flex items-start gap-sm rounded-lg bg-tertiary-fixed/30 p-sm">
        <span class="material-symbols-outlined text-tertiary" aria-hidden="true">verified</span>
        <p class="font-caption text-caption text-on-tertiary-fixed-variant">Lịch nghỉ sẽ tự động cập nhật vào lịch học của các lớp liên quan: buổi trùng ngày nghỉ chuyển "Đã hủy" và được xếp 1 buổi học bù cuối lịch (buổi đã điểm danh giữ nguyên).</p>
    </div>
</template>
