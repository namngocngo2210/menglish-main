<script setup>
/**
 * Báo cáo ngày Học vụ / Học thuật (mockup 02/06 + chủ dự án 15/10/2026): số liệu từng khối lấy từ hệ thống
 * (StaffReportController::daily → DailyReportMetrics), người viết chỉ nhập ghi chú mỗi khối + thêm task follow-up nếu cần.
 * Ngày chưa nộp: số liệu tính trực tiếp. Đã nộp: hiện số đã chụp lúc nộp; sửa ghi chú giữ nguyên số đó, "Cập nhật số liệu"
 * tính lại và Lưu thì ghi số mới. Rà soát 16/10/2026: ghi chú giữ trên máy khi tải lại số liệu / đổi ngày (useNoteDraft),
 * ngày còn thiếu, trạng thái Admin kiểm tra / yêu cầu bổ sung, nhiều task (mỗi task có tiêu đề, hạn, người phụ trách).
 * Chủ dự án 17/10/2026: task tạo ngay trong từng khối (việc chưa xong kỳ trước hiện lại đúng khối đó), khối "Chất lượng buổi học"
 * có góp ý từng buổi của Học vụ + "Báo Admin gấp" (SessionFeedbackRow). Task không thuộc khối nào nằm ở "Việc khác" cuối form.
 * Thiết kế lại 27/10/2026: ô trạng thái + dãy 7 ngày (DailyStatusBar), khối "Cần chú ý hôm nay" gom việc bất thường của mọi khối,
 * bảng lớp trong ngày (DailyClassTable), khối không phát sinh thu gọn một dòng, mục lục bên phải và thanh nộp dính đáy màn hình.
 */
