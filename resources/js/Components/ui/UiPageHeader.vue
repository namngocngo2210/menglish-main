<script setup>
/**
 * Khối tiêu đề CHUNG của mọi trang (như <x-ui.page-header>): tiêu đề (cũng là tiêu đề tab trình duyệt + topbar),
 * mô tả, icon, nút quay lại; slot breadcrumbs / badges / meta / actions.
 * `back`: URL dự phòng của nút quay lại — nút về trang người dùng vừa mở trước đó (giữ bộ lọc, tab, trang), chỉ dùng `back`
 * khi không có trang trước (tab mới, link ngoài). Nút quay lại tự làm khác: useBackLink() trong lib/backLink.js.
 *   <UiPageHeader title="TKB — Quản lý lớp học" icon="calendar_month" description="Cấu hình thời khóa biểu.">
 *       <template #actions><UiButton icon="add" :href="route('classes.create')">Tạo lớp mới</UiButton></template>
 *   </UiPageHeader>
 */
import { onBeforeUnmount, watchEffect } from 'vue';
import { Head } from '@inertiajs/vue3';
import UiButtonBack from './UiButtonBack.vue';
import { usePageMeta } from './pageMeta';

const props = defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
    icon: { type: String, default: null },
    back: { type: String, default: null },
    backLabel: { type: String, default: 'Quay lại' },
    documentTitle: { type: Boolean, default: true },
});
const meta = usePageMeta();
if (meta) {
    watchEffect(() => {
        meta.title = props.title;
    });
    onBeforeUnmount(() => {
        if (meta.title === props.title) meta.title = null;
    });
}
</script>

<template>
    <Head v-if="documentTitle" :title="title" />
    <header class="mb-lg flex flex-col gap-md md:flex-row md:items-center md:justify-between" data-page-header>
        <div class="flex min-w-0 items-start gap-sm">
            <UiButtonBack v-if="back" :href="back" :label="backLabel" />
            <div class="min-w-0">
                <nav v-if="$slots.breadcrumbs" class="mb-xs flex flex-wrap items-center gap-xs font-body-small text-body-small text-on-surface-variant" aria-label="Breadcrumb"><slot name="breadcrumbs" /></nav>
                <div class="flex flex-wrap items-center gap-sm">
                    <h1 class="flex min-w-0 items-center gap-sm font-h1 text-h1 text-on-surface">
                        <span v-if="icon" class="material-symbols-outlined shrink-0 text-[26px] text-primary" aria-hidden="true">{{ icon }}</span>
                        <span class="min-w-0">{{ title }}</span>
                    </h1>
                    <div v-if="$slots.badges" class="flex flex-wrap items-center gap-xs"><slot name="badges" /></div>
                </div>
                <p v-if="description" class="mt-xs font-body-medium text-body-medium text-on-surface-variant">{{ description }}</p>
                <div v-if="$slots.meta" class="mt-xs font-body-small text-body-small text-on-surface-variant"><slot name="meta" /></div>
            </div>
        </div>
        <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-sm"><slot name="actions" /></div>
    </header>
</template>
