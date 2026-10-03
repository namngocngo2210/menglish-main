<script setup>
/** Lịch sử công của tôi theo tháng: tổng hợp (ngày công, đi muộn, nghỉ phép) + từng ngày vào / ra kèm ảnh. */
import { Head, Link } from '@inertiajs/vue3';
import MobileLayout from '@/Layouts/MobileLayout.vue';

defineOptions({ layout: MobileLayout });

defineProps({
    month: { type: String, required: true },
    monthLabel: { type: String, required: true },
    prevMonth: { type: String, required: true },
    nextMonth: { type: String, default: null },
    summary: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
    leaves: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Lịch sử công" />

    <div class="space-y-md">
        <div class="flex items-center justify-between gap-sm rounded-2xl border border-outline-variant bg-surface-container-lowest p-xs shadow-sm">
            <Link :href="route('mobile.history', { month: prevMonth })" class="flex h-11 w-11 items-center justify-center rounded-xl hover:bg-surface-container-low" aria-label="Tháng trước">
                <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
            </Link>
            <h2 class="font-body-semibold text-body-semibold text-on-surface">{{ monthLabel }}</h2>
            <Link v-if="nextMonth" :href="route('mobile.history', { month: nextMonth })" class="flex h-11 w-11 items-center justify-center rounded-xl hover:bg-surface-container-low" aria-label="Tháng sau">
                <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
            </Link>
            <span v-else class="h-11 w-11" aria-hidden="true"></span>
        </div>

        <div class="grid grid-cols-3 gap-sm" data-month-summary>
            <div class="rounded-xl bg-surface-container-lowest p-sm text-center shadow-sm">
                <p class="font-h3 text-h3 text-on-surface">{{ summary.days }}</p>
                <p class="font-caption text-caption text-on-surface-variant">Ngày công</p>
            </div>
            <div class="rounded-xl bg-surface-container-lowest p-sm text-center shadow-sm">
                <p class="font-h3 text-h3" :class="summary.late_count ? 'text-error' : 'text-on-surface'">{{ summary.late_count }}</p>
                <p class="font-caption text-caption text-on-surface-variant">Đi muộn ({{ summary.late_minutes }}′)</p>
            </div>
            <div class="rounded-xl bg-surface-container-lowest p-sm text-center shadow-sm">
                <p class="font-h3 text-h3 text-on-surface">{{ summary.leave_days }}</p>
                <p class="font-caption text-caption text-on-surface-variant">Nghỉ phép</p>
            </div>
        </div>

        <ul v-if="rows.length" class="space-y-sm">
            <li v-for="row in rows" :key="row.id" class="flex items-center gap-sm rounded-2xl border border-outline-variant bg-surface-container-lowest p-sm shadow-sm">
                <div class="w-14 shrink-0 text-center">
                    <p class="font-h3 text-h3 text-on-surface">{{ formatDate(row.date, 'd') }}</p>
                    <p class="font-caption text-caption text-on-surface-variant">{{ formatDate(row.date, 'l') }}</p>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="font-mono text-body-medium text-on-surface">{{ row.check_in ?? '--:--' }} → {{ row.check_out ?? '--:--' }}</p>
                    <UiBadge :color="row.status_tone" class="mt-0.5">{{ row.status_label }}</UiBadge>
                    <p v-if="row.source === 'request'" class="font-caption text-caption text-on-surface-variant">Bổ sung công đã duyệt</p>
                    <p v-if="row.penalty" class="font-caption text-caption text-error">Biên bản {{ row.penalty.code }}</p>
                </div>
                <img v-if="row.check_in_photo" :src="row.check_in_photo" alt="" class="h-12 w-12 shrink-0 rounded-lg object-cover" loading="lazy" />
            </li>
        </ul>
        <UiEmptyState v-else compact icon="event_busy" title="Chưa có ngày công nào trong tháng" />

        <section v-if="leaves.length">
            <h2 class="mb-sm font-body-semibold text-body-semibold text-on-surface">Nghỉ có phép</h2>
            <ul class="space-y-xs">
                <li v-for="leave in leaves" :key="leave.id" class="rounded-xl bg-surface-container-lowest p-sm font-body-small text-body-small shadow-sm">
                    <span class="font-body-semibold text-body-semibold">{{ leave.period }}</span> · {{ leave.reason }}
                </li>
            </ul>
        </section>
    </div>
</template>
