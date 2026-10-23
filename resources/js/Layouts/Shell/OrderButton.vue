<script setup>
/**
 * Nút ORDER cố định góc màn cho GV (quy trình syllabus Bước 7 — "góc màn hình luôn có nút ORDER", docs/kho-syllabus.md §9.3):
 * nhấp nháy + số mốc khi lớp có mốc order in ấn / mini test / Big Test đến hạn chưa order.
 */
import { Link } from '@inertiajs/vue3';

defineProps({ order: { type: Object, required: true } });
</script>

<template>
    <Link
        :href="order.url"
        :class="[
            'fixed bottom-24 right-md z-40 inline-flex items-center gap-xs rounded-full px-md py-sm font-body-semibold text-body-small shadow-level-3 transition md:bottom-lg md:right-lg',
            order.count > 0 ? 'animate-pulse bg-error text-white' : 'bg-primary-container text-white',
        ]"
        :aria-label="order.count > 0 ? `ORDER — ${order.count} mốc đến hạn` : 'ORDER'"
        data-testid="order-button"
    >
        <span class="material-symbols-outlined text-[20px]" aria-hidden="true">local_shipping</span>
        <span>ORDER</span>
        <span v-if="order.count > 0" class="flex h-5 min-w-5 items-center justify-center rounded-full bg-white px-1 font-code text-xs font-bold text-error">{{ order.count }}</span>
    </Link>
</template>
