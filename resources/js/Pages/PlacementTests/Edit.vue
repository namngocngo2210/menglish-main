<script setup>
/**
 * Sửa đề test đầu vào: cấu hình bộ đề + câu hỏi theo 1 trong 2 cách soạn (như Create.vue):
 * - "Tải đề PDF": file đề + phiếu trả lời / đáp án (PdfSheetEditor);
 * - "Soạn từng câu": danh sách câu hỏi (lọc theo kỹ năng, lên / xuống, nhân bản, xóa), thêm / sửa câu hỏi trong modal.
 * Câu hỏi gửi lên server dạng JSON (ô ẩn `questions`); chuyển sang soạn từng câu thì đề bỏ file PDF.
 */
import { computed, ref } from 'vue';
import { confirmDialog } from '@/lib/confirm';
import { toast } from '@/lib/toast';
import PdfSheetEditor from '@/Components/PlacementTests/PdfSheetEditor.vue';
import { emptyOptions, model } from './questionEditor';

defineOptions({ layout: (props) => ({ title: 'Sửa đề: ' + props.test.title }) });

const props = defineProps({
    test: { type: Object, required: true },
    gradeLevels: { type: Array, default: () => [] },
});

// Đề chưa có câu hỏi: bắt đầu với 1 câu trống (không soạn sẵn nội dung mẫu / file nghe giả).
const blankFirstQuestion = () => ({ id: 1, skill: 'reading', type: 'multiple_choice', title: '', audio_url: '', passage: '', options: emptyOptions(), correct_answer: 'A', points: 1, explanation: '' });
const mode = ref(props.test.pdf_path ? 'pdf' : 'manual');
const questions = ref(props.test.questions.length ? JSON.parse(JSON.stringify(props.test.questions)) : mode.value === 'manual' ? [blankFirstQuestion()] : []);
const pdfPath = ref(props.test.pdf_path ?? '');
const pdfUrl = ref(props.test.pdf_url ?? '');
const audioUrl = ref(props.test.audio_url ?? '');
const modes = [
    { value: 'pdf', icon: 'picture_as_pdf', label: 'Tải đề PDF' },
    { value: 'manual', icon: 'edit_note', label: 'Soạn từng câu' },
];
async function setMode(next) {
    if (mode.value === next) return;
    if (next === 'manual' && pdfPath.value && !(await confirmDialog({ message: 'Chuyển sang soạn từng câu? Khi lưu, đề không dùng file PDF nữa; các câu trên phiếu được giữ để soạn tiếp.', confirmLabel: 'Chuyển' }))) return;
    if (next === 'manual' && !questions.value.length) questions.value = [blankFirstQuestion()];
    mode.value = next;
}
const filterSkill = ref('all');
const showModal = ref(false);
const editIndex = ref(null);
const modalForm = ref(blankQuestion());

const targetLevels = ['Tổng hợp A1 - B2', 'IELTS Foundation (3.0 - 4.5)', 'IELTS Intensive (5.0 - 6.5)', 'IELTS Master (6.5 - 7.5+)', 'Giao tiếp Quốc tế B1 - B2'].map((v) => ({ value: v, label: v }));
const skillOptions = [
    { value: 'listening', label: 'Listening (Nghe hiểu)' },
    { value: 'reading', label: 'Reading (Đọc hiểu)' },
    { value: 'grammar', label: 'Grammar & Vocabulary (Ngữ pháp)' },
    { value: 'writing', label: 'Writing (Viết luận)' },
    { value: 'speaking', label: 'Speaking (Vấn đáp / Nói)' },
];
const typeOptions = [
    { value: 'multiple_choice', label: 'Trắc nghiệm 4 lựa chọn (A, B, C, D)' },
    { value: 'fill_blank', label: 'Điền từ vào chỗ trống' },
    { value: 'essay', label: 'Tự luận / Viết đoạn văn (Essay)' },
    { value: 'speaking_prompt', label: 'Chủ đề phỏng vấn Speaking' },
];
const skillFilters = [
    { skill: 'listening', icon: 'headphones', label: 'Listening', on: 'bg-secondary text-white', off: 'bg-secondary/10 text-secondary hover:bg-secondary/20' },
    { skill: 'reading', icon: 'menu_book', label: 'Reading', on: 'bg-info text-white', off: 'bg-info/10 text-info hover:bg-info/20' },
    { skill: 'grammar', icon: 'spellcheck', label: 'Grammar & Điền từ', on: 'bg-warning text-white', off: 'bg-warning-container text-on-warning-container hover:bg-warning/20' },
    { skill: 'writing', icon: 'edit_note', label: 'Writing', on: 'bg-accent text-white', off: 'bg-accent-container text-accent hover:bg-accent-container' },
    { skill: 'speaking', icon: 'mic', label: 'Speaking', on: 'bg-error text-white', off: 'bg-error/10 text-error hover:bg-error/20' },
];
const skillBadge = {
    listening: 'bg-secondary/10 text-on-secondary-fixed border border-secondary/30',
    reading: 'bg-info/10 text-on-info-container border border-info/30',
    grammar: 'bg-warning-container text-on-warning-container border border-warning/30',
    writing: 'bg-accent-container text-on-accent-container border border-accent/30',
    speaking: 'bg-error/10 text-on-error-container border border-error/30',
};
const skillLabels = { listening: 'Nghe hiểu (Listening)', reading: 'Đọc hiểu (Reading)', grammar: 'Ngữ pháp (Grammar)', writing: 'Viết (Writing)', speaking: 'Nói (Speaking)' };
const typeLabels = { multiple_choice: 'Trắc nghiệm A/B/C/D', fill_blank: 'Điền từ vào chỗ trống', essay: 'Viết đoạn văn / Tự luận', speaking_prompt: 'Phỏng vấn / Vấn đáp' };

