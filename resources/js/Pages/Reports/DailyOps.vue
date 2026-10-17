<script setup>
/**
 * Báo cáo ngày Học vụ / Học thuật (mockup 02/06 + chủ dự án 15/10/2026): số liệu từng khối lấy từ hệ thống
 * (StaffReportController::daily → DailyReportMetrics), người viết chỉ nhập ghi chú mỗi khối + thêm task follow-up nếu cần.
 * Ngày chưa nộp: số liệu tính trực tiếp. Đã nộp: hiện số đã chụp lúc nộp; sửa ghi chú giữ nguyên số đó, "Cập nhật số liệu"
 * tính lại và Lưu thì ghi số mới. Rà soát 16/10/2026: ghi chú giữ trên máy khi tải lại số liệu / đổi ngày (useNoteDraft),
 * băng nhắc ngày còn thiếu, trạng thái Admin kiểm tra / yêu cầu bổ sung, nhiều task (mỗi task có tiêu đề, hạn, người phụ trách).
 * Chủ dự án 17/10/2026: task tạo ngay trong từng khối (việc chưa xong kỳ trước hiện lại đúng khối đó), khối "Chất lượng buổi học"
 * có góp ý từng buổi của Học vụ + "Báo Admin gấp" (SessionFeedbackRow). Task không thuộc khối nào nằm ở "Task chung" cuối form.
 */
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import ReportTabs from './ReportTabs.vue';
import ReportHistory from './ReportHistory.vue';
import ReportReviewState from './ReportReviewState.vue';
import LegacyReports from './LegacyReports.vue';
import DailyMetric from './DailyMetric.vue';
import SectionTasks from './SectionTasks.vue';
import SessionFeedbackRow from './SessionFeedbackRow.vue';
import { useNoteDraft } from './useNoteDraft';

defineOptions({ layout: { title: 'Báo cáo ngày' } });

const props = defineProps({
    audience: { type: String, default: 'Học vụ' },
    tabs: { type: Array, default: () => [] },
    date: { type: String, required: true },
    dateLabel: { type: String, default: '' },
    today: { type: String, required: true },
    deadline: { type: String, default: null },
    sections: { type: Object, default: () => ({}) },
    computedAt: { type: String, default: null },
    live: { type: Boolean, default: true },
    noteLabels: { type: Object, default: () => ({}) },
    notes: { type: Object, default: () => ({}) },
    draftKey: { type: String, default: 'report-draft:daily' },
    legacyFields: { type: Array, default: () => [] },
    tasks: { type: Array, default: () => [] },
    sectionTasks: { type: Object, default: () => ({}) },
    sessionRows: { type: Array, default: () => [] },
    verdicts: { type: Object, default: () => ({}) },
    assignees: { type: Array, default: () => [] },
    submittedAt: { type: String, default: null },
    firstSubmittedAt: { type: String, default: null },
    late: { type: Boolean, default: false },
    review: { type: Object, default: null },
    missingDays: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
    legacy: { type: Array, default: () => [] },
});

// Mọi khối đều có khóa (chuỗi rỗng) để ô ghi chú là v-model ngay từ đầu.
const { notes: draft, restored, discard, clear } = useNoteDraft(props.draftKey, {
    ...Object.fromEntries(Object.keys(props.sections).map((key) => [key, ''])),
    ...props.notes,
});
const otherMissing = computed(() => props.missingDays.filter((d) => d.date !== props.date));
const picker = ref(null);
const openPicker = () => (picker.value?.showPicker ? picker.value.showPicker() : picker.value?.click());
const pickDate = (event) => event.target.value && event.target.value !== props.date && router.get(route('reports.periodic.daily'), { date: event.target.value });
// Giữ nguyên trang (ghi chú đang gõ, task đang thêm), chỉ thay số liệu.
const refresh = () => router.get(route('reports.periodic.daily'), { date: props.date, refresh: 1 }, { preserveScroll: true, preserveState: true });
// Mục có số → thẻ nhỏ (3 cột); mục chỉ là danh sách (không có số) → cả hàng.
const hasValue = (item) => item.value !== null && item.value !== undefined;

let uid = 0;
// Mọi dòng task mới của cả form chung một danh sách để `tasks[i]` không trùng chỉ số giữa các khối.
const newTasks = ref([]);
const addTask = (section = '', preset = {}) => newTasks.value.push({ uid: ++uid, section, ...preset });
const removeTask = (t) => (newTasks.value = newTasks.value.filter((x) => x.uid !== t.uid));
const rowsIn = (section) => newTasks.value.filter((t) => t.section === section);
const indexOf = (t) => newTasks.value.indexOf(t);
const taskFromSession = (row) => addTask('sessions', { class_id: row.class_id, title: `Theo dõi buổi ${row.class} ${row.time}` });
const onSaved = () => {
    clear();
    newTasks.value = [];
};
</script>

