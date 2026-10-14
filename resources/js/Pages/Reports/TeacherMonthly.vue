<script setup>
/**
 * Báo cáo tháng của giáo viên (mockup 03/17 "Báo cáo chung của giáo viên"):
 *  - Báo cáo chấm công tháng: ghi chú tùy chọn, nộp riêng.
 *  - Báo cáo giảng dạy hàng tháng theo từng lớp (chọn lớp): số buổi đã dạy, A. Học thuật, B. Vận hành lớp, C. Chất lượng lớp
 *    (mục và nhãn theo StaffReportController::TEACHER_*), cộng nhận xét chung tháng (tiến độ, khó khăn, đề xuất — 04/10/2026).
 *    Chuyên cần / tỉ lệ làm bài gợi ý sẵn từ số liệu lớp. Lưu nháp hoặc nộp; mọi lớp gửi cùng một lần nên đổi lớp không mất
 *    phần đã nhập. Nhãn ô viết hoa nhỏ như mockup; lịch sử các tháng nằm dưới cùng.
 */
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { formatDate } from '@/lib/format';
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
// Tô màu lựa chọn theo ý nghĩa: đạt → xanh, chậm / thấp → đỏ, chuyên cần < 95% → xanh dương (mockup 03/17).
const tones = {
    success: 'border-tertiary-container bg-tertiary-container/10 text-tertiary',
    error: 'border-error bg-error-container/20 text-error',
    secondary: 'border-secondary-container bg-secondary-container/10 text-secondary',
};
const toneOf = (field, opt) => (opt === 'on_track' || opt === 'high' ? 'success' : field === 'attendance_level' ? 'secondary' : 'error');
const pill = (on, field, opt, shape = 'rounded-full px-md') =>
    ['inline-block cursor-pointer border py-xs font-body-small text-body-small font-medium transition-colors', shape, on ? tones[toneOf(field, opt)] : 'border-surface-variant bg-surface text-on-surface hover:bg-surface-container-low'].join(' ');
const statusPill = {
    error: 'border-error/20 bg-error-container/30 text-error',
    warning: 'border-warning/20 bg-warning-container text-on-warning-container',
    success: 'border-tertiary/20 bg-tertiary-container/10 text-tertiary',
};
const box = 'rounded-xl border border-surface-variant bg-surface p-md transition-shadow hover:shadow-sm';
const boxTitle = 'mb-md flex items-center gap-sm border-b border-surface-variant pb-sm font-h3 text-h3 text-on-surface';
</script>

