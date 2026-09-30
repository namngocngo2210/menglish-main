<script setup>
/** Nút quay lại cạnh tiêu đề trang (UiPageHeader `back`). */
import { Link } from '@inertiajs/vue3';

defineProps({
    href: { type: String, required: true },
    label: { type: String, default: 'Quay lại' },
});
</script>

<template>
    <Link :href="href" class="mt-0.5 shrink-0 rounded-lg border border-outline-variant bg-surface-container-lowest p-1.5 text-on-surface-variant transition-colors hover:bg-surface-container-low hover:text-on-surface" :aria-label="label" :title="label">
        <span class="material-symbols-outlined block text-[18px]" aria-hidden="true">arrow_back</span>
    </Link>
</template>
