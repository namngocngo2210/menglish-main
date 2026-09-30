<script setup>
/** Avatar chữ cái đầu, màu nền cố định theo tên (như <x-ui.avatar>). size: sm | md | lg */
import { computed } from 'vue';
import { crc32, initials } from '@/lib/format';

const props = defineProps({ name: { type: String, default: '' }, size: { type: String, default: 'md' } });
const tones = ['bg-primary-fixed text-on-primary-fixed', 'bg-secondary-fixed text-on-secondary-fixed', 'bg-tertiary-fixed text-on-tertiary-fixed', 'bg-surface-variant text-on-surface-variant'];
const sizes = { sm: 'h-8 w-8 text-body-small', md: 'h-10 w-10 text-body-medium', lg: 'h-16 w-16 text-h3' };
const tone = computed(() => tones[crc32(props.name ?? '') % tones.length]);
</script>

<template>
    <span :class="['inline-flex shrink-0 select-none items-center justify-center rounded-full font-bold', tone, sizes[size] ?? sizes.md]" :title="name" aria-hidden="true">{{ initials(name) }}</span>
</template>
