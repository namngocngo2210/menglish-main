<script setup>
/**
 * Báo cáo buổi học của giáo viên chính (chủ dự án 27/10/2026), mở từ nút trên từng buổi ở Trang lớp.
 * 1. Thông tin buổi (chỉ đọc) · 2. Việc của buổi (lấy từ màn điểm danh / bài giảng / bài tập) · 3. Nhận xét từng học sinh · 4. GV ghi nhận.
 * Chỉ để xem (không duyệt), không ảnh; bắt buộc đã điểm danh. Người khác (Admin, Học vụ…) mở → chỉ đọc.
 */
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import SessionTrialGuests from '@/Components/Teacher/SessionTrialGuests.vue';
import { useBackLink } from '@/lib/backLink';

defineOptions({ layout: { title: 'Báo cáo buổi học' } });

const props = defineProps({
    classInfo: { type: Object, required: true },
    sessionInfo: { type: Object, required: true },
    checklist: { type: Array, default: () => [] },
    attendanceDone: { type: Boolean, default: false },
    students: { type: Array, default: () => [] },
    suggestions: { type: Array, default: () => [] },
    report: { type: Object, default: null },
    lessonText: { type: String, default: null },
    deadline: { type: String, default: null },
    overdue: { type: Boolean, default: false },
    canSubmit: { type: Boolean, default: false },
    remarkStudents: { type: Array, default: () => [] },
    remarkOptions: { type: Object, default: () => ({}) },
    trialGuests: { type: Object, default: () => ({ scope: 'upcoming', items: [] }) },
});
const back = useBackLink(() => props.classInfo.url);

const content = ref(props.report?.content ?? props.lessonText ?? '');

// Học sinh cần chú ý: bản đã nộp, hoặc gợi ý (vắng buổi này / nhận xét "Cần cố gắng", "Không đạt") + dòng thêm tay.
let seq = 1;
const row = (r = {}, suggested = false) => ({ id: seq++, student_id: r.student_id ? String(r.student_id) : '', reason: r.reason ?? '', action: r.action ?? '', suggested });
const attention = ref(props.report ? props.report.attention.map((r) => row(r)) : props.suggestions.map((r) => row(r, true)));
const addRow = () => attention.value.push(row());
const removeRow = (index) => attention.value.splice(index, 1);

// Nhận xét từng học sinh (nơi nhập duy nhất). Chọn "Cần cố gắng" / "Không đạt" → thêm vào "Học sinh cần chú ý".
const REMARK_FIELDS = [
    ['monsters_group', 'Monsters (Nhóm)', 'VD: +5'],
    ['monsters_bonus', 'Monsters (Thưởng)', 'VD: +2'],
    ['grammar', 'Thực hành ngữ pháp'],
    ['attitude', 'Tinh thần học tập'],
    ['result', 'Kết quả'],
];
const WEAK = { grammar: 'Cần cố gắng', attitude: 'Cần cố gắng', result: 'Không đạt' };
const remarkLabels = Object.fromEntries(REMARK_FIELDS.map(([key, label]) => [key, label]));
const choicesFor = (key, current) => {
    const options = props.remarkOptions[key] ?? [];
    return current && !options.includes(current) ? [...options, current] : options;
};
function onRemarkPick(student, key, value) {
    if (WEAK[key] !== value || attention.value.some((r) => r.student_id === String(student.id))) return;
    attention.value.push(row({ student_id: student.id, reason: `Nhận xét: ${remarkLabels[key]} ${value.toLowerCase()}` }, true));
}
const hasRemark = (student) => Object.values(student.remark).some((v) => v);
const attLabel = { present: 'Có mặt', late: 'Đi muộn', absent: 'Vắng', excused: 'Vắng có phép', none: 'Chưa điểm danh' };

