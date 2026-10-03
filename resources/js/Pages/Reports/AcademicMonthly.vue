<script setup>
/**
 * Báo cáo tháng Học thuật (mockup "Báo cáo tháng"): tham chiếu các báo cáo tuần đã nộp trong tháng và các buổi họp giáo viên
 * đã ghi; nhập tường thuật theo 4 mục. Mỗi tháng 1 báo cáo, lưu lại là cập nhật.
 */
import { ref } from 'vue';
import { formatDate } from '@/lib/format';
import ReportTabs from './ReportTabs.vue';
import ReportHistory from './ReportHistory.vue';

defineOptions({ layout: { title: 'Báo cáo tháng Học thuật' } });

const props = defineProps({
    tabs: { type: Array, default: () => [] },
    month: { type: String, required: true },
    months: { type: Array, default: () => [] },
    fields: { type: Object, default: () => ({}) },
    values: { type: Object, default: () => ({}) },
    submittedAt: { type: String, default: null },
    weeklyReports: { type: Array, default: () => [] },
    meetings: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
});

const viewing = ref(null);
const monthLabel = props.months.find((m) => m.value === props.month)?.label ?? props.month;
const week = (start, end) => `${formatDate(start, 'd/m')} – ${formatDate(end, 'd/m')}`;
</script>

<template>
    <UiPageHeader title="Báo cáo tháng" icon="summarize">
        <template #meta>Tổng hợp công việc học thuật của {{ monthLabel.toLowerCase() }} từ các báo cáo tuần và buổi họp giáo viên</template>
    </UiPageHeader>

    <ReportTabs :tabs="tabs" />

    <UiFilterBar :search="false">
        <UiSelect name="month" label="Tháng báo cáo" :options="months" :value="month" />
    </UiFilterBar>

    <div class="grid grid-cols-1 gap-lg lg:grid-cols-[1fr_280px]">
        <div class="space-y-lg">
            <section class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
                <h2 class="mb-md flex items-center gap-xs font-semibold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">date_range</span>Báo cáo tuần trong tháng
                </h2>
                <ul v-if="weeklyReports.length" class="divide-y divide-surface-container-highest">
                    <li v-for="r in weeklyReports" :key="r.id">
                        <button type="button" class="flex w-full items-center justify-between gap-md py-sm text-left hover:text-primary" @click="viewing = r">
                            <span>
                                <span class="font-semibold">{{ r.title }}</span>
                                <span class="block font-body-small text-body-small text-on-surface-variant">Tuần {{ week(r.week_start, r.week_end) }}</span>
                            </span>
                            <span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">chevron_right</span>
                        </button>
                    </li>
                </ul>
                <p v-else class="font-body-small text-body-small italic text-on-surface-variant">Chưa nộp báo cáo tuần nào trong tháng này.</p>
            </section>

            <section class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
                <h2 class="mb-md flex items-center gap-xs font-semibold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">groups</span>Họp giáo viên trong tháng
                    <UiButton variant="ghost" size="sm" class="ml-auto" :href="route('class-quality.teacher-meetings')">Mở danh sách</UiButton>
                </h2>
                <UiDataTable v-if="meetings.length">
                    <table>
                        <thead>
                            <tr>
                                <th>Tuần</th>
                                <th>Giáo viên</th>
                                <th>Tình trạng</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in meetings" :key="m.id">
                                <td class="font-code">{{ formatDate(m.week_start, 'd/m/Y') }}</td>
                                <td>{{ m.teacher }}</td>
                                <td><UiBadge :color="m.status_color">{{ m.status_label }}</UiBadge></td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>
                <p v-else class="font-body-small text-body-small italic text-on-surface-variant">Chưa ghi buổi họp giáo viên nào trong tháng này.</p>
            </section>

            <UiForm id="academic-monthly-form" :action="route('reports.periodic.academic-monthly.store')" method="post" class="space-y-lg">
                <input type="hidden" name="month" :value="month" />
                <UiAlert v-if="submittedAt" type="info">Đã nộp báo cáo tháng này lúc {{ formatDate(submittedAt, 'H:i d/m/Y') }} — lưu lại để cập nhật.</UiAlert>
                <section class="space-y-md rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm">
                    <h2 class="flex items-center gap-xs font-semibold text-on-surface">
                        <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">edit_note</span>Nội dung báo cáo
                    </h2>
                    <UiTextarea v-for="(label, key) in fields" :key="key" :name="`narrative[${key}]`" :label="label" :rows="4" :value="values[key]" />
                </section>
                <div class="flex justify-end">
                    <UiButton type="submit" icon="save">Lưu báo cáo tháng</UiButton>
                </div>
            </UiForm>
        </div>

        <ReportHistory :items="history" :current="month" title="Các tháng đã nộp" />
    </div>

    <UiModal :show="!!viewing" :title="viewing?.title ?? ''" max-width="lg" @close="viewing = null">
        <p v-if="viewing" class="whitespace-pre-line text-on-surface">{{ viewing.content }}</p>
        <template #footer><UiButton variant="secondary" @click="viewing = null">Đóng</UiButton></template>
    </UiModal>
</template>
