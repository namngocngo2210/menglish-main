<script setup>
/**
 * Trang cho chức năng CHƯA triển khai (QA dự giờ, checklist học phí & feedback, đánh giá dự giờ):
 * hiện rõ trạng thái thay vì dữ liệu mẫu, kèm liên kết tới các màn thật liên quan (đã lọc theo quyền ở server).
 */
defineOptions({ layout: (props) => ({ title: props.title }) });

defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
    links: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <UiPageHeader :title="title" :description="description" />
        <div class="rounded-xl border border-surface-container-highest bg-surface">
            <UiEmptyState icon="construction" title="Chức năng chưa triển khai" description="Màn hình này chưa được kết nối dữ liệu nên chưa có gì để hiển thị. Vui lòng dùng các màn liên quan bên dưới.">
                <UiButton v-for="link in links" :key="link.href" variant="secondary" :icon="link.icon" :href="link.href">{{ link.label }}</UiButton>
            </UiEmptyState>
        </div>
    </div>
</template>
