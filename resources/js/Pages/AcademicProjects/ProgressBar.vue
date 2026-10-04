<script setup>
/** Thanh tiến độ % của dự án / mốc: xanh khi xong, đỏ khi có mốc trễ, còn lại màu phụ. */
import { computed } from 'vue';

const props = defineProps({
    value: { type: Number, default: 0 },
    overdue: { type: Boolean, default: false },
    label: { type: String, default: null },
    width: { type: String, default: 'w-28' },
});
const tone = computed(() => (props.value >= 100 ? 'bg-tertiary' : props.overdue ? 'bg-error' : 'bg-secondary'));
</script>

<template>
    <div :class="width">
        <div class="h-1.5 overflow-hidden rounded-full bg-surface-container" role="progressbar" :aria-valuenow="value" aria-valuemin="0" aria-valuemax="100" :aria-label="label ?? `Tiến độ ${value}%`">
            <div :class="['h-full rounded-full', tone]" :style="{ width: Math.min(100, value) + '%' }"></div>
        </div>
        <span class="mt-[2px] block font-code text-xs text-on-surface-variant">{{ label ?? value + '%' }}</span>
    </div>
</template>
