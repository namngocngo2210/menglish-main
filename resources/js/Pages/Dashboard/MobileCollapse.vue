<script setup>
import { onBeforeUnmount, onMounted, ref, useId } from 'vue';

defineProps({ title: { type: String, required: true } });
const id = useId();
// Start expanded for SSR and hydration; apply the viewport after mounting.
const open = ref(true);
let viewport;
const syncViewport = () => { open.value = viewport.matches; };
const syncToggle = (event) => { open.value = event.target.open; };

onMounted(() => {
    viewport = window.matchMedia('(min-width:1024px)');
    syncViewport();
    viewport.addEventListener('change', syncViewport);
});
onBeforeUnmount(() => viewport?.removeEventListener('change', syncViewport));
</script>

<template>
    <details :open="open" class="group/mobile min-w-0" @toggle="syncToggle">
        <summary :aria-controls="`${id}-content`" :aria-expanded="open" class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-sm rounded-lg border border-surface-variant bg-surface-container-lowest px-md py-sm text-on-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-container focus-visible:ring-offset-2 lg:hidden [&::-webkit-details-marker]:hidden">
            <span class="min-w-0 break-words font-h3 text-h3">{{ title }}</span>
            <span class="material-symbols-outlined shrink-0 group-open/mobile:rotate-180" aria-hidden="true">expand_more</span>
        </summary>
        <div :id="`${id}-content`" class="min-w-0 pt-sm lg:pt-0">
            <slot />
        </div>
    </details>
</template>
