<script setup>
/** Operational alerts use both icons and accessible severity labels. */
import { Link } from '@inertiajs/vue3';

defineProps({
    alerts: { type: Array, required: true },
    extra: { type: Array, default: () => [] },
});
const severity = {
    overdue: { color: 'text-error', background: 'bg-error-container/30', icon: 'error', label: 'Quá hạn' },
    today: { color: 'text-primary', background: 'bg-primary-fixed/30', icon: 'schedule', label: 'Hôm nay' },
    info: { color: 'text-on-surface-variant', background: 'bg-surface-container-low', icon: 'info', label: 'Thông tin' },
};
const tone = (key) => severity[key] ?? severity.info;
</script>

<template>
    <section class="min-w-0 overflow-hidden rounded-xl border border-surface-variant bg-surface-container-lowest" data-overview-alerts>
        <div class="border-b border-surface-variant px-md py-sm">
            <h3 class="font-h3 text-h3 text-on-surface">Cảnh báo vận hành</h3>
        </div>
        <div class="grid min-w-0 grid-cols-1 gap-sm p-md sm:grid-cols-2">
            <component
                :is="alert.href ? Link : 'div'"
                v-for="(alert, index) in [...alerts, ...extra]"
                :key="index"
                :href="alert.href ?? undefined"
                :class="['flex min-h-11 min-w-0 flex-col gap-xs rounded-lg border border-surface-variant p-sm sm:p-md', tone(alert.tone).background, alert.href ? 'transition hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-container focus-visible:ring-offset-2' : '']"
            >
                <span :class="['flex min-w-0 items-center gap-sm', tone(alert.tone).color]">
                    <span class="material-symbols-outlined shrink-0" aria-hidden="true">{{ tone(alert.tone).icon }}</span>
                    <span class="sr-only">{{ tone(alert.tone).label }}: </span>
                    <span class="min-w-0 break-words font-h2 text-h2 font-bold tabular-nums">{{ formatNumber(alert.value) }}</span>
                </span>
                <span class="break-words font-body-medium text-body-medium font-semibold text-on-surface">{{ alert.title }}</span>
                <span class="line-clamp-2 break-words font-caption text-caption text-on-surface-variant" :title="alert.meta">{{ alert.meta }}</span>
            </component>
        </div>
    </section>
</template>
