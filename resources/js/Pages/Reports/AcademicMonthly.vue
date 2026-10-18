<script setup>
/**
 * Báo cáo tháng Học thuật (mockup 02/19 "Báo cáo tháng — Học thuật"): trái là các khối số liệu tự động của tháng, mỗi khối có ghi chú
 * và task follow-up, cuối là "Đánh giá chung tháng" (chủ dự án 17/10/2026 bỏ các ô tường thuật, AcademicPeriodForm); phải là bảng
 * báo cáo tuần trong tháng (Tuần / Khoảng ngày / PIC, bấm để xem) và các buổi họp giáo viên đã ghi. Mỗi tháng 1 báo cáo, lưu lại là cập nhật.
 */
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import ReportTabs from './ReportTabs.vue';
import ReportReviewState from './ReportReviewState.vue';
import ReportHistory from './ReportHistory.vue';
import AcademicPeriodForm from './AcademicPeriodForm.vue';

defineOptions({ layout: { title: 'Báo cáo tháng Học thuật' } });

const props = defineProps({
    tabs: { type: Array, default: () => [] },
    month: { type: String, required: true },
    months: { type: Array, default: () => [] },
    fields: { type: Object, default: () => ({}) },
    values: { type: Object, default: () => ({}) },
    submittedAt: { type: String, default: null },
    review: { type: Object, default: null },
    weeklyReports: { type: Array, default: () => [] },
    meetings: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
    sections: { type: Object, default: () => ({}) },
    computedAt: { type: String, default: null },
    live: { type: Boolean, default: true },
    noteLabels: { type: Object, default: () => ({}) },
    notes: { type: Object, default: () => ({}) },
    draftKey: { type: String, default: 'report-draft:academic-monthly' },
    sectionTasks: { type: Object, default: () => ({}) },
    assignees: { type: Array, default: () => [] },
    today: { type: String, required: true },
    legacyFields: { type: Array, default: () => [] },
});

const viewing = ref(null);
const placeholders = { overall: 'Nhận xét tổng quan về hoạt động học thuật trong tháng, việc cần ưu tiên tháng tới...' };
const pickMonth = (value) => value && value !== props.month && router.get(route('reports.periodic.academic-monthly'), { month: value });
</script>

<template>
    <UiPageHeader title="Báo cáo tháng — Học thuật" icon="summarize">
        <template #actions>
            <div class="flex items-center gap-sm">
                <span class="whitespace-nowrap font-body-small text-body-small text-on-surface-variant">Chọn tháng:</span>
                <UiSelect :model-value="month" :options="months" :searchable="false" aria-label="Chọn tháng" @update:model-value="pickMonth" />
            </div>
        </template>
    </UiPageHeader>

    <ReportTabs :tabs="tabs" />
    <div v-if="review?.status === 'returned' || review?.status === 'reviewed'" class="mb-lg"><ReportReviewState :review="review" /></div>

    <div class="grid grid-cols-1 gap-lg xl:grid-cols-[1fr_360px]">
        <AcademicPeriodForm
            id="academic-monthly-form"
            :action="route('reports.periodic.academic-monthly.store')"
            period-field="month"
            :period="month"
            :refresh-url="route('reports.periodic.academic-monthly')"
            overall-title="Đánh giá chung tháng"
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
            submit-label="Lưu báo cáo tháng"
            class="min-w-0"
        />

        <div class="space-y-lg">
            <section class="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm" data-weekly-in-month>
                <h2 class="flex items-center gap-xs px-lg py-md font-semibold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">table_rows</span>Báo cáo tuần trong tháng
                </h2>
                <UiDataTable>
                    <table>
                        <thead>
                            <tr>
                                <th>Tuần</th>
                                <th>Khoảng ngày</th>
                                <th>PIC</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="w in weeklyReports" :key="w.week" :class="w.content ? 'cursor-pointer hover:bg-surface-container-low' : ''" @click="w.content && (viewing = w)">
                                <td class="font-semibold">{{ w.label }}</td>
                                <td class="font-code text-on-surface-variant">{{ w.range }}</td>
                                <td>
                                    <span v-if="w.pic">{{ w.pic }}</span>
                                    <Link v-else :href="w.href" class="font-body-small text-body-small italic text-on-surface-variant hover:text-primary" @click.stop>Chưa nộp</Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>
            </section>

            <section class="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm" data-meetings-in-month>
                <h2 class="flex items-center gap-xs px-lg py-md font-semibold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">groups</span>Họp giáo viên
                    <UiButton variant="ghost" size="sm" class="ml-auto" :href="route('class-quality.teacher-meetings')">Mở danh sách</UiButton>
                </h2>
                <UiDataTable>
                    <table>
                        <thead>
                            <tr>
                                <th>Ngày</th>
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
                            <tr v-if="!meetings.length">
                                <td colspan="3" class="font-body-small text-body-small italic text-on-surface-variant">Chưa ghi buổi họp giáo viên nào trong tháng này.</td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>
            </section>

            <ReportHistory :items="history" :current="month" title="Các tháng đã nộp" />
        </div>
    </div>

    <UiModal :show="!!viewing" :title="viewing ? `${viewing.label} (${viewing.range}) · ${viewing.title}` : ''" max-width="2xl" @close="viewing = null">
        <p v-if="viewing" class="whitespace-pre-line text-on-surface">{{ viewing.content }}</p>
        <template #footer>
            <UiButton v-if="viewing" variant="secondary" :href="viewing.href">Mở báo cáo tuần</UiButton>
            <UiButton variant="secondary" @click="viewing = null">Đóng</UiButton>
        </template>
    </UiModal>
</template>
