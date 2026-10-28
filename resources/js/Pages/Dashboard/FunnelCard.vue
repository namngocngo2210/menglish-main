<script setup>
/** Phễu khách tạo trong tháng (Overview::funnel): mỗi bước tính cả khách đã đi xa hơn. */
import { computed } from 'vue';

const props = defineProps({ funnel: { type: Object, required: true } });
const top = computed(() => Math.max(1, props.funnel.rows[0]?.value ?? 0));
</script>

<template>
    <section class="flex flex-col gap-sm rounded-xl border border-surface-variant bg-surface-container-lowest p-md" data-overview-funnel>
        <h3 class="font-h3 text-h3 text-on-surface">Phễu khách tháng này</h3>
        <div v-for="(row, i) in funnel.rows" :key="row.label" class="flex flex-col gap-xs">
            <div class="flex justify-between font-body-small text-body-small">
                <span class="text-on-surface-variant">{{ row.label }}</span>
                <span class="font-semibold tabular-nums text-on-surface">{{ formatNumber(row.value) }}</span>
            </div>
            <div class="h-5 overflow-hidden rounded-md bg-surface-container-low">
                <div :class="['h-full rounded-md', i === funnel.rows.length - 1 ? 'bg-primary-container' : 'bg-surface-container-highest']" :style="{ width: (row.value / top) * 100 + '%' }" />
            </div>
        </div>
        <p class="font-caption text-caption text-on-surface-variant">Khách tạo trong tháng, theo bước hiện tại<template v-if="funnel.lost"> · {{ funnel.lost }} khách thất bại</template>.</p>
    </section>
</template>
