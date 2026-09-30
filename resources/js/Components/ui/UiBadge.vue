<script setup>
/**
 * Nhãn trạng thái dạng "soft" (như <x-ui.badge>).
 *   <UiBadge color="success">Đang học</UiBadge>   <UiBadge :color="'stage-' + lead.stage">{{ lead.stage_label }}</UiBadge>
 */
import { computed } from 'vue';

const props = defineProps({
    color: { type: String, default: 'neutral' },
    dot: { type: Boolean, default: true },
    pill: { type: Boolean, default: false },
});
// Chuỗi class viết đầy đủ để Tailwind quét được.
const palette = {
    neutral: ['bg-on-surface-variant/10 text-on-surface-variant', 'bg-on-surface-variant'],
    primary: ['bg-primary-container/10 text-primary', 'bg-primary-container'],
    secondary: ['bg-secondary/10 text-secondary', 'bg-secondary'],
    success: ['bg-tertiary/10 text-tertiary', 'bg-tertiary'],
    warning: ['bg-warning/10 text-warning', 'bg-warning'],
    error: ['bg-error/10 text-error', 'bg-error'],
    info: ['bg-info/10 text-info', 'bg-info'],
    accent: ['bg-accent/10 text-accent', 'bg-accent'],
    'stage-new': ['bg-stage-new/10 text-stage-new', 'bg-stage-new'],
    'stage-consulting': ['bg-stage-consulting/10 text-stage-consulting', 'bg-stage-consulting'],
    'stage-test_scheduled': ['bg-stage-test_scheduled/10 text-stage-test_scheduled', 'bg-stage-test_scheduled'],
    'stage-tested': ['bg-stage-tested/10 text-stage-tested', 'bg-stage-tested'],
    'stage-result_sent': ['bg-stage-result_sent/10 text-stage-result_sent', 'bg-stage-result_sent'],
    'stage-closing': ['bg-stage-closing/10 text-stage-closing', 'bg-stage-closing'],
    'stage-won': ['bg-stage-won/10 text-stage-won', 'bg-stage-won'],
    'stage-lost': ['bg-stage-lost/10 text-stage-lost', 'bg-stage-lost'],
    'status-new': ['bg-status-new/10 text-on-surface-variant', 'bg-status-new'],
    'status-progress': ['bg-status-progress/15 text-warning', 'bg-status-progress'],
    'status-pending': ['bg-status-pending/10 text-warning', 'bg-status-pending'],
    'status-blocked': ['bg-status-blocked/10 text-status-blocked', 'bg-status-blocked'],
    'status-done': ['bg-status-done/10 text-tertiary', 'bg-status-done'],
    'status-overdue': ['bg-status-overdue/10 text-error', 'bg-status-overdue'],
    'status-canceled': ['bg-status-canceled/10 text-status-canceled', 'bg-status-canceled'],
};
const tone = computed(() => palette[props.color] ?? palette.neutral);
</script>

<template>
    <span :class="['inline-flex shrink-0 items-center gap-xs whitespace-nowrap px-sm py-[2px] font-body-medium text-caption', tone[0], pill ? 'rounded-full' : 'rounded']">
        <span v-if="dot" :class="['h-1.5 w-1.5 shrink-0 rounded-full', tone[1]]" aria-hidden="true"></span>
        <slot />
    </span>
</template>
