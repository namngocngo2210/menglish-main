<script setup>
/**
 * Báo cáo buổi học của giáo viên chính, mở từ bảng Buổi học ở Trang lớp. Một cột, từ trên xuống:
 *   Việc của buổi (điểm danh · đã dạy · BTVN, mở ngăn để làm ngay) → Nhận xét học sinh (điểm thưởng, BTVN buổi trước,
 *   cột Thực hành do GV đặt tên, tinh thần, kết quả, nhận xét) → Nội dung buổi học → Học sinh cần chú ý → Sự vụ trong buổi
 *   → thanh nộp (hạn nộp, ghi nhận Đã dạy). Người khác GV chính mở → cùng bố cục, chỉ đọc.
 */
import { computed, reactive, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import SessionTrialGuests from '@/Components/Teacher/SessionTrialGuests.vue';
import { openRemoteModal } from '@/lib/remoteModal';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Báo cáo buổi học' } });

const props = defineProps({
    classInfo: { type: Object, required: true },
    sessionInfo: { type: Object, required: true },
    checklist: { type: Array, default: () => [] },
    attendanceDone: { type: Boolean, default: false },
    students: { type: Array, default: () => [] },
    suggestions: { type: Array, default: () => [] },
    sharedAttention: { type: Array, default: () => [] },
    syllabus: { type: Object, default: null },
    report: { type: Object, default: null },
    lessonText: { type: String, default: null },
    deadline: { type: String, default: null },
    overdue: { type: Boolean, default: false },
    canSubmit: { type: Boolean, default: false },
    remarkStudents: { type: Array, default: () => [] },
    remarkOptions: { type: Object, default: () => ({}) },
    trialGuests: { type: Object, default: () => ({ scope: 'upcoming', items: [] }) },
    homeworkColumns: { type: Array, default: () => [] },
    homeworkTitle: { type: String, default: null },
    practiceColumns: { type: Array, default: () => [] },
    incidents: { type: Array, default: () => [] },
    severities: { type: Object, default: () => ({}) },
});

const page = usePage();
const errors = computed(() => page.props.errors ?? {});
const errorList = computed(() => Object.values(errors.value).filter((v) => typeof v === 'string'));
const edit = computed(() => props.canSubmit);

const WEEKDAYS = ['Chủ nhật', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7'];
const title = computed(() => {
    const day = props.sessionInfo.weekday !== undefined && props.sessionInfo.weekday !== null ? `${WEEKDAYS[props.sessionInfo.weekday]}, ` : '';
    return `Báo cáo buổi${props.sessionInfo.no ? ` ${props.sessionInfo.no}` : ''} · ${day}${props.sessionInfo.date.slice(0, 5)}`;
});
const meta = computed(() =>
    [props.sessionInfo.time, props.sessionInfo.room ? `Phòng ${props.sessionInfo.room}` : null, props.sessionInfo.lesson, props.sessionInfo.teacher ? `GV ${props.sessionInfo.teacher}` : null]
        .filter(Boolean)
        .join(' · '),
);

// ── Việc của buổi: điểm danh / BTVN mở ngăn bên phải ngay trên báo cáo.
function openItem(item) {
    if (item.drawer) openRemoteModal(item.link.url, { size: 'drawer' });
}

// ── Nhận xét học sinh
let seq = 1;
const present = computed(() => props.remarkStudents.filter((s) => !s.absent));
const absent = computed(() => props.remarkStudents.filter((s) => s.absent));
const showAbsent = ref(false);
// Cột Thực hành: GV đặt tên ngay trên tiêu đề, thêm / bỏ cột tại chỗ. Giá trị từng học sinh giữ theo id cột.
const columns = ref(props.practiceColumns.map((label) => ({ id: seq++, label })));
const remarks = reactive(
    Object.fromEntries(
        props.remarkStudents.map((s) => {
            const r = s.remark ?? {};
            const practice = r.practice && typeof r.practice === 'object' ? r.practice : {};
            return [
                s.id,
                {
                    bonus: r.bonus ?? '',
                    hw_score: r.hw_score ?? '',
                    attitude: r.attitude ?? '',
                    result: r.result ?? '',
                    comment: r.comment ?? '',
                    practice: Object.fromEntries(columns.value.map((c) => [c.id, practice[c.label] ?? ''])),
                },
            ];
        }),
    ),
);
const editingColumn = ref(null);
function addColumn() {
    if (columns.value.length >= 6) return;
    const col = { id: seq++, label: '' };
    columns.value.push(col);
    editingColumn.value = col.id;
}
const removeColumn = (idx) => columns.value.splice(idx, 1);
const vFocus = { mounted: (el) => el.focus() };

const HW_ICON = { done: ['check_circle', 'text-tertiary', 'Đã nộp đúng hạn'], late: ['schedule', 'text-warning', 'Nộp trễ'], missing: ['cancel', 'text-error', 'Chưa nộp'] };
const choicesFor = (key, current) => {
    const options = props.remarkOptions[key] ?? [];
    return current && !options.includes(current) ? [...options, current] : options;
};
const WEAK = { attitude: 'Cần cố gắng', result: 'Không đạt' };
const FIELD_LABEL = { attitude: 'Tinh thần', result: 'Kết quả' };
function onPick(student, key, value) {
    if (WEAK[key] !== value || attention.value.some((r) => r.student_id === String(student.id))) return;
    attention.value.push(row({ student_id: student.id, reason: `Nhận xét: ${FIELD_LABEL[key]} ${value.toLowerCase()}` }, true));
}

// ── Học sinh cần chú ý (danh sách chung của buổi với TA / Học vụ)
const row = (r = {}, suggested = false) => ({ id: seq++, student_id: r.student_id ? String(r.student_id) : '', reason: r.reason ?? '', action: r.action ?? '', student: r.student ?? null, suggested });
const attention = ref(props.report ? props.report.attention.map((r) => row(r)) : props.canSubmit ? props.suggestions.map((r) => row(r, true)) : []);
const addAttention = () => attention.value.push(row());

// ── Sự vụ trong buổi (ghi vào Nhật ký sự vụ của lớp)
const incidents = ref(props.incidents.map((i) => ({ key: seq++, ...i })));
const addIncident = () => incidents.value.push({ key: seq++, id: null, title: '', content: '', severity: 'normal' });
const SEVERITY_TONE = { normal: 'neutral', important: 'warning', urgent: 'error' };

const content = ref(props.report?.content ?? props.lessonText ?? '');

const card = 'rounded-xl border border-surface-container-highest bg-surface-container-lowest p-lg';
const h2 = 'font-h3 text-h3 text-on-surface';
const field = 'w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs font-body-small text-body-small text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/50';
const th = 'border-b border-surface-container px-sm py-xs text-left text-xs font-semibold text-on-surface-variant';
const td = 'border-b border-surface-container px-sm py-xs align-top';
const sticky = 'sticky left-0 z-[1] bg-surface-container-lowest';
const attLabel = { present: 'Có mặt', late: 'Đi muộn', absent: 'Vắng', excused: 'Vắng có phép', none: 'Chưa điểm danh' };
</script>

<template>
    <UiForm :action="route('classes.session-report.store', { id: classInfo.id, session: sessionInfo.id })" method="post" class="space-y-md pb-28" #default="{ processing }">
        <!-- Đầu trang -->
        <header class="space-y-1">
            <nav aria-label="Đường dẫn" class="flex flex-wrap items-center gap-xs font-body-small text-body-small text-on-surface-variant">
                <Link :href="route('classes.index')" class="font-semibold text-primary hover:underline">Danh sách lớp</Link>
                <span aria-hidden="true">/</span>
                <Link :href="classInfo.url" class="font-semibold text-primary hover:underline">{{ classInfo.name }}</Link>
                <span aria-hidden="true">/</span>
                <span>Báo cáo buổi</span>
            </nav>
            <div class="flex flex-wrap items-center gap-sm">
                <h1 class="font-h2 text-h2 text-on-surface">{{ title }}</h1>
                <UiBadge v-if="report" :color="report.late ? 'warning' : 'success'" pill>{{ report.late ? 'Đã nộp · trễ hạn' : 'Đã nộp' }}</UiBadge>
                <UiBadge v-else-if="overdue" color="error" pill>Quá hạn nộp</UiBadge>
                <UiBadge v-else color="neutral" pill>Chưa nộp</UiBadge>
            </div>
            <p v-if="meta" class="font-body-base text-body-base text-on-surface-variant">{{ meta }}</p>
        </header>

        <UiAlert v-if="!canSubmit" type="info">Bạn đang xem báo cáo của giáo viên chính (chỉ đọc).</UiAlert>
        <UiErrors v-if="errorList.length" :messages="errorList" />
        <SessionTrialGuests :guests="trialGuests" />

        <!-- Việc của buổi -->
        <section :class="card" data-report-section="checklist">
            <h2 :class="h2" class="mb-sm">Việc của buổi</h2>
            <ul class="grid grid-cols-1 gap-sm md:grid-cols-3">
                <li v-for="item in checklist" :key="item.key" class="flex flex-col gap-xs rounded-lg border border-surface-container p-sm" :data-check="item.key">
                    <div class="flex items-center gap-xs">
                        <span class="material-symbols-outlined text-[20px]" :class="item.done ? 'text-tertiary' : item.required ? 'text-error' : 'text-warning'" aria-hidden="true">{{
                            item.done ? 'check_circle' : item.required ? 'error' : 'radio_button_unchecked'
                        }}</span>
                        <span class="font-semibold text-on-surface">{{ item.label }}</span>
                        <span v-if="item.required && !item.done" class="text-xs text-error">bắt buộc</span>
                    </div>
                    <p class="font-body-small text-body-small text-on-surface-variant">{{ item.value }}</p>
                    <ul v-if="item.details?.length" class="flex flex-wrap gap-xs">
                        <li v-for="(d, i) in item.details" :key="i" class="rounded bg-surface-container-low px-xs text-xs text-on-surface-variant">
                            {{ d.text }}<span v-if="d.tag" class="ml-xs font-semibold" :class="d.tone === 'error' ? 'text-error' : 'text-warning'">{{ d.tag }}</span>
                        </li>
                    </ul>
                    <template v-if="item.link">
                        <UiButton v-if="item.drawer" variant="secondary" size="sm" class="mt-auto self-start" @click="openItem(item)">{{ item.link.label }}</UiButton>
                        <Link v-else :href="item.link.url" class="mt-auto font-body-small text-body-small font-semibold text-primary hover:underline">{{ item.link.label }} →</Link>
                    </template>
                </li>
            </ul>
        </section>

        <!-- Nhận xét học sinh -->
        <section :class="card" data-report-section="remarks">
            <div class="mb-sm flex flex-wrap items-baseline justify-between gap-sm">
                <h2 :class="h2">Nhận xét học sinh</h2>
                <p class="text-xs text-on-surface-variant">Nộp báo cáo là gửi nhận xét cho phụ huynh.{{ homeworkTitle ? ` Cột BTVN: ${homeworkTitle}.` : '' }}</p>
            </div>
            <p v-if="!remarkStudents.length" class="text-on-surface-variant">Lớp chưa có học viên.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[960px] border-separate border-spacing-0 text-sm">
                    <thead>
                        <tr>
                            <th rowspan="2" :class="[th, sticky, 'min-w-[11rem]']">Học sinh</th>
                            <th rowspan="2" :class="[th, 'w-20']">Điểm thưởng</th>
                            <th :colspan="homeworkColumns.length + 1" :class="[th, 'border-l text-center']">BTVN</th>
                            <th :colspan="columns.length + (edit ? 1 : 0) || 1" :class="[th, 'border-l text-center']">Thực hành</th>
                            <th rowspan="2" :class="[th, 'border-l']">Tinh thần</th>
                            <th rowspan="2" :class="th">Kết quả</th>
                            <th rowspan="2" :class="[th, 'w-full min-w-[14rem]']">Nhận xét</th>
                        </tr>
                        <tr>
                            <th v-for="(c, i) in homeworkColumns" :key="c.key" :class="[th, 'text-center', i === 0 && 'border-l']" :title="c.label">
                                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ c.icon }}</span><span class="sr-only">{{ c.label }}</span>
                            </th>
                            <th :class="[th, !homeworkColumns.length && 'border-l', 'w-20']">Điểm</th>
                            <th v-for="(c, idx) in columns" :key="c.id" :class="[th, idx === 0 && 'border-l', 'min-w-[8rem]']">
                                <input v-if="edit" type="hidden" :name="`practice_columns[${idx}]`" :value="c.label" />
                                <div v-if="edit && editingColumn === c.id" class="flex items-center gap-0.5">
                                    <input v-model="c.label" v-focus type="text" maxlength="60" placeholder="Tên cột" :class="[field, 'h-7']" :aria-label="`Tên cột thực hành ${idx + 1}`" @keydown.enter.prevent="editingColumn = null" @blur="editingColumn = null" />
                                </div>
                                <div v-else class="flex items-center gap-0.5">
                                    <span :class="!c.label && 'italic'">{{ c.label || 'Chưa đặt tên' }}</span>
                                    <template v-if="edit">
                                        <button type="button" class="rounded p-0.5 hover:bg-surface-container" :aria-label="`Đổi tên cột ${c.label}`" @click="editingColumn = c.id">
                                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">edit</span>
                                        </button>
                                        <button type="button" class="rounded p-0.5 hover:bg-surface-container" :aria-label="`Bỏ cột ${c.label}`" @click="removeColumn(idx)">
                                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">close</span>
                                        </button>
                                    </template>
                                </div>
                            </th>
                            <th v-if="edit" :class="[th, !columns.length && 'border-l', 'whitespace-nowrap']">
                                <button v-if="columns.length < 6" type="button" class="font-semibold text-primary hover:underline" data-add-practice @click="addColumn">+ Thêm cột</button>
                            </th>
                            <th v-else-if="!columns.length" :class="[th, 'border-l']">—</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in present" :key="s.id" data-remark-row>
                            <td :class="[td, sticky]">
                                <p class="font-semibold text-on-surface">{{ s.name }}</p>
                                <p v-if="s.att_status !== 'present'" class="text-xs" :class="s.att_status === 'late' ? 'text-warning' : 'text-on-surface-variant'">{{ attLabel[s.att_status] ?? s.att_status }}</p>
                            </td>
                            <td :class="td">
                                <input v-if="edit" v-model="remarks[s.id].bonus" type="number" min="0" max="999" step="1" placeholder="0" :name="`remarks[${s.id}][bonus]`" :aria-label="`Điểm thưởng — ${s.name}`" :class="[field, 'w-16']" />
                                <span v-else>{{ remarks[s.id].bonus || '0' }}</span>
                            </td>
                            <td v-for="(c, i) in homeworkColumns" :key="c.key" :class="[td, 'text-center', i === 0 && 'border-l']">
                                <span
                                    v-if="s.homework[c.key]"
                                    class="material-symbols-outlined text-[20px]"
                                    :class="HW_ICON[s.homework[c.key]][1]"
                                    :title="`${c.label}: ${HW_ICON[s.homework[c.key]][2]}`"
                                    :aria-label="`${c.label}: ${HW_ICON[s.homework[c.key]][2]}`"
                                    role="img"
                                    >{{ HW_ICON[s.homework[c.key]][0] }}</span
                                >
                            </td>
                            <td :class="[td, !homeworkColumns.length && 'border-l']">
                                <input v-if="edit" v-model="remarks[s.id].hw_score" type="number" min="0" max="100" step="0.5" :name="`remarks[${s.id}][hw_score]`" :aria-label="`Điểm BTVN — ${s.name}`" :class="[field, 'w-16']" />
                                <span v-else>{{ remarks[s.id].hw_score || '—' }}</span>
                            </td>
                            <td v-for="(c, idx) in columns" :key="c.id" :class="[td, idx === 0 && 'border-l']">
                                <input v-if="edit" v-model="remarks[s.id].practice[c.id]" type="text" maxlength="255" :name="`remarks[${s.id}][practice][${idx}]`" :aria-label="`${c.label || 'Thực hành'} — ${s.name}`" :class="field" />
                                <span v-else>{{ remarks[s.id].practice[c.id] || '—' }}</span>
                            </td>
                            <td v-if="edit" :class="[td, !columns.length && 'border-l']"></td>
                            <td v-else-if="!columns.length" :class="[td, 'border-l']"></td>
                            <td v-for="key in ['attitude', 'result']" :key="key" :class="[td, key === 'attitude' && 'border-l']">
                                <select v-if="edit" v-model="remarks[s.id][key]" :name="`remarks[${s.id}][${key}]`" :aria-label="`${FIELD_LABEL[key]} — ${s.name}`" :class="[field, 'min-w-[7.5rem]']" @change="onPick(s, key, $event.target.value)">
                                    <option value="">— Chọn —</option>
                                    <option v-for="choice in choicesFor(key, s.remark[key])" :key="choice" :value="choice">{{ choice }}</option>
                                </select>
                                <span v-else>{{ remarks[s.id][key] || '—' }}</span>
                            </td>
                            <td :class="td">
                                <textarea v-if="edit" v-model="remarks[s.id].comment" :name="`remarks[${s.id}][comment]`" rows="2" :aria-label="`Nhận xét — ${s.name}`" placeholder="Nhận xét về buổi học..." :class="field"></textarea>
                                <span v-else class="whitespace-pre-line">{{ remarks[s.id].comment || '—' }}</span>
                            </td>
                        </tr>
                        <!-- Học sinh vắng: gom một dòng, không cần nhận xét. -->
                        <tr v-if="absent.length">
                            <td :colspan="99" :class="[td, 'bg-surface-container-low/60']">
                                <button type="button" class="flex items-center gap-xs text-on-surface-variant" :aria-expanded="showAbsent ? 'true' : 'false'" @click="showAbsent = !showAbsent">
                                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ showAbsent ? 'expand_less' : 'expand_more' }}</span>
                                    {{ absent.length }} học sinh vắng, không cần nhận xét
                                </button>
                                <p v-if="showAbsent" class="mt-xs text-on-surface-variant">{{ absent.map((s) => `${s.name} (${attLabel[s.att_status]})`).join(', ') }}</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Nội dung buổi học -->
        <section :class="card" class="space-y-md" data-report-section="notes">
            <h2 :class="h2">Nội dung buổi học</h2>
            <template v-if="edit">
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
                <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                    <UiTextarea id="class_situation" name="class_situation" label="Tình hình lớp (tùy chọn)" :rows="3" :value="report?.class_situation" placeholder="Không khí lớp, nề nếp, mức độ tiếp thu..." />
                    <UiTextarea id="proposal" name="proposal" label="Đề xuất / cần hỗ trợ (tùy chọn)" :rows="3" :value="report?.proposal" placeholder="Cần Học vụ / trợ giảng hỗ trợ gì, học liệu, phòng học..." />
                </div>
            </template>
            <UiEmptyState v-else-if="!report" icon="pending_actions" title="Giáo viên chính chưa nộp báo cáo buổi này." :description="`Hạn nộp ${deadline}.`" />
            <dl v-else class="space-y-sm">
                <div>
                    <dt class="text-xs font-semibold text-on-surface-variant">Nội dung đã dạy</dt>
                    <dd class="whitespace-pre-line text-on-surface">{{ report.content }}</dd>
                </div>
                <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold text-on-surface-variant">Tình hình lớp</dt>
                        <dd class="whitespace-pre-line text-on-surface">{{ report.class_situation || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold text-on-surface-variant">Đề xuất / cần hỗ trợ</dt>
                        <dd class="whitespace-pre-line text-on-surface">{{ report.proposal || '—' }}</dd>
                    </div>
                </div>
                <p class="text-xs text-on-surface-variant">{{ report.teacher }} nộp lúc {{ report.submitted_at }}<template v-if="report.updated_at !== report.submitted_at"> · sửa lần cuối {{ report.updated_at }}</template></p>
            </dl>
        </section>

        <!-- Học sinh cần chú ý -->
        <section :class="card" class="space-y-sm" data-attention>
            <div class="flex flex-wrap items-center justify-between gap-sm">
                <div>
                    <h2 :class="h2">Học sinh cần chú ý</h2>
                    <p class="text-xs text-on-surface-variant">Danh sách chung của buổi với trợ giảng và Học vụ.</p>
                </div>
                <UiButton v-if="edit" variant="secondary" size="sm" icon="add" @click="addAttention">Thêm học sinh</UiButton>
            </div>
            <ul v-if="sharedAttention.length" class="space-y-xs" data-shared-attention>
                <li v-for="(r, i) in sharedAttention" :key="i" class="rounded-lg bg-surface-container-low px-sm py-xs text-sm text-on-surface">
                    <UiBadge color="neutral" :dot="false" class="mr-xs">{{ r.source }}</UiBadge>
                    <strong>{{ r.student ?? 'Học sinh' }}</strong><span v-if="r.reason">: {{ r.reason }}</span><span v-if="r.action" class="text-on-surface-variant"> → {{ r.action }}</span>
                </li>
            </ul>
            <p v-if="!attention.length && !sharedAttention.length" class="text-sm text-on-surface-variant">Không có học sinh cần chú ý.</p>
            <template v-if="edit">
                <div v-for="(r, idx) in attention" :key="r.id" class="grid grid-cols-1 items-start gap-sm rounded-lg border border-surface-container p-sm md:grid-cols-[14rem_1fr_1fr_auto]">
                    <select v-model="r.student_id" :name="`attention[${idx}][student_id]`" aria-label="Học sinh" :class="[field, 'h-9']">
                        <option value="">— Chọn học sinh —</option>
                        <option v-for="st in students" :key="st.value" :value="String(st.value)">{{ st.label }}</option>
                    </select>
                    <input v-model="r.reason" type="text" :name="`attention[${idx}][reason]`" aria-label="Lý do" placeholder="Lý do" :class="[field, 'h-9']" />
                    <input v-model="r.action" type="text" :name="`attention[${idx}][action]`" aria-label="Hướng xử lý" placeholder="Hướng xử lý" :class="[field, 'h-9']" />
                    <div class="flex items-center gap-xs">
                        <UiBadge v-if="r.suggested" color="info" :dot="false">Gợi ý</UiBadge>
                        <button type="button" class="rounded p-xs text-on-surface-variant hover:bg-surface-container hover:text-error" aria-label="Bỏ dòng" @click="attention.splice(idx, 1)">
                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">delete</span>
                        </button>
                    </div>
                </div>
            </template>
            <ul v-else class="space-y-xs">
                <li v-for="r in attention" :key="r.id" class="rounded-lg bg-surface-container-low px-sm py-xs text-sm text-on-surface">
                    <strong>{{ r.student ?? 'Học sinh' }}</strong><span v-if="r.reason">: {{ r.reason }}</span><span v-if="r.action" class="text-on-surface-variant"> → {{ r.action }}</span>
                </li>
            </ul>
        </section>

        <!-- Sự vụ trong buổi -->
        <section :class="card" class="space-y-sm" data-report-section="incidents">
            <div class="flex flex-wrap items-center justify-between gap-sm">
                <div>
                    <h2 :class="h2">Sự vụ trong buổi</h2>
                    <p class="text-xs text-on-surface-variant">Ghi vào Nhật ký sự vụ của lớp. Mức Khẩn báo ngay Admin và Quản lý cơ sở.</p>
                </div>
                <UiButton v-if="edit" variant="secondary" size="sm" icon="add" data-add-incident @click="addIncident">Thêm sự vụ</UiButton>
            </div>
            <p v-if="!incidents.length" class="text-sm text-on-surface-variant">Không có sự vụ.</p>
            <template v-if="edit">
                <div v-for="(inc, idx) in incidents" :key="inc.key" class="space-y-sm rounded-lg border border-surface-container p-sm" data-incident>
                    <input type="hidden" :name="`incidents[${idx}][id]`" :value="inc.id ?? ''" />
                    <input type="hidden" :name="`incidents[${idx}][severity]`" :value="inc.severity" />
                    <div class="flex flex-wrap items-center gap-sm">
                        <input v-model="inc.title" type="text" maxlength="255" required :name="`incidents[${idx}][title]`" aria-label="Tiêu đề sự vụ" placeholder="Tiêu đề, vd. HS bị ngã trong giờ ra chơi" :class="[field, 'h-9 min-w-[14rem] flex-1']" />
                        <div role="radiogroup" aria-label="Mức độ" class="inline-flex gap-0.5 rounded-lg bg-surface-container-low p-[3px]">
                            <button
                                v-for="(label, value) in severities"
                                :key="value"
                                type="button"
                                role="radio"
                                :aria-checked="inc.severity === value ? 'true' : 'false'"
                                :class="['h-8 rounded-md px-sm text-xs font-semibold', inc.severity === value ? ['bg-surface-container-lowest shadow-sm', value === 'urgent' ? 'text-error' : value === 'important' ? 'text-warning' : 'text-on-surface'] : 'text-on-surface-variant']"
                                @click="inc.severity = value"
                            >
                                {{ label }}
                            </button>
                        </div>
                        <button type="button" class="rounded p-xs text-on-surface-variant hover:bg-surface-container hover:text-error" aria-label="Bỏ sự vụ" @click="incidents.splice(idx, 1)">
                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">delete</span>
                        </button>
                    </div>
                    <textarea v-model="inc.content" :name="`incidents[${idx}][content]`" rows="2" aria-label="Mô tả sự vụ" placeholder="Diễn biến, đã xử lý thế nào..." :class="field"></textarea>
                </div>
            </template>
            <ul v-else class="space-y-xs">
                <li v-for="inc in incidents" :key="inc.key" class="rounded-lg bg-surface-container-low px-sm py-xs text-sm">
                    <UiBadge :color="SEVERITY_TONE[inc.severity]" class="mr-xs">{{ severities[inc.severity] }}</UiBadge>
                    <strong>{{ inc.title }}</strong><span v-if="inc.content" class="text-on-surface-variant">: {{ inc.content }}</span>
                </li>
            </ul>
        </section>

        <!-- Thanh nộp -->
        <div v-if="edit" class="sticky bottom-0 z-10 -mx-xs flex flex-wrap items-center justify-between gap-sm rounded-xl border border-surface-container-highest bg-surface-container-lowest px-lg py-sm shadow-lg">
            <div class="min-w-0 space-y-0.5 text-sm">
                <p :class="overdue ? 'font-semibold text-error' : 'text-on-surface-variant'">
                    <template v-if="report">Nộp lúc {{ report.submitted_at }}</template>
                    <template v-else>Hạn nộp {{ deadline }}{{ overdue ? ' · đã quá hạn' : '' }}</template>
                </p>
                <p v-if="!attendanceDone" class="font-semibold text-error">Cần điểm danh trước khi nộp.</p>
                <template v-else-if="syllabus && !syllabus.marked">
                    <p class="text-on-surface-variant" data-taught-on-submit>Nộp báo cáo là ghi nhận buổi này Đã dạy.{{ !syllabus.confirmed ? ' Chặng chưa xác nhận nên chưa ghi nhận được.' : '' }}</p>
                    <label v-if="syllabus.confirmed && syllabus.extra_left > 0" class="flex items-center gap-xs text-on-surface">
                        <input type="checkbox" name="use_extra" value="1" class="rounded border-outline-variant text-primary" />
                        Dạy chưa xong bài, dùng 1 buổi giãn (còn {{ syllabus.extra_left }})
                    </label>
                </template>
            </div>
            <UiButton type="submit" icon="send" :disabled="processing || !attendanceDone">{{ report ? 'Cập nhật báo cáo' : 'Nộp báo cáo buổi' }}</UiButton>
        </div>
    </UiForm>
</template>
