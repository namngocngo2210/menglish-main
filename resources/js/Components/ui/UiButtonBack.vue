<script setup>
/**
 * Nút quay lại cạnh tiêu đề trang (UiPageHeader `back`): về trang người dùng vừa mở trước đó (giữ bộ lọc, tab, trang);
 * mở thẳng (tab mới, link ngoài) thì về `href` — xem lib/backLink.js.
 */
import { Link } from '@inertiajs/vue3';
import { useBackLink } from '@/lib/backLink';

const props = defineProps({
    href: { type: String, required: true },
    label: { type: String, default: 'Quay lại' },
});
const back = useBackLink(() => props.href, props.label);
</script>

<template>
    <Link :href="back.href" class="mt-0.5 shrink-0 rounded-lg border border-outline-variant bg-surface-container-lowest p-1.5 text-on-surface-variant transition-colors hover:bg-surface-container-low hover:text-on-surface" :aria-label="back.label" :title="back.label" data-back-link>
        <span class="material-symbols-outlined block text-[18px]" aria-hidden="true">arrow_back</span>
    </Link>
</template>
