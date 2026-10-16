<script setup>
/**
 * Chi tiết báo cáo cho Admin (StaffReportController::reportDetail, mở trong modal từ màn Tổng hợp — chủ dự án 15/10/2026):
 * hiện như màn người viết nhập, đủ mọi ô (ô trống hiện "—"). Báo cáo ngày: số liệu tự động (đủ mọi dòng chi tiết) + ghi chú từng khối
 * + Task follow-up; báo cáo ngày nhập tay bản cũ: số liệu hệ thống tính lại cho ngày đó (như màn Học vụ đang thấy) + đủ các ô của
 * form cũ trong từng khối, cùng bố cục (ô số + ghi chú chia 4/8); báo cáo tuần Học vụ:
 * bảng KPI + chỉ số quy mô + ghi chú (bản cũ: số đã nhập ở mọi mục, kể cả 0); báo cáo tuần Học thuật: 3 bảng; loại khác: nội dung chữ.
 */
import { computed, ref } from 'vue';
import { route } from '@/lib/route';
import { openRemoteModal } from '@/lib/remoteModal';
import DailyMetric from './DailyMetric.vue';
import WeeklyKpiTable from './WeeklyKpiTable.vue';
import ReadonlyField from './ReadonlyField.vue';

const props = defineProps({
    report: { type: Object, required: true },
    view: { type: String, required: true },
    auto: { type: Object, default: null },
    notes: { type: Object, default: () => ({}) },
    noteLabels: { type: Object, default: () => ({}) },
    note: { type: String, default: null },
    tasks: { type: Array, default: () => [] },
    canReview: { type: Boolean, default: false },
    nextId: { type: Number, default: null },
    legacy: { type: Object, default: null },
    rows: { type: Object, default: () => ({}) },
    sections: { type: Object, default: () => ({}) },
    columns: { type: Object, default: () => ({}) },
});

const hasValue = (item) => item.value !== null && item.value !== undefined;
const sectionClass = 'space-y-lg rounded-xl border border-surface-variant bg-surface-container-lowest p-lg shadow-sm';
// Bố cục hàng của form báo cáo ngày bản cũ (như DailyOps.vue trước 15/10): 2 ô số chia đôi; ô số + ghi chú chia 4/8; ô ghi chú đứng riêng thì cả hàng.
const field = (key) => props.legacy?.fields?.[key] ?? { label: key, type: 'text' };
const paired = (row) => row.length > 1 && row.some((name) => field(name).type === 'text');
const rowClass = (row) => (row.length < 2 ? '' : paired(row) ? 'md:grid-cols-12' : 'md:grid-cols-2');
const extraLegacy = computed(() =>
    Object.fromEntries(Object.entries(props.legacy?.sections ?? {}).filter(([key]) => !props.auto?.sections?.[key])),
);
// Kiểm tra liên tục (rà soát 16/10/2026 B11): "Đã kiểm tra" xong mở luôn báo cáo kế tiếp đang chờ; "Yêu cầu bổ sung" kèm nhận xét.
const asking = ref(false);
const openNext = () => props.nextId && openRemoteModal(route('reports.detail', props.nextId), { size: '5xl', history: true });
const onReviewed = () => openNext();
const cellClass = (row, name) => (paired(row) ? (field(name).type === 'text' ? 'md:col-span-8' : 'md:col-span-4') : '');
</script>

