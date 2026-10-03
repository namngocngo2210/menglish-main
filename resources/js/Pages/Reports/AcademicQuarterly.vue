<script setup>
/**
 * Báo cáo quý Học thuật (mockup "Báo cáo quý"): tình trạng 3 báo cáo tháng trong quý (bấm để mở) + tường thuật theo
 * 2 nhóm "Nội dung báo cáo" và "Đánh giá chung". Mỗi quý 1 báo cáo, lưu lại là cập nhật.
 */
import { Link } from '@inertiajs/vue3';
import ReportTabs from './ReportTabs.vue';
import ReportHistory from './ReportHistory.vue';

defineOptions({ layout: { title: 'Báo cáo quý Học thuật' } });

defineProps({
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
</script>

<template>
    <UiPageHeader title="Báo cáo quý" icon="calendar_view_month">
        <template #meta>{{ quarterLabel }} — tổng hợp từ các báo cáo tháng trong quý</template>
    </UiPageHeader>

    <ReportTabs :tabs="tabs" />

    <UiFilterBar :search="false">
        <UiSelect name="quarter" label="Quý báo cáo" :options="quarters" :value="quarter" />
    </UiFilterBar>

    <div class="grid grid-cols-1 gap-lg lg:grid-cols-[1fr_280px]">
        <div class="space-y-lg">
            <section class="grid grid-cols-1 gap-md sm:grid-cols-3">
                <Link
                    v-for="m in monthlyReports"
                    :key="m.month"
                    :href="route('reports.periodic.academic-monthly', { month: m.month })"
                    class="flex items-center gap-sm rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm transition-colors hover:bg-surface-container-low"
                >
                    <span class="material-symbols-outlined text-[22px]" :class="m.submitted ? 'text-tertiary' : 'text-on-surface-variant'" aria-hidden="true">{{ m.submitted ? 'task_alt' : 'pending' }}</span>
                    <span>
                        <span class="block font-semibold text-on-surface">{{ m.label }}</span>
                        <span class="block font-body-small text-body-small text-on-surface-variant">{{ m.submitted ? `Đã nộp ${formatDate(m.updated_at, 'd/m/Y')}` : 'Chưa nộp' }}</span>
                    </span>
                </Link>
            </section>

            <UiForm id="academic-quarterly-form" :action="route('reports.periodic.academic-quarterly.store')" method="post" class="space-y-lg">
                <input type="hidden" name="quarter" :value="quarter" />
                <UiAlert v-if="submittedAt" type="info">Đã nộp báo cáo quý này lúc {{ formatDate(submittedAt, 'H:i d/m/Y') }} — lưu lại để cập nhật.</UiAlert>
                <section v-for="(fields, title) in sections" :key="title" class="space-y-md rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
                    <h2 class="flex items-center gap-xs font-semibold text-on-surface">
                        <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">edit_note</span>{{ title }}
                    </h2>
                    <UiTextarea v-for="(label, key) in fields" :key="key" :name="`narrative[${key}]`" :label="label" :rows="4" :value="values[key]" />
                </section>
                <div class="flex justify-end">
                    <UiButton type="submit" icon="save">Lưu báo cáo quý</UiButton>
                </div>
            </UiForm>
        </div>

        <ReportHistory :items="history" :current="quarter" title="Các quý đã nộp" />
    </div>
</template>
