<script setup>
/**
 * Báo cáo quý Học thuật (mockup 02/20 "Báo cáo quý — Học thuật"): góc phải chọn tháng bắt đầu kỳ + hiện kỳ báo cáo; trái là
 * "Nội dung báo cáo" (3 ô) và "Đánh giá chung" (3 ô cạnh nhau); phải là danh sách báo cáo tháng trong kỳ (bấm để mở).
 * Mỗi quý 1 báo cáo, lưu lại là cập nhật.
 */
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import ReportTabs from './ReportTabs.vue';
import ReportHistory from './ReportHistory.vue';

defineOptions({ layout: { title: 'Báo cáo quý Học thuật' } });

const props = defineProps({
    tabs: { type: Array, default: () => [] },
    quarter: { type: String, required: true },
    quarterLabel: { type: String, default: '' },
    quarters: { type: Array, default: () => [] },
    sections: { type: Object, default: () => ({}) },
    values: { type: Object, default: () => ({}) },
    submittedAt: { type: String, default: null },
    monthlyReports: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
});

const placeholders = {
    academic_work: 'Nhập chi tiết các hoạt động học thuật đã triển khai trong quý...',
    teacher_staffing: 'Nhập tình hình nhân sự, tuyển dụng, đào tạo giáo viên...',
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
const sectionList = computed(() => Object.entries(props.sections));
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

    <div class="grid grid-cols-1 gap-lg lg:grid-cols-[1fr_340px]">
        <UiForm id="academic-quarterly-form" :action="route('reports.periodic.academic-quarterly.store')" method="post" class="space-y-lg">
            <input type="hidden" name="quarter" :value="quarter" />
            <UiAlert v-if="submittedAt" type="info">Đã nộp báo cáo quý này lúc {{ formatDate(submittedAt, 'H:i d/m/Y') }}. Lưu lại để cập nhật.</UiAlert>
            <section v-for="([title, fields], i) in sectionList" :key="title" class="space-y-md rounded-xl border border-surface-variant bg-surface-container-lowest p-lg shadow-sm" :data-quarter-section="i">
                <h2 :class="['font-h2 text-h2 text-on-surface', i > 0 ? 'border-b border-surface-variant pb-sm' : '']">{{ title }}</h2>
                <div :class="i > 0 ? 'grid grid-cols-1 gap-md md:grid-cols-3' : 'space-y-md'">
                    <UiTextarea v-for="(label, key) in fields" :key="key" :name="`narrative[${key}]`" :label="label" :rows="i > 0 ? 3 : 4" :placeholder="placeholders[key]" :value="values[key]" />
                </div>
            </section>
            <div class="flex justify-end border-t border-surface-variant pt-lg">
                <UiButton type="submit" icon="save">Lưu phần tường thuật</UiButton>
            </div>
        </UiForm>

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