<template>
    <UiPageHeader :title="`Báo cáo ngày ${audience}`" icon="assignment">
        <template #meta>
            <span class="inline-flex items-center gap-xs">
                Số liệu lấy tự động từ hệ thống, bạn chỉ cần ghi chú.
                <UiInfo title="Số liệu báo cáo ngày">
                    <p>Mọi số trong báo cáo được hệ thống tính theo <strong class="text-on-surface">ngày báo cáo</strong> và phạm vi lớp / chi nhánh bạn được xem. Bấm icon "i" ở từng mục để xem cách đếm.</p>
                    <p>Số sai thì sửa ở màn gốc (điểm danh, CRM, phiếu thu, buổi bổ trợ…) rồi bấm <strong class="text-on-surface">Cập nhật số liệu</strong>.</p>
                    <p>Khi bấm Lưu, số liệu lúc đó được lưu vào báo cáo để Admin xem đúng số bạn đã thấy.</p>
                </UiInfo>
            </span>
        </template>
        <template #actions>
            <div class="relative text-right">
                <p class="font-body-small text-body-small text-on-surface-variant">Ngày báo cáo</p>
                <button type="button" class="inline-flex items-center gap-xs font-body-medium text-body-medium text-on-surface hover:text-primary" title="Chọn ngày khác" @click="openPicker">
                    <span data-date-label>{{ dateLabel }}</span>
                    <span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">calendar_month</span>
                </button>
                <input ref="picker" type="date" class="pointer-events-none absolute bottom-0 right-0 h-px w-px opacity-0" tabindex="-1" :value="date" :max="today" aria-label="Chọn ngày báo cáo" @change="pickDate" />
            </div>
        </template>
    </UiPageHeader>

    <ReportTabs :tabs="tabs" />

    <div class="space-y-xl">
        <UiForm id="daily-ops-form" :action="route('reports.periodic.daily.store')" method="post" class="space-y-xl" #default="{ errors }" @success="onSaved">
            <input type="hidden" name="date" :value="date" />
            <!-- Đã nộp mà vừa bấm "Cập nhật số liệu" → Lưu ghi số mới; không thì giữ số đã chụp lúc nộp. -->
            <input type="hidden" name="refresh" :value="live && submittedAt ? 1 : 0" />
            <UiAlert v-if="otherMissing.length" type="warning" data-daily-missing>
                Bạn chưa nộp báo cáo ngày
                <template v-for="(d, i) in otherMissing.slice(0, 6)" :key="d.date">
                    <Link :href="d.href" class="font-semibold underline">{{ d.label }}</Link><template v-if="i < Math.min(otherMissing.length, 6) - 1">, </template>
                </template><template v-if="otherMissing.length > 6"> và {{ otherMissing.length - 6 }} ngày khác (xem danh sách bên dưới)</template>. Ngày không nộp tính là nộp trễ.
            </UiAlert>
            <ReportReviewState :review="review" />
            <UiAlert v-if="submittedAt" type="info" data-daily-submitted>
                Đã nộp lúc {{ formatDate(firstSubmittedAt ?? submittedAt, 'H:i d/m/Y') }}<template v-if="firstSubmittedAt && firstSubmittedAt !== submittedAt">, sửa lần cuối {{ formatDate(submittedAt, 'H:i d/m/Y') }}</template>.
                <UiBadge v-if="late" color="error">Nộp sau hạn {{ deadline }}</UiBadge>
                <template v-if="!live">Số liệu bên dưới là số lúc nộp, sửa ghi chú không làm đổi số.</template>
                <template v-else>Số liệu bên dưới vừa được tính lại, bấm Lưu để cập nhật báo cáo bằng số mới.</template>
            </UiAlert>
            <UiAlert v-else-if="deadline && date < today" type="info">Hạn nộp báo cáo ngày này: {{ deadline }}.</UiAlert>
            <UiAlert v-if="restored" type="info" data-daily-draft-restored>
                Đã khôi phục ghi chú bạn gõ trên máy này nhưng chưa lưu.
                <button type="button" class="ml-xs font-semibold underline" @click="discard">Bỏ bản nháp</button>
            </UiAlert>
            <UiAlert v-if="errors.notes" type="error">{{ errors.notes }}</UiAlert>

            <div class="flex flex-wrap items-center justify-between gap-sm">
                <p class="font-body-small text-body-small text-on-surface-variant" data-computed-at>
                    <span class="material-symbols-outlined align-middle text-[16px]" aria-hidden="true">sync</span>
                    Số liệu tính lúc {{ computedAt ? formatDate(computedAt, 'H:i d/m/Y') : '—' }}
                </p>
                <UiButton type="button" variant="ghost" size="sm" icon="refresh" data-refresh-metrics @click="refresh">Cập nhật số liệu</UiButton>
            </div>

            <section
                v-if="legacyFields.length"
                class="space-y-sm rounded-xl border border-surface-variant bg-surface-container-lowest p-lg shadow-sm"
                data-daily-legacy
            >
                <h2 class="font-h3 text-h3 text-on-surface">Số liệu đã nhập tay (bản cũ)</h2>
                <p class="font-body-small text-body-small text-on-surface-variant">Báo cáo này nộp trước khi chuyển sang số liệu tự động. Lưu lại sẽ thay bằng số liệu hệ thống.</p>
                <dl class="grid grid-cols-1 gap-sm md:grid-cols-2">
                    <div v-for="f in legacyFields" :key="f.label">
                        <dt class="font-label text-label uppercase text-on-surface-variant">{{ f.label }}</dt>
                        <dd class="whitespace-pre-line font-body-medium text-body-medium text-on-surface">{{ f.value }}</dd>
                    </div>
                </dl>
            </section>

            <section v-for="(section, key) in sections" :key="key" class="space-y-lg rounded-xl border border-surface-variant bg-surface-container-lowest p-lg shadow-sm" :data-daily-section="key">
                <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-primary-container" style="font-variation-settings: 'FILL' 1" aria-hidden="true">{{ section.icon }}</span>{{ section.title }}
                </h2>
                <p v-if="!section.items.length && section.empty" class="font-body-small text-body-small text-on-surface-variant">{{ section.empty }}</p>
                <div v-if="section.items.length" class="grid grid-cols-1 gap-md md:grid-cols-2 xl:grid-cols-3">
                    <DailyMetric
                        v-for="item in section.items"
                        :key="item.key"
                        :item="item"
                        :class="hasValue(item) ? '' : 'md:col-span-2 xl:col-span-3'"
                    />
                </div>
                <div v-if="key === 'sessions' && sessionRows.length" class="space-y-sm" data-session-feedback>
                    <div class="flex flex-wrap items-baseline justify-between gap-sm">
                        <h3 class="font-body-medium text-body-medium font-semibold text-on-surface">Góp ý từng buổi</h3>
                        <p class="font-body-small text-body-small text-on-surface-variant">Không cần dự giờ. Chỉ Học vụ, Học thuật và Admin xem được, giáo viên không thấy.</p>
                    </div>
                    <ul class="space-y-sm">
                        <SessionFeedbackRow v-for="row in sessionRows" :key="row.id" :row="row" :verdicts="verdicts" @task="taskFromSession" />
                    </ul>
                </div>
                <UiTextarea
                    v-model="draft[key]"
                    :name="`notes[${key}]`"
                    :label="noteLabels[key] ?? 'Ghi chú'"
                    :rows="3"
                    :placeholder="key === 'media' ? 'Nhập nội dung kèm link bài viết...' : 'Ghi chú thêm (nếu có)...'"
                />
                <SectionTasks
                    :section="key"
                    :title="`Task follow-up · ${section.title}`"
                    :tasks="sectionTasks[key] ?? []"
                    :rows="rowsIn(key)"
                    :index-of="indexOf"
                    :assignees="assignees"
                    :today="today"
                    @add="addTask(key)"
                    @remove="removeTask"
                />
            </section>

            <section class="space-y-md rounded-xl border border-primary-container/30 bg-surface-container-low p-lg shadow-sm" data-daily-task>
                <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-primary-container" aria-hidden="true">assignment_turned_in</span>Task chung
                </h2>
                <ul v-if="tasks.length" class="space-y-xs" data-daily-task-created>
                    <li v-for="t in tasks" :key="t.id" class="flex flex-wrap items-center gap-x-sm gap-y-0.5 rounded-lg bg-surface-container-lowest px-md py-sm font-body-small text-body-small text-on-surface">
                        <strong>{{ t.title }}</strong>
                        <span class="text-on-surface-variant">· {{ t.assignee ?? '—' }} · hạn {{ formatDate(t.due_date, 'd/m/Y') }}</span>
                        <UiBadge color="neutral">{{ t.status_label }}</UiBadge>
                    </li>
                </ul>
                <SectionTasks
                    title="Việc không thuộc khối nào"
                    :rows="rowsIn('')"
                    :index-of="indexOf"
                    :assignees="assignees"
                    :today="today"
                    hint="Việc gắn với một khối thì thêm ngay trong khối đó, chưa xong sẽ hiện lại ở báo cáo hôm sau."
                    @add="addTask('')"
                    @remove="removeTask"
                />
            </section>

            <div class="flex justify-end pt-md">
                <UiButton type="submit" icon="save" class="px-xl py-md">Lưu báo cáo</UiButton>
            </div>
        </UiForm>

        <div class="grid grid-cols-1 gap-lg lg:grid-cols-2">
            <ReportHistory :items="history" :current="date" title="Các ngày gần đây" />
            <LegacyReports :items="legacy" />
        </div>
    </div>
</template>
