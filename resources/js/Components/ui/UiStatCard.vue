<script setup>
/**
 * Thẻ số liệu (như <x-ui.stat-card>). tone: default | primary | success | secondary | error | warning
 *   <UiStatCard label="Tổng nhân sự" :value="total" icon="groups" />
 */
import { computed } from 'vue';

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], default: null },
    tone: { type: String, default: 'default' },
    icon: { type: String, default: null },
    hint: { type: String, default: null },
});
const tones = {
    default: ['text-on-surface', 'bg-surface-container-low text-on-surface-variant'],
    primary: ['text-primary-container', 'bg-primary-fixed text-primary'],
    success: ['text-tertiary', 'bg-tertiary-fixed/50 text-tertiary'],
    secondary: ['text-secondary-container', 'bg-secondary-fixed text-secondary'],
    error: ['text-error', 'bg-error-container text-error'],
    warning: ['text-warning', 'bg-warning-container text-warning'],
};
const tone = computed(() => tones[props.tone] ?? tones.default);
</script>

<template>
    <div class="flex items-center gap-md rounded-xl border border-surface-variant bg-surface-container-lowest p-md">
        <div v-if="icon" :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-full', tone[1]]">
            <span class="material-symbols-outlined" aria-hidden="true">{{ icon }}</span>
        </div>
        <div class="flex min-w-0 flex-1 flex-col gap-xs">
            <span class="line-clamp-2 font-body-medium text-body-medium text-on-surface-variant" :title="label">{{ label }}</span>
            <span :class="['whitespace-nowrap font-h2 text-h2 font-bold leading-tight tabular-nums', tone[0]]"><slot>{{ value }}</slot></span>
            <span v-if="hint" class="font-caption text-caption text-on-surface-variant">{{ hint }}</span>
        </div>
    </div>
</template>
