<script setup>
/**
 * Chi tiết báo cáo cho Admin (StaffReportController::reportDetail, mở trong modal từ màn Tổng hợp — chủ dự án 15/10/2026):
 * hiện như màn người viết nhập. Báo cáo ngày: số liệu tự động + ghi chú từng khối; báo cáo tuần Học vụ: bảng KPI + chỉ số quy mô
 * + ghi chú; báo cáo tuần Học thuật: 3 bảng; báo cáo nhập tay bản cũ: các ô đã nhập; loại khác: nội dung chữ.
 */
import { route } from '@/lib/route';
import DailyMetric from './DailyMetric.vue';
import WeeklyKpiTable from './WeeklyKpiTable.vue';

defineProps({
    report: { type: Object, required: true },
    view: { type: String, required: true },
    auto: { type: Object, default: null },
    notes: { type: Object, default: () => ({}) },
    noteLabels: { type: Object, default: () => ({}) },
    note: { type: String, default: null },
    task: { type: Object, default: null },
    fields: { type: Array, default: () => [] },
    rows: { type: Object, default: () => ({}) },
    sections: { type: Object, default: () => ({}) },
    columns: { type: Object, default: () => ({}) },
});

const hasValue = (item) => item.value !== null && item.value !== undefined;
const sectionClass = 'space-y-md rounded-xl border border-surface-variant bg-surface-container-lowest p-md shadow-sm';
</script>

<template>
    <UiModalFrame :title="report.title" :submit-label="false" cancel="Đóng" :back="route('reports.all')" size="5xl" page-width="max-w-6xl">
        <div class="space-y-lg" :data-report-detail="view">
            <div class="flex flex-wrap items-center gap-sm">
                <UiBadge color="primary" pill :dot="false">{{ report.type_label }}<template v-if="report.for"> · {{ report.for }}</template></UiBadge>
                <UiBadge v-if="report.severity_label" color="neutral" pill :dot="false">{{ report.severity_label }}</UiBadge>
                <UiBadge v-else-if="report.reviewed_at" color="success" pill>Đã kiểm tra</UiBadge>
                <UiBadge v-else color="warning" pill>Chờ kiểm tra</UiBadge>
                <span class="font-body-small text-body-small text-on-surface-variant">
                    {{ formatDate(report.report_date) }} · <strong class="text-on-surface">{{ report.user }}</strong>
                    <template v-if="report.submitted_at"> · nộp lúc {{ formatDate(report.submitted_at, 'H:i d/m') }}</template>
                    <template v-if="report.reviewed_at"> · {{ report.reviewed_by ?? 'Admin' }} kiểm tra lúc {{ formatDate(report.reviewed_at, 'H:i d/m') }}</template>
                </span>
            </div>
            <p v-if="auto?.computed_at" class="font-body-small text-body-small text-on-surface-variant">
                <span class="material-symbols-outlined align-middle text-[16px]" aria-hidden="true">sync</span>
                Số liệu hệ thống tính lúc {{ formatDate(auto.computed_at, 'H:i d/m/Y') }} (lúc người viết nộp).
            </p>

            <!-- Báo cáo ngày: số liệu + ghi chú từng khối -->
            <template v-if="view === 'daily'">
                <section v-for="(section, key) in auto.sections" :key="key" :class="sectionClass" :data-detail-section="key">
                    <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                        <span class="material-symbols-outlined text-primary-container" style="font-variation-settings: 'FILL' 1" aria-hidden="true">{{ section.icon }}</span>{{ section.title }}
                    </h3>
                    <p v-if="!section.items.length && section.empty" class="font-body-small text-body-small text-on-surface-variant">{{ section.empty }}</p>
                    <div v-if="section.items.length" class="grid grid-cols-1 gap-md md:grid-cols-2 xl:grid-cols-3">
                        <DailyMetric v-for="item in section.items" :key="item.key" :item="item" :class="hasValue(item) ? '' : 'md:col-span-2 xl:col-span-3'" />
                    </div>
                    <div class="rounded-lg bg-surface-container-low p-md">
                        <p class="font-label text-label uppercase text-on-surface-variant">{{ noteLabels[key] ?? 'Ghi chú' }}</p>
                        <p :class="['whitespace-pre-line font-body-medium text-body-medium', notes[key] ? 'text-on-surface' : 'italic text-on-surface-variant']" data-detail-note>
                            {{ notes[key] || 'Không ghi chú.' }}
                        </p>
                    </div>
                </section>
                <section :class="sectionClass">
                    <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                        <span class="material-symbols-outlined text-primary-container" aria-hidden="true">assignment_turned_in</span>Task follow-up
                    </h3>
                    <p v-if="task" class="font-body-medium text-body-medium text-on-surface">
                        <strong>{{ task.title }}</strong> · Người phụ trách: {{ task.assignee ?? '—' }} · Ngày giải quyết {{ formatDate(task.due_date, 'd/m/Y') }}
                    </p>
                    <p v-else class="font-body-small text-body-small italic text-on-surface-variant">Không tạo task.</p>
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
                        <DailyMetric v-for="item in auto.metrics.items" :key="item.key" :item="item" />
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
                    <p :class="['whitespace-pre-line font-body-medium text-body-medium', note ? 'text-on-surface' : 'italic text-on-surface-variant']">{{ note || 'Không ghi chú tuần.' }}</p>
                </section>
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

            <!-- Báo cáo nhập tay (bản cũ) -->
            <template v-else-if="view === 'fields'">
                <section v-for="section in fields" :key="section.title" :class="sectionClass">
                    <h3 class="font-h3 text-h3 text-on-surface">{{ section.title }}</h3>
                    <dl class="grid grid-cols-1 gap-md md:grid-cols-2">
                        <div v-for="f in section.rows" :key="f.label">
                            <dt class="font-label text-label uppercase text-on-surface-variant">{{ f.label }}</dt>
                            <dd class="whitespace-pre-line font-body-medium text-body-medium text-on-surface">{{ f.value }}</dd>
                        </div>
                    </dl>
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
    </UiModalFrame>
</template>
