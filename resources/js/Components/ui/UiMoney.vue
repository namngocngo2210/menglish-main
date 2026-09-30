<script setup>
/**
 * Số tiền: font mono, căn phải, số âm màu đỏ (như <x-ui.money>) → "1.500.000 đ".
 * tone: auto (âm = đỏ) | success | error | warning | primary | secondary | muted
 */
import { computed } from 'vue';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    value: { type: [Number, String], default: null },
    suffix: { type: String, default: 'đ' },
    align: { type: String, default: 'right' },
    sign: { type: Boolean, default: false },
    tone: { type: String, default: 'auto' },
});
const tones = { success: 'text-tertiary', error: 'text-error', warning: 'text-warning', primary: 'text-primary', secondary: 'text-secondary', muted: 'text-on-surface-variant' };
const toneClass = computed(() => tones[props.tone] ?? (Number(props.value) < 0 ? 'text-error' : 'text-on-surface'));
</script>

<template>
    <span :class="['block whitespace-nowrap font-code text-code tabular-nums', align === 'left' ? 'text-left' : 'text-right', toneClass]">{{ formatMoney(value, suffix, sign) }}</span>
</template>
