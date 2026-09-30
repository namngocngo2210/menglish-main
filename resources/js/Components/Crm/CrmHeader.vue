<script setup>
/**
 * Đầu trang chung của workspace CRM (thay crm/partials/header-tabs): tab khu vực (+ nút Nhập Excel / Thêm khách mới,
 * khai báo ở SidebarMenu workspace "crm") → tiêu đề màn hình.
 * Trang dùng: defineOptions({ layout: { title, workspaceTabs: false } }) + <CrmHeader title="…" />.
 * title: tiêu đề màn (bỏ trống = trang tự đặt <UiPageHeader>). Số trên chip lọc nhanh: controller gửi prop `chipCounts`
 * (CrmController::chipCounts) → <WorkspaceChips :counts="chipCounts"> trong khung bộ lọc.
 */
import WorkspaceTabs from '@/Layouts/Shell/WorkspaceTabs.vue';

defineProps({
    title: { type: String, default: null },
    description: { type: String, default: null },
});
</script>

<template>
    <WorkspaceTabs />
    <UiPageHeader v-if="title" :title="title" :description="description" />
</template>