const filteredQuestions = computed(() => (filterSkill.value === 'all' ? questions.value : questions.value.filter((q) => q.skill === filterSkill.value)));
const countSkill = (skill) => questions.value.filter((q) => q.skill === skill).length;

function blankQuestion() {
    return { id: Date.now(), skill: 'reading', type: 'multiple_choice', title: '', audio_url: '', passage: '', options: emptyOptions(), correct_answer: 'A', points: 1, min_words: 100, rubric_note: '', cue_points: '', explanation: '' };
}

function openAddModal() {
    editIndex.value = null;
    modalForm.value = blankQuestion();
    showModal.value = true;
}

function editQuestion(index) {
    editIndex.value = index;
    modalForm.value = JSON.parse(JSON.stringify(filteredQuestions.value[index]));
    if (!modalForm.value.options || modalForm.value.options.length === 0) modalForm.value.options = emptyOptions();
    showModal.value = true;
}

function saveModalQuestion() {
    if (!String(modalForm.value.title ?? '').trim()) {
        toast('Vui lòng nhập nội dung câu hỏi!', 'error');
        return;
    }
    const questionData = JSON.parse(JSON.stringify(modalForm.value));
    if (editIndex.value !== null) {
        const realIndex = questions.value.indexOf(filteredQuestions.value[editIndex.value]);
        if (realIndex !== -1) questions.value[realIndex] = questionData;
    } else {
        questions.value.push(questionData);
    }
    closeModal();
}

function closeModal() {
    showModal.value = false;
    editIndex.value = null;
}

async function deleteQuestion(index) {
    if (!(await confirmDialog({ message: 'Xóa câu hỏi này?', confirmLabel: 'Xóa', danger: true }))) return;
    const target = filteredQuestions.value[index];
    questions.value = questions.value.filter((q) => q !== target);
}

function duplicateQuestion(index) {
    const copy = JSON.parse(JSON.stringify(filteredQuestions.value[index]));
    copy.id = Date.now();
    copy.title = copy.title + ' (Bản sao)';
    questions.value.push(copy);
}

/** Đổi chỗ 2 câu đang hiển thị (theo bộ lọc kỹ năng) trong danh sách đầy đủ. */
function swap(index, other) {
    if (other < 0 || other >= filteredQuestions.value.length) return;
    const list = questions.value;
    const a = list.indexOf(filteredQuestions.value[index]);
    const b = list.indexOf(filteredQuestions.value[other]);
    if (a !== -1 && b !== -1) [list[a], list[b]] = [list[b], list[a]];
}
</script>

