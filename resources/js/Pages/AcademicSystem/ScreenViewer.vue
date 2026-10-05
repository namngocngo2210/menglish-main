<script setup>
/**
 * Xem một màn mockup trong khung ứng dụng (giữ menu): nhúng trang gốc (?embed) bằng iframe.
 */
import { Link } from '@inertiajs/vue3';

defineOptions({ layout: { title: 'Hệ thống 58 Màn hình' } });

defineProps({
    title: { type: String, required: true },
    category: { type: String, required: true },
    screenKey: { type: String, required: true },
    rawUrl: { type: String, required: true },
});
</script>

<template>
    <UiPageHeader :title="title" icon="web" :back="route('dashboard')">
        <template #breadcrumbs>
            <span>Hệ thống 58 Màn hình</span>
            <span class="material-symbols-outlined text-[12px]">chevron_right</span>
            <span>{{ category }}</span>
        </template>
        <template #actions>
            <a :href="rawUrl" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 rounded-xl border border-surface-container-highest bg-white px-3 py-1.5 text-xs font-semibold text-on-surface-variant shadow-2xs transition hover:bg-surface-container-low" title="Mở trang gốc không có menu">
                <span class="material-symbols-outlined text-[16px]">open_in_new</span>
                <span>Toàn màn hình (Không Menu)</span>
            </a>
            <Link :href="route('syllabus.documents')" class="inline-flex items-center gap-1.5 rounded-xl bg-primary-container px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-primary-hover">
                <span class="material-symbols-outlined text-[16px]">menu_book</span>
                <span>Về Giáo trình Flow 2</span>
            </Link>
        </template>
    </UiPageHeader>

    <!-- Màn nhúng trong khung ứng dụng (giữ menu bên trái) -->
    <div class="flex h-[calc(100vh-140px)] min-h-[700px] w-full flex-col overflow-hidden rounded-2xl border border-surface-container-highest bg-white shadow-sm">
        <iframe :src="rawUrl" class="w-full flex-1 border-0" loading="lazy" :title="screenKey"></iframe>
    </div>
</template>

