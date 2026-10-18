<script setup>
/**
 * Báo cáo quý Học thuật (mockup 02/20 "Báo cáo quý — Học thuật"): góc phải chọn tháng bắt đầu kỳ + hiện kỳ báo cáo; trái là các
 * khối số liệu tự động của quý (ghi chú + task follow-up từng khối) và "Đánh giá chung" (chủ dự án 17/10/2026 bỏ các ô "Nội dung báo cáo",
 * AcademicPeriodForm); phải là danh sách báo cáo tháng trong kỳ (bấm để mở). Mỗi quý 1 báo cáo, lưu lại là cập nhật.
 */
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import ReportTabs from './ReportTabs.vue';
import ReportReviewState from './ReportReviewState.vue';
import ReportHistory from './ReportHistory.vue';
import AcademicPeriodForm from './AcademicPeriodForm.vue';

defineOptions({ layout: { title: 'Báo cáo quý Học thuật' } });

const props = defineProps({
    tabs: { type: Array, default: () => [] },
    quarter: { type: String, required: true },
    quarterLabel: { type: String, default: '' },
    quarters: { type: Array, default: () => [] },
    fields: { type: Object, default: () => ({}) },
    values: { type: Object, default: () => ({}) },
    submittedAt: { type: String, default: null },
    review: { type: Object, default: null },
    monthlyReports: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
    sections: { type: Object, default: () => ({}) },
    computedAt: { type: String, default: null },
    live: { type: Boolean, default: true },
    noteLabels: { type: Object, default: () => ({}) },
    notes: { type: Object, default: () => ({}) },
    draftKey: { type: String, default: 'report-draft:academic-quarterly' },
    sectionTasks: { type: Object, default: () => ({}) },
    assignees: { type: Array, default: () => [] },
    today: { type: String, required: true },
    legacyFields: { type: Array, default: () => [] },
});

const placeholders = {
    next_plan: 'Định hướng và mục tiêu công việc cho quý tiếp theo...',
    program_progress: 'Đánh giá tiến độ...',
    implementation_quality: 'Đánh giá chất lượng...',
    improvement_priorities: 'Các điểm cần khắc phục...',
};
// Quý "2026-Q4" ↔ tháng bắt đầu 10/2026, kỳ "Tháng 10 - Tháng 12 / Năm 2026".
const parse = (key) => {
    const [, year, q] = /^(\d{4})-Q([1-4])$/.exec(key) ?? [null, '', '1'];
    const start = (Number(q) - 1) * 3 + 1;
    return { year, start, end: start + 2 };
};
const startOptions = computed(() => props.quarters.map((o) => ({ value: o.value, label: `Tháng ${parse(o.value).start}/${parse(o.value).year}` })));
const period = computed(() => parse(props.quarter));
const pickQuarter = (value) => value && value !== props.quarter && router.get(route('reports.periodic.academic-quarterly'), { quarter: value });
</script>

<template>
    <UiPageHeader title="Báo cáo quý — Học thuật" icon="calendar_view_month">
        <template #meta>Hệ thống Quản trị Học thuật</template>
        <template #actions>
            <div class="flex items-stretch gap-lg rounded-xl border border-surface-variant bg-surface-container-lowest px-lg py-sm shadow-sm" data-quarter-period>
                <div>
                    <p class="mb-xs font-body-small text-body-small font-semibold uppercase text-on-surface-variant">Tháng bắt đầu</p>
                    <UiSelect :model-value="quarter" :options="startOptions" :searchable="false" aria-label="Tháng bắt đầu kỳ báo cáo" @update:model-value="pickQuarter" />
                </div>
                <div class="border-l border-surface-variant pl-lg">
                    <p class="mb-xs font-body-small text-body-small font-semibold uppercase text-on-surface-variant">Kỳ báo cáo</p>
                    <p class="font-semibold text-on-surface">Tháng {{ period.start }} - Tháng {{ period.end }} / Năm {{ period.year }}</p>
                </div>
            </div>
        </template>
    </UiPageHeader>

    <ReportTabs :tabs="tabs" />
    <div v-if="review?.status === 'returned' || review?.status === 'reviewed'" class="mb-lg"><ReportReviewState :review="review" /></div>

    <div class="grid grid-cols-1 gap-lg xl:grid-cols-[1fr_340px]">
        <AcademicPeriodForm
            id="academic-quarterly-form"
            :action="route('reports.periodic.academic-quarterly.store')"
            period-field="quarter"
            :period="quarter"
            :refresh-url="route('reports.periodic.academic-quarterly')"
            overall-title="Đánh giá chung"
            :fields="fields"
            :values="values"
            :placeholders="placeholders"
            :sections="sections"
            :computed-at="computedAt"
            :live="live"
            :note-labels="noteLabels"
            :notes="notes"
            :draft-key="draftKey"
            :section-tasks="sectionTasks"
            :assignees="assignees"
            :today="today"
            :legacy-fields="legacyFields"
            :submitted-at="submittedAt"
            submit-label="Lưu báo cáo quý"
            class="min-w-0"
        />

        <div class="space-y-lg">
            <section class="overflow-hidden rounded-xl border border-surface-variant bg-surface-container-lowest shadow-sm" data-quarter-months>
                <h2 class="border-b border-surface-variant bg-surface-container-low px-lg py-md font-h3 text-h3 text-on-surface">Danh sách báo cáo tháng trong kỳ</h2>
                <Link
                    v-for="m in monthlyReports"
                    :key="m.month"
                    :href="route('reports.periodic.academic-monthly', { month: m.month })"
                    class="flex items-center gap-sm border-b border-surface-variant px-lg py-md transition-colors last:border-b-0 hover:bg-surface-container-low"
                >
                    <span class="material-symbols-outlined text-[22px] text-secondary" aria-hidden="true">description</span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-on-surface">{{ m.label }}</span>
                        <span class="block font-body-small text-body-small" :class="m.submitted ? 'text-tertiary' : 'text-on-surface-variant'">{{ m.submitted ? `Đã nộp ${formatDate(m.updated_at, 'd/m/Y')}` : 'Chưa nộp' }}</span>
                    </span>
                    <span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">chevron_right</span>
                </Link>
            </section>
            <ReportHistory :items="history" :current="quarter" title="Các quý đã nộp" />
        </div>
    </div>
</template>
