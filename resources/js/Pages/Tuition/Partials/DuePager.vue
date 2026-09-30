<script setup>
/**
 * Link phân trang gọn (như ->links() mặc định của Laravel) cho paginator có tên trang riêng (vd. `upcoming_page`):
 * dùng sẵn URL trong `links` / `prev_page_url` / `next_page_url` do server sinh (giữ bộ lọc và tên trang).
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({ paginator: { type: Object, required: true } });

const current = computed(() => Number(props.paginator.current_page ?? 1));
const last = computed(() => Number(props.paginator.last_page ?? 1));
// Bỏ link "Trước" / "Sau" ở hai đầu, còn lại là số trang và "...".
const pages = computed(() => (props.paginator.links ?? []).slice(1, -1));

const base = 'inline-flex h-11 min-w-11 md:h-8 md:min-w-8 items-center justify-center rounded px-xs font-body-medium text-body-medium transition-colors';
const idle = `${base} text-on-surface hover:bg-surface-container-high`;
const disabled = `${base} cursor-default text-on-surface-variant opacity-30`;
</script>

<template>
    <nav v-if="last > 1" role="navigation" aria-label="Phân trang" class="flex items-center gap-xs">
        <span v-if="!paginator.prev_page_url" :class="disabled" aria-disabled="true" aria-label="Trang trước"><span class="material-symbols-outlined">chevron_left</span></span>
        <Link v-else :href="paginator.prev_page_url" rel="prev" :class="idle" aria-label="Trang trước" preserve-scroll><span class="material-symbols-outlined">chevron_left</span></Link>

        <span class="px-sm font-body-small text-body-small text-on-surface-variant sm:hidden">{{ current }} / {{ last }}</span>

        <span class="hidden items-center gap-xs sm:flex">
            <template v-for="(link, i) in pages" :key="i">
                <span v-if="!link.url" class="px-sm text-on-surface-variant" aria-disabled="true">{{ link.label }}</span>
                <span v-else-if="link.active" aria-current="page" :class="[base, 'bg-primary-container text-white']">{{ link.label }}</span>
                <Link v-else :href="link.url" :class="idle" :aria-label="`Trang ${link.label}`" preserve-scroll>{{ link.label }}</Link>
            </template>
        </span>

        <Link v-if="paginator.next_page_url" :href="paginator.next_page_url" rel="next" :class="idle" aria-label="Trang sau" preserve-scroll><span class="material-symbols-outlined">chevron_right</span></Link>
        <span v-else :class="disabled" aria-disabled="true" aria-label="Trang sau"><span class="material-symbols-outlined">chevron_right</span></span>
    </nav>
</template>
