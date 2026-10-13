<script setup>
/**
 * Báo cáo tháng của giáo viên (mockup 03/17 "Báo cáo chung của giáo viên"):
 *  - Báo cáo chấm công tháng: ghi chú tùy chọn, nộp riêng.
 *  - Báo cáo giảng dạy hàng tháng theo từng lớp (chọn lớp): số buổi đã dạy, A. Học thuật, B. Vận hành lớp, C. Chất lượng lớp
 *    (mục và nhãn theo StaffReportController::TEACHER_*), cộng nhận xét chung tháng (tiến độ, khó khăn, đề xuất — 04/10/2026)
 *    và order học thuật trong tháng để tham chiếu. Chuyên cần / tỉ lệ làm bài gợi ý sẵn từ số liệu lớp. Lưu nháp hoặc nộp;
 *    mọi lớp gửi cùng một lần nên đổi lớp không mất phần đã nhập.
 */
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { formatDate, formatPercent, formatScore } from '@/lib/format';
import ReportHistory from './ReportHistory.vue';
import LegacyReports from './LegacyReports.vue';

defineOptions({ layout: { title: 'Báo cáo chung của giáo viên' } });

const props = defineProps({
    tabs: { type: Array, default: () => [] },
    month: { type: String, required: true },
    monthLabel: { type: String, default: '' },
    months: { type: Array, default: () => [] },
    dueLabel: { type: String, default: '' },
    generalFields: { type: Object, default: () => ({}) },
    academicChecks: { type: Object, default: () => ({}) },
    operationChecks: { type: Object, default: () => ({}) },
    qualityChecks: { type: Object, default: () => ({}) },
    classTexts: { type: Object, default: () => ({}) },
    choices: { type: Object, default: () => ({}) },
    general: { type: Object, default: () => ({}) },
    classValues: { type: Object, default: () => ({}) },
    classes: { type: Array, default: () => [] },
    orders: { type: Array, default: () => [] },
    status: { type: String, default: null },
    submittedAt: { type: String, default: null },
    attendance: { type: Object, default: () => ({}) },
    proposeUrl: { type: String, default: null },
    history: { type: Array, default: () => [] },
    legacy: { type: Array, default: () => [] },
});

const saved = (classId) => props.classValues?.[classId] ?? {};
const value = (classId, field) => saved(classId)[field] ?? null;
const checked = (classId, key) => !!saved(classId).checks?.[key];
const note = (classId, key) => saved(classId).notes?.[key] ?? null;
// Gợi ý chuyên cần / bài tập từ số liệu tháng khi chưa chọn.
const suggest = (c) => ({
    program_progress: value(c.id, 'program_progress'),
    attendance_level: value(c.id, 'attendance_level') ?? (c.attendance === null ? null : c.attendance >= 95 ? 'high' : 'low'),
    homework_level: value(c.id, 'homework_level') ?? (c.homework === null ? null : c.homework > 80 ? 'high' : 'low'),
});
const picks = reactive(Object.fromEntries(props.classes.map((c) => [c.id, suggest(c)])));
const selected = ref(props.classes[0]?.id ?? null);
const intent = ref('submit');
const classOptions = props.classes.map((c) => ({ value: c.id, label: c.name }));
const pickMonth = (v) => v && v !== props.month && router.get(route('reports.periodic.teacher-monthly'), { month: v });
const pill = (on, tone = 'primary') =>
    [
        'cursor-pointer rounded-full border px-md py-xs font-body-small text-body-small font-semibold transition-colors',
        on ? (tone === 'success' ? 'border-tertiary bg-tertiary/10 text-tertiary' : 'border-primary-container bg-primary-container/10 text-primary') : 'border-outline-variant bg-surface-container-lowest text-on-surface-variant hover:bg-surface-container-low',
    ].join(' ');
</script>

