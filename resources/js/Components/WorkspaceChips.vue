<script setup>
/**
 * Hàng lọc nhanh của tab workspace đang mở (như <x-ui.workspace-chips>) — đặt trong slot `quick` của <UiFilterBar>.
 *   <UiFilterBar><template #quick><WorkspaceChips :counts="chipCounts" /></template> … </UiFilterBar>
 * counts: số trên chip theo khoá `count` của chip (controller tính). Chip `hide_empty` chỉ hiện khi còn dữ liệu.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { formatNumber } from '@/lib/format';

const props = defineProps({ counts: { type: Object, default: () => ({}) } });
const page = usePage();
const chips = computed(() => page.props.shell?.workspace?.chips ?? null);

const countOf = (chip) => (chip.count ? (props.counts?.[chip.count] ?? null) : null);
const visibleItems = computed(() => (chips.value?.items ?? []).filter((chip) => !(chip.hide_empty && countOf(chip) === 0 && !chip.active)));
const chipClass = (active, tone) =>
    'inline-flex items-center gap-xs rounded-full border px-sm py-1 max-md:min-h-11 max-md:px-md font-body-small text-body-small font-semibold transition-colors ' +
    (active && tone === 'danger'
        ? 'border-error bg-error text-white'
        : active
          ? 'border-primary-container bg-primary-container text-white'
          : tone === 'danger'
            ? 'border-error/30 bg-error/5 text-error hover:bg-error/10'
            : 'border-outline-variant bg-surface-container-lowest text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface');
</script>

<template>
    <nav v-if="chips" class="flex flex-wrap items-center gap-xs" aria-label="Lọc nhanh" data-workspace-chips>
        <Link :href="chips.all.url" :class="chipClass(chips.all.active, null)" :aria-current="chips.all.active ? 'page' : null">Tất cả</Link>
        <Link v-for="chip in visibleItems" :key="chip.url" :href="chip.url" :class="chipClass(chip.active, chip.tone)" :aria-current="chip.active ? 'page' : null">
            <span v-if="chip.tone === 'danger'" class="material-symbols-outlined text-[16px]" aria-hidden="true">warning</span>
            {{ chip.label }}
            <span v-if="countOf(chip) !== null" :class="['rounded-full px-1.5 font-code text-xs leading-4', chip.active ? 'bg-white/25' : 'bg-surface-container-high']">{{ formatNumber(countOf(chip)) }}</span>
        </Link>
    </nav>
</template>
