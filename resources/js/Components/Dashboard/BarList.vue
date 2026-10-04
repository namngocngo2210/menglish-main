<script setup>
/**
 * Biểu đồ thanh ngang 1 chỉ số (vd. chuyên cần theo lớp): mỗi dòng 1 thanh, trục 0 → `max`, nhãn giá trị bên phải.
 * Một chuỗi số liệu nên không có chú thích màu; rê chuột / focus vào dòng hiện chi tiết (`hint`). Dòng chưa có dữ liệu
 * hiện "—" thay vì thanh 0 để không nhầm với 0%.
 *   <BarList title="Chuyên cần" :rows="[{ key: 1, label: 'Lớp A', value: 92.5, hint: '24 học sinh' }]" :max="100" unit="%" />
 */
import { ref } from 'vue';

const props = defineProps({
    title: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    max: { type: Number, default: 100 },
    unit: { type: String, default: '' },
    digits: { type: Number, default: 1 },
    emptyText: { type: String, default: 'Chưa có dữ liệu trong tháng' },
});

const hovered = ref(null);
const width = (value) => `${Math.max(0, Math.min(100, (value / props.max) * 100))}%`;
const format = (value) => (value === null || value === undefined ? '—' : `${Number(value).toLocaleString('vi-VN', { maximumFractionDigits: props.digits })}${props.unit}`);
</script>

<template>
    <figure class="rounded-xl border border-surface-variant bg-surface-container-lowest p-md" :data-bar-list="title">
        <figcaption class="mb-sm flex items-baseline justify-between gap-sm">
            <span class="font-h3 text-h3 text-on-surface">{{ title }}</span>
            <span class="font-caption text-caption text-on-surface-variant">thang 0–{{ max }}{{ unit }}</span>
        </figcaption>
        <ul v-if="rows.some((r) => r.value !== null && r.value !== undefined)" class="space-y-xs">
            <li
                v-for="row in rows"
                :key="row.key ?? row.label"
                tabindex="0"
                class="relative grid grid-cols-[minmax(0,7rem)_1fr_3.5rem] items-center gap-sm rounded-md px-xs py-1 outline-none hover:bg-surface-container-low focus-visible:ring-2 focus-visible:ring-primary-container"
                :aria-label="`${row.label}: ${format(row.value)}${row.hint ? ' · ' + row.hint : ''}`"
                @mouseenter="hovered = row.key ?? row.label"
                @mouseleave="hovered = null"
                @focus="hovered = row.key ?? row.label"
                @blur="hovered = null"
            >
                <span class="truncate font-body-small text-body-small text-on-surface-variant" :title="row.label">{{ row.label }}</span>
                <span class="relative h-2.5 rounded-full bg-surface-container-low" aria-hidden="true">
                    <span v-if="row.value !== null && row.value !== undefined" class="absolute inset-y-0 left-0 rounded-full bg-primary-container" :style="{ width: width(row.value) }"></span>
                </span>
                <span class="text-right font-body-small text-body-small tabular-nums text-on-surface">{{ format(row.value) }}</span>
                <span
                    v-if="hovered === (row.key ?? row.label) && row.hint"
                    class="pointer-events-none absolute -top-8 left-1/2 z-10 -translate-x-1/2 whitespace-nowrap rounded-md bg-inverse-surface px-sm py-1 font-caption text-caption text-inverse-on-surface shadow-md"
                    role="tooltip"
                >{{ row.label }} · {{ row.hint }}</span>
            </li>
        </ul>
        <p v-else class="py-sm font-body-small text-body-small italic text-on-surface-variant">{{ emptyText }}</p>
    </figure>
</template>
