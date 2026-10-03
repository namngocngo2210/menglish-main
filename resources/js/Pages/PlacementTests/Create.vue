<script setup>
/**
 * Tạo đề test đầu vào, 2 cách soạn dùng chung cấu trúc câu hỏi:
 * - "Tải đề PDF" (mặc định): tải file đề, hệ thống dựng sẵn phiếu trả lời + đáp án để kiểm tra (PdfSheetEditor);
 *   thí sinh xem nguyên file PDF và trả lời trên phiếu.
 * - "Soạn từng câu" (mockup t_o_m_i_qu_n_l_test): cột trái thông tin chung + danh sách câu hỏi, cột phải soạn chi tiết câu đang chọn.
 * Chuyển từ PDF sang soạn từng câu giữ các câu đã đọc được để soạn tiếp (đề không dùng file PDF nữa).
 * Câu hỏi gửi lên server dạng JSON (ô ẩn `questions`).
 * "Cấp độ" = khối lớp (A6 Q2); mã đề gợi ý theo khối để chấm đúng thang điểm (sửa tay thì giữ nguyên).
 */
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { confirmDialog } from '@/lib/confirm';
import { toast } from '@/lib/toast';
import PdfSheetEditor from '@/Components/PlacementTests/PdfSheetEditor.vue';
import { emptyOptions, model, toNumber, useMediaUpload } from './questionEditor';

defineOptions({ layout: { title: 'Tạo đề thi mới', hideErrors: true } });

const props = defineProps({
    gradeCodeTokens: { type: Object, required: true },
    gradeLevels: { type: Array, default: () => [] },
    levelRubricGroups: { type: Object, required: true },
    initialLevel: { type: String, default: 'lop_3' },
    initialCode: { type: String, required: true },
    initialMode: { type: String, default: 'pdf' },
});

const page = usePage();
const errorMessages = computed(() => Object.values(page.props.errors ?? {}).filter(Boolean));

// Soạn từng câu: đề mới bắt đầu với 1 câu hỏi trống (không soạn sẵn nội dung mẫu).
const blankFirstQuestion = () => ({
    id: 1,
    skill: 'reading',
    type: 'multiple_choice',
    section: 'READING SECTION',
    title: '',
    audio_url: '',
    passage: '',
    options: emptyOptions(),
    correct_answer: 'A',
    points: 1,
    explanation: '',
    teacher_note: '',
});
const mode = ref(props.initialMode);
const questions = ref(mode.value === 'manual' ? [blankFirstQuestion()] : []);
const pdfPath = ref('');
const pdfUrl = ref('');
const audioUrl = ref('');
const currentIndex = ref(0);

const isUntouchedBlank = () => questions.value.length === 1 && !String(questions.value[0].title || '').trim();
function setMode(next) {
    if (mode.value === next) return;
    if (next === 'manual') {
        if (!questions.value.length) questions.value = [blankFirstQuestion()];
    } else if (isUntouchedBlank()) {
        questions.value = [];
    }
    currentIndex.value = 0;
    mode.value = next;
}
const gradeLevel = ref(props.initialLevel);
const gradeGroup = ref(props.levelRubricGroups[props.initialLevel] || 'khac');
const code = ref(props.initialCode);
const codeTouched = ref(false);
const { uploading, uploadMedia } = useMediaUpload();

const currentQ = computed(() => questions.value[currentIndex.value] || null);

