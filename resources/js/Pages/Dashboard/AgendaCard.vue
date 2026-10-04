<script setup>
/** Lịch hẹn 7 ngày tới (MyWorkBoard::agenda): test đầu vào, học thử, gọi lại khách, Big Test, hạn mốc dự án học thuật — nhóm theo ngày, xếp theo giờ. */
import { Link } from '@inertiajs/vue3';

defineProps({ agenda: { type: Object, required: true } });

// Màu nhãn loại lịch theo token tailwind (không mã màu cứng).
const kinds = {
    test: 'bg-primary-fixed text-primary',
    trial: 'bg-secondary-fixed text-secondary',
    call: 'bg-warning-container text-warning',
    bigtest: 'bg-tertiary-fixed/50 text-tertiary',
    project: 'bg-error-container text-error',
};
</script>

<template>
    <section class="rounded-xl border border-surface-variant bg-surface-container-lowest" data-agenda>
        <div class="flex items-center justify-between gap-sm border-b border-surface-variant px-md py-sm">
            <h3 class="font-h3 text-h3 text-on-surface">Lịch hẹn 7 ngày tới</h3>
            <span class="font-caption text-caption text-on-surface-variant">{{ agenda.range }} · {{ formatNumber(agenda.total) }} lịch</span>
        </div>
        <div v-for="day in agenda.days" :key="day.date" class="border-b border-surface-variant/60 last:border-0">
            <p class="bg-surface-container-low px-md py-xs font-caption text-caption font-semibold uppercase tracking-wide text-on-surface-variant">{{ day.label }}</p>
            <component
                :is="item.href ? Link : 'div'"
                v-for="(item, i) in day.items"
                :key="day.date + i"
                :href="item.href ?? undefined"
                :class="['flex items-center gap-sm px-md py-sm', item.href ? 'hover:bg-surface-container-low' : '', item.past ? 'opacity-60' : '']"
            >
                <span class="w-12 shrink-0 font-code text-code text-on-surface">{{ item.time ?? '—' }}</span>
                <span :class="['shrink-0 rounded-full px-sm py-0.5 font-caption text-caption font-semibold', kinds[item.kind] ?? 'bg-surface-container-high text-on-surface-variant']">{{ item.kindLabel }}</span>
                <span class="min-w-0">
                    <span class="block truncate font-body-medium text-body-medium text-on-surface">{{ item.title }}</span>
                    <span v-if="item.subtitle" class="block truncate font-caption text-caption text-on-surface-variant">{{ item.subtitle }}</span>
                </span>
            </component>
        </div>
        <p v-if="agenda.more" class="px-md py-sm font-caption text-caption text-on-surface-variant">+{{ formatNumber(agenda.more) }} lịch nữa</p>
        <UiEmptyState v-if="!agenda.days.length" icon="event_available" title="Không có lịch hẹn trong 7 ngày tới" />
    </section>
</template>
