<script setup>
/** Trường cơ bản của vai trò (tên hiển thị / mã / mô tả) — dùng chung cho trang đầy đủ (kèm ma trận quyền) và modal (id tiền tố "modal-role-"). */
defineProps({
    role: { type: Object, default: null },
    isSystemRole: { type: Boolean, default: false },
    idPrefix: { type: String, default: null },
});
</script>

<template>
    <UiInput name="label" :id="idPrefix ? `${idPrefix}label` : undefined" label="Tên hiển thị" :value="role?.label ?? ''" placeholder="Ví dụ: Thu ngân chi nhánh" />
    <UiInput name="name" :id="idPrefix ? `${idPrefix}name` : undefined" label="Mã vai trò" :value="role?.name" required placeholder="vd. cashier_branch" hint="Chữ thường, số, gạch dưới. Vai trò hệ thống không đổi mã." :readonly="isSystemRole || undefined" />
    <UiInput name="description" :id="idPrefix ? `${idPrefix}description` : undefined" label="Mô tả" :value="role?.description" placeholder="Vai trò này dùng cho ai, làm gì" />
</template>