<template>
    <UiPageHeader title="Báo cáo chung của giáo viên" icon="summarize">
        <template #meta>Hệ thống ghi nhận và quản lý công việc giảng dạy tháng</template>
        <template #actions>
            <UiSelect :model-value="month" :options="months" :searchable="false" aria-label="Chọn tháng báo cáo" @update:model-value="pickMonth" />
        </template>
    </UiPageHeader>

    <div class="grid grid-cols-1 gap-lg xl:grid-cols-[1fr_260px]">
        <div class="min-w-0 space-y-lg">
            <!-- Báo cáo chấm công tháng -->
            <UiForm :action="route('reports.periodic.teacher-attendance.store')" method="post" class="space-y-md rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-lg shadow-sm" data-teacher-attendance>
                <input type="hidden" name="month" :value="month" />
                <h2 class="flex items-center gap-xs font-h2 text-h2 text-on-surface">
                    <span class="material-symbols-outlined text-[22px] text-primary-container" aria-hidden="true">schedule</span>Báo cáo chấm công tháng
                </h2>
                <UiBadge v-if="attendance.submitted_at" color="success">Đã nộp báo cáo chấm công {{ monthLabel.toLowerCase() }} lúc {{ formatDate(attendance.submitted_at, 'H:i d/m') }}</UiBadge>
                <UiBadge v-else color="error">Chưa nộp báo cáo chấm công {{ monthLabel.toLowerCase() }}</UiBadge>
                <UiTextarea name="note" label="Nội dung mô tả (tùy chọn)" :rows="3" placeholder="Nhập ghi chú thêm về chấm công nếu có..." :value="attendance.note" />
                <div class="flex justify-end">
                    <UiButton type="submit" icon="send">Nộp báo cáo</UiButton>
                </div>
            </UiForm>

            <!-- Báo cáo giảng dạy hàng tháng -->
            <UiForm id="teacher-monthly-form" :action="route('reports.periodic.teacher-monthly.store')" method="post" :transform="(data) => ({ ...data, intent })" class="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm" #default="{ errors }">
                <input type="hidden" name="month" :value="month" />
                <div class="space-y-md p-lg">
                    <h2 class="flex items-center gap-xs font-h2 text-h2 text-on-surface">
                        <span class="material-symbols-outlined text-[22px] text-primary-container" aria-hidden="true">menu_book</span>Báo cáo giảng dạy hàng tháng
                    </h2>
                    <UiBadge v-if="status === 'submitted'" color="success" data-teaching-status>Đã nộp báo cáo giảng dạy {{ monthLabel.toLowerCase() }} lúc {{ formatDate(submittedAt, 'H:i d/m') }}. Lưu lại để cập nhật.</UiBadge>
                    <UiBadge v-else-if="status === 'draft'" color="warning" data-teaching-status>Đang lưu nháp, chưa nộp. Hạn {{ dueLabel }}</UiBadge>
                    <UiBadge v-else color="error" data-teaching-status>Chưa nộp báo cáo giảng dạy {{ monthLabel.toLowerCase() }}, hạn {{ dueLabel }}</UiBadge>
                    <UiAlert v-if="errors.general" type="error">{{ errors.general }}</UiAlert>

                    <div class="grid grid-cols-1 gap-md rounded-xl border border-surface-variant bg-surface-container-low p-md md:grid-cols-3">
                        <UiSelect v-model="selected" label="Chọn lớp học" :options="classOptions" placeholder="-- Chọn lớp học --" :searchable="false" />
                        <UiInput label="Kỳ báo cáo" :model-value="monthLabel" readonly />
                        <template v-for="c in classes" :key="c.id">
                            <UiInput v-show="selected === c.id" :name="`classes[${c.id}][sessions]`" label="Số buổi đã dạy" type="number" min="0" placeholder="Nhập số buổi..." :value="value(c.id, 'sessions') ?? c.sessions_done" />
                        </template>
                    </div>
                    <p v-if="!classes.length" class="font-body-small text-body-small italic text-on-surface-variant">Không có lớp nào bạn dạy trong tháng này.</p>
                </div>

                <div v-for="c in classes" v-show="selected === c.id" :key="c.id" class="space-y-md border-t border-surface-variant bg-surface-container-low/40 p-lg" :data-teacher-class="c.id">
                    <p class="font-body-small text-body-small text-on-surface-variant">
                        {{ c.name }} · {{ c.students }} HS · Chuyên cần {{ formatPercent(c.attendance) }} · BTVN {{ formatPercent(c.homework) }} · Điểm TB {{ formatScore(c.score) }}
                    </p>
                    <div class="grid grid-cols-1 gap-md lg:grid-cols-[2fr_3fr]">
                        <div class="space-y-md">
                            <!-- A. Học thuật -->
                            <section class="space-y-sm rounded-xl border border-surface-variant bg-surface-container-lowest p-md">
                                <h3 class="flex items-center gap-xs border-b border-surface-variant pb-xs font-h3 text-h3 text-on-surface">
                                    <span class="material-symbols-outlined text-[20px] text-tertiary" aria-hidden="true">school</span>A. Học thuật
                                </h3>
                                <div class="flex flex-wrap items-center justify-between gap-sm">
                                    <span class="font-body-small text-body-small text-on-surface">{{ choices.program_progress.label }}</span>
                                    <div class="flex gap-xs">
                                        <label v-for="(label, opt) in choices.program_progress.options" :key="opt" :class="pill(picks[c.id].program_progress === opt, 'success')">
                                            <input v-model="picks[c.id].program_progress" type="radio" class="sr-only" :name="`classes[${c.id}][program_progress]`" :value="opt" />{{ label }}
                                        </label>
                                    </div>
                                </div>
                                <div v-for="(check, key) in academicChecks" :key="key" class="space-y-xs">
                                    <input type="hidden" :name="`classes[${c.id}][checks][${key}]`" value="0" />
                                    <UiCheckbox :name="`classes[${c.id}][checks][${key}]`" value="1" :checked="checked(c.id, key)" :label="check.label" />
                                    <a v-if="key === 'syllabus_adjust' && proposeUrl" :href="proposeUrl" class="ml-lg inline-flex items-center gap-xs font-body-small text-body-small text-primary hover:underline">
                                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">edit_square</span>Gửi đề xuất sửa giáo trình
                                    </a>
                                    <div v-if="check.note" class="pl-lg"><UiInput :name="`classes[${c.id}][notes][${key}]`" :placeholder="check.note" :value="note(c.id, key)" :aria-label="`${check.label}: ghi chú`" /></div>
                                </div>
                                <UiTextarea :name="`classes[${c.id}][foreign_feedback]`" :label="classTexts.foreign_feedback" :rows="2" :value="value(c.id, 'foreign_feedback')" />
                                <UiTextarea :name="`classes[${c.id}][academic_note]`" :label="classTexts.academic_note" :rows="2" :value="value(c.id, 'academic_note')" />
                            </section>

                            <!-- B. Vận hành lớp -->
                            <section class="space-y-sm rounded-xl border border-surface-variant bg-surface-container-lowest p-md">
                                <h3 class="flex items-center gap-xs border-b border-surface-variant pb-xs font-h3 text-h3 text-on-surface">
                                    <span class="material-symbols-outlined text-[20px] text-info" aria-hidden="true">settings</span>B. Vận hành lớp
                                </h3>
                                <div v-for="(check, key) in operationChecks" :key="key" class="space-y-xs">
                                    <input type="hidden" :name="`classes[${c.id}][checks][${key}]`" value="0" />
                                    <UiCheckbox :name="`classes[${c.id}][checks][${key}]`" value="1" :checked="checked(c.id, key)" :label="check.label" />
                                    <div class="pl-lg"><UiInput :name="`classes[${c.id}][notes][${key}]`" :placeholder="check.note" :value="note(c.id, key)" :aria-label="`${check.label}: ghi chú`" /></div>
                                </div>
                                <UiTextarea :name="`classes[${c.id}][operations_note]`" :label="classTexts.operations_note" :rows="2" :value="value(c.id, 'operations_note')" />
                            </section>
                        </div>

                        <!-- C. Chất lượng lớp học -->
                        <section class="space-y-md rounded-xl border border-surface-variant bg-surface-container-lowest p-md">
                            <h3 class="flex items-center gap-xs border-b border-surface-variant pb-xs font-h3 text-h3 text-on-surface">
                                <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">star</span>C. Chất lượng lớp học
                            </h3>
                            <div class="grid grid-cols-1 gap-md rounded-lg bg-surface-container-low p-md sm:grid-cols-2">
                                <div v-for="field in ['attendance_level', 'homework_level']" :key="field" class="space-y-xs">
                                    <span class="block font-body-small text-body-small font-semibold uppercase text-on-surface-variant">{{ choices[field].label }}</span>
                                    <div class="flex flex-wrap gap-xs">
                                        <label v-for="(label, opt) in choices[field].options" :key="opt" :class="pill(picks[c.id][field] === opt, 'success')">
                                            <input v-model="picks[c.id][field]" type="radio" class="sr-only" :name="`classes[${c.id}][${field}]`" :value="opt" />{{ label }}
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-sm sm:grid-cols-2">
                                <div v-for="(check, key) in qualityChecks" :key="key" class="space-y-xs rounded-lg border border-surface-variant p-sm">
                                    <input type="hidden" :name="`classes[${c.id}][checks][${key}]`" value="0" />
                                    <UiCheckbox :name="`classes[${c.id}][checks][${key}]`" value="1" :checked="checked(c.id, key)" :label="check.label" />
                                    <UiInput :name="`classes[${c.id}][notes][${key}]`" :placeholder="check.note" :value="note(c.id, key)" :aria-label="`${check.label}: ghi chú`" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                                <UiInput :name="`classes[${c.id}][test_result]`" :label="classTexts.test_result" placeholder="VD: 85% học viên pass" :value="value(c.id, 'test_result')" />
                                <UiInput :name="`classes[${c.id}][attitude]`" :label="classTexts.attitude" placeholder="VD: Tích cực, hăng hái..." :value="value(c.id, 'attitude')" />
                            </div>
                            <UiTextarea :name="`classes[${c.id}][attention]`" :label="classTexts.attention" :rows="3" placeholder="- Nguyễn Văn A: Thường xuyên không làm bài tập về nhà..." :value="value(c.id, 'attention')" />
                            <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                                <UiTextarea :name="`classes[${c.id}][situation]`" :label="classTexts.situation" :rows="3" placeholder="Mô tả vấn đề..." :value="value(c.id, 'situation')" />
                                <UiTextarea :name="`classes[${c.id}][solution]`" :label="classTexts.solution" :rows="3" placeholder="Cách xử lý của GV..." :value="value(c.id, 'solution')" />
                            </div>
                            <div class="rounded-lg border border-error/30 bg-error/5 p-sm">
                                <UiTextarea :name="`classes[${c.id}][support_note]`" :label="classTexts.support_note" :rows="2" placeholder="Ghi chú yêu cầu hỗ trợ nếu cần..." :value="value(c.id, 'support_note')" />
                            </div>
                        </section>
                    </div>
                </div>

                <!-- Nhận xét chung tháng (chủ dự án 04/10/2026) -->
                <section class="space-y-md border-t border-surface-variant p-lg">
                    <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                        <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">edit_note</span>Nhận xét chung tháng (mọi lớp)
                    </h3>
                    <div class="grid grid-cols-1 gap-md md:grid-cols-3">
                        <UiTextarea v-for="(label, key) in generalFields" :key="key" :name="`general[${key}]`" :label="label" :rows="3" :value="general[key]" />
                    </div>
                </section>

                <section class="space-y-sm border-t border-surface-variant p-lg">
                    <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                        <span class="material-symbols-outlined text-[20px] text-primary-container" aria-hidden="true">inventory</span>Order học thuật trong tháng
                        <UiButton variant="ghost" size="sm" class="ml-auto" :href="route('material-orders.index')">Order học liệu</UiButton>
                    </h3>
                    <UiDataTable v-if="orders.length">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nội dung</th>
                                    <th>Lớp</th>
                                    <th>Ngày dùng</th>
                                    <th>Tình trạng</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="o in orders" :key="o.id">
                                    <td>{{ o.title }}</td>
                                    <td>{{ o.class ?? '—' }}</td>
                                    <td class="font-code">{{ formatDate(o.use_date, 'd/m/Y') || '—' }}</td>
                                    <td>{{ o.status_label }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </UiDataTable>
                    <p v-else class="font-body-small text-body-small italic text-on-surface-variant">Chưa có order học thuật nào trong tháng này.</p>
                </section>

                <div class="flex justify-end gap-sm border-t border-surface-variant bg-surface-container-low p-lg">
                    <UiButton v-if="status !== 'submitted'" type="submit" variant="secondary" @click="intent = 'draft'">Lưu nháp</UiButton>
                    <UiButton type="submit" icon="assignment_turned_in" @click="intent = 'submit'">Nộp báo cáo giảng dạy</UiButton>
                </div>
            </UiForm>
        </div>

        <div class="space-y-lg">
            <ReportHistory :items="history" :current="month" title="Các tháng đã nộp" />
            <LegacyReports :items="legacy" />
        </div>
    </div>
</template>
