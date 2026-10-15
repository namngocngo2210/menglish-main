<script setup>
/**
 * Một mục số liệu tự động của báo cáo ngày (StaffReportController::daily → DailyReportMetrics): nhãn + icon "i" (cách đếm),
 * số chỉ đọc và danh sách chi tiết (5 dòng đầu, bấm "Xem thêm" để mở hết). Mục không có số (danh sách) chiếm cả hàng.
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    item: { type: Object, required: true },
});

const LIMIT = 5;
const expanded = ref(false);
const details = computed(() => props.item.details ?? []);
const shown = computed(() => (expanded.value ? details.value : details.value.slice(0, LIMIT)));
const toneColor = { success: 'success', warning: 'warning', error: 'error', muted: 'neutral' };
const textClass = (d) => (d.tone === 'strong' ? 'font-semibold text-on-surface' : d.tone === 'muted' ? 'text-on-surface-variant line-through' : 'text-on-surface');
</script>

<template>
    <div class="flex h-full flex-col gap-sm rounded-lg border border-surface-variant bg-surface-container-low p-md" :data-daily-metric="item.key">
        <div class="flex items-start justify-between gap-sm">
            <p class="font-label text-label uppercase text-on-surface-variant">{{ item.label }}</p>
            <UiInfo :title="item.label" align="right" :label="`Cách tính: ${item.label}`">
                <p>{{ item.info }}</p>
            </UiInfo>
        </div>
        <p v-if="item.value !== null && item.value !== undefined" class="font-h2 text-h2 text-on-surface" data-metric-value>{{ item.value }}</p>
        <ul v-if="details.length" class="space-y-xs" data-metric-details>
            <li v-for="(d, i) in shown" :key="i" class="font-body-small text-body-small">
                <UiBadge v-if="d.tag" :color="toneColor[d.tone] ?? 'neutral'" :dot="false" class="mr-xs align-middle">{{ d.tag }}</UiBadge>
                <Link v-if="d.url" :href="d.url" :class="['hover:text-primary hover:underline', textClass(d)]">{{ d.text }}</Link>
                <span v-else :class="textClass(d)">{{ d.text }}</span>
            </li>
        </ul>
        <p v-else-if="item.value === null || item.value === undefined" class="font-body-small text-body-small text-on-surface-variant">Không có phát sinh.</p>
        <button
            v-if="details.length > LIMIT"
            type="button"
            class="self-start font-body-small text-body-small font-semibold text-primary hover:underline"
            @click="expanded = !expanded"
        >
            {{ expanded ? 'Thu gọn' : `Xem thêm ${details.length - LIMIT} dòng` }}
        </button>
    </div>
</template>
