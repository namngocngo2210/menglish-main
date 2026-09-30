<script setup>
/** Menu con trang Cài đặt (như <x-ui.settings-nav>): cột trái ≥ lg, thu gọn thành nút mở danh sách < lg. */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({ sections: { type: Array, default: () => [] } });
const open = ref(false);
const current = computed(() => props.sections.flatMap((s) => s.items).find((i) => i.active));
</script>

<template>
    <nav class="shrink-0 lg:w-56" aria-label="Cài đặt" data-settings-nav>
        <button type="button" class="flex w-full items-center justify-between gap-sm rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-medium text-body-medium text-on-surface lg:hidden" :aria-expanded="open ? 'true' : 'false'" @click="open = !open">
            <span class="flex min-w-0 items-center gap-sm">
                <span class="material-symbols-outlined text-[20px] text-on-surface-variant" aria-hidden="true">settings</span>
                <span class="truncate">Cài đặt{{ current ? ` · ${current.label}` : '' }}</span>
            </span>
            <span :class="['material-symbols-outlined text-[20px] transition-transform', open ? 'rotate-180' : '']" aria-hidden="true">expand_more</span>
        </button>
        <div :class="['mt-sm hidden space-y-md rounded-xl border border-surface-container-highest bg-surface p-sm lg:sticky lg:top-20 lg:mt-0 lg:block', open ? '!block' : '']">
            <div class="hidden px-sm pt-xs font-h3 text-h3 text-on-surface lg:block">Cài đặt</div>
            <div v-for="section in sections" :key="section.label">
                <div class="px-sm pb-xs font-caption text-xs font-semibold uppercase tracking-widest text-on-surface-variant">{{ section.label }}</div>
                <ul class="space-y-0.5">
                    <li v-for="item in section.items" :key="item.url">
                        <Link :href="item.url" :aria-current="item.active ? 'page' : null" :class="['block truncate rounded-lg px-sm py-1.5 font-body-small text-body-small transition-colors', item.active ? 'bg-primary-container/10 font-semibold text-primary' : 'text-on-surface-variant hover:bg-surface-container-low hover:text-primary']">{{ item.label }}</Link>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
</template>
