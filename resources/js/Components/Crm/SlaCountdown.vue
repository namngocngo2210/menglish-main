<script setup>
/**
 * Đồng hồ SLA liên hệ CRM (CrmCustomer::contactSlaPayload): liên hệ lần đầu trong 24h, chăm sóc tiếp theo trong 72h.
 * Xanh "Còn hạn" / vàng "Sắp hết hạn" (còn dưới warn_seconds) / đỏ "Quá hạn", đếm ngược mỗi 30 giây. Chỉ hiển thị.
 *   <SlaCountdown :sla="lead.sla" />                     badge + thời gian còn lại
 *   <SlaCountdown :sla="lead.sla" with-label compact />  kèm nhãn hạn ("Hạn liên hệ lần đầu"), chữ nhỏ
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    sla: { type: Object, default: null },
    withLabel: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
});

// Lần hiển thị đầu (render phía server) dùng state / remaining máy chủ tính; sau khi gắn vào trang mới đếm theo giờ máy.
const now = ref(null);
let timer = null;
onMounted(() => {
    now.value = Date.now();
    timer = setInterval(() => (now.value = Date.now()), 30000);
});
onBeforeUnmount(() => clearInterval(timer));

const live = computed(() => !!props.sla && now.value !== null);
const seconds = computed(() => (live.value ? Math.round((new Date(props.sla.deadline).getTime() - now.value) / 1000) : 0));
const state = computed(() => {
    if (!live.value) return props.sla?.state ?? 'on_time';
    return seconds.value < 0 ? 'overdue' : seconds.value < props.sla.warn_seconds ? 'due_soon' : 'on_time';
});
const tone = computed(() => ({ overdue: 'error', due_soon: 'warning', on_time: 'success' })[state.value]);
const stateLabel = computed(() => ({ overdue: 'Quá hạn', due_soon: 'Sắp hết hạn', on_time: 'Còn hạn' })[state.value]);
const icon = computed(() => ({ overdue: 'alarm_on', due_soon: 'priority_high', on_time: 'timer' })[state.value]);

const remaining = computed(() => {
    if (!live.value) return props.sla?.remaining ?? '';
    const abs = Math.abs(seconds.value);
    const days = Math.floor(abs / 86400);
    const hours = Math.floor((abs % 86400) / 3600);
    const minutes = Math.floor((abs % 3600) / 60);
    const parts = [days ? `${days} ngày` : null, hours ? `${hours} giờ` : null, !days && minutes ? `${minutes} phút` : null].filter(Boolean);
    const text = parts.length ? parts.join(' ') : 'dưới 1 phút';
    return (seconds.value < 0 ? 'Quá hạn ' : 'Còn ') + text;
});
</script>

<template>
    <span v-if="sla" class="inline-flex flex-wrap items-center gap-xs" :data-sla-state="state" :title="sla.label + ': ' + sla.deadline_label">
        <UiBadge :color="tone" pill :dot="false" class="font-bold">
            <span class="material-symbols-outlined text-[14px]">{{ icon }}</span>{{ stateLabel }}
        </UiBadge>
        <span :class="['font-medium', compact ? 'font-caption text-caption' : 'font-body-small text-body-small', state === 'overdue' ? 'text-error' : state === 'due_soon' ? 'text-warning' : 'text-on-surface-variant']">
            <template v-if="withLabel">{{ sla.label }}: </template>{{ remaining }}
        </span>
    </span>
</template>
