<script setup>
/** Bốn bước vòng đời; lấy mốc gần nhất của từng trạng thái từ lịch sử chuyển tiếp. */
import { computed } from 'vue';

const props = defineProps({ period: { type: Object, required: true } });
const steps = [
    { key: 'draft', label: 'Đang tính' },
    { key: 'closed', label: 'Đã chốt' },
    { key: 'published', label: 'Đã gửi GV xem' },
    { key: 'paid', label: 'Đã trả' },
];
const current = computed(() => steps.findIndex((step) => step.key === props.period.status));
const latest = computed(() => Object.fromEntries((props.period.lifecycle_steps ?? []).filter((step) => step.created_at).map((step) => [step.key, step])));
</script>

<template>
    <section class="rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm" aria-label="Vòng đời kỳ lương" data-payroll-lifecycle>
        <ol class="grid grid-cols-1 gap-md sm:grid-cols-2 lg:grid-cols-4">
            <li v-for="(step, index) in steps" :key="step.key" :aria-current="period.status === step.key ? 'step' : undefined" class="flex min-w-0 items-start gap-sm">
                <span :class="['flex size-8 shrink-0 items-center justify-center rounded-full border font-body-semibold text-body-semibold', index <= current ? 'border-primary bg-primary-container text-on-primary-container' : 'border-outline-variant bg-surface-container text-on-surface-variant']" aria-hidden="true">{{ index + 1 }}</span>
                <div class="min-w-0">
                    <p :class="['font-body-semibold text-body-semibold', period.status === step.key ? 'text-primary' : 'text-on-surface']">{{ step.label }}</p>
                    <p v-if="period.status === step.key" class="font-caption text-caption text-primary">Hiện tại</p>
                    <template v-if="index <= current && latest[step.key]">
                        <p class="font-caption text-caption text-on-surface-variant"><time :datetime="latest[step.key].created_at">{{ formatDate(latest[step.key].created_at, 'd/m/Y H:i') }}</time></p>
                        <p class="break-words font-caption text-caption text-on-surface-variant">{{ latest[step.key].user_name ?? 'Hệ thống' }}</p>
                    </template>
                </div>
            </li>
        </ol>
        <details v-if="period.transitions?.length" class="mt-md border-t border-surface-container-highest pt-sm">
            <summary class="cursor-pointer rounded-md font-body-small text-body-small font-semibold text-on-surface-variant focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">Lịch sử chuyển trạng thái ({{ period.transitions.length }})</summary>
            <ol class="mt-sm space-y-sm">
                <li v-for="(transition, index) in period.transitions" :key="index" class="flex flex-wrap gap-x-sm font-body-small text-body-small">
                    <span class="font-semibold text-on-surface">{{ transition.status_label ?? transition.to_status }}</span>
                    <span class="text-on-surface-variant">{{ formatDate(transition.created_at, 'd/m/Y H:i') }} · {{ transition.user_name ?? 'Hệ thống' }}</span>
                </li>
            </ol>
        </details>
    </section>
</template>