<template>
    <UiModalFrame :title="report.title" :submit-label="false" cancel="Đóng" :back="route('reports.all')" size="5xl" page-width="max-w-6xl">
        <div class="space-y-lg" :data-report-detail="view">
            <div class="flex flex-wrap items-center gap-sm">
                <UiBadge color="primary" pill :dot="false">{{ report.type_label }}<template v-if="report.for"> · {{ report.for }}</template></UiBadge>
                <UiBadge v-if="report.severity_label" color="neutral" pill :dot="false">{{ report.severity_label }}</UiBadge>
                <UiBadge v-else-if="report.status === 'returned'" color="warning" pill>Đã yêu cầu bổ sung</UiBadge>
                <UiBadge v-else-if="report.reviewed_at" color="success" pill>Đã kiểm tra</UiBadge>
                <UiBadge v-else color="warning" pill>Chờ kiểm tra</UiBadge>
                <UiBadge v-if="report.late" color="error" pill :title="`Hạn nộp ${report.deadline}`">Trễ</UiBadge>
                <span class="font-body-small text-body-small text-on-surface-variant">
                    {{ formatDate(report.report_date) }} · <strong class="text-on-surface">{{ report.user }}</strong>
                    <template v-if="report.submitted_at"> · nộp lúc {{ formatDate(report.submitted_at, 'H:i d/m') }}</template>
                    <template v-if="report.updated_at && report.updated_at !== report.submitted_at"> · sửa lúc {{ formatDate(report.updated_at, 'H:i d/m') }}</template>
                    <template v-if="report.reviewed_at"> · {{ report.reviewed_by ?? 'Admin' }} kiểm tra lúc {{ formatDate(report.reviewed_at, 'H:i d/m') }}</template>
                </span>
            </div>
            <UiAlert v-if="report.status === 'returned' && report.review_note" type="warning" title="Đã yêu cầu bổ sung">
                <p class="whitespace-pre-line">{{ report.review_note }}</p>
            </UiAlert>
            <UiForm v-if="asking" :action="route('reports.request-changes', report.id)" method="post" stay class="space-y-sm rounded-xl border border-warning/40 bg-surface-container-low p-md" data-request-changes-form @success="asking = false">
                <UiTextarea name="note" label="Cần bổ sung gì?" required :rows="3" placeholder="Vd: Thiếu ghi chú lớp K26 nghỉ đột xuất, bổ sung lý do và hướng xử lý." />
                <div class="flex justify-end gap-sm">
                    <UiButton type="button" variant="ghost" size="sm" @click="asking = false">Thôi</UiButton>
                    <UiButton type="submit" size="sm" icon="send">Gửi cho {{ report.user }}</UiButton>
                </div>
            </UiForm>
            <p v-if="auto?.computed_at" class="font-body-small text-body-small text-on-surface-variant" data-computed-at>
                <span class="material-symbols-outlined align-middle text-[16px]" aria-hidden="true">sync</span>
                Số liệu hệ thống tính lúc {{ formatDate(auto.computed_at, 'H:i d/m/Y') }}
                {{ view === 'daily_legacy' ? '(tính lại khi mở chi tiết).' : '(lúc người viết nộp).' }}
            </p>

            <!-- Báo cáo ngày: số liệu + ghi chú từng khối. Bản nhập tay cũ: số liệu hệ thống tính lại cho ngày đó + các ô người viết đã nhập. -->
            <template v-if="view === 'daily' || view === 'daily_legacy'">
                <UiAlert v-if="view === 'daily_legacy'" type="info">
                    Báo cáo này nộp trước khi chuyển sang số liệu tự động. Số liệu và chi tiết bên dưới được hệ thống tính lại cho ngày
                    {{ formatDate(report.report_date, 'd/m/Y') }} như màn Học vụ đang thấy; khung "Nhập tay lúc nộp" là các ô người viết đã nhập.
                </UiAlert>
                <section v-for="(section, key) in auto?.sections ?? {}" :key="key" :class="sectionClass" :data-detail-section="key">
                    <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                        <span class="material-symbols-outlined text-primary-container" style="font-variation-settings: 'FILL' 1" aria-hidden="true">{{ section.icon }}</span>{{ section.title }}
                    </h3>
                    <p v-if="!section.items.length && section.empty" class="font-body-small text-body-small text-on-surface-variant">{{ section.empty }}</p>
                    <div v-if="section.items.length" class="grid grid-cols-1 gap-md md:grid-cols-2 xl:grid-cols-3">
                        <DailyMetric v-for="item in section.items" :key="item.key" :item="item" full :class="hasValue(item) ? '' : 'md:col-span-2 xl:col-span-3'" />
                    </div>
                    <ReadonlyField v-if="view === 'daily'" :label="noteLabels[key] ?? 'Ghi chú'" :value="notes[key]" multiline data-detail-note />
                    <div v-else-if="legacy.sections[key]" class="space-y-lg rounded-lg border border-dashed border-outline-variant p-md" data-legacy-block>
                        <p class="font-label text-label uppercase text-on-surface-variant">Nhập tay lúc nộp</p>
                        <div v-for="(row, i) in legacy.sections[key].rows" :key="i" :class="['grid grid-cols-1 gap-lg', rowClass(row)]">
                            <div v-for="name in row" :key="name" :class="cellClass(row, name)" :data-legacy-field="name">
                                <ReadonlyField :label="field(name).label" :value="legacy.values[name]" :multiline="field(name).type === 'text'" />
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Khối của form cũ không có trong số liệu tự động (vd. người viết đổi chức danh) -->
                <section v-for="(section, key) in extraLegacy" :key="key" :class="sectionClass" :data-detail-section="key">
                    <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                        <span class="material-symbols-outlined text-primary-container" style="font-variation-settings: 'FILL' 1" aria-hidden="true">{{ section.icon }}</span>{{ section.title }}
                    </h3>
                    <div v-for="(row, i) in section.rows" :key="i" :class="['grid grid-cols-1 gap-lg', rowClass(row)]">
                        <div v-for="name in row" :key="name" :class="cellClass(row, name)" :data-legacy-field="name">
                            <ReadonlyField :label="field(name).label" :value="legacy.values[name]" :multiline="field(name).type === 'text'" />
                        </div>
                    </div>
                </section>

                <section class="space-y-md rounded-xl border border-primary-container/30 bg-surface-container-low p-lg shadow-sm" data-detail-task>
                    <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                        <span class="material-symbols-outlined text-primary-container" aria-hidden="true">assignment_turned_in</span>Task follow-up
                    </h3>
                    <div v-for="task in tasks" :key="task.id" class="grid grid-cols-1 gap-lg md:grid-cols-4">
                        <ReadonlyField label="Việc đã tạo" :value="task.title" />
                        <ReadonlyField label="Người phụ trách (PIC)" :value="task.assignee" />
                        <ReadonlyField label="Hạn xử lý" :value="task.due_date ? formatDate(task.due_date, 'd/m/Y') : null" />
                        <ReadonlyField label="Trạng thái" :value="task.status_label" />
                    </div>
                    <p v-if="!tasks.length" class="font-body-small text-body-small italic text-on-surface-variant">Không tạo task.</p>
                </section>
            </template>

            <!-- Báo cáo tuần Học vụ: bảng KPI + chỉ số quy mô + ghi chú -->
            <template v-else-if="view === 'weekly_kpi'">
                <div class="grid grid-cols-1 gap-lg lg:grid-cols-[1fr_340px]">
                    <section class="min-w-0 rounded-xl border border-surface-variant bg-surface-container-lowest shadow-sm">
                        <h3 class="border-b border-surface-variant px-md py-sm font-semibold text-on-surface">Số lần phát sinh theo mục KPI</h3>
                        <WeeklyKpiTable :groups="auto.groups" />
                    </section>
                    <section class="min-w-0 space-y-sm">
                        <h3 class="font-semibold text-on-surface">Thông tin theo dõi</h3>
                        <DailyMetric v-for="item in auto.metrics.items" :key="item.key" :item="item" full />
                    </section>
                </div>
                <section :class="sectionClass">
                    <h3 class="font-h3 text-h3 text-on-surface">Ghi chú tuần</h3>
                    <p class="font-body-small text-body-small text-on-surface-variant">
                        Báo cáo ngày đã nộp {{ auto.daily.submitted }}/{{ auto.daily.expected }} ngày<template v-if="auto.daily.missing.length"> · còn thiếu {{ auto.daily.missing.join(', ') }}</template>.
                    </p>
                    <div v-if="auto.daily.notes.length" class="grid grid-cols-1 gap-md md:grid-cols-3">
                        <div v-for="n in auto.daily.notes" :key="n.key" class="rounded-lg border border-surface-variant bg-surface-container-low p-md">
                            <p class="mb-xs font-label text-label uppercase text-on-surface-variant">{{ n.label }}</p>
                            <ul class="space-y-xs">
                                <li v-for="(l, i) in n.lines" :key="i" class="whitespace-pre-line font-body-small text-body-small text-on-surface">{{ l.text }}</li>
                            </ul>
                        </div>
                    </div>
                    <ReadonlyField label="Ghi chú tuần" :value="note" multiline />
                </section>
            </template>

            <!-- Báo cáo tuần Học vụ nhập tay (bản cũ): số đã nhập ở mọi mục KPI và chỉ số quy mô -->
            <template v-else-if="view === 'weekly_legacy'">
                <UiAlert type="info">Báo cáo nộp trước khi chuyển sang số liệu tự động: các số dưới đây do người viết nhập tay.</UiAlert>
                <div class="grid grid-cols-1 gap-lg lg:grid-cols-[1fr_340px]">
                    <section class="min-w-0 rounded-xl border border-surface-variant bg-surface-container-lowest shadow-sm">
                        <h3 class="border-b border-surface-variant px-md py-sm font-semibold text-on-surface">Số lần phát sinh theo mục KPI</h3>
                        <UiDataTable>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Hạng mục công việc</th>
                                        <th class="w-32 text-right">Số lần phát sinh</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="group in legacy.groups" :key="group.name">
                                        <tr class="bg-surface-container-low">
                                            <td colspan="2" class="font-body-small text-body-small font-semibold italic text-on-surface-variant">Nhóm {{ group.name }}</td>
                                        </tr>
                                        <tr v-for="item in group.items" :key="item.id" :data-kpi-row="item.code">
                                            <td>{{ item.name }} <span class="font-code text-xs text-on-surface-subtle">{{ item.code }}</span></td>
                                            <td class="text-right font-semibold text-on-surface" data-kpi-count>{{ item.count }}</td>
                                        </tr>
                                    </template>
                                    <tr v-if="!legacy.groups.length">
                                        <td colspan="2" class="italic text-on-surface-variant">Không có mục KPI nào trong báo cáo.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </UiDataTable>
                    </section>
                    <section class="min-w-0 space-y-sm rounded-xl border border-surface-variant bg-surface-container-lowest p-md shadow-sm">
                        <h3 class="font-semibold text-on-surface">Thông tin theo dõi</h3>
                        <p class="font-body-small text-body-small text-on-surface-variant">(Chỉ số quy mô, không tính KPI)</p>
                        <div v-for="m in legacy.metrics" :key="m.key" class="grid grid-cols-[1fr_120px] items-center gap-sm" :data-legacy-metric="m.key">
                            <span class="font-body-base text-body-base text-on-surface">{{ m.label }}</span>
                            <span :class="['rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm text-right', m.value === null ? 'text-on-surface-subtle' : 'text-on-surface']">{{ m.value ?? '—' }}</span>
                        </div>
                    </section>
                </div>
            </template>

            <!-- Báo cáo tuần Học thuật: 3 bảng -->
            <template v-else-if="view === 'academic_weekly'">
                <section v-for="(title, key) in sections" :key="key" :class="sectionClass">
                    <h3 class="font-h3 text-h3 text-primary">{{ title }}</h3>
                    <UiDataTable v-if="rows[key]?.length" min-width="1100px">
                        <table>
                            <thead>
                                <tr>
                                    <th class="w-10">STT</th>
                                    <th v-for="(label, col) in columns" :key="col">{{ label }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, i) in rows[key]" :key="i" class="align-top">
                                    <td class="font-code text-on-surface-variant">{{ i + 1 }}</td>
                                    <td v-for="(label, col) in columns" :key="col" class="whitespace-pre-line">
                                        <template v-if="col === 'progress'">{{ row.progress === null || row.progress === undefined ? '—' : `${row.progress}%` }}</template>
                                        <template v-else-if="col === 'deadline'">{{ row.deadline ? formatDate(row.deadline, 'd/m/Y') : '—' }}</template>
                                        <a v-else-if="col === 'link' && row.link" :href="row.link" target="_blank" rel="noopener" class="break-all text-primary hover:underline">Mở link</a>
                                        <template v-else>{{ row[col] ?? '—' }}</template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </UiDataTable>
                    <p v-else class="font-body-small text-body-small italic text-on-surface-variant">Không có dòng nào.</p>
                </section>
            </template>

            <!-- Nhật ký / báo cáo chữ -->
            <template v-else>
                <dl v-if="view === 'journal' && (report.class || report.kpi_code)" class="grid grid-cols-2 gap-md">
                    <div v-if="report.class"><dt class="font-label text-label uppercase text-on-surface-variant">Lớp</dt><dd class="text-on-surface">{{ report.class }}</dd></div>
                    <div v-if="report.kpi_code"><dt class="font-label text-label uppercase text-on-surface-variant">Mã KPI</dt><dd class="text-on-surface">{{ report.kpi_code }}</dd></div>
                </dl>
                <div :class="sectionClass">
                    <p class="whitespace-pre-line font-body-medium text-body-medium text-on-surface">{{ report.content || 'Không có nội dung.' }}</p>
                </div>
            </template>

            <section v-if="report.followups.length" :class="sectionClass">
                <h3 class="font-h3 text-h3 text-on-surface">Follow-up</h3>
                <ul class="space-y-sm">
                    <li v-for="(f, i) in report.followups" :key="i" class="font-body-small text-body-small">
                        <strong class="text-on-surface">{{ f.user }}</strong> <span class="text-on-surface-variant">· {{ formatDate(f.created_at, 'H:i d/m') }}</span>
                        <p class="whitespace-pre-line text-on-surface">{{ f.content }}</p>
                    </li>
                </ul>
            </section>
        </div>

        <template v-if="(canReview && report.reviewable) || nextId" #footer>
            <template v-if="canReview && report.reviewable">
                <UiButton v-if="!asking && report.status !== 'returned'" type="button" variant="secondary" icon="assignment_return" data-request-changes @click="asking = true">Yêu cầu bổ sung</UiButton>
                <UiForm v-if="!report.reviewed_at" :action="route('reports.review', report.id)" method="post" stay @success="onReviewed">
                    <input type="hidden" name="reviewed" value="1" />
                    <UiButton type="submit" icon="fact_check" data-detail-review>{{ nextId ? 'Đã kiểm tra, sang báo cáo kế tiếp' : 'Đã kiểm tra' }}</UiButton>
                </UiForm>
            </template>
            <UiButton v-if="nextId" type="button" variant="ghost" icon="arrow_forward" data-detail-next @click="openNext">Kế tiếp</UiButton>
        </template>
    </UiModalFrame>
</template>