const fieldCls = 'w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs font-body-small text-body-small';
const labelCls = 'mb-xs block font-label text-label uppercase text-on-surface-variant';
const sectionCls = 'space-y-md rounded-2xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm';
const headingCls = 'flex items-center gap-sm font-h3 text-h3 text-on-surface';
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-md pb-24 md:pb-0">
        <header class="flex items-center gap-sm rounded-2xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm">
            <UiButton variant="ghost" icon="arrow_back" :href="back.href" data-back-link aria-label="Quay lại" title="Quay lại" />
            <div class="min-w-0 flex-1">
                <h1 class="flex flex-wrap items-center gap-sm font-h2 text-h2 text-on-surface">
                    Báo cáo buổi học
                    <UiBadge v-if="report" :color="report.late ? 'warning' : 'success'">{{ report.late ? 'Đã nộp · trễ hạn' : 'Đã nộp' }}</UiBadge>
                    <UiBadge v-else-if="overdue" color="error">Quá hạn nộp</UiBadge>
                </h1>
                <p class="font-body-small text-body-small text-on-surface-variant">
                    {{ classInfo.name }} · {{ sessionInfo.no ? `Buổi ${sessionInfo.no} · ` : '' }}{{ sessionInfo.date }}
                    <template v-if="!report"> · Hạn nộp {{ deadline }}</template>
                </p>
            </div>
        </header>

        <SessionTrialGuests :guests="trialGuests" />

        <!-- 1. Thông tin buổi -->
        <section :class="sectionCls" data-report-section="session">
            <h2 :class="headingCls" class="border-b border-surface-container pb-sm"><span class="h-2 w-2 rounded-full bg-primary-container" aria-hidden="true"></span>1. Thông tin buổi</h2>
            <dl class="grid grid-cols-1 gap-sm sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-outline-variant bg-surface-container-low p-sm">
                    <dt class="font-label text-label uppercase text-on-surface-variant">Lớp học</dt>
                    <dd class="mt-xs font-body-medium text-body-medium font-semibold text-on-surface">{{ classInfo.name }}</dd>
                    <dd class="font-caption text-caption text-on-surface-variant">Mã: {{ classInfo.code }}</dd>
                </div>
                <div class="rounded-xl border border-outline-variant bg-surface-container-low p-sm">
                    <dt class="font-label text-label uppercase text-on-surface-variant">Buổi học</dt>
                    <dd class="mt-xs font-body-medium text-body-medium font-semibold text-on-surface">{{ sessionInfo.no ? `Buổi ${sessionInfo.no}` : sessionInfo.date }}</dd>
                    <dd v-if="sessionInfo.lesson" class="font-caption text-caption text-on-surface-variant">{{ sessionInfo.lesson }}</dd>
                </div>
                <div class="rounded-xl border border-outline-variant bg-surface-container-low p-sm">
                    <dt class="font-label text-label uppercase text-on-surface-variant">Ngày giờ & phòng</dt>
                    <dd class="mt-xs font-body-medium text-body-medium font-semibold text-on-surface">{{ sessionInfo.date }}{{ sessionInfo.time ? ` · ${sessionInfo.time}` : '' }}</dd>
                    <dd v-if="sessionInfo.room" class="font-caption text-caption text-on-surface-variant">Phòng {{ sessionInfo.room }}</dd>
                </div>
                <div class="rounded-xl border border-outline-variant bg-surface-container-low p-sm">
                    <dt class="font-label text-label uppercase text-on-surface-variant">Giáo viên chính</dt>
                    <dd class="mt-xs font-body-medium text-body-medium font-semibold text-on-surface">{{ sessionInfo.teacher ?? '—' }}</dd>
                </div>
            </dl>
        </section>

        <!-- 2. Việc của buổi -->
        <section :class="sectionCls" data-report-section="checklist">
            <div class="border-b border-surface-container pb-sm">
                <h2 :class="headingCls"><span class="h-2 w-2 rounded-full bg-primary-container" aria-hidden="true"></span>2. Việc của buổi</h2>
                <p class="font-caption text-caption text-on-surface-variant">Lấy tự động từ các màn giáo viên đã làm. Bắt buộc điểm danh trước khi nộp; mục khác chưa xong chỉ nhắc.</p>
            </div>
            <ul class="divide-y divide-surface-container">
                <li v-for="item in checklist" :key="item.key" class="flex flex-wrap items-start justify-between gap-sm py-sm" :data-check="item.key">
                    <div class="flex min-w-0 items-start gap-sm">
                        <span class="material-symbols-outlined text-[20px]" :class="item.done ? 'text-tertiary' : item.required ? 'text-error' : 'text-warning'" aria-hidden="true">{{
                            item.done ? 'check_circle' : item.required ? 'error' : 'radio_button_unchecked'
                        }}</span>
                        <div class="min-w-0">
                            <p class="font-body-medium text-body-medium font-semibold text-on-surface">
                                {{ item.label }}<span v-if="item.required" class="ml-xs font-caption text-caption font-normal text-error">(bắt buộc)</span>
                            </p>
                            <p class="font-body-small text-body-small text-on-surface-variant">{{ item.value }}</p>
                            <ul v-if="item.details?.length" class="mt-xs flex flex-wrap gap-xs">
                                <li v-for="(d, i) in item.details" :key="i" class="rounded-md bg-surface-container-low px-xs py-[1px] font-caption text-caption text-on-surface-variant">
                                    {{ d.text }}<span v-if="d.tag" class="ml-xs font-semibold" :class="d.tone === 'error' ? 'text-error' : 'text-warning'">{{ d.tag }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <Link v-if="item.link" :href="item.link.url" class="shrink-0 font-caption text-caption font-semibold text-primary hover:underline">{{ item.link.label }} →</Link>
                </li>
            </ul>
        </section>

        <!-- 3–4. Nhận xét học sinh + GV ghi nhận -->
        <UiForm v-if="canSubmit" :action="route('classes.session-report.store', { id: classInfo.id, session: sessionInfo.id })" method="post" class="space-y-md" #default="{ errors, processing }">
            <!-- 3. Nhận xét học sinh -->
            <section :class="sectionCls" data-report-section="remarks">
                <div class="border-b border-surface-container pb-sm">
                    <h2 :class="headingCls"><span class="h-2 w-2 rounded-full bg-primary-container" aria-hidden="true"></span>3. Nhận xét học sinh</h2>
                    <p class="font-caption text-caption text-on-surface-variant">Nộp báo cáo là gửi luôn nhận xét cho học viên. Học sinh vắng không cần nhận xét.</p>
                </div>
                <UiErrors :messages="Object.entries(errors).filter(([k]) => k.startsWith('remarks')).map(([, m]) => m)" />
                <p v-if="!remarkStudents.length" class="text-center font-body-small text-body-small italic text-on-surface-variant">Lớp chưa có học viên.</p>
                <div v-for="student in remarkStudents" :key="student.id" class="space-y-sm rounded-xl border border-outline-variant p-sm" :class="student.absent ? 'bg-surface-container-low/60' : 'bg-surface-container-lowest'" data-remark-row>
                    <div class="flex flex-wrap items-center justify-between gap-sm">
                        <span class="font-body-medium text-body-medium font-semibold text-on-surface">{{ student.name }}</span>
                        <UiBadge :color="student.absent ? 'error' : student.att_status === 'late' ? 'warning' : student.att_status === 'present' ? 'success' : 'neutral'">{{ attLabel[student.att_status] ?? student.att_status }}</UiBadge>
                    </div>
                    <div v-if="!student.absent" class="grid grid-cols-2 gap-sm md:grid-cols-5">
                        <label v-for="[key, label, placeholder] in REMARK_FIELDS" :key="key" class="block">
                            <span :class="labelCls">{{ label }}</span>
                            <select v-if="remarkOptions[key]" :name="`remarks[${student.id}][${key}]`" :class="fieldCls" @change="onRemarkPick(student, key, $event.target.value)">
                                <option value="" :selected="!student.remark[key]">— Chọn —</option>
                                <option v-for="choice in choicesFor(key, student.remark[key])" :key="choice" :value="choice" :selected="student.remark[key] === choice">{{ choice }}</option>
                            </select>
                            <input v-else type="text" :name="`remarks[${student.id}][${key}]`" :value="student.remark[key]" :placeholder="placeholder" :class="fieldCls" />
                        </label>
                    </div>
                    <textarea
                        v-if="!student.absent"
                        :name="`remarks[${student.id}][comment]`"
                        rows="2"
                        :value="student.remark.comment"
                        :aria-label="`Nhận xét chi tiết — ${student.name}`"
                        placeholder="Nhận xét chi tiết về quá trình học trong buổi..."
                        :class="fieldCls"
                    ></textarea>
                </div>
            </section>

            <section :class="sectionCls" data-report-section="notes">
                <h2 :class="headingCls" class="border-b border-surface-container pb-sm"><span class="h-2 w-2 rounded-full bg-primary-container" aria-hidden="true"></span>4. Giáo viên ghi nhận</h2>
                <UiAlert v-if="!attendanceDone" type="error">Buổi này chưa điểm danh. Điểm danh xong mới nộp được báo cáo buổi.</UiAlert>
                <UiErrors :messages="[errors.attendance]" />
                <UiTextarea
                    id="content"
                    v-model="content"
                    name="content"
                    label="Nội dung đã dạy"
                    required
                    :rows="3"
                    :hint="lessonText && !report ? 'Điền sẵn theo giáo trình của buổi. Sửa lại nếu thực tế dạy khác.' : null"
                    placeholder="Bài, kiến thức ngữ pháp / từ vựng, hoạt động chính đã dạy..."
                />
                <UiTextarea id="class_situation" name="class_situation" label="Tình hình lớp (tùy chọn)" :rows="3" :value="report?.class_situation" placeholder="Không khí lớp, nề nếp, mức độ tiếp thu, sự cố trong buổi..." />

                <div class="space-y-sm" data-attention>
                    <div class="flex flex-wrap items-center justify-between gap-sm">
                        <div>
                            <p class="font-body-medium text-body-medium font-semibold text-on-surface">Học sinh cần chú ý (tùy chọn)</p>
                            <p class="font-caption text-caption text-on-surface-variant">Gợi ý sẵn học sinh vắng buổi này hoặc được nhận xét "Cần cố gắng" / "Không đạt".</p>
                        </div>
                        <UiButton variant="secondary" size="sm" icon="add_circle" @click="addRow()">Thêm học sinh</UiButton>
                    </div>
                    <p v-if="!attention.length" class="text-center font-body-small text-body-small italic text-on-surface-variant">Không có học sinh cần chú ý.</p>
                    <div v-for="(r, idx) in attention" :key="r.id" class="space-y-sm rounded-xl border border-outline-variant bg-surface-container-low p-sm">
                        <div class="flex items-center justify-between">
                            <UiBadge v-if="r.suggested" color="info" :dot="false">Gợi ý</UiBadge>
                            <span v-else></span>
                            <button type="button" class="inline-flex items-center gap-xs font-caption text-caption font-semibold text-error hover:underline" @click="removeRow(idx)">
                                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">delete</span>Xóa dòng
                            </button>
                        </div>
                        <div class="grid grid-cols-1 gap-sm md:grid-cols-3">
                            <label>
                                <span :class="labelCls">Học sinh</span>
                                <select v-model="r.student_id" :name="`attention[${idx}][student_id]`" :class="fieldCls">
                                    <option value="">-- Chọn học sinh --</option>
                                    <option v-for="st in students" :key="st.value" :value="String(st.value)">{{ st.label }}</option>
                                </select>
                            </label>
                            <label>
                                <span :class="labelCls">Lý do</span>
                                <textarea v-model="r.reason" :name="`attention[${idx}][reason]`" rows="2" :class="fieldCls" placeholder="Vắng, chưa theo kịp, thái độ..."></textarea>
                            </label>
                            <label>
                                <span :class="labelCls">Hướng xử lý</span>
                                <textarea v-model="r.action" :name="`attention[${idx}][action]`" rows="2" :class="fieldCls" placeholder="Kèm thêm, báo phụ huynh, bù bài..."></textarea>
                            </label>
                        </div>
                    </div>
                </div>

                <UiTextarea id="proposal" name="proposal" label="Đề xuất / cần hỗ trợ (tùy chọn)" :rows="2" :value="report?.proposal" placeholder="Cần Học vụ / trợ giảng hỗ trợ gì, học liệu, phòng học..." />
            </section>
            <div class="flex flex-wrap items-center justify-end gap-sm">
                <span v-if="report" class="font-caption text-caption text-on-surface-variant">Nộp lúc {{ report.submitted_at }}</span>
                <UiButton type="submit" icon="send" :disabled="processing || !attendanceDone">{{ report ? 'Cập nhật báo cáo' : 'Nộp báo cáo buổi' }}</UiButton>
            </div>
        </UiForm>

        <!-- Người xem (không phải GV chính): chỉ đọc -->
        <section v-if="!canSubmit" :class="sectionCls" data-report-section="remarks-readonly">
            <h2 :class="headingCls" class="border-b border-surface-container pb-sm"><span class="h-2 w-2 rounded-full bg-primary-container" aria-hidden="true"></span>3. Nhận xét học sinh</h2>
            <p v-if="!remarkStudents.some(hasRemark)" class="font-body-small text-body-small italic text-on-surface-variant">Chưa có nhận xét.</p>
            <ul v-else class="divide-y divide-surface-container">
                <li v-for="student in remarkStudents.filter(hasRemark)" :key="student.id" class="py-sm font-body-small text-body-small text-on-surface">
                    <strong>{{ student.name }}</strong>
                    <span v-for="[key, label] in REMARK_FIELDS" :key="key"><template v-if="student.remark[key]"> · {{ label }}: {{ student.remark[key] }}</template></span>
                    <p v-if="student.remark.comment" class="mt-xs whitespace-pre-line text-on-surface-variant">{{ student.remark.comment }}</p>
                </li>
            </ul>
        </section>
        <section v-if="!canSubmit" :class="sectionCls" data-report-section="readonly">
            <h2 :class="headingCls" class="border-b border-surface-container pb-sm"><span class="h-2 w-2 rounded-full bg-primary-container" aria-hidden="true"></span>4. Giáo viên ghi nhận</h2>
            <UiEmptyState v-if="!report" icon="pending_actions" title="Giáo viên chính chưa nộp báo cáo buổi này." :description="`Hạn nộp ${deadline}.`" />
            <template v-else>
                <p class="font-caption text-caption text-on-surface-variant">{{ report.teacher }} nộp lúc {{ report.submitted_at }}<template v-if="report.updated_at !== report.submitted_at"> · sửa lần cuối {{ report.updated_at }}</template></p>
                <div>
                    <p :class="labelCls">Nội dung đã dạy</p>
                    <p class="whitespace-pre-line font-body-medium text-body-medium text-on-surface">{{ report.content }}</p>
                </div>
                <div v-if="report.class_situation">
                    <p :class="labelCls">Tình hình lớp</p>
                    <p class="whitespace-pre-line font-body-medium text-body-medium text-on-surface">{{ report.class_situation }}</p>
                </div>
                <div v-if="report.attention.length">
                    <p :class="labelCls">Học sinh cần chú ý</p>
                    <ul class="space-y-xs">
                        <li v-for="(r, i) in report.attention" :key="i" class="rounded-lg bg-surface-container-low px-sm py-xs font-body-small text-body-small text-on-surface">
                            <strong>{{ r.student ?? 'Học sinh' }}</strong><span v-if="r.reason">: {{ r.reason }}</span><span v-if="r.action" class="text-on-surface-variant"> → {{ r.action }}</span>
                        </li>
                    </ul>
                </div>
                <div v-if="report.proposal">
                    <p :class="labelCls">Đề xuất / cần hỗ trợ</p>
                    <p class="whitespace-pre-line font-body-medium text-body-medium text-on-surface">{{ report.proposal }}</p>
                </div>
            </template>
        </section>
    </div>
</template>
