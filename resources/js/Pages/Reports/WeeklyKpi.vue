<script setup>
/**
 * Báo cáo tuần Học vụ (mockup 02/07 + chủ dự án 15/10/2026): số lần phát sinh theo từng mục KPI và chỉ số quy mô đều do hệ thống
 * tính cho cả tuần (StaffReportController::weeklyKpi → WeeklyReportMetrics), người viết chỉ nhập ghi chú tuần. Mục KPI có nguồn
 * tự động đếm như phiếu KPI tháng, còn lại đếm sự vụ trong Nhật ký Học vụ gắn mã KPI. Ghi chú các báo cáo ngày trong tuần hiện kèm.
 * Chủ dự án 17/10/2026: mỗi khối có task follow-up riêng, việc chưa xong tuần trước hiện lại đúng khối đó (SectionTasks).
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import ReportTabs from './ReportTabs.vue';
import ReportHistory from './ReportHistory.vue';
import ReportReviewState from './ReportReviewState.vue';
import { useNoteDraft } from './useNoteDraft';
import DailyMetric from './DailyMetric.vue';
import WeeklyKpiTable from './WeeklyKpiTable.vue';
import SectionTasks from './SectionTasks.vue';

defineOptions({ layout: { title: 'Báo cáo tuần Học vụ' } });

const props = defineProps({
    tabs: { type: Array, default: () => [] },
    week: { type: String, required: true },
    weeks: { type: Array, default: () => [] },
    groups: { type: Array, default: () => [] },
    metrics: { type: Object, default: () => ({ items: [] }) },
    daily: { type: Object, default: () => ({ submitted: 0, expected: 0, missing: [], notes: [] }) },
    computedAt: { type: String, default: null },
    live: { type: Boolean, default: true },
    note: { type: String, default: null },
    draftKey: { type: String, default: 'report-draft:weekly-kpi' },
    review: { type: Object, default: null },
    legacy: { type: Array, default: () => [] },
    submittedAt: { type: String, default: null },
    history: { type: Array, default: () => [] },
    sectionTasks: { type: Object, default: () => ({}) },
    assignees: { type: Array, default: () => [] },
    today: { type: String, required: true },
});

const pickWeek = (value) => value && value !== props.week && router.get(route('reports.periodic.weekly-kpi'), { week: value });
// Giữ nguyên ghi chú đang gõ, chỉ thay số liệu (rà soát 16/10/2026 B5); ghi chú còn lưu nháp trên máy khi đổi tuần.
const refresh = () => router.get(route('reports.periodic.weekly-kpi'), { week: props.week, refresh: 1 }, { preserveScroll: true, preserveState: true });
const { notes: draft, restored, discard, clear } = useNoteDraft(props.draftKey, { note: props.note ?? '' });

let uid = 0;
const newTasks = ref([]);
const addTask = (section) => newTasks.value.push({ uid: ++uid, section });
const removeTask = (t) => (newTasks.value = newTasks.value.filter((x) => x.uid !== t.uid));
const rowsIn = (section) => newTasks.value.filter((t) => t.section === section);
const indexOf = (t) => newTasks.value.indexOf(t);
const taskProps = (section) => ({ section, tasks: props.sectionTasks[section] ?? [], rows: rowsIn(section), indexOf, assignees: props.assignees, today: props.today });
const onSaved = () => {
    clear();
    newTasks.value = [];
};
</script>

<template>
    <UiPageHeader title="Báo cáo tuần" icon="assignment_turned_in">
        <template #meta>
            <span class="inline-flex items-center gap-xs">
                Số liệu lấy tự động từ hệ thống, bạn chỉ cần ghi chú.
                <UiInfo title="Số liệu báo cáo tuần">
                    <p>Mọi số được tính lại từ dữ liệu gốc cho cả tuần (thứ 2 → chủ nhật), cùng cách đếm của báo cáo ngày, nên ngày quên nộp báo cáo ngày vẫn đủ số.</p>
                    <p>Mục KPI ghi <strong class="text-on-surface">Tự động</strong> đếm như phiếu KPI tháng. Mục ghi <strong class="text-on-surface">Nhật ký</strong> đếm sự vụ trong Nhật ký Học vụ có gắn mã KPI đó: thiếu thì ghi thêm ở Nhật ký Học vụ rồi bấm Cập nhật số liệu.</p>
                    <p>Khi bấm Lưu, số liệu lúc đó được lưu vào báo cáo để Admin xem đúng số bạn đã thấy.</p>
                </UiInfo>
            </span>
        </template>
        <template #actions>
            <div class="w-72">
                <p class="mb-xs font-body-small text-body-small font-semibold text-on-surface-variant">Tuần báo cáo</p>
                <UiSelect :model-value="week" :options="weeks" :searchable="false" aria-label="Tuần báo cáo" @update:model-value="pickWeek" />
            </div>
        </template>
    </UiPageHeader>

    <ReportTabs :tabs="tabs" />

    <UiForm id="weekly-kpi-form" :action="route('reports.periodic.weekly-kpi.store')" method="post" class="space-y-lg" @success="onSaved">
        <input type="hidden" name="week" :value="week" />
        <input type="hidden" name="refresh" :value="live && submittedAt ? 1 : 0" />
        <ReportReviewState :review="review" />
        <UiAlert v-if="submittedAt" type="info">
            Đã nộp tuần này lúc {{ formatDate(submittedAt, 'H:i d/m/Y') }}.
            <template v-if="!live">Số liệu bên dưới là số lúc nộp, sửa ghi chú không làm đổi số.</template>
            <template v-else>Số liệu bên dưới vừa được tính lại, bấm Lưu để cập nhật báo cáo bằng số mới.</template>
        </UiAlert>
        <UiAlert v-if="restored" type="info">
            Đã khôi phục ghi chú bạn gõ trên máy này nhưng chưa lưu.
            <button type="button" class="ml-xs font-semibold underline" @click="discard">Bỏ bản nháp</button>
        </UiAlert>

        <div class="flex flex-wrap items-center justify-between gap-sm">
            <p class="font-body-small text-body-small text-on-surface-variant" data-computed-at>
                <span class="material-symbols-outlined align-middle text-[16px]" aria-hidden="true">sync</span>
                Số liệu tính lúc {{ computedAt ? formatDate(computedAt, 'H:i d/m/Y') : '—' }}
            </p>
            <UiButton type="button" variant="ghost" size="sm" icon="refresh" data-refresh-metrics @click="refresh">Cập nhật số liệu</UiButton>
        </div>

        <section v-if="legacy.length" class="space-y-sm rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm" data-weekly-legacy>
            <h2 class="font-h3 text-h3 text-on-surface">Số liệu đã nhập tay (bản cũ)</h2>
            <p class="font-body-small text-body-small text-on-surface-variant">Báo cáo này nộp trước khi chuyển sang số liệu tự động. Lưu lại sẽ thay bằng số liệu hệ thống.</p>
            <dl class="grid grid-cols-1 gap-sm md:grid-cols-2">
                <div v-for="f in legacy" :key="f.label">
                    <dt class="font-label text-label uppercase text-on-surface-variant">{{ f.label }}</dt>
                    <dd class="font-body-medium text-body-medium text-on-surface">{{ f.value }}</dd>
                </div>
            </dl>
        </section>

        <div class="grid grid-cols-1 gap-lg lg:grid-cols-[1fr_380px]">
            <section class="min-w-0 rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm" data-weekly-kpi>
                <h2 class="flex items-center gap-xs border-b border-surface-container-highest px-lg py-md font-semibold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">assignment_turned_in</span>Số lần phát sinh theo mục KPI
                </h2>
                <WeeklyKpiTable :groups="groups" />
                <div class="px-lg pb-lg">
                    <SectionTasks v-bind="taskProps('kpi')" title="Task follow-up · Mục KPI" @add="addTask('kpi')" @remove="removeTask" />
                </div>
            </section>

            <div class="min-w-0 space-y-lg">
                <section class="space-y-md rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm" data-weekly-metrics>
                    <h2 class="flex items-center gap-xs font-semibold text-on-surface">
                        <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">monitoring</span>Thông tin theo dõi
                    </h2>
                    <p class="font-body-small text-body-small text-on-surface-variant">(Chỉ số quy mô, không tính KPI)</p>
                    <div class="space-y-sm">
                        <DailyMetric v-for="item in metrics.items" :key="item.key" :item="item" />
                    </div>
                    <SectionTasks v-bind="taskProps('metrics')" title="Task follow-up" @add="addTask('metrics')" @remove="removeTask" />
                </section>
            </div>
        </div>

        <section class="space-y-md rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm" data-weekly-daily>
            <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                <span class="material-symbols-outlined text-primary-container" aria-hidden="true">event_note</span>Ghi chú tuần
            </h2>
            <p class="font-body-small text-body-small text-on-surface-variant" data-daily-submitted>
                Báo cáo ngày đã nộp {{ daily.submitted }}/{{ daily.expected }} ngày<template v-if="daily.missing.length"> · còn thiếu {{ daily.missing.join(', ') }}</template>.
            </p>
            <div v-if="daily.notes.length" class="grid grid-cols-1 gap-md md:grid-cols-3">
                <div v-for="n in daily.notes" :key="n.key" class="rounded-lg border border-surface-variant bg-surface-container-low p-md">
                    <p class="mb-xs font-label text-label uppercase text-on-surface-variant">{{ n.label }}</p>
                    <ul class="space-y-xs">
                        <li v-for="(l, i) in n.lines" :key="i" class="whitespace-pre-line font-body-small text-body-small text-on-surface">{{ l.text }}</li>
                    </ul>
                </div>
            </div>
            <UiTextarea v-model="draft.note" name="note" label="Ghi chú tuần" :rows="3" placeholder="Tổng kết tuần, việc cần lưu ý tuần tới..." />
            <SectionTasks v-bind="taskProps('week')" title="Task follow-up tuần tới" hint="Việc cần làm tiếp tuần tới thì thêm ở đây, chưa xong sẽ hiện lại ở báo cáo tuần sau." @add="addTask('week')" @remove="removeTask" />
        </section>

        <div class="flex justify-end">
            <UiButton type="submit" icon="save">Lưu báo cáo tuần</UiButton>
        </div>
    </UiForm>

    <div class="mt-lg">
        <ReportHistory :items="history" :current="week" title="Các tuần đã nộp" />
    </div>
</template>