<template>
    <UiPageHeader :title="'Sửa đề: ' + test.title" icon="edit_document" :back="route('placement-tests.index')">
        <template #meta>Mã đề: <strong class="font-mono text-on-surface">{{ test.code }}</strong> · Cập nhật cấu hình, audio, bài đọc, câu hỏi và đáp án chấm</template>
    </UiPageHeader>

    <div class="space-y-6">
        <UiForm :action="route('placement-tests.update', test.id)" method="put" class="space-y-6">
            <!-- Hidden synchronized questions payload -->
            <input type="hidden" name="questions" :value="JSON.stringify(questions)" />
            <input type="hidden" name="questions_count" :value="questions.length" />
            <input type="hidden" name="mode" :value="mode" />
            <input type="hidden" name="pdf_path" :value="mode === 'pdf' ? pdfPath : ''" />
            <input type="hidden" name="audio_url" :value="mode === 'pdf' ? audioUrl : ''" />

            <!-- 1. General Test Info -->
            <div class="space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
                <h2 class="flex items-center justify-between border-b border-surface-container-highest pb-2 text-sm font-bold uppercase tracking-wider text-on-surface">
                    <span class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-base text-primary">info</span>
                        1. Thông tin cấu hình bộ đề
                    </span>
                    <span class="text-xs font-normal text-on-surface-subtle">Các trường đánh dấu <span class="text-error">*</span> là bắt buộc</span>
                </h2>

                <div class="grid grid-cols-1 gap-4 text-xs md:grid-cols-4">
                    <UiInput label="Mã đề thi (Code)" :value="test.code" disabled class="font-mono font-bold" />
                    <UiInput type="number" name="duration_minutes" label="Thời gian làm bài (Phút)" :value="test.duration_minutes" min="5" required class="font-mono font-bold" />
                    <UiSelect name="grade_level" label="Cấp độ" :value="test.grade_level ?? ''" class="font-semibold" placeholder="-- Chưa chọn --" :options="gradeLevels" />
                    <UiSelect name="target_level" label="Trình độ mục tiêu" required :value="test.target_level" class="font-semibold" :options="targetLevels" />
                    <div class="md:col-span-4">
                        <UiInput name="title" label="Tiêu đề đề thi" :value="test.title" required class="font-bold" />
                    </div>
                    <div class="md:col-span-4">
                        <UiTextarea name="description" label="Mô tả / Hướng dẫn thí sinh khi bắt đầu" rows="2" :value="test.description" />
                    </div>
                    <div class="flex items-center gap-2 pt-1 md:col-span-4">
                        <input id="is_active" type="checkbox" name="is_active" value="1" :checked="test.is_active" class="rounded border-outline-variant text-primary focus:ring-primary-container" />
                        <label for="is_active" class="text-xs font-semibold text-on-surface">Đang kích hoạt đề thi (Hiển thị cho Lead / Thí sinh truy cập làm bài)</label>
                    </div>
                </div>
            </div>

            <!-- Cách soạn đề -->
            <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-3 shadow-sm">
                <span class="text-xs font-bold uppercase tracking-wider text-on-surface">2. Cách soạn đề</span>
                <div class="inline-flex rounded-xl bg-surface-container p-1 text-xs" role="group" aria-label="Cách soạn đề">
                    <button v-for="m in modes" :key="m.value" type="button" :aria-pressed="mode === m.value" :class="['flex items-center gap-1.5 rounded-lg px-3 py-1.5 font-bold transition', mode === m.value ? 'bg-surface-container-lowest text-primary shadow-xs' : 'text-on-surface-variant hover:text-on-surface']" @click="setMode(m.value)">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ m.icon }}</span>{{ m.label }}
                    </button>
                </div>
            </div>

            <PdfSheetEditor v-if="mode === 'pdf'" v-model:questions="questions" v-model:pdf-path="pdfPath" v-model:pdf-url="pdfUrl" v-model:audio-url="audioUrl" />

            <!-- 2. Question Builder (Trình soạn thảo câu hỏi đa định dạng) -->
            <div v-else class="space-y-5 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
                <div class="flex flex-col justify-between gap-3 border-b border-surface-container-highest pb-3 sm:flex-row sm:items-center">
                    <div>
                        <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-on-surface">
                            <span class="material-symbols-outlined text-base text-tertiary">quiz</span>
                            Danh sách câu hỏi &amp; Kiểu bài thi
                            <span class="rounded-full border border-tertiary/30 bg-tertiary/10 px-2 py-0.5 font-mono text-xs font-bold text-tertiary">{{ questions.length }} câu hỏi</span>
                        </h2>
                        <p class="mt-0.5 text-xs text-on-surface-variant">Tạo và cấu hình các dạng bài: Trắc nghiệm A/B/C/D, Audio Nghe, Đọc hiểu, Điền từ, Viết luận, Vấn đáp</p>
                    </div>

                    <UiButton variant="success" size="sm" icon="add_circle" @click="openAddModal()">Thêm câu hỏi mới</UiButton>
                </div>

                <!-- Skill Filter Tabs -->
                <div class="flex items-center gap-2 overflow-x-auto pb-1 text-xs">
                    <button type="button" :class="['cursor-pointer whitespace-nowrap rounded-xl px-3 py-1.5 font-bold transition', filterSkill === 'all' ? 'bg-inverse-surface text-white' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high']" @click="filterSkill = 'all'">
                        Tất cả ({{ questions.length }})
                    </button>
                    <button v-for="f in skillFilters" :key="f.skill" type="button" :class="['flex cursor-pointer items-center gap-1.5 whitespace-nowrap rounded-xl px-3 py-1.5 font-bold transition', filterSkill === f.skill ? f.on : f.off]" @click="filterSkill = f.skill">
                        <span class="material-symbols-outlined text-sm">{{ f.icon }}</span>
                        <span>{{ f.label }} ({{ countSkill(f.skill) }})</span>
                    </button>
                </div>

                <!-- Questions List Container -->
                <div class="space-y-3.5">
                    <div v-for="(q, idx) in filteredQuestions" :key="q.id || idx" class="group rounded-2xl border border-surface-container-highest/90 bg-surface-container-low/90 p-4 shadow-xs transition hover:border-tertiary/50 hover:bg-surface-container-lowest">
                        <div class="mb-2.5 flex items-start justify-between gap-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="flex h-6 w-6 items-center justify-center rounded-lg bg-inverse-surface font-mono text-xs font-bold text-white">#{{ idx + 1 }}</span>
                                <!-- Skill Badge -->
                                <span :class="['rounded-md px-2 py-0.5 text-xs font-bold uppercase tracking-wider', skillBadge[q.skill]]">{{ skillLabels[q.skill] || q.skill }}</span>
                                <!-- Type Badge -->
                                <span class="rounded-md bg-surface-container-high/70 px-2 py-0.5 text-xs font-semibold text-on-surface-variant">{{ typeLabels[q.type] || q.type }}</span>
                                <span class="text-xs font-medium text-on-surface-subtle">({{ q.points || 1 }} điểm)</span>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex shrink-0 items-center gap-1">
                                <UiButton variant="ghost" size="sm" icon="arrow_upward" :disabled="idx === 0" title="Chuyển lên trên" aria-label="Chuyển lên trên" @click="swap(idx, idx - 1)" />
                                <UiButton variant="ghost" size="sm" icon="arrow_downward" :disabled="idx === filteredQuestions.length - 1" title="Chuyển xuống dưới" aria-label="Chuyển xuống dưới" @click="swap(idx, idx + 1)" />
                                <UiButton variant="ghost" size="sm" icon="content_copy" title="Nhân bản câu hỏi" aria-label="Nhân bản câu hỏi" @click="duplicateQuestion(idx)" />
                                <UiButton variant="ghost" size="sm" icon="edit" title="Chỉnh sửa câu hỏi" aria-label="Chỉnh sửa câu hỏi" @click="editQuestion(idx)" />
                                <UiButton variant="danger-text" size="sm" icon="delete" title="Xóa câu hỏi" aria-label="Xóa câu hỏi" @click="deleteQuestion(idx)" />
                            </div>
                        </div>

                        <!-- Question Title -->
                        <div class="mb-2 text-xs font-bold leading-relaxed text-on-surface">{{ q.title }}</div>

                        <!-- Audio attachment preview if any -->
                        <div v-if="q.audio_url" class="mb-2.5 flex items-center gap-2 rounded-xl border border-secondary/20 bg-secondary/5 p-2 text-xs">
                            <span class="material-symbols-outlined text-base text-secondary">volume_up</span>
                            <span class="truncate font-mono text-xs text-on-secondary-fixed">Audio MP3: {{ q.audio_url }}</span>
                        </div>

                        <!-- Reading Passage preview if any -->
                        <div v-if="q.passage" class="mb-2.5 line-clamp-2 rounded-xl border border-secondary/20 bg-secondary/5 p-2.5 text-xs italic text-on-surface-variant">Đoạn văn: {{ q.passage }}</div>

                        <!-- Multiple Choice Options preview -->
                        <div v-if="q.type === 'multiple_choice' && q.options" class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
                            <div v-for="opt in q.options" :key="opt.key" :class="['flex items-center gap-2 rounded-xl border p-2', opt.key === q.correct_answer ? 'border-tertiary/30 bg-tertiary/10 font-semibold text-on-tertiary-container' : 'border-surface-container-highest bg-surface-container-lowest text-on-surface-variant']">
                                <span :class="['flex h-5 w-5 items-center justify-center rounded-md text-xs font-bold', opt.key === q.correct_answer ? 'bg-tertiary text-white' : 'bg-surface-container text-on-surface-variant']">{{ opt.key }}</span>
                                <span class="truncate">{{ opt.text }}</span>
                                <span v-if="opt.key === q.correct_answer" class="material-symbols-outlined ml-auto text-[14px] text-tertiary">check_circle</span>
                            </div>
                        </div>

                        <!-- Fill in the blank preview -->
                        <div v-if="q.type === 'fill_blank'" class="flex items-center gap-2 rounded-xl border border-warning/30 bg-warning-container p-2 text-xs text-on-warning-container">
                            <span class="material-symbols-outlined text-sm text-warning">spellcheck</span>
                            <span>Đáp án chính xác: <strong class="font-mono">{{ q.correct_answer }}</strong></span>
                        </div>

                        <!-- Essay preview -->
                        <div v-if="q.type === 'essay'" class="space-y-1 rounded-xl border border-accent/30 bg-accent-container p-2.5 text-xs text-on-accent-container">
                            <div><strong>Yêu cầu số từ:</strong> {{ q.min_words || 100 }} từ trở lên</div>
                            <div v-if="q.rubric_note" class="italic text-accent">Barem chấm: {{ q.rubric_note }}</div>
                        </div>

                        <!-- Speaking Cue Points preview -->
                        <div v-if="q.type === 'speaking_prompt'" class="whitespace-pre-line rounded-xl border border-error/30 bg-error/10 p-2.5 text-xs text-on-error-container">{{ q.cue_points || 'Gợi ý trả lời vấn đáp...' }}</div>
                    </div>

                    <!-- Empty state -->
                    <UiEmptyState v-if="filteredQuestions.length === 0" icon="quiz" title="Chưa có câu hỏi nào trong danh mục này" description='Bấm nút "Thêm câu hỏi mới" bên trên để bắt đầu soạn đề thi.' class="rounded-2xl border border-dashed border-outline-variant bg-surface-container-low" />
                </div>
            </div>

            <!-- Form Actions -->
            <div class="flex items-center justify-between rounded-2xl border border-t border-surface-container-highest bg-surface-container-lowest p-4 pt-4 shadow-xs">
                <UiButton variant="secondary" :href="route('placement-tests.index')">Hủy bỏ</UiButton>
                <UiButton type="submit" icon="save">Cập nhật đề thi &amp; Câu hỏi vào CSDL</UiButton>
            </div>
        </UiForm>

        <!-- 3. Modal Soạn Thảo / Chỉnh Sửa Câu Hỏi (Question Modal) -->
        <UiModal :show="showModal" max-width="2xl" bare labelledby="modal-placement-question-title" @close="closeModal()">
            <div class="flex shrink-0 items-center justify-between gap-md border-b border-surface-container px-lg py-md">
                <div>
                    <h3 id="modal-placement-question-title" class="flex items-center gap-2 text-sm font-bold text-on-surface">
                        <span class="material-symbols-outlined text-base text-tertiary">{{ editIndex !== null ? 'edit_note' : 'add_circle' }}</span>
                        <span>{{ editIndex !== null ? 'Chỉnh sửa câu hỏi #' + (editIndex + 1) : 'Thêm câu hỏi mới vào đề thi' }}</span>
                    </h3>
                    <p class="text-xs text-on-surface-variant">Cấu hình kỹ năng, kiểu bài thi và đáp án chấm điểm</p>
                </div>
                <UiButton variant="ghost" icon="close" aria-label="Đóng" @click="closeModal()" />
            </div>

            <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-lg py-md text-xs">
                <!-- Skill & Type row -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <UiField label="Kỹ năng (Skill)" required>
                        <UiSelect v-model="modalForm.skill" :options="skillOptions" class="font-semibold" aria-label="Kỹ năng (Skill)" />
                    </UiField>
                    <UiField label="Định dạng kiểu bài (Type)" required>
                        <UiSelect v-model="modalForm.type" :options="typeOptions" class="font-semibold" aria-label="Định dạng kiểu bài (Type)" />
                    </UiField>
                </div>

                <!-- Audio URL (If listening) -->
                <div v-if="modalForm.skill === 'listening'" class="rounded-xl border border-secondary/20 bg-secondary/5 p-3">
                    <UiInput v-bind="model(modalForm, 'audio_url')" label="Đường dẫn file Audio MP3 (Audio URL)" placeholder="Đường dẫn file nghe (.mp3)" hint="Hỗ trợ tệp MP3 lưu tại Media Manager hoặc link CDN trực tiếp." class="font-mono" />
                </div>

                <!-- Reading Passage (If reading) -->
                <div v-if="modalForm.skill === 'reading'" class="rounded-xl border border-secondary/20 bg-secondary/5 p-3">
                    <UiTextarea v-bind="model(modalForm, 'passage')" label="Đoạn văn bài đọc (Reading Passage - tùy chọn)" rows="3" placeholder="Nhập văn bản bài đọc nếu câu hỏi dựa vào đoạn văn..." />
                </div>

                <!-- Question Title / Prompt -->
                <UiTextarea v-bind="model(modalForm, 'title')" label="Nội dung câu hỏi / Đề bài" rows="2" placeholder="Ví dụ: What is the main idea of the passage?" required class="font-bold" />

                <!-- Multiple Choice Options Form -->
                <div v-if="modalForm.type === 'multiple_choice'" class="space-y-3 border-t border-surface-container-highest pt-2">
                    <label class="block font-semibold text-on-surface-variant">4 Lựa chọn trả lời &amp; Tích chọn đáp án đúng:</label>
                    <div class="space-y-2">
                        <div v-for="opt in modalForm.options" :key="opt.key" :class="['flex items-center gap-2 rounded-xl border p-2', modalForm.correct_answer === opt.key ? 'border-tertiary/30 bg-tertiary/5' : 'border-surface-container-highest bg-surface-container-low']">
                            <input v-model="modalForm.correct_answer" type="radio" name="correct_opt_radio" :value="opt.key" class="cursor-pointer text-tertiary focus:ring-tertiary" />
                            <span class="w-6 text-center font-mono text-xs font-bold">{{ opt.key }}</span>
                            <input v-model="opt.text" type="text" placeholder="Nhập nội dung phương án..." class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest p-2 text-xs focus:border-tertiary focus:ring-tertiary" />
                        </div>
                    </div>
                </div>

                <!-- Fill in blank Form -->
                <div v-if="modalForm.type === 'fill_blank'" class="border-t border-surface-container-highest pt-2">
                    <UiInput v-bind="model(modalForm, 'correct_answer')" label="Từ / Cụm từ đáp án chính xác:" placeholder="Ví dụ: had studied" class="font-mono font-bold" />
                    <p class="mt-1 text-xs italic text-on-surface-variant">Nhiều cách viết được chấp nhận thì ngăn cách bằng dấu |, VD: 7 | seven.</p>
                </div>

                <!-- Essay Form -->
                <div v-if="modalForm.type === 'essay'" class="grid grid-cols-1 gap-3 border-t border-surface-container-highest pt-2 sm:grid-cols-2">
                    <UiInput v-bind="model(modalForm, 'min_words')" type="number" label="Số từ tối thiểu:" placeholder="100" class="font-mono" />
                    <UiInput v-bind="model(modalForm, 'rubric_note')" label="Ghi chú barem chấm điểm:" placeholder="Tiêu chí chấm điểm..." />
                </div>

                <!-- Speaking Prompt Form -->
                <div v-if="modalForm.type === 'speaking_prompt'" class="border-t border-surface-container-highest pt-2">
                    <UiTextarea v-bind="model(modalForm, 'cue_points')" label="Gợi ý trả lời / Cue card points:" rows="3" :placeholder="'• Where you went...\n• Who you went with...'" />
                </div>

                <!-- Explanation & Points -->
                <div class="grid grid-cols-1 gap-3 border-t border-surface-container-highest pt-2 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <UiInput v-bind="model(modalForm, 'explanation')" label="Giải thích đáp án / Hướng dẫn (Tùy chọn):" placeholder="Lý do chọn đáp án này..." />
                    </div>
                    <UiInput v-bind="model(modalForm, 'points')" type="number" label="Điểm câu hỏi:" min="1" max="10" class="font-mono font-bold" />
                </div>
            </div>

            <div class="flex shrink-0 flex-wrap justify-end gap-sm border-t border-surface-container bg-surface-container-low px-lg py-md">
                <UiButton variant="secondary" @click="closeModal()">Đóng</UiButton>
                <UiButton variant="success" @click="saveModalQuestion()">Lưu câu hỏi</UiButton>
            </div>
        </UiModal>
    </div>
</template>
