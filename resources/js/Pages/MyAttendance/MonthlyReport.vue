<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    month: { type: String, required: true },
    months: { type: Array, required: true },
    summary: { type: Object, required: true },
    report: { type: Object, default: null },
});
</script>

<template>
    <Head title="Báo cáo chấm công tháng" />
    <UiPageHeader title="Báo cáo chấm công tháng" icon="description" :back="route('my-attendance.history', { month })" description="Kiểm tra tổng hợp công và nộp báo cáo chấm công của bạn." />

    <div class="max-w-3xl space-y-md">
        <UiForm :action="route('my-attendance.monthly-report')" method="get" class="flex flex-wrap items-end gap-sm">
            <UiSelect name="month" label="Tháng báo cáo" :value="month" :options="months" required />
            <UiButton type="submit" variant="secondary">Xem</UiButton>
        </UiForm>

        <h2 class="font-body-semibold text-body-semibold text-on-surface">Tháng {{ month.slice(5) }}/{{ month.slice(0, 4) }}</h2>
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

        <section v-if="report" class="space-y-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
            <UiBadge color="success">Đã nộp - {{ formatDate(report.submitted_at, 'd/m/Y') }}</UiBadge>
            <p v-if="report.note" class="whitespace-pre-wrap text-on-surface">{{ report.note }}</p>
        </section>
        <UiForm v-else-if="months.some(option => option.value === month)" :key="month" :action="route('my-attendance.monthly-report.store')" method="post" class="space-y-md">
            <input type="hidden" name="month" :value="month" />
            <UiTextarea name="note" label="Ghi chú (không bắt buộc)" :maxlength="1000" hint="Tối đa 1000 ký tự." />
            <UiButton type="submit" icon="send">Nộp báo cáo</UiButton>
        </UiForm>
        <UiAlert v-else>Chỉ được nộp báo cáo tháng hiện tại hoặc tháng trước.</UiAlert>
    </div>
</template>
