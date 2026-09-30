<script setup>
/** Một tab trong <UiTabs>: href (link Inertia), active, icon, count (số nhỏ). `button` → nút (tab trong trang, không đổi URL). */
import { Link } from '@inertiajs/vue3';

defineProps({
    href: { type: String, default: null },
    active: { type: Boolean, default: false },
    icon: { type: String, default: null },
    count: { type: [Number, String], default: null },
    preserveState: { type: Boolean, default: false },
    preserveScroll: { type: Boolean, default: false },
});
defineEmits(['click']);
</script>

<template>
    <component
        :is="href ? Link : 'button'"
        v-bind="href ? { href, preserveState, preserveScroll } : { type: 'button' }"
        :aria-current="active ? 'page' : null"
        :class="['-mb-px inline-flex shrink-0 items-center gap-xs whitespace-nowrap border-b-2 px-sm py-md font-body-medium text-body-medium transition-colors', active ? 'border-primary-container font-semibold text-primary' : 'border-transparent text-on-surface-variant hover:text-primary']"
        @click="$emit('click', $event)"
    >
        <span v-if="icon" class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ icon }}</span>
        <slot />
        <span v-if="count !== null && count !== undefined" :class="['rounded-full px-1.5 font-code text-caption', active ? 'bg-primary-container/10 text-primary' : 'bg-surface-container-high text-on-surface-variant']">{{ count }}</span>
    </component>
</template>
