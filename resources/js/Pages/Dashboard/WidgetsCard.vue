<script setup>
/** Widget nghiệp vụ (TK-2026-0154): luôn hiện số đếm kể cả 0; Admin thêm khối tổng hợp toàn hệ thống. */
import { Link } from '@inertiajs/vue3';

defineProps({
    widgets: { type: Array, default: () => [] },
    aggregate: { type: Array, default: null },
});
</script>

<template>
    <section class="min-w-0 space-y-md" data-overview-widgets>
        <div v-if="widgets.length" class="grid min-w-0 grid-cols-1 gap-sm sm:grid-cols-2 lg:grid-cols-3">
            <component
                :is="widget.href ? Link : 'div'"
                v-for="widget in widgets"
                :key="widget.key"
                :href="widget.href ?? undefined"
                :data-widget="widget.key"
                :class="['flex min-h-11 min-w-0 flex-col gap-xs rounded-xl border border-surface-variant bg-surface-container-lowest p-sm sm:p-md', widget.href ? 'transition hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-container' : '']"
            >
                <span class="flex min-w-0 items-center gap-sm text-on-surface-variant">
                    <span class="material-symbols-outlined shrink-0" aria-hidden="true">{{ widget.icon }}</span>
                    <span class="break-words font-body-small text-body-small font-semibold">{{ widget.label }}</span>
                </span>
                <span class="font-h2 text-h2 font-bold tabular-nums text-on-surface">{{ formatNumber(widget.value) }}</span>
                <span v-if="widget.hint" class="break-words font-caption text-caption text-on-surface-variant">{{ widget.hint }}</span>
            </component>
        </div>
        <div v-if="aggregate" data-overview-aggregate>
            <h3 class="mb-sm font-h3 text-h3 text-on-surface">Tổng hợp toàn hệ thống (mọi chi nhánh)</h3>
            <div class="grid min-w-0 grid-cols-2 gap-sm lg:grid-cols-4">
                <div v-for="item in aggregate" :key="item.label" class="flex min-w-0 flex-col gap-xs rounded-xl border border-surface-variant bg-surface-container-lowest p-sm sm:p-md">
                    <span class="break-words font-body-small text-body-small font-semibold text-on-surface-variant">{{ item.label }}</span>
                    <span class="font-h2 text-h2 font-bold tabular-nums text-on-surface">{{ formatNumber(item.value) }}</span>
                </div>
            </div>
        </div>
    </section>
</template>