<template>
    <div class="mx-auto max-w-5xl space-y-xl [&_[data-field-label]]:font-label [&_[data-field-label]]:text-label [&_[data-field-label]]:uppercase">
        <UiPageHeader title="Báo cáo chung của giáo viên" description="Hệ thống ghi nhận và quản lý công việc giảng dạy tháng">
            <template #actions>
                <label class="flex items-center gap-sm rounded-lg border border-surface-variant bg-surface-container-low px-md py-xs">
                    <span class="material-symbols-outlined text-primary-container" aria-hidden="true">calendar_month</span>
                    <select :value="month" aria-label="Chọn tháng báo cáo" class="border-0 bg-transparent py-xs pl-0 pr-8 font-body-medium text-body-medium text-on-surface focus:ring-0" @change="pickMonth($event.target.value)">
                        <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                </label>
            </template>
        </UiPageHeader>

        <!-- 1. Báo cáo chấm công tháng -->
        <UiForm :action="route('reports.periodic.teacher-attendance.store')" method="post" class="rounded-xl border border-surface-variant bg-surface-container-lowest p-lg shadow-sm" data-teacher-attendance>
            <input type="hidden" name="month" :value="month" />
            <h2 class="flex items-center gap-sm font-h2 text-h2 text-on-surface">
                <span class="material-symbols-outlined text-primary-container" aria-hidden="true">schedule</span>Báo cáo chấm công tháng
            </h2>
            <p :class="['mt-md inline-flex items-center gap-xs rounded-full border px-md py-xs font-body-medium text-body-medium', statusPill[attendance.submitted_at ? 'success' : 'error']]">
                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ attendance.submitted_at ? 'check_circle' : 'error' }}</span>
                <template v-if="attendance.submitted_at">Đã nộp báo cáo chấm công {{ monthLabel.toLowerCase() }} lúc {{ formatDate(attendance.submitted_at, 'H:i d/m') }}</template>
                <template v-else>Chưa nộp báo cáo chấm công {{ monthLabel.toLowerCase() }}</template>
            </p>
            <div class="mt-md space-y-md">
                <UiTextarea name="note" label="Nội dung mô tả (tùy chọn)" :rows="3" placeholder="Nhập ghi chú thêm về chấm công nếu có..." :value="attendance.note" />
                <div class="flex justify-end">
                    <UiButton type="submit" icon="send">Nộp báo cáo</UiButton>
                </div>
            </div>
        </UiForm>

        <!-- 2. Báo cáo giảng dạy hàng tháng -->
        <UiForm id="teacher-monthly-form" :action="route('reports.periodic.teacher-monthly.store')" method="post" :transform="(data) => ({ ...data, intent })" class="flex flex-col overflow-hidden rounded-xl border border-surface-variant bg-surface-container-lowest shadow-sm" #default="{ errors }">
            <input type="hidden" name="month" :value="month" />
            <div class="border-b border-surface-variant bg-surface-container-low/50 p-lg">
                <div class="mb-xs flex flex-wrap items-center gap-sm">
                    <h2 class="flex items-center gap-sm font-h2 text-h2 text-on-surface">
                        <span class="material-symbols-outlined text-primary-container" aria-hidden="true">menu_book</span>Báo cáo giảng dạy hàng tháng
                    </h2>
                    <span class="rounded-full border border-secondary-container/20 bg-secondary-container/10 px-sm py-[2px] font-caption text-caption text-secondary">Số hóa mới 2026</span>
                </div>
                <p v-if="status === 'submitted'" :class="['mt-md inline-flex items-center gap-xs rounded-full border px-md py-xs font-body-medium text-body-medium', statusPill.success]" data-teaching-status>
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">check_circle</span>Đã nộp báo cáo giảng dạy {{ monthLabel.toLowerCase() }} lúc {{ formatDate(submittedAt, 'H:i d/m') }}. Lưu lại để cập nhật.
                </p>
                <p v-else-if="status === 'draft'" :class="['mt-md inline-flex items-center gap-xs rounded-full border px-md py-xs font-body-medium text-body-medium', statusPill.warning]" data-teaching-status>
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">edit_note</span>Đang lưu nháp, chưa nộp — hạn {{ dueLabel }}
                </p>
                <p v-else :class="['mt-md inline-flex items-center gap-xs rounded-full border px-md py-xs font-body-medium text-body-medium', statusPill.error]" data-teaching-status>
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">warning</span>Chưa nộp báo cáo giảng dạy {{ monthLabel.toLowerCase() }} — hạn {{ dueLabel }}
                </p>
                <UiAlert v-if="errors.general" type="error" class="mt-md">{{ errors.general }}</UiAlert>

                <div class="mt-lg grid grid-cols-1 gap-md rounded-lg border border-surface-variant bg-surface p-md md:grid-cols-3">
                    <UiSelect v-model="selected" label="Chọn lớp học" :options="classOptions" placeholder="-- Chọn lớp học --" :searchable="false" />
                    <UiInput label="Kỳ báo cáo" :model-value="monthLabel" disabled />
                    <template v-for="c in classes" :key="c.id">
                        <UiInput v-show="selected === c.id" :name="`classes[${c.id}][sessions]`" label="Số buổi đã dạy" type="number" min="0" placeholder="Nhập số buổi..." :value="value(c.id, 'sessions') ?? c.sessions_done" />
                    </template>
                </div>
                <p v-if="!classes.length" class="mt-sm font-body-small text-body-small italic text-on-surface-variant">Không có lớp nào bạn dạy trong tháng này.</p>
            </div>

            <div v-for="c in classes" v-show="selected === c.id" :key="c.id" class="p-lg" :data-teacher-class="c.id">
                <div class="grid grid-cols-1 gap-lg xl:grid-cols-12">
                    <div class="flex flex-col gap-lg xl:col-span-5">
                        <!-- A. Học thuật -->
                        <section :class="box">
                            <h3 :class="boxTitle"><span class="material-symbols-outlined text-tertiary-container" aria-hidden="true">school</span>A. Học thuật</h3>
                            <div class="space-y-sm">
                                <div class="flex flex-wrap items-center justify-between gap-sm py-xs">
                                    <span class="font-body-medium text-body-medium text-on-surface">{{ choices.program_progress.label }}</span>
                                    <div class="flex gap-sm">
                                        <label v-for="(label, opt) in choices.program_progress.options" :key="opt" :class="pill(picks[c.id].program_progress === opt, 'program_progress', opt, 'rounded-md px-sm')">
                                            <input v-model="picks[c.id].program_progress" type="radio" class="sr-only" :name="`classes[${c.id}][program_progress]`" :value="opt" />{{ label }}
                                        </label>
                                    </div>
                                </div>
                                <div v-for="(check, key) in academicChecks" :key="key" :class="key === 'syllabus_adjust' ? 'rounded-md border border-surface-variant bg-surface-container-low p-sm' : 'p-sm'">
                                    <input type="hidden" :name="`classes[${c.id}][checks][${key}]`" value="0" />
                                    <UiCheckbox :name="`classes[${c.id}][checks][${key}]`" value="1" :checked="checked(c.id, key)" :label="check.label" />
                                    <a v-if="key === 'syllabus_adjust' && proposeUrl" :href="proposeUrl" class="ml-xl mt-xs inline-flex items-center gap-xs font-body-small text-body-small text-secondary underline decoration-secondary/30 underline-offset-4 hover:text-on-secondary-fixed-variant">
                                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">open_in_new</span>Gửi đề xuất sửa giáo trình
                                    </a>
                                    <div v-if="check.note" class="ml-xl mt-xs"><UiInput :name="`classes[${c.id}][notes][${key}]`" :placeholder="check.note" :value="note(c.id, key)" :aria-label="`${check.label}: ghi chú`" /></div>
                                </div>
                                <div class="mt-sm space-y-sm border-t border-surface-variant pt-sm">
                                    <UiTextarea :name="`classes[${c.id}][foreign_feedback]`" :label="classTexts.foreign_feedback" :rows="2" :value="value(c.id, 'foreign_feedback')" />
                                    <UiTextarea :name="`classes[${c.id}][academic_note]`" :label="classTexts.academic_note" :rows="2" :value="value(c.id, 'academic_note')" />
                                </div>
                            </div>
                        </section>

                        <!-- B. Vận hành lớp -->
                        <section :class="box">
                            <h3 :class="boxTitle"><span class="material-symbols-outlined text-secondary-container" aria-hidden="true">settings_suggest</span>B. Vận hành lớp</h3>
                            <div class="space-y-sm">
                                <div v-for="(check, key) in operationChecks" :key="key" class="flex flex-col gap-xs rounded-md border border-transparent p-sm transition-colors hover:border-surface-variant hover:bg-surface-container-low">
                                    <input type="hidden" :name="`classes[${c.id}][checks][${key}]`" value="0" />
                                    <UiCheckbox :name="`classes[${c.id}][checks][${key}]`" value="1" :checked="checked(c.id, key)" :label="check.label" />
                                    <div class="ml-xl"><UiInput :name="`classes[${c.id}][notes][${key}]`" :placeholder="check.note" :value="note(c.id, key)" :aria-label="`${check.label}: ghi chú`" /></div>
                                </div>
                                <div class="mt-sm border-t border-surface-variant pt-sm">
                                    <UiTextarea :name="`classes[${c.id}][operations_note]`" :label="classTexts.operations_note" :rows="2" :value="value(c.id, 'operations_note')" />
                                </div>
                            </div>
                        </section>
                    </div>

                    <!-- C. Chất lượng lớp học -->
                    <section :class="[box, 'flex flex-col lg:p-lg xl:col-span-7']">
                        <h3 :class="boxTitle"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">star</span>C. Chất lượng lớp học</h3>
                        <div class="flex-1 space-y-md">
                            <div class="grid grid-cols-1 gap-md rounded-lg border border-surface-variant bg-surface-container-low p-md sm:grid-cols-2">
                                <div v-for="field in ['attendance_level', 'homework_level']" :key="field">
                                    <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">{{ choices[field].label }}</span>
                                    <div class="flex flex-wrap gap-sm">
                                        <label v-for="(label, opt) in choices[field].options" :key="opt" :class="pill(picks[c.id][field] === opt, field, opt)">
                                            <input v-model="picks[c.id][field]" type="radio" class="sr-only" :name="`classes[${c.id}][${field}]`" :value="opt" />{{ label }}
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-x-md gap-y-sm sm:grid-cols-2">
                                <div v-for="(check, key) in qualityChecks" :key="key" class="flex flex-col gap-xs rounded-md border border-surface-variant bg-surface-container-lowest p-sm">
                                    <input type="hidden" :name="`classes[${c.id}][checks][${key}]`" value="0" />
                                    <UiCheckbox :name="`classes[${c.id}][checks][${key}]`" value="1" :checked="checked(c.id, key)" :label="check.label" />
                                    <UiInput :name="`classes[${c.id}][notes][${key}]`" :placeholder="check.note" :value="note(c.id, key)" :aria-label="`${check.label}: ghi chú`" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-md border-t border-surface-variant pt-md sm:grid-cols-2">
                                <UiInput :name="`classes[${c.id}][test_result]`" :label="classTexts.test_result" placeholder="VD: 85% học viên pass" :value="value(c.id, 'test_result')" />
                                <UiInput :name="`classes[${c.id}][attitude]`" :label="classTexts.attitude" placeholder="VD: Tích cực, hăng hái..." :value="value(c.id, 'attitude')" />
                            </div>
                            <div class="space-y-md border-t border-surface-variant pt-md">
                                <UiTextarea :name="`classes[${c.id}][attention]`" :label="classTexts.attention" :rows="3" placeholder="- Nguyễn Văn A: Thường xuyên không làm bài tập về nhà..." :value="value(c.id, 'attention')" />
                                <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                                    <UiTextarea :name="`classes[${c.id}][situation]`" :label="classTexts.situation" :rows="3" placeholder="Mô tả vấn đề..." :value="value(c.id, 'situation')" />
                                    <UiTextarea :name="`classes[${c.id}][solution]`" :label="classTexts.solution" :rows="3" placeholder="Cách xử lý của GV..." :value="value(c.id, 'solution')" />
                                </div>
                                <div class="rounded-lg border border-primary-container/20 bg-primary-container/5 p-sm [&_[data-field-label]]:text-primary-container">
                                    <UiTextarea :name="`classes[${c.id}][support_note]`" :label="classTexts.support_note" :rows="2" placeholder="Ghi chú yêu cầu hỗ trợ nếu cần..." :value="value(c.id, 'support_note')" />
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <!-- Nhận xét chung tháng cho mọi lớp (chủ dự án 04/10/2026), không bắt buộc -->
            <section class="px-lg pb-lg">
                <div :class="box">
                    <h3 :class="boxTitle"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">edit_note</span>Nhận xét chung tháng (mọi lớp, tùy chọn)</h3>
                    <div class="grid grid-cols-1 gap-md md:grid-cols-3">
                        <UiTextarea v-for="(label, key) in generalFields" :key="key" :name="`general[${key}]`" :label="label" :rows="2" :value="general[key]" />
                    </div>
                </div>
            </section>

            <div class="flex items-center justify-end gap-md border-t border-surface-variant bg-surface-container-low p-lg">
                <UiButton v-if="status !== 'submitted'" type="submit" variant="secondary" @click="intent = 'draft'">Lưu nháp</UiButton>
                <UiButton type="submit" icon="assignment_turned_in" @click="intent = 'submit'">Nộp báo cáo giảng dạy</UiButton>
            </div>
        </UiForm>

        <div class="grid grid-cols-1 gap-lg md:grid-cols-2">
            <ReportHistory :items="history" :current="month" title="Các tháng đã nộp" />
            <LegacyReports :items="legacy" />
        </div>
    </div>
</template>
