<script setup>
/** Doanh thu 5 tuần gần nhất (phiếu thu đã duyệt); cột tuần này tô màu nhấn. */
import { computed } from 'vue';

const props = defineProps({ weeks: { type: Array, required: true } });
const max = computed(() => Math.max(1, ...props.weeks.map((w) => w.value)));
</script>

<template>
    <section class="flex min-w-0 flex-col gap-md rounded-xl border border-surface-variant bg-surface-container-lowest p-md" data-overview-revenue-weeks>
        <h3 class="font-h3 text-h3 text-on-surface">Doanh thu theo tuần</h3>
        <div class="flex h-40 items-end gap-sm border-b border-surface-variant" role="img" :aria-label="'Doanh thu 5 tuần: ' + weeks.map((w) => 'tuần ' + w.label + ' ' + w.display).join(', ')">
            <div v-for="w in weeks" :key="w.label" class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-xs">
                <span class="max-w-full break-words text-center font-caption text-caption font-semibold text-on-surface-variant">{{ w.display }}</span>
                <div :class="['w-full max-w-10 rounded-t-md', w.current ? 'bg-primary-container' : 'bg-surface-container-highest']" :style="{ height: Math.max(2, (w.value / max) * 100) + '%' }" />
            </div>
        </div>
        <div class="flex gap-sm">
            <span v-for="w in weeks" :key="w.label" class="min-w-0 flex-1 break-words text-center font-caption text-caption text-on-surface-variant">{{ w.current ? 'Tuần này' : w.label }}</span>
        </div>
        <p class="font-caption text-caption text-on-surface-variant">Theo tuần bắt đầu thứ Hai; tuần này tính đến hôm nay.</p>
    </section>
</template>
