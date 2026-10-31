<script setup>
/** Tối đa 4 thẻ số liệu tháng; mỗi thẻ có dòng so sánh (tháng trước, tỉ lệ chốt…) và bấm được khi có màn đích. */
import { Link } from '@inertiajs/vue3';

defineProps({ kpis: { type: Array, required: true } });
const hintTone = { success: 'text-tertiary', error: 'text-error' };
</script>

<template>
    <div class="grid min-w-0 grid-cols-2 gap-sm lg:grid-cols-4 lg:gap-md" data-overview-kpis>
        <component
            :is="kpi.href ? Link : 'div'"
            v-for="kpi in kpis"
            :key="kpi.label"
            :href="kpi.href ?? undefined"
            :class="['flex min-h-11 min-w-0 flex-col gap-xs rounded-xl border border-surface-variant bg-surface-container-lowest p-sm sm:p-md', kpi.href ? 'transition hover:shadow-md' : '']"
        >
            <span class="break-words font-body-small text-body-small font-semibold text-on-surface-variant">{{ kpi.label }}</span>
            <span class="min-w-0 break-words font-h1 text-h2 leading-tight sm:text-h1 font-bold tabular-nums text-on-surface">{{ kpi.value }}</span>
            <span v-if="kpi.hint" :class="['break-words font-caption text-caption', hintTone[kpi.tone] ?? 'text-on-surface-variant']">{{ kpi.hint }}</span>
        </component>
    </div>
</template>
