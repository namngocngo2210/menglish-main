<script setup>
/**
 * Khung bảng dữ liệu (như <x-ui.data-table>): card viền + cuộn ngang + style chuẩn cho <table> thô trong slot.
 *   <UiDataTable min-width="720px" sticky="first">
 *       <template #header><h2 class="font-h3 text-h3">Danh sách lớp</h2></template>
 *       <table>…</table>
 *       <template #footer><UiPagination :paginator="classes" unit="lớp" /></template>
 *   </UiDataTable>
 * sticky: first | last | both — bảng rộng cuộn ngang: cố định cột đầu và/hoặc cột cuối.
 * Hàng bấm được để mở chi tiết: <tr :data-href="url"> hoặc dùng <UiRowLink> — xem lib/rowLink.js.
 */
import { computed } from 'vue';

const props = defineProps({
    minWidth: { type: String, default: null },
    sticky: { type: String, default: null },
});

const stickyFirst = '[&_tr>*:first-child]:min-w-[12rem] [&_tr>*:first-child]:sticky [&_tr>*:first-child]:left-0 [&_tr>*:first-child]:z-10 [&_tr>*:first-child]:shadow-[inset_-1px_0_0_theme(colors.surface-container-highest)] [&_thead_th:first-child]:bg-surface-container-low [&_tbody_td:first-child]:bg-surface-container-lowest [&_tbody_tr:hover>td:first-child]:bg-surface-container-low';
const stickyLast = '[&_tr>*:last-child]:sticky [&_tr>*:last-child]:right-0 [&_tr>*:last-child]:z-10 [&_tr>*:last-child]:shadow-[inset_1px_0_0_theme(colors.surface-container-highest)] [&_thead_th:last-child]:bg-surface-container-low [&_tbody_td:last-child]:bg-surface-container-lowest [&_tbody_tr:hover>td:last-child]:bg-surface-container-low';

const scrollClasses = computed(() => [
    'custom-scrollbar relative overflow-x-auto',
    '[&_table]:w-full [&_table]:border-collapse [&_table]:text-left',
    '[&_thead]:border-b [&_thead]:border-outline-variant [&_thead]:bg-surface-container-low',
    '[&_th]:whitespace-nowrap [&_th]:px-md [&_th]:py-3 [&_th]:font-label [&_th]:text-label [&_th]:uppercase [&_th]:tracking-wider [&_th]:text-on-surface-variant',
    '[&_td]:px-md [&_td]:py-sm [&_td]:font-body-base [&_td]:text-body-base [&_td]:text-on-surface',
    '[&_tbody_tr]:border-b [&_tbody_tr]:border-surface-container [&_tbody_tr:last-child]:border-0',
    '[&_tbody_tr]:transition-colors [&_tbody_tr:hover]:bg-surface-container-low',
    props.minWidth ? '[&_table]:min-w-[var(--tw-table-min)]' : '',
    ['first', 'both'].includes(props.sticky) ? stickyFirst : '',
    ['last', 'both'].includes(props.sticky) ? stickyLast : '',
]);
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest">
        <div v-if="$slots.header" class="flex flex-wrap items-center justify-between gap-sm border-b border-surface-container p-md"><slot name="header" /></div>
        <div :class="scrollClasses" :style="minWidth ? { '--tw-table-min': minWidth } : null"><slot /></div>
        <div v-if="$slots.footer" class="border-t border-outline-variant bg-surface-container-low"><slot name="footer" /></div>
    </div>
</template>
