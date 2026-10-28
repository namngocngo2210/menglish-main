<script setup>
/** Cảnh báo vận hành của Admin / Quản lý (Overview::alerts): việc quá hạn, học phí quá hạn, ticket chưa ai nhận. */
import { Link } from '@inertiajs/vue3';

defineProps({
    alerts: { type: Array, required: true },
    extra: { type: Array, default: () => [] },
});
const color = { overdue: 'text-error', today: 'text-primary', info: 'text-on-surface-variant' };
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-surface-variant bg-surface-container-lowest" data-overview-alerts>
        <div class="border-b border-surface-variant px-md py-sm">
            <h3 class="font-h3 text-h3 text-on-surface">Cảnh báo vận hành</h3>
        </div>
        <component
            :is="alert.href ? Link : 'div'"
            v-for="alert in [...alerts, ...extra]"
            :key="alert.title"
            :href="alert.href ?? undefined"
            :class="['flex items-start gap-md border-b border-surface-variant/60 px-md py-sm last:border-0', alert.href ? 'hover:bg-surface-container-low' : '']"
        >
            <span :class="['min-w-10 font-h2 text-h2 font-bold tabular-nums', color[alert.tone] ?? color.info]">{{ formatNumber(alert.value) }}</span>
            <span class="min-w-0">
                <span class="block font-body-medium text-body-medium font-semibold text-on-surface">{{ alert.title }}</span>
                <span class="block font-caption text-caption text-on-surface-variant">{{ alert.meta }}</span>
            </span>
        </component>
    </section>
</template>