const skillOptions = [
    { value: 'listening', label: 'Listening (Nghe)' },
    { value: 'reading', label: 'Reading (Đọc hiểu)' },
    { value: 'grammar', label: 'Grammar / Vocab' },
    { value: 'writing', label: 'Writing (Viết)' },
    { value: 'speaking', label: 'Speaking (Nói)' },
];
const typeButtons = [
    { type: 'multiple_choice', icon: 'list_alt', label: 'Trắc nghiệm A/B/C/D' },
    { type: 'fill_blank', icon: 'edit_square', label: 'Điền vào chỗ trống' },
    { type: 'essay', icon: 'edit_note', label: 'Tự luận Writing' },
    { type: 'speaking_prompt', icon: 'record_voice_over', label: 'Phỏng vấn Speaking' },
];
const modes = [
    { value: 'pdf', icon: 'picture_as_pdf', label: 'Tải đề PDF', hint: 'Thí sinh xem nguyên file PDF và trả lời trên phiếu; hệ thống dựng sẵn phiếu và đáp án từ file.' },
    { value: 'manual', icon: 'edit_note', label: 'Soạn từng câu', hint: 'Nhập từng câu hỏi, phương án, ảnh, file nghe; thí sinh làm từng câu trên màn hình.' },
];
const typeBadges = { multiple_choice: 'Trắc nghiệm', fill_blank: 'Điền từ', speaking_prompt: 'Nói', essay: 'Tự luận' };
const getTypeBadge = (type) => typeBadges[type] || 'Câu hỏi';

function onGradeLevelChange() {
    gradeGroup.value = props.levelRubricGroups[gradeLevel.value] || 'khac';
    syncCode();
}

// Mã đề gợi ý theo khối lớp (người dùng sửa tay thì giữ nguyên).
function syncCode() {
    if (codeTouched.value) return;
    const stamp = new Date().toISOString().slice(2, 10).replace(/-/g, '') + '-' + String(Date.now()).slice(-4);
    const levelToken = gradeLevel.value === 'mau_giao' ? 'PRE-G1' : 'G' + String(gradeLevel.value).replace('lop_', '');
    code.value = 'TEST-' + (gradeGroup.value !== 'khac' && props.gradeCodeTokens[gradeGroup.value] ? props.gradeCodeTokens[gradeGroup.value] : levelToken) + '-' + stamp;
}

function saveAndNext() {
    if (!currentQ.value || !String(currentQ.value.title || '').trim()) {
        toast('Nhập nội dung câu hỏi trước khi sang câu tiếp theo.', 'error');
        return;
    }
    if (currentIndex.value < questions.value.length - 1) {
        currentIndex.value++;
    } else {
        addNewQuestion(currentQ.value.type, currentQ.value.skill);
    }
}

function setType(type) {
    if (!currentQ.value) return;
    currentQ.value.type = type;
    if (type === 'multiple_choice' && (!currentQ.value.options || currentQ.value.options.length === 0)) {
        currentQ.value.options = emptyOptions();
        currentQ.value.correct_answer = 'A';
    }
}

const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

function addOption() {
    if (!currentQ.value || !currentQ.value.options) return;
    const nextKey = LETTERS[currentQ.value.options.length] || String.fromCharCode(65 + currentQ.value.options.length);
    currentQ.value.options.push({ key: nextKey, text: '' });
}

function removeOption(idx) {
    const q = currentQ.value;
    if (!q || !q.options || q.options.length <= 2) return;
    const removedKey = q.options[idx].key;
    q.options.splice(idx, 1);
    q.options.forEach((opt, i) => {
        opt.key = LETTERS[i] || String.fromCharCode(65 + i);
    });
    if (q.correct_answer === removedKey) q.correct_answer = q.options[0].key;
}

function addNewQuestion(type = 'multiple_choice', skill = 'reading') {
    questions.value.push({
        id: Date.now(),
        skill,
        type,
        section: skill.toUpperCase() + ' SECTION',
        title: '',
        audio_url: '',
        passage: '',
        options: type === 'multiple_choice' ? emptyOptions() : [],
        correct_answer: type === 'multiple_choice' ? 'A' : '',
        points: 1,
        explanation: '',
        teacher_note: '',
    });
    currentIndex.value = questions.value.length - 1;
}

function moveQuestion(idx, direction) {
    const targetIdx = idx + direction;
    if (targetIdx < 0 || targetIdx >= questions.value.length) return;
    const list = questions.value;
    [list[idx], list[targetIdx]] = [list[targetIdx], list[idx]];
    currentIndex.value = targetIdx;
}

function duplicateQuestion(idx) {
    const clone = JSON.parse(JSON.stringify(questions.value[idx]));
    clone.id = Date.now();
    clone.title = (clone.title ? clone.title : 'Câu hỏi') + ' (Bản sao)';
    questions.value.splice(idx + 1, 0, clone);
    currentIndex.value = idx + 1;
}

