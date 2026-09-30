<script setup>
/**
 * Tiêu đề trang + dải tab workspace của các màn cổng học viên — chỉ hiện từ màn md trở lên
 * (trên điện thoại thanh điều hướng đáy là điều hướng chính). Trang dùng: defineOptions({ layout: { workspaceTabs: false } }).
 */
import WorkspaceTabs from '@/Layouts/Shell/WorkspaceTabs.vue';

defineProps({
    title: { type: String, required: true },
    icon: { type: String, default: null },
    back: { type: String, default: null },
});
</script>

<template>
    <div class="hidden md:block">
        <UiPageHeader :title="title" :icon="icon" :back="back">
            <template v-if="$slots.actions" #actions><slot name="actions" /></template>
        </UiPageHeader>
    </div>
    <WorkspaceTabs class="hidden md:block" />
</template>