import { computed, reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { alertCount, attentionGroups, classTable, hasValue, isQuiet, leftoverDetails, quietSummary, shiftDate } from '@/lib/dailyReport';
import ReportTabs from './ReportTabs.vue';
import ReportHistory from './ReportHistory.vue';
import LegacyReports from './LegacyReports.vue';
import DailyMetric from './DailyMetric.vue';
import DailyStatusBar from './DailyStatusBar.vue';
import DailyClassTable from './DailyClassTable.vue';
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
    deadlineAt: { type: String, default: null },
    week: { type: Array, default: () => [] },
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

const picker = ref(null);
const openPicker = () => (picker.value?.showPicker ? picker.value.showPicker() : picker.value?.click());
const goTo = (date) => date && date !== props.date && router.get(route('reports.periodic.daily'), { date });
const pickDate = (event) => goTo(event.target.value);
// Giữ nguyên trang (ghi chú đang gõ, task đang thêm), chỉ thay số liệu.
const refresh = () => router.get(route('reports.periodic.daily'), { date: props.date, refresh: 1 }, { preserveScroll: true, preserveState: true });

let uid = 0;
// Mọi dòng task mới của cả form chung một danh sách để `tasks[i]` không trùng chỉ số giữa các khối.
const newTasks = ref([]);
const addTask = (section = '', preset = {}) => {
    if (section) open[section] = true;
    newTasks.value.push({ uid: ++uid, section, ...preset });
};
const removeTask = (t) => (newTasks.value = newTasks.value.filter((x) => x.uid !== t.uid));
const rowsIn = (section) => newTasks.value.filter((t) => t.section === section);
const indexOf = (t) => newTasks.value.indexOf(t);
const taskFromSession = (row) => addTask('sessions', { class_id: row.class_id, title: `Theo dõi buổi ${row.class} ${row.time}` });
const onSaved = () => {
    clear();
    newTasks.value = [];
};

const attention = computed(() => attentionGroups(props.sections));
const tables = computed(() => Object.fromEntries(Object.entries(props.sections).map(([key, s]) => [key, key === 'operations' ? classTable(s) : null])));
const hasNote = (key) => Boolean((draft[key] ?? '').trim());
const hasTasks = (key) => (props.sectionTasks[key] ?? []).length > 0;
const quiet = (key) => isQuiet(props.sections[key]) && !(key === 'sessions' && props.sessionRows.length);
// Khối không phát sinh gì, chưa có ghi chú / task: thu gọn một dòng (vẫn gửi ghi chú vì ô nhập chỉ bị ẩn).
const open = reactive(Object.fromEntries(Object.keys(props.sections).map((key) => [key, !quiet(key) || hasNote(key) || hasTasks(key)])));

// Mục số (thẻ nhỏ) và mục danh sách (cả hàng) của một khối; dòng đã vào bảng lớp không lặp lại; mục trống gom một dòng.
const blocks = (key) => {
    const tabled = Boolean(tables.value[key]);
    const items = props.sections[key].items.map((item) => ({ ...item, details: leftoverDetails(item, tabled) }));
    return {
        stats: items.filter(hasValue),
        lists: items.filter((i) => !hasValue(i) && i.details.length && !(tabled && i.key === 'classes_list')),
        empty: items.filter((i) => !hasValue(i) && !i.details.length && !(tabled && i.key === 'classes_list')).map((i) => i.label),
    };
};
const sectionState = (key) => {
    const alerts = alertCount(props.sections[key]);
    if (hasNote(key)) return { label: 'Đã ghi chú', color: 'success', dot: 'bg-tertiary' };
    if (alerts) return { label: `${alerts} cần chú ý`, color: 'warning', dot: 'bg-warning' };
    if (quiet(key)) return { label: 'Không phát sinh', color: 'neutral', dot: 'bg-surface-variant' };
    return { label: 'Chưa ghi chú', color: 'neutral', dot: 'bg-surface-variant' };
};
const unnoted = computed(() => Object.keys(props.sections).filter((key) => alertCount(props.sections[key]) && !hasNote(key)));
const noteCount = computed(() => Object.keys(props.sections).filter(hasNote).length);
const submitLabel = computed(() => (props.review?.status === 'returned' ? 'Nộp lại' : props.submittedAt ? 'Lưu thay đổi' : 'Nộp báo cáo'));
const anchor = (key) => `daily-section-${key}`;
const jump = (key) => {
    open[key] = true;
    document.getElementById(anchor(key))?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};
</script>

<template>
    <UiPageHeader :title="`Báo cáo ngày ${audience}`">
        <template #breadcrumbs>Báo cáo của tôi</template>
        <template #meta>
            <span class="inline-flex items-center gap-xs">
                Số liệu lấy tự động. Bạn chỉ ghi những gì hệ thống chưa biết.
                <UiInfo title="Số liệu báo cáo ngày">
                    <p>Mọi số trong báo cáo được hệ thống tính theo <strong class="text-on-surface">ngày báo cáo</strong> và phạm vi lớp / chi nhánh bạn được xem. Bấm icon "i" ở từng mục để xem cách đếm.</p>
                    <p>Số sai thì sửa ở màn gốc (điểm danh, CRM, phiếu thu, buổi bổ trợ…) rồi bấm <strong class="text-on-surface">Cập nhật số liệu</strong>.</p>
                    <p>Khi bấm Lưu, số liệu lúc đó được lưu vào báo cáo để Admin xem đúng số bạn đã thấy.</p>
                </UiInfo>
            </span>
        </template>
        <template #actions>
            <ReportTabs :tabs="tabs" segmented />
            <div class="relative flex items-center rounded-xl border border-surface-variant bg-surface-container-lowest" data-daily-date>
                <button type="button" class="flex min-h-[40px] min-w-[40px] items-center justify-center rounded-l-xl text-on-surface-variant hover:bg-surface-container-low hover:text-primary" aria-label="Ngày trước" @click="goTo(shiftDate(date, -1))">
                    <span class="material-symbols-outlined text-[20px]" aria-hidden="true">chevron_left</span>
                </button>
                <button type="button" class="flex min-h-[40px] items-center gap-xs border-x border-surface-variant px-md font-body-medium text-body-medium text-on-surface hover:text-primary" title="Chọn ngày khác" @click="openPicker">
                    <span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">calendar_month</span>
                    <span data-date-label>{{ dateLabel }}</span>
                </button>
                <button
                    type="button"
                    class="flex min-h-[40px] min-w-[40px] items-center justify-center rounded-r-xl text-on-surface-variant hover:bg-surface-container-low hover:text-primary disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent"
                    aria-label="Ngày sau"
                    :disabled="date >= today"
                    @click="goTo(shiftDate(date, 1))"
                >
                    <span class="material-symbols-outlined text-[20px]" aria-hidden="true">chevron_right</span>
                </button>
                <input ref="picker" type="date" class="pointer-events-none absolute bottom-0 right-0 h-px w-px opacity-0" tabindex="-1" :value="date" :max="today" aria-label="Chọn ngày báo cáo" @change="pickDate" />
            </div>
        </template>
    </UiPageHeader>

    <div class="space-y-lg">
        <DailyStatusBar
            :date="date"
            :deadline="deadline"
            :deadline-at="deadlineAt"
            :week="week"
            :missing-days="missingDays"
            :computed-at="computedAt"
            :live="live"
            :submitted-at="submittedAt"
            :first-submitted-at="firstSubmittedAt"
            :late="late"
            :review="review"
            @refresh="refresh"
        />

        <div class="grid grid-cols-1 items-start gap-lg lg:grid-cols-[minmax(0,1fr)_300px]">
            <UiForm id="daily-ops-form" :action="route('reports.periodic.daily.store')" method="post" class="min-w-0 space-y-md" #default="{ errors }" @success="onSaved">
                <input type="hidden" name="date" :value="date" />
                <!-- Đã nộp mà vừa bấm "Cập nhật số liệu" → Lưu ghi số mới; không thì giữ số đã chụp lúc nộp. -->
                <input type="hidden" name="refresh" :value="live && submittedAt ? 1 : 0" />
                <UiAlert v-if="restored" type="info" data-daily-draft-restored>
                    Đã khôi phục ghi chú bạn gõ trên máy này nhưng chưa lưu.
                    <button type="button" class="ml-xs font-semibold underline" @click="discard">Bỏ bản nháp</button>
                </UiAlert>
                <UiAlert v-if="errors.notes" type="error">{{ errors.notes }}</UiAlert>

                <!-- Điện thoại / máy tính bảng: mục lục thành hàng chip cuộn ngang. -->
                <nav class="-mx-xs flex gap-xs overflow-x-auto px-xs pb-xs lg:hidden" aria-label="Các khối báo cáo">
                    <a
                        v-for="(section, key) in sections"
                        :key="key"
                        :href="`#${anchor(key)}`"
                        class="inline-flex min-h-[36px] shrink-0 items-center gap-xs rounded-full border border-surface-variant bg-surface-container-lowest px-md font-body-small text-body-small font-semibold text-on-surface"
                        @click.prevent="jump(key)"
                    >
                        <span :class="['h-2 w-2 rounded-full', sectionState(key).dot]" aria-hidden="true"></span>{{ section.title }}
                    </a>
                </nav>

                <section class="rounded-xl border border-surface-variant bg-surface-container-lowest px-lg pb-xs pt-md shadow-sm" aria-labelledby="daily-attention-title" data-daily-attention>
                    <div class="mb-xs flex flex-wrap items-center gap-sm">
                        <span :class="['material-symbols-outlined text-[20px]', attention.length ? 'text-error' : 'text-tertiary']" aria-hidden="true">{{ attention.length ? 'warning' : 'task_alt' }}</span>
                        <h2 id="daily-attention-title" class="font-h3 text-h3 text-on-surface">Cần chú ý hôm nay</h2>
                        <UiBadge v-if="attention.length" color="error">{{ attention.length }} nhóm việc</UiBadge>
                        <span v-if="attention.length" class="font-body-small text-body-small text-on-surface-subtle lg:ml-auto">Gom từ các khối bên dưới, bấm để tới chỗ cần ghi chú</span>
                    </div>
                    <ul v-if="attention.length">
                        <li v-for="g in attention" :key="g.key" class="border-b border-surface-container-high last:border-b-0">
                            <a :href="`#${anchor(g.section)}`" class="flex items-start gap-sm py-sm hover:text-primary" :data-attention="g.tag" @click.prevent="jump(g.section)">
                                <span :class="['mt-[7px] h-2 w-2 shrink-0 rounded-full', g.tone === 'error' ? 'bg-error' : 'bg-warning']" aria-hidden="true"></span>
                                <span class="min-w-0 flex-1 font-body-small text-body-small">
                                    <strong class="font-body-semibold text-body-semibold text-on-surface">{{ g.count }} · {{ g.tag }}</strong>
                                    <span class="text-on-surface-variant"> · {{ g.samples.join(', ') }}<template v-if="g.count > g.samples.length">…</template></span>
                                </span>
                                <UiBadge color="neutral" :dot="false" class="hidden shrink-0 sm:inline-flex">{{ g.sectionTitle }}</UiBadge>
                            </a>
                        </li>
                    </ul>
                    <p v-else class="pb-sm font-body-small text-body-small text-on-surface-variant">Không có việc nào bất thường theo số liệu hệ thống.</p>
                </section>

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

                <section
                    v-for="(section, key, n) in sections"
                    :id="anchor(key)"
                    :key="key"
                    class="scroll-mt-lg rounded-xl border border-surface-variant bg-surface-container-lowest shadow-sm"
                    :data-daily-section="key"
                    :data-open="open[key] ? 1 : 0"
                >
                    <div :class="['flex flex-wrap items-center gap-sm px-md py-md sm:px-lg', open[key] ? 'border-b border-surface-container-high' : '']">
                        <span :class="['flex h-7 w-7 shrink-0 items-center justify-center rounded-lg font-body-semibold text-body-semibold', open[key] ? 'bg-primary-container/10 text-primary' : 'bg-surface-container-low text-on-surface-variant']">{{ n + 1 }}</span>
                        <h2 class="min-w-0 flex-1 font-h3 text-h3 text-on-surface">{{ section.title }}</h2>
                        <UiBadge v-if="open[key]" :color="sectionState(key).color">{{ sectionState(key).label }}</UiBadge>
                        <UiButton v-else type="button" variant="ghost" size="sm" icon="edit_note" class="max-sm:hidden" @click="open[key] = true">Thêm ghi chú</UiButton>
                        <button
                            type="button"
                            class="flex h-9 w-9 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container-low hover:text-primary"
                            :aria-expanded="open[key]"
                            :aria-controls="`${anchor(key)}-body`"
                            :aria-label="open[key] ? `Thu gọn ${section.title}` : `Mở ${section.title}`"
                            @click="open[key] = !open[key]"
                        >
                            <span class="material-symbols-outlined text-[20px]" aria-hidden="true">{{ open[key] ? 'expand_less' : 'expand_more' }}</span>
                        </button>
                        <p v-if="!open[key]" class="-mt-xs basis-full pl-9 font-body-small text-body-small text-on-surface-variant" data-daily-quiet>
                            {{ section.empty || 'Không phát sinh hôm nay' }}<template v-if="quietSummary(section)"> · {{ quietSummary(section) }}</template>
                        </p>
                    </div>

                    <div v-show="open[key]" :id="`${anchor(key)}-body`" class="space-y-md px-md pb-lg pt-md sm:px-lg">
                        <p v-if="!section.items.length && section.empty" class="font-body-small text-body-small text-on-surface-variant">{{ section.empty }}</p>
                        <div v-if="blocks(key).stats.length" class="grid grid-cols-2 gap-sm xl:grid-cols-4">
                            <DailyMetric v-for="item in blocks(key).stats" :key="item.key" :item="item" />
                        </div>
                        <DailyClassTable v-if="tables[key]" :rows="tables[key]" />
                        <DailyMetric v-for="item in blocks(key).lists" :key="item.key" :item="item" />
                        <p v-if="blocks(key).empty.length" class="flex items-center gap-xs font-body-small text-body-small text-on-surface-subtle" data-daily-empty-items>
                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">check</span>
                            Không phát sinh: {{ blocks(key).empty.join(', ') }}.
                        </p>
                        <div v-if="key === 'sessions' && sessionRows.length" class="space-y-sm" data-session-feedback>
                            <div class="flex flex-wrap items-baseline justify-between gap-sm">
                                <h3 class="font-body-semibold text-body-semibold text-on-surface">Góp ý từng buổi</h3>
                                <p class="font-body-small text-body-small text-on-surface-variant">Không bắt buộc, không cần dự giờ. Chỉ Học vụ, Học thuật và Admin xem được, giáo viên không thấy.</p>
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
                            :placeholder="key === 'media' ? 'Nhập nội dung kèm link bài viết...' : alertCount(section) ? 'Các việc cần chú ý ở trên đã xử lý thế nào?' : 'Ghi chú thêm (nếu có)...'"
                        />
                        <SectionTasks
                            :section="key"
                            title="Task follow-up"
                            :tasks="sectionTasks[key] ?? []"
                            :rows="rowsIn(key)"
                            :index-of="indexOf"
                            :assignees="assignees"
                            :today="today"
                            @add="addTask(key)"
                            @remove="removeTask"
                        />
                    </div>
                </section>

                <section class="space-y-md rounded-xl border border-surface-variant bg-surface-container-lowest px-lg py-md shadow-sm" data-daily-task>
                    <div class="flex items-center gap-sm">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-surface-container-low font-body-semibold text-body-semibold text-on-surface-variant">{{ Object.keys(sections).length + 1 }}</span>
                        <h2 class="font-h3 text-h3 text-on-surface">Việc khác</h2>
                    </div>
                    <ul v-if="tasks.length" class="space-y-xs" data-daily-task-created>
                        <li v-for="t in tasks" :key="t.id" class="flex flex-wrap items-center gap-x-sm gap-y-0.5 rounded-lg bg-surface-container-low px-md py-sm font-body-small text-body-small text-on-surface">
                            <strong>{{ t.title }}</strong>
                            <span class="text-on-surface-variant">· {{ t.assignee ?? '—' }} · hạn {{ formatDate(t.due_date, 'd/m/Y') }}</span>
                            <UiBadge color="neutral">{{ t.status_label }}</UiBadge>
                        </li>
                    </ul>
                    <SectionTasks
                        title="Task không thuộc khối nào"
                        :rows="rowsIn('')"
                        :index-of="indexOf"
                        :assignees="assignees"
                        :today="today"
                        hint="Việc gắn với một khối thì thêm ngay trong khối đó. Chưa xong sẽ hiện lại ở báo cáo hôm sau."
                        @add="addTask('')"
                        @remove="removeTask"
                    />
                </section>

                <!-- Thanh nộp dính đáy màn hình khi cuộn. -->
                <div class="sticky bottom-0 z-20 -mx-xs flex flex-wrap items-center gap-sm rounded-t-xl border border-b-0 border-surface-variant bg-surface-container-lowest px-lg py-sm shadow-level-3" data-daily-submit-bar>
                    <span class="material-symbols-outlined text-[18px] text-tertiary" aria-hidden="true">cloud_done</span>
                    <p class="min-w-0 flex-1 font-body-small text-body-small text-on-surface-variant">
                        Ghi chú tự lưu nháp trên máy này ·
                        <strong class="text-on-surface">{{ noteCount }} khối đã ghi chú<template v-if="newTasks.length"> · {{ newTasks.length }} task mới</template></strong>
                    </p>
                    <UiButton type="submit" icon="send" class="w-full px-xl sm:w-auto">{{ submitLabel }}</UiButton>
                </div>
            </UiForm>

            <aside class="space-y-md lg:sticky lg:top-md">
                <nav class="hidden rounded-xl border border-surface-variant bg-surface-container-lowest p-md shadow-sm lg:block" aria-label="Nội dung báo cáo" data-daily-toc>
                    <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Nội dung báo cáo</p>
                    <a
                        v-for="(section, key, n) in sections"
                        :key="key"
                        :href="`#${anchor(key)}`"
                        class="flex items-center gap-sm rounded-lg px-sm py-xs font-body-small text-body-small text-on-surface hover:bg-surface-container-low hover:text-primary"
                        @click.prevent="jump(key)"
                    >
                        <span :class="['h-2 w-2 shrink-0 rounded-full', sectionState(key).dot]" aria-hidden="true"></span>
                        <span class="min-w-0 flex-1 truncate">{{ n + 1 }}. {{ section.title }}</span>
                        <span class="shrink-0 font-caption text-caption text-on-surface-variant">{{ sectionState(key).label }}</span>
                    </a>
                </nav>

                <section class="space-y-sm rounded-xl border border-surface-variant bg-surface-container-lowest p-md shadow-sm" aria-labelledby="daily-checklist-title" data-daily-checklist>
                    <h2 id="daily-checklist-title" class="font-label text-label uppercase text-on-surface-variant">Trước khi nộp</h2>
                    <p class="flex gap-sm font-body-small text-body-small text-on-surface">
                        <span class="material-symbols-outlined text-[18px] text-tertiary" aria-hidden="true">check_circle</span>
                        Số liệu tính lúc {{ computedAt ? formatDate(computedAt, 'H:i d/m') : '—' }}
                    </p>
                    <p v-for="key in unnoted" :key="key" class="flex gap-sm font-body-small text-body-small text-on-surface">
                        <span class="material-symbols-outlined text-[18px] text-warning" aria-hidden="true">radio_button_unchecked</span>
                        <span>
                            <a :href="`#${anchor(key)}`" class="font-semibold hover:text-primary hover:underline" @click.prevent="jump(key)">{{ sections[key].title }}</a>
                            có {{ alertCount(sections[key]) }} việc cần chú ý, nên ghi vài dòng đã xử lý thế nào.
                        </span>
                    </p>
                    <p v-if="!unnoted.length" class="flex gap-sm font-body-small text-body-small text-on-surface">
                        <span class="material-symbols-outlined text-[18px] text-tertiary" aria-hidden="true">check_circle</span>
                        Khối nào có việc cần chú ý cũng đã có ghi chú.
                    </p>
                    <p class="font-caption text-caption text-on-surface-subtle">Chỉ là gợi ý, không chặn việc nộp.</p>
                </section>

                <ReportHistory :items="history" :current="date" title="Các ngày gần đây" />
                <LegacyReports :items="legacy" />
            </aside>
        </div>
    </div>
</template>