async function deleteQuestion(idx) {
    if (questions.value.length <= 1) {
        toast('Đề thi phải có ít nhất 1 câu hỏi!', 'error');
        return;
    }
    if (await confirmDialog({ message: 'Xóa câu hỏi này?', confirmLabel: 'Xóa', danger: true })) {
        questions.value.splice(idx, 1);
        if (currentIndex.value >= questions.value.length) currentIndex.value = questions.value.length - 1;
    }
}

const typeButtonClass = (type) =>
    currentQ.value?.type === type
        ? 'border-2 border-primary-container bg-primary-container/10 text-primary-container font-black shadow-xs'
        : 'border border-surface-container-highest bg-surface-container-lowest text-on-surface-variant hover:bg-surface-container-low';
</script>

<template>
    <UiPageHeader title="Tạo đề thi mới" :back="route('placement-tests.index')" description='Soạn đề test đầu vào theo khối lớp — chấm theo "Thang điểm + hướng dẫn nhận xét"'>
        <template #actions>
            <UiButton variant="secondary" :href="route('placement-tests.index')">Hủy</UiButton>
            <UiButton type="submit" icon="save" form="createPlacementTestForm">Lưu đề thi</UiButton>
        </template>
    </UiPageHeader>

    <UiAlert v-if="errorMessages.length" type="error" title="Vui lòng kiểm tra lại các thông tin:" class="mb-4">
        <ul class="list-inside list-disc space-y-0.5 pl-2">
            <li v-for="(err, i) in errorMessages" :key="i">{{ err }}</li>
        </ul>
    </UiAlert>

    <div class="space-y-6">
        <UiForm id="createPlacementTestForm" :action="route('placement-tests.store')" method="post">
            <!-- Hidden Synchronized Questions JSON -->
            <input type="hidden" name="questions" :value="JSON.stringify(questions)" />
            <input type="hidden" name="questions_count" :value="questions.length" />
            <input type="hidden" name="mode" :value="mode" />
            <input type="hidden" name="pdf_path" :value="mode === 'pdf' ? pdfPath : ''" />
            <input type="hidden" name="audio_url" :value="mode === 'pdf' ? audioUrl : ''" />

            <!-- Cách soạn đề -->
            <div class="mb-4 flex flex-wrap items-center gap-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-3 shadow-sm">
                <span class="text-xs font-bold uppercase tracking-wider text-on-surface">Cách soạn đề</span>
                <div class="inline-flex rounded-xl bg-surface-container p-1 text-xs" role="group" aria-label="Cách soạn đề">
                    <button v-for="m in modes" :key="m.value" type="button" :aria-pressed="mode === m.value" :class="['flex items-center gap-1.5 rounded-lg px-3 py-1.5 font-bold transition', mode === m.value ? 'bg-surface-container-lowest text-primary shadow-xs' : 'text-on-surface-variant hover:text-on-surface']" @click="setMode(m.value)">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ m.icon }}</span>{{ m.label }}
                    </button>
                </div>
                <span class="text-xs text-on-surface-variant">{{ modes.find((m) => m.value === mode).hint }}</span>
            </div>

            <div :class="['flex min-h-[calc(100vh-180px)] flex-col gap-6', mode === 'manual' ? 'lg:flex-row' : '']">
                <!-- LEFT SIDEBAR: GENERAL INFO & QUESTION LIST -->
                <aside :class="['w-full shrink-0 space-y-4', mode === 'manual' ? 'lg:w-[360px] xl:w-[400px]' : '']">
                    <!-- General Info Card -->
                    <div class="space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm">
                        <div class="flex items-center justify-between border-b border-surface-container-highest pb-2">
                            <h3 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-on-surface">
                                <span class="material-symbols-outlined text-[18px] text-primary-container">info</span>
                                <span>Thông tin chung</span>
                            </h3>
                            <span class="text-xs font-medium text-error">* Bắt buộc</span>
                        </div>

                        <div :class="['text-xs', mode === 'manual' ? 'space-y-2.5' : 'grid grid-cols-1 gap-3 md:grid-cols-2']">
                            <UiInput name="title" label="Tên đề thi" required placeholder="VD: Đề Test Đầu Vào IELTS 6.5" class="font-bold" />

                            <div class="grid grid-cols-2 gap-2">
                                <UiInput v-model="code" name="code" label="Mã đề (Code)" required placeholder="TEST-G3-G4-01" class="font-mono font-bold" @input="codeTouched = true" />
                                <UiInput type="number" name="duration_minutes" label="Thời gian (phút)" :value="45" min="5" required class="font-mono font-bold" />
                            </div>

                            <div>
                                <UiSelect v-model="gradeLevel" name="grade_level" label="Cấp độ" required :options="gradeLevels" class="font-semibold" @change="onGradeLevelChange" />
                                <input type="hidden" name="grade_group" :value="gradeGroup" />
                                <p class="mt-1 text-xs text-on-surface-variant">Lớp hiện tại của khách làm đề này (ô "Chọn cấp độ" khi hẹn test ở CRM). Lớp 1–4 chấm theo thang điểm khối (mã đề chứa khối, vd. <span class="font-mono">G3-G4</span>); các lớp khác Học thuật chọn lớp thủ công.</p>
                            </div>

                            <UiTextarea name="description" label="Mô tả / Hướng dẫn làm bài" rows="2" placeholder="Ghi chú hướng dẫn..." />
                        </div>
                    </div>

                    <!-- Question List Navigation Card -->
                    <div v-if="mode === 'manual'" class="space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm">
                        <div class="flex items-center justify-between border-b border-surface-container-highest pb-2">
                            <h3 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-on-surface">
                                <span class="material-symbols-outlined text-[18px] text-secondary">format_list_numbered</span>
                                <span>Danh sách câu hỏi</span>
                            </h3>
                            <span class="rounded-full bg-primary-container/10 px-2.5 py-0.5 font-mono text-xs font-bold text-primary-container">{{ questions.length }} Câu</span>
                        </div>

                        <!-- Action Toolbar: Quick Add Types -->
                        <div class="grid grid-cols-2 gap-1.5 text-xs">
                            <button type="button" class="flex cursor-pointer items-center justify-center gap-1 rounded-lg bg-secondary/10 px-2.5 py-1.5 text-xs font-bold text-secondary transition hover:bg-secondary/20" @click="addNewQuestion('multiple_choice', 'listening')">
                                <span class="material-symbols-outlined text-[14px]">headphones</span>
                                <span>+ Trắc nghiệm</span>
                            </button>
                            <button type="button" class="flex cursor-pointer items-center justify-center gap-1 rounded-lg bg-accent-container px-2.5 py-1.5 text-xs font-bold text-accent transition hover:bg-accent-container" @click="addNewQuestion('fill_blank', 'grammar')">
                                <span class="material-symbols-outlined text-[14px]">edit_square</span>
                                <span>+ Điền từ</span>
                            </button>
                            <button type="button" class="flex cursor-pointer items-center justify-center gap-1 rounded-lg bg-warning-container px-2.5 py-1.5 text-xs font-bold text-on-warning-container transition hover:bg-warning/20" @click="addNewQuestion('essay', 'writing')">
                                <span class="material-symbols-outlined text-[14px]">edit_document</span>
                                <span>+ Tự luận Writing</span>
                            </button>
                            <button type="button" class="flex cursor-pointer items-center justify-center gap-1 rounded-lg bg-error/10 px-2.5 py-1.5 text-xs font-bold text-error transition hover:bg-error/20" @click="addNewQuestion('speaking_prompt', 'speaking')">
                                <span class="material-symbols-outlined text-[14px]">mic</span>
                                <span>+ Speaking</span>
                            </button>
                        </div>

                        <!-- Question Cards List -->
                        <div class="max-h-[460px] space-y-2 overflow-y-auto pr-1">
                            <div
                                v-for="(q, idx) in questions"
                                :key="q.id"
                                :class="[
                                    'cursor-pointer space-y-1 rounded-xl border p-3 transition-all',
                                    currentIndex === idx ? 'border-primary bg-primary-container text-white shadow-md ring-2 ring-primary-container/30' : 'border-surface-container-highest bg-surface-container-lowest text-on-surface hover:border-primary-container/30 hover:bg-surface-container-low',
                                ]"
                                @click="currentIndex = idx"
                            >
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-1.5">
                                        <span :class="['font-mono text-xs font-black', currentIndex === idx ? 'text-white' : 'text-on-surface']">Câu {{ String(idx + 1).padStart(2, '0') }}</span>
                                        <span :class="['rounded px-1.5 py-0.5 text-xs font-extrabold uppercase', currentIndex === idx ? 'bg-white/20 text-white' : 'bg-surface-container text-on-surface-variant']">{{ getTypeBadge(q.type) }}</span>
                                    </div>
                                    <div class="flex items-center gap-1" @click.stop>
                                        <button type="button" :disabled="idx === 0" class="rounded p-0.5 hover:bg-black/10 disabled:opacity-30" title="Lên" @click="moveQuestion(idx, -1)">
                                            <span class="material-symbols-outlined text-[14px]">arrow_upward</span>
                                        </button>
                                        <button type="button" :disabled="idx === questions.length - 1" class="rounded p-0.5 hover:bg-black/10 disabled:opacity-30" title="Xuống" @click="moveQuestion(idx, 1)">
                                            <span class="material-symbols-outlined text-[14px]">arrow_downward</span>
                                        </button>
                                        <button type="button" :disabled="questions.length <= 1" class="rounded p-0.5 text-error/70 hover:bg-black/10 hover:text-error-container disabled:opacity-30" title="Xóa" @click="deleteQuestion(idx)">
                                            <span class="material-symbols-outlined text-[14px]">close</span>
                                        </button>
                                    </div>
                                </div>
                                <p :class="['line-clamp-1 text-xs leading-snug', currentIndex === idx ? 'text-white/90' : 'text-on-surface-variant']">{{ q.title || '(Chưa nhập nội dung đề bài...)' }}</p>
                                <div :class="['flex items-center justify-between border-t pt-1 text-xs', currentIndex === idx ? 'border-white/20 text-white/90' : 'border-surface-container-highest text-on-surface-variant']">
                                    <span class="font-bold uppercase tracking-wider">{{ q.skill }}</span>
                                    <span :class="['font-mono font-bold', currentIndex === idx ? 'text-white' : 'text-primary']">{{ (q.points || 1) + 'đ' }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Big Add Button -->
                        <button type="button" class="flex w-full cursor-pointer items-center justify-center gap-1.5 rounded-xl border-2 border-dashed border-primary-container/30 bg-primary-container/5 py-2.5 text-xs font-bold text-primary-container shadow-2xs transition-all hover:border-primary-container hover:bg-primary-container/10" @click="addNewQuestion('multiple_choice', 'reading')">
                            <span class="material-symbols-outlined text-[18px]">add_circle</span>
                            <span>Thêm câu hỏi mới vào đề</span>
                        </button>
                    </div>
                </aside>

                <!-- Tải đề PDF: xem đề + phiếu đáp án -->
                <div v-if="mode === 'pdf'" class="space-y-4">
                    <PdfSheetEditor v-model:questions="questions" v-model:pdf-path="pdfPath" v-model:pdf-url="pdfUrl" v-model:audio-url="audioUrl" />
                    <div class="flex flex-wrap items-center justify-end gap-sm rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-xs">
                        <UiButton type="submit" variant="secondary" name="save_mode" value="draft">Lưu nháp</UiButton>
                        <UiButton type="submit" icon="save">Lưu đề thi</UiButton>
                    </div>
                </div>

                <!-- RIGHT MAIN PANEL: QUESTION DETAIL EDITOR -->
                <div v-else class="flex-1 space-y-6 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
                    <div v-if="currentQ" class="space-y-5">
                        <!-- Editor Header -->
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-surface-container-highest pb-3">
                            <div class="flex items-center gap-2">
                                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-primary-container font-mono text-sm font-black text-white shadow-xs">#{{ String(currentIndex + 1).padStart(2, '0') }}</span>
                                <div>
                                    <h2 class="text-sm font-black text-on-surface">
                                        Soạn Thảo Chi Tiết Câu Hỏi Số <span class="font-mono text-primary-container">{{ currentIndex + 1 }}</span>
                                    </h2>
                                    <span class="text-xs text-on-surface-variant">Thiết lập nội dung đề, phương án, đoạn văn, file nghe &amp; đáp án chấm tự động</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <UiButton variant="secondary" size="sm" icon="content_copy" @click="duplicateQuestion(currentIndex)">Nhân bản câu</UiButton>
                                <UiButton variant="danger-text" size="sm" icon="delete" :disabled="questions.length <= 1" @click="deleteQuestion(currentIndex)">Xóa câu</UiButton>
                            </div>
                        </div>

                        <!-- Section & Points & Skill -->
                        <div class="grid grid-cols-1 gap-3 text-xs md:grid-cols-12">
                            <div class="md:col-span-6">
                                <UiInput v-model="currentQ.section" label="Phần thi (Section / Tiêu đề nhóm câu)" placeholder="VD: A. LISTENING - Exercise 1" class="font-semibold" />
                            </div>
                            <UiField label="Kỹ năng (Skill)" required class="md:col-span-3">
                                <UiSelect v-model="currentQ.skill" :options="skillOptions" class="min-w-0 font-bold" aria-label="Kỹ năng (Skill)" />
                            </UiField>
                            <div class="md:col-span-3">
                                <UiInput type="number" label="Điểm số (Points)" v-bind="model(currentQ, 'points', toNumber)" step="0.25" min="0.25" class="font-mono font-black text-primary" />
                            </div>
                        </div>

                        <!-- Question Type Selector Buttons -->
                        <div class="space-y-2 text-xs">
                            <label class="block text-xs font-bold uppercase tracking-wider text-on-surface">Định dạng loại câu hỏi</label>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                <button v-for="b in typeButtons" :key="b.type" type="button" :class="['flex cursor-pointer items-center justify-center gap-1.5 rounded-xl p-2.5 text-xs transition', typeButtonClass(b.type)]" @click="setType(b.type)">
                                    <span class="material-symbols-outlined text-[18px]">{{ b.icon }}</span>
                                    <span>{{ b.label }}</span>
                                </button>
                            </div>
                        </div>

                        <!-- Audio Track Upload & Player (for Listening) -->
                        <div class="space-y-2 rounded-2xl border border-secondary/30 bg-secondary/5 p-4 text-xs">
                            <div class="flex items-center justify-between">
                                <span class="flex items-center gap-1.5 font-bold text-on-secondary-fixed">
                                    <span class="material-symbols-outlined text-base text-secondary">headphones</span>
                                    <span>Đường dẫn tệp Audio Nghe (.mp3)</span>
                                </span>
                                <span class="font-mono text-xs italic text-secondary">Phát trực tiếp trên giao diện thi</span>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <label class="inline-flex cursor-pointer items-center gap-1 rounded-lg border border-secondary/30 bg-surface-container-lowest px-3 py-2 font-bold text-secondary hover:bg-secondary/10">
                                    <span class="material-symbols-outlined text-[16px]">upload_file</span>
                                    <span>{{ uploading === 'audio' ? 'Đang tải lên...' : 'Tải file nghe (.mp3)' }}</span>
                                    <input type="file" accept="audio/*" class="sr-only" @change="uploadMedia($event, 'audio', (url) => (currentQ.audio_url = url))" />
                                </label>
                                <input v-model="currentQ.audio_url" type="text" placeholder="hoặc dán đường dẫn file nghe" class="min-w-[200px] flex-1 rounded-xl border border-secondary/30 bg-surface-container-lowest p-2.5 font-mono text-xs shadow-2xs" />
                            </div>
                            <div v-if="currentQ.audio_url" class="pt-1">
                                <audio controls class="h-8 w-full" :src="currentQ.audio_url"></audio>
                            </div>
                        </div>

                        <!-- Reading / Context Passage Content -->
                        <UiTextarea v-model="currentQ.passage" label="Đoạn văn đọc hiểu / Bối cảnh câu hỏi (Passage / Reading Text)" rows="3" placeholder="Nhập đoạn văn đọc hiểu hoặc ngữ cảnh của câu hỏi (nếu có)..." class="font-serif leading-relaxed" />

                        <!-- Question Text Content -->
                        <UiField label="Nội dung câu hỏi / Yêu cầu đề bài" required>
                            <UiTextarea v-model="currentQ.title" rows="2" placeholder="VD: According to the passage, what is the primary benefit of renewable energy?" class="font-bold" aria-label="Nội dung câu hỏi / Yêu cầu đề bài" />
                        </UiField>

                        <!-- MULTIPLE CHOICE OPTIONS EDITOR -->
                        <div v-if="currentQ.type === 'multiple_choice'" class="space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-low/70 p-4 text-xs">
                            <div class="flex items-center justify-between border-b border-surface-container-highest pb-2">
                                <h4 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-on-surface">
                                    <span class="material-symbols-outlined text-base text-primary-container">tune</span>
                                    <span>Các phương án trả lời (Tích chọn radio vào đáp án đúng)</span>
                                </h4>
                                <UiButton variant="secondary" size="sm" icon="add" @click="addOption()">Thêm phương án</UiButton>
                            </div>

                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div v-for="(opt, oIdx) in currentQ.options" :key="opt.key" class="space-y-2 rounded-xl border border-surface-container-highest bg-surface-container-lowest p-3 shadow-2xs">
                                    <div class="flex items-center gap-2">
                                        <input type="radio" :name="'correct_ans_' + currentQ.id" :checked="currentQ.correct_answer === opt.key" class="h-4 w-4 cursor-pointer text-primary focus:ring-primary-container" @change="currentQ.correct_answer = opt.key" />
                                        <span :class="['w-5 font-mono text-sm font-black', currentQ.correct_answer === opt.key ? 'text-primary-container' : 'text-on-surface-variant']">{{ opt.key + '.' }}</span>
                                        <input v-model="opt.text" type="text" placeholder="Nhập nội dung phương án..." class="flex-1 rounded-lg border border-outline-variant bg-surface-container-lowest p-2 text-xs font-medium focus:border-primary-container focus:ring-primary-container" />
                                        <UiButton variant="ghost" size="sm" icon="close" :disabled="currentQ.options.length <= 2" title="Xóa phương án" aria-label="Xóa phương án" @click="removeOption(oIdx)" />
                                    </div>
                                    <div class="flex items-center gap-2 pl-6">
                                        <label class="inline-flex cursor-pointer items-center gap-1 rounded-lg border border-dashed border-outline-variant px-2 py-1 text-xs font-semibold text-on-surface-variant hover:bg-surface-container-low">
                                            <span class="material-symbols-outlined text-[14px]">add_photo_alternate</span>Tải ảnh lên
                                            <input type="file" accept="image/*" class="sr-only" @change="uploadMedia($event, 'image', (url) => (opt.image_url = url))" />
                                        </label>
                                        <div v-if="opt.image_url" class="flex items-center gap-1">
                                            <img :src="opt.image_url" :alt="'Ảnh phương án ' + opt.key" class="h-10 w-10 rounded border border-surface-container-highest object-cover" />
                                            <UiButton variant="ghost" size="sm" icon="close" title="Bỏ ảnh" aria-label="Bỏ ảnh" @click="opt.image_url = ''" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 rounded-xl border border-tertiary/30 bg-tertiary/10 p-2.5 text-xs text-on-tertiary-container">
                                <span class="material-symbols-outlined text-base text-tertiary">check_circle</span>
                                <span>Đáp án đúng hiện tại: <strong class="font-mono text-sm">{{ currentQ.correct_answer || 'Chưa chọn' }}</strong></span>
                            </div>
                        </div>

                        <!-- FILL BLANK SETUP -->
                        <div v-if="currentQ.type === 'fill_blank'" class="space-y-2 rounded-xl border border-surface-container-highest bg-surface-container-low p-4 text-xs">
                            <UiField label="Từ / Cụm từ đáp án chính xác" required>
                                <UiInput v-model="currentQ.correct_answer" placeholder="VD: had studied, will go, beautiful..." class="font-mono font-bold text-tertiary" aria-label="Từ / Cụm từ đáp án chính xác" />
                            </UiField>
                            <p class="text-xs italic text-on-surface-variant">* Hệ thống tự động đối soát đáp án này (không phân biệt hoa thường, bỏ dấu chấm cuối). Nhiều cách viết được chấp nhận thì ngăn cách bằng dấu |, VD: 7 | seven.</p>
                        </div>

                        <!-- ESSAY WRITING SETUP -->
                        <div v-if="currentQ.type === 'essay'" class="space-y-3 rounded-xl border border-warning/30 bg-warning/5 p-4 text-xs">
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <UiInput type="number" label="Số từ tối thiểu (Minimum Words)" v-bind="model(currentQ, 'min_words', toNumber)" placeholder="120" min="10" class="font-bold" />
                                <UiInput v-bind="model(currentQ, 'rubric_note')" label="Tiêu chuẩn Rubric áp dụng" placeholder="IELTS Writing Task 1 / Task 2 Rubric" class="font-medium" />
                            </div>
                            <p class="text-xs italic text-on-warning-container">* Bài làm tự luận sẽ được giáo viên chấm trực tiếp tại cổng kết quả.</p>
                        </div>

                        <!-- SPEAKING SETUP -->
                        <div v-if="currentQ.type === 'speaking_prompt'" class="rounded-xl border border-error/30 bg-error/5 p-4 text-xs">
                            <UiTextarea v-model="currentQ.passage" label="Gợi ý dàn ý / Cue points cho học viên" rows="3" placeholder="• Where you went and who you went with\n• How you travelled there\n• What you did..." class="leading-relaxed" />
                        </div>

                        <!-- Explanation Box -->
                        <div class="space-y-1.5 rounded-2xl border border-warning/30 bg-warning/5 p-4 text-xs">
                            <label class="block flex items-center gap-1.5 text-xs font-bold uppercase text-on-warning-container">
                                <span class="material-symbols-outlined text-base text-primary-container">lightbulb</span>
                                <span>Lời giải thích chi tiết &amp; Dẫn chứng bài làm (Explanation)</span>
                            </label>
                            <UiTextarea v-model="currentQ.explanation" rows="2" placeholder="Giải thích vì sao đáp án này đúng, trích dẫn transcript bài nghe hoặc đoạn văn bài đọc..." class="leading-relaxed" aria-label="Lời giải thích chi tiết" />
                        </div>

                        <!-- Teacher Note Box -->
                        <div class="space-y-1.5 rounded-2xl border border-surface-container-highest bg-surface-container-low p-4 text-xs">
                            <label class="block flex items-center gap-1.5 text-xs font-bold uppercase text-on-surface">
                                <span class="material-symbols-outlined text-base text-primary">settings_suggest</span>
                                <span>Ghi chú chuyên môn cho Giáo viên khi chấm (Teacher's Note)</span>
                            </label>
                            <UiTextarea v-model="currentQ.teacher_note" rows="2" placeholder="Ghi chú thêm về tiêu chí, bẫy từ vựng..." aria-label="Ghi chú chuyên môn cho Giáo viên" />
                        </div>

                        <!-- Lưu nháp = lưu đề ở trạng thái Ẩn; Lưu và Tiếp theo = sang câu kế tiếp -->
                        <div class="flex flex-wrap items-center justify-end gap-sm border-t border-surface-container-highest pt-md">
                            <UiButton type="submit" variant="secondary" name="save_mode" value="draft">Lưu nháp</UiButton>
                            <UiButton variant="info" @click="saveAndNext()">
                                Lưu và Tiếp theo<span class="material-symbols-outlined text-[18px]" aria-hidden="true">arrow_forward</span>
                            </UiButton>
                        </div>
                    </div>
                </div>
            </div>
        </UiForm>
    </div>
</template>
