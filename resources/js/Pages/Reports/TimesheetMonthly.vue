<script setup>
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { formatDate } from '@/lib/format';
import ReportTabs from './ReportTabs.vue';

defineOptions({ layout: { title: 'Báo cáo chấm công tháng' } });
const props = defineProps({
    tabs: { type: Array, default: () => [] },
    month: { type: String, required: true },
    monthLabel: String,
    months: { type: Array, default: () => [] },
    note: String,
    submitted_at: String,
    pending: { type: Array, default: () => [] },
});
const pickMonth = (value) => value && value !== props.month && router.get(route('reports.periodic.timesheet-monthly'), { month: value });
</script>

<template>
    <ReportTabs :tabs="tabs" />
    <UiPageHeader title="Báo cáo chấm công tháng" description="Nộp bất cứ lúc nào, không có hạn nộp. Nộp lại để cập nhật báo cáo của tháng đã chọn.">
        <template #actions>
            <UiSelect
                label="Tháng báo cáo"
                :model-value="month"
                :options="months.some((item) => item.value === month) ? months : [{ value: month, label: monthLabel }, ...months]"
                @update:model-value="pickMonth"
            />
        </template>
    </UiPageHeader>
    <UiForm :key="month" :action="route('reports.periodic.teacher-attendance.store')" method="post" class="rounded-xl border border-surface-variant bg-surface-container-lowest p-lg shadow-sm">
        <input type="hidden" name="month" :value="month" />
        <h2 class="flex items-center gap-sm font-h2 text-h2 text-on-surface">
            <span class="material-symbols-outlined text-primary-container" aria-hidden="true">schedule</span>{{ monthLabel }}
        </h2>
        <p :class="['mt-md inline-flex items-center gap-xs rounded-full border px-md py-xs font-body-medium text-body-medium', submitted_at ? 'border-tertiary/20 bg-tertiary-container/10 text-tertiary' : 'border-error/20 bg-error-container/30 text-error']">
            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ submitted_at ? 'check_circle' : 'error' }}</span>
            {{ submitted_at ? 'Đã nộp - ' + formatDate(submitted_at, 'd/m/Y') : 'Chưa nộp' }}
        </p>
        <div class="mt-md space-y-md">
            <UiTextarea name="note" label="Nội dung mô tả (tùy chọn)" :rows="3" placeholder="Nhập ghi chú thêm về chấm công nếu có..." :value="note" />
            <div class="flex justify-end"><UiButton type="submit" icon="send">Nộp báo cáo</UiButton></div>
        </div>
    </UiForm>
</template>
