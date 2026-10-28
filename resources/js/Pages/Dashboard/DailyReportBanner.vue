<script setup>
/** Học vụ / Học thuật: báo cáo ngày còn thiếu / Admin yêu cầu bổ sung / hôm nay đã nộp chưa (rà soát 16/10/2026). */
import { Link } from '@inertiajs/vue3';

defineProps({ dailyReport: { type: Object, required: true } });
</script>

<template>
    <section
        :class="[
            'flex flex-col gap-3 rounded-2xl border p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between',
            dailyReport.missing.length || dailyReport.returned.length ? 'border-warning/40 bg-warning-container/40' : 'border-surface-container-highest bg-surface-container-lowest',
        ]"
        data-dashboard-daily-report
    >
        <div class="flex min-w-0 items-start gap-3">
            <span :class="['material-symbols-outlined mt-0.5', dailyReport.missing.length || dailyReport.returned.length ? 'text-warning' : 'text-tertiary']" aria-hidden="true">
                {{ dailyReport.missing.length || dailyReport.returned.length ? 'assignment_late' : 'assignment_turned_in' }}
            </span>
            <div class="min-w-0 space-y-1 text-sm">
                <p class="font-bold text-on-surface">Báo cáo ngày</p>
                <p v-if="dailyReport.missing.length" class="text-on-surface">
                    Chưa nộp:
                    <template v-for="(d, i) in dailyReport.missing.slice(0, 5)" :key="d.href">
                        <Link :href="d.href" class="font-semibold text-primary underline">{{ d.label }}</Link><template v-if="i < Math.min(dailyReport.missing.length, 5) - 1">, </template>
                    </template>
                    <template v-if="dailyReport.missing.length > 5"> và {{ dailyReport.missing.length - 5 }} ngày khác</template>
                    <span v-if="!dailyReport.missing[0].overdue" class="text-on-surface-variant"> · hạn {{ dailyReport.missing[0].deadline }}</span>
                </p>
                <p v-for="r in dailyReport.returned" :key="r.href" class="text-on-surface">
                    Admin yêu cầu bổ sung báo cáo <Link :href="r.href" class="font-semibold text-primary underline">{{ r.label }}</Link><template v-if="r.note">: {{ r.note }}</template>
                </p>
                <p v-if="!dailyReport.missing.length && !dailyReport.returned.length" class="text-on-surface-variant">Đã nộp đủ các ngày trước.</p>
                <p class="text-on-surface-variant">Hôm nay: {{ dailyReport.todaySubmitted ? 'đã nộp' : 'chưa nộp (hạn 09:00 sáng mai)' }}</p>
            </div>
        </div>
        <UiButton :href="dailyReport.missing.length ? dailyReport.missing[0].href : dailyReport.todayHref" icon="edit_note" size="sm">
            {{ dailyReport.missing.length ? 'Nộp báo cáo ' + dailyReport.missing[0].label : 'Viết báo cáo hôm nay' }}
        </UiButton>
    </section>
</template>
