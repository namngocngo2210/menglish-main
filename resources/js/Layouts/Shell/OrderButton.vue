<script setup>
/** Fixed teacher ORDER shortcut; pulse when printing or test orders are due. */
import { Link } from '@inertiajs/vue3';

defineProps({ order: { type: Object, required: true } });
</script>

<template>
    <Link
        :href="order.url"
        :class="[
            'fixed bottom-[calc(5.5rem+env(safe-area-inset-bottom))] right-md z-30 inline-flex h-11 items-center gap-xs rounded-full px-md py-sm font-body-semibold text-body-small shadow-level-3 transition md:bottom-lg md:right-lg',
            order.count > 0 ? 'motion-safe:animate-pulse bg-error text-white' : 'bg-primary-container text-white',
        ]"
        :aria-label="order.count > 0 ? `ORDER — ${order.count} mốc đến hạn` : 'ORDER'"
        data-testid="order-button"
    >
        <span class="material-symbols-outlined text-[20px]" aria-hidden="true">local_shipping</span>
        <span>ORDER</span>
        <span v-if="order.count > 0" class="flex h-5 min-w-5 items-center justify-center rounded-full bg-white px-1 font-code text-xs font-bold text-error">{{ order.count }}</span>
    </Link>
</template>
