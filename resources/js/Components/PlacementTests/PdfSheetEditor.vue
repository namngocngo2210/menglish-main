<script setup>
/**
 * Soạn đề test đầu vào bằng file PDF (Create.vue / Edit.vue, cách "Tải đề PDF"): tải file → server đọc chữ trong PDF
 * và dựng sẵn phiếu trả lời + đáp án (PlacementPdfAnswerSheet). Người tạo đề xem PDF bên trái, kiểm tra / sửa phiếu bên phải:
 * kỹ năng, dạng câu, đáp án đúng, điểm. Phiếu dùng chung cấu trúc câu hỏi với cách soạn từng câu nên tự chấm như cũ.
 *   <PdfSheetEditor v-model:questions="questions" v-model:pdf-path="pdfPath" v-model:pdf-url="pdfUrl" v-model:audio-url="audioUrl" />
 */
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { confirmDialog } from '@/lib/confirm';
import { route } from '@/lib/route';
import { toast } from '@/lib/toast';
import { useMediaUpload } from '@/Pages/PlacementTests/questionEditor';
import PdfViewer from './PdfViewer.vue';

const questions = defineModel('questions', { type: Array, required: true });
const pdfPath = defineModel('pdfPath', { type: String, default: '' });
const pdfUrl = defineModel('pdfUrl', { type: String, default: '' });
const audioUrl = defineModel('audioUrl', { type: String, default: '' });

const page = usePage();
const { uploading: audioUploading, uploadMedia } = useMediaUpload();
const uploading = ref(false);
const warnings = ref([]);
const keyPage = ref(null);
const readingKey = ref(false);
const summary = ref(null);
const pasteText = ref('');
const applying = ref(false);
const blankCount = ref(20);

const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
const skillOptions = [
    { value: 'listening', label: 'Nghe' },
    { value: 'reading', label: 'Đọc' },
    { value: 'grammar', label: 'Ngữ pháp' },
    { value: 'writing', label: 'Viết' },
    { value: 'speaking', label: 'Nói' },
];
const typeOptions = [
    { value: 'multiple_choice', label: 'Trắc nghiệm' },
    { value: 'fill_blank', label: 'Điền từ' },
    { value: 'essay', label: 'Tự luận' },
    { value: 'speaking_prompt', label: 'Speaking' },
];

const isGradable = (q) => q.type === 'multiple_choice' || q.type === 'fill_blank';
const answeredCount = computed(() => questions.value.filter((q) => isGradable(q) && String(q.correct_answer ?? '').trim() !== '').length);
const gradableCount = computed(() => questions.value.filter(isGradable).length);

function optionsFor(count, old = []) {
    return LETTERS.slice(0, count).map((key, i) => ({ ...(old[i] ?? {}), key, text: old[i]?.text ?? '' }));
}

function sheetQuestion(id, number) {
    return { id, number: String(number), skill: 'reading', type: 'multiple_choice', section: '', title: '', audio_url: '', passage: '', options: optionsFor(3), correct_answer: '', points: 1, explanation: '', teacher_note: '' };
}

const nextId = () => questions.value.reduce((max, q) => Math.max(max, Number(q.id) || 0), 0) + 1;

function setType(q, type) {
    q.type = type;
    if (type === 'multiple_choice') {
        if (!q.options?.length) q.options = optionsFor(3);
        if (!q.options.some((o) => o.key === q.correct_answer)) q.correct_answer = '';
    } else {
        q.options = [];
        if (type !== 'fill_blank') q.correct_answer = '';
    }
}

function setOptionCount(q, count) {
    q.options = optionsFor(Math.max(2, Math.min(LETTERS.length, count)), q.options);
    if (!q.options.some((o) => o.key === q.correct_answer)) q.correct_answer = '';
}

function addRow() {
    const last = questions.value[questions.value.length - 1];
    const row = sheetQuestion(nextId(), last && /^\d+$/.test(String(last.number ?? '')) ? Number(last.number) + 1 : questions.value.length + 1);
    if (last) {
        row.skill = last.skill;
        row.section = last.section ?? '';
    }
    questions.value.push(row);
}

async function removeRow(index) {
    if (await confirmDialog({ message: 'Xóa câu này khỏi phiếu?', confirmLabel: 'Xóa', danger: true })) questions.value.splice(index, 1);
}

const hasWork = () => questions.value.some((q) => String(q.correct_answer ?? '').trim() !== '' || String(q.title ?? '').trim() !== '');

async function makeBlankSheet() {
    const count = Math.max(1, Math.min(200, parseInt(blankCount.value, 10) || 0));
    if (hasWork() && !(await confirmDialog({ message: `Thay phiếu hiện tại bằng phiếu trống ${count} câu?`, confirmLabel: 'Tạo phiếu mới', danger: true }))) return;
    questions.value = Array.from({ length: count }, (_, i) => sheetQuestion(i + 1, i + 1));
}

async function postJson(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': page.props.csrf ?? '', Accept: 'application/json', ...(body instanceof FormData ? {} : { 'Content-Type': 'application/json' }) },
        body: body instanceof FormData ? body : JSON.stringify(body),
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error((json.errors && Object.values(json.errors)[0][0]) || json.message || 'Có lỗi, vui lòng thử lại.');
    return json;
}

async function uploadPdf(event) {
    const file = event.target.files[0];
    event.target.value = '';
    if (!file) return;
    const data = new FormData();
    data.append('file', file);
    uploading.value = true;
    try {
        const json = await postJson(route('placement-tests.pdf.store'), data);
        const hadAnswers = answeredCount.value > 0;
        pdfPath.value = json.pdf_path;
        pdfUrl.value = json.pdf_url;
        keyPage.value = json.answer_key_page ?? null;
        warnings.value = json.warnings ?? [];
        summary.value = { questions: json.questions.length, answers: json.answers_found };
        // Đổi sang file đề không có trang đáp án: giữ phiếu + đáp án đã có.
        if (hadAnswers && !json.answers_found) {
            warnings.value = [];
            summary.value = null;
            toast('Đã đổi file đề, giữ nguyên phiếu và đáp án hiện tại.');
            return;
        }
        if (json.questions.length && (!hasWork() || (await confirmDialog({ message: `Thay phiếu hiện tại bằng phiếu đọc từ file mới (${json.questions.length} câu)?`, confirmLabel: 'Dùng phiếu mới' })))) {
            questions.value = json.questions;
        }
        toast('Đã tải file đề lên.');
    } catch (error) {
        toast(error.message, 'error');
    } finally {
        uploading.value = false;
    }
}

async function removePdf() {
    if (!(await confirmDialog({ message: 'Bỏ file PDF khỏi đề? Phiếu đáp án vẫn giữ nguyên.', confirmLabel: 'Bỏ file', danger: true }))) return;
    pdfPath.value = '';
    pdfUrl.value = '';
    keyPage.value = null;
    warnings.value = [];
    summary.value = null;
}

/** Dán nhanh "1A 2B 3 apple" vào phiếu. */
async function applyAnswers() {
    if (!pasteText.value.trim()) return;
    applying.value = true;
    try {
        const { answers } = await postJson(route('placement-tests.pdf.answers'), { text: pasteText.value });
        if (fillAnswers(answers)) pasteText.value = '';
    } catch (error) {
        toast(error.message, 'error');
    } finally {
        applying.value = false;
    }
}

/** File đáp án riêng (PDF): chỉ đọc đáp án rồi điền vào phiếu, không lưu file. */
async function readKeyFile(event) {
    const file = event.target.files[0];
    event.target.value = '';
    if (!file) return;
    const data = new FormData();
    data.append('file', file);
    data.append('answers_only', '1');
    readingKey.value = true;
    try {
        const { answers } = await postJson(route('placement-tests.pdf.store'), data);
        fillAnswers(answers);
    } catch (error) {
        toast(error.message, 'error');
    } finally {
        readingKey.value = false;
    }
}

/**
 * Điền danh sách {number, answer} vào phiếu, trả về số câu đã điền: số câu không trùng → ghép theo số câu;
 * đề đánh lại số từng phần → ghép theo thứ tự các câu chấm tự động.
 */
function fillAnswers(answers) {
    if (!answers.length) {
        toast('Không nhận ra đáp án nào. Ghi dạng "1A 2B 3. apple".', 'error');
        return 0;
    }
    if (!questions.value.length) questions.value = answers.map((a, i) => sheetQuestion(i + 1, a.number));
    const numbers = questions.value.map((q) => String(q.number ?? ''));
    const unique = (list) => new Set(list).size === list.length;
    const byNumber = unique(numbers) && numbers.every((n) => n !== '') && unique(answers.map((a) => String(a.number)));
    const gradable = questions.value.filter((q) => q.type !== 'essay' && q.type !== 'speaking_prompt');
    let applied = 0;
    answers.forEach((entry, i) => {
        const q = byNumber ? questions.value.find((item) => String(item.number) === String(entry.number)) : gradable[i];
        if (!q || q.type === 'essay' || q.type === 'speaking_prompt') return;
        const letter = /^\(?([A-Ha-h])\)?\.?$/.exec(entry.answer.trim());
        if (q.type === 'multiple_choice' && letter) {
            const key = letter[1].toUpperCase();
            if (LETTERS.indexOf(key) >= q.options.length) setOptionCount(q, LETTERS.indexOf(key) + 1);
            q.correct_answer = key;
        } else {
            setType(q, 'fill_blank');
            q.correct_answer = entry.answer;
        }
        applied++;
    });
    toast(`Đã điền đáp án cho ${applied} câu.`);
    return applied;
}

const showSection = (index) => {
    const section = questions.value[index]?.section ?? '';
    return section !== '' && (index === 0 || (questions.value[index - 1]?.section ?? '') !== section);
};
</script>

<template>
    <div class="space-y-4 text-xs">
        <!-- 1. File đề + file nghe -->
        <div class="space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm">
            <h3 class="flex items-center gap-1.5 border-b border-surface-container-highest pb-2 text-xs font-bold uppercase tracking-wider text-on-surface">
                <span class="material-symbols-outlined text-[18px] text-error" aria-hidden="true">picture_as_pdf</span>
                File đề PDF
            </h3>

            <label v-if="!pdfUrl" class="flex cursor-pointer flex-col items-center gap-1.5 rounded-2xl border-2 border-dashed border-primary-container/40 bg-primary-container/5 p-6 text-center transition hover:border-primary-container hover:bg-primary-container/10">
                <span class="material-symbols-outlined text-3xl text-primary-container" aria-hidden="true">{{ uploading ? 'progress_activity' : 'upload_file' }}</span>
                <span class="text-sm font-bold text-on-surface">{{ uploading ? 'Đang tải lên và đọc đề…' : 'Chọn file PDF đề test (tối đa 20 MB)' }}</span>
                <span class="max-w-xl text-on-surface-variant">Hệ thống đọc chữ trong file để tạo sẵn phiếu trả lời và đáp án (nếu đề có phần "Answer key" / "Đáp án"). Thí sinh xem nguyên file PDF, giữ tranh và bố cục, rồi trả lời trên phiếu.</span>
                <input type="file" accept="application/pdf,.pdf" class="sr-only" :disabled="uploading" @change="uploadPdf" />
            </label>
            <div v-else class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-1.5 rounded-lg bg-surface-container px-2.5 py-1.5 font-semibold text-on-surface">
                    <span class="material-symbols-outlined text-[16px] text-error" aria-hidden="true">description</span>Đã có file đề
                </span>
                <a :href="pdfUrl" target="_blank" rel="noopener" class="font-bold text-primary underline">Mở file</a>
                <label class="inline-flex cursor-pointer items-center gap-1 rounded-lg border border-outline-variant px-2.5 py-1.5 font-bold text-on-surface-variant hover:bg-surface-container-low">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ uploading ? 'progress_activity' : 'swap_horiz' }}</span>
                    {{ uploading ? 'Đang tải…' : 'Đổi file' }}
                    <input type="file" accept="application/pdf,.pdf" class="sr-only" :disabled="uploading" @change="uploadPdf" />
                </label>
                <UiButton variant="danger-text" size="sm" icon="delete" @click="removePdf">Bỏ file</UiButton>
            </div>

            <UiAlert v-if="summary" :type="warnings.length ? 'warning' : 'success'" :title="`Đã đọc ${summary.questions} câu, ${summary.answers} câu có đáp án — kiểm tra lại phiếu trước khi lưu.`">
                <ul v-if="warnings.length" class="list-inside list-disc space-y-0.5">
                    <li v-for="(w, i) in warnings" :key="i">{{ w }}</li>
                </ul>
            </UiAlert>

            <UiAlert v-if="pdfUrl && keyPage" type="error" :title="`File này còn phần đáp án (trang ${keyPage}) — thí sinh sẽ thấy nếu dùng file này.`">
                Hệ thống đã đọc đáp án vào phiếu. Bấm "Đổi file" và tải file đề không có trang đáp án để lưu được đề; phiếu và đáp án hiện tại được giữ nguyên.
            </UiAlert>

            <div class="space-y-2 rounded-xl border border-secondary/30 bg-secondary/5 p-3">
                <span class="flex items-center gap-1.5 font-bold text-on-secondary-fixed">
                    <span class="material-symbols-outlined text-base text-secondary" aria-hidden="true">headphones</span>
                    File nghe của đề (phần Listening, nếu có)
                </span>
                <div class="flex flex-wrap items-center gap-2">
                    <label class="inline-flex cursor-pointer items-center gap-1 rounded-lg border border-secondary/30 bg-surface-container-lowest px-3 py-2 font-bold text-secondary hover:bg-secondary/10">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">upload_file</span>
                        <span>{{ audioUploading === 'audio' ? 'Đang tải lên...' : 'Tải file nghe (.mp3)' }}</span>
                        <input type="file" accept="audio/*" class="sr-only" @change="uploadMedia($event, 'audio', (url) => (audioUrl = url))" />
                    </label>
                    <input v-model="audioUrl" type="text" placeholder="hoặc dán đường dẫn file nghe" aria-label="Đường dẫn file nghe" class="min-w-[200px] flex-1 rounded-xl border border-secondary/30 bg-surface-container-lowest p-2.5 font-mono text-xs" />
                </div>
                <audio v-if="audioUrl" controls class="h-8 w-full" :src="audioUrl"></audio>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <!-- 2. Xem đề -->
            <div class="rounded-2xl border border-surface-container-highest bg-surface-container-low p-3 shadow-sm xl:sticky xl:top-4 xl:max-h-[85vh] xl:overflow-y-auto">
                <PdfViewer v-if="pdfUrl" :src="pdfUrl" />
                <UiEmptyState v-else icon="picture_as_pdf" title="Chưa có file đề" description="Tải file PDF lên để xem đề cạnh phiếu đáp án." />
            </div>

            <!-- 3. Phiếu đáp án -->
            <div class="space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-surface-container-highest pb-2">
                    <h3 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-on-surface">
                        <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">checklist</span>
                        Phiếu trả lời &amp; đáp án
                    </h3>
                    <span class="rounded-full bg-primary-container/10 px-2.5 py-0.5 font-mono font-bold text-primary-container">{{ questions.length }} câu · {{ answeredCount }}/{{ gradableCount }} có đáp án</span>
                </div>

                <div class="space-y-2 rounded-xl border border-surface-container-highest bg-surface-container-low p-3">
                    <UiTextarea v-model="pasteText" label="Dán nhanh đáp án" rows="2" placeholder="VD: 1A 2B 3C 4. apple 5. seven | 7" hint="Câu trắc nghiệm ghi chữ A–H, câu điền từ ghi đáp án; nhiều cách viết đúng ngăn bằng dấu |." />
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <UiButton variant="secondary" size="sm" icon="playlist_add_check" :disabled="applying || !pasteText.trim()" @click="applyAnswers">{{ applying ? 'Đang điền…' : 'Điền đáp án' }}</UiButton>
                            <label class="inline-flex cursor-pointer items-center gap-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-2.5 py-1.5 font-bold text-on-surface-variant hover:bg-surface-container-low" title="Đọc đáp án từ file PDF đáp án riêng (không lưu file)">
                                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ readingKey ? 'progress_activity' : 'key' }}</span>
                                {{ readingKey ? 'Đang đọc…' : 'Đọc từ file đáp án' }}
                                <input type="file" accept="application/pdf,.pdf" class="sr-only" :disabled="readingKey" @change="readKeyFile" />
                            </label>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <label for="sheet-blank-count" class="font-semibold text-on-surface-variant">Phiếu trống</label>
                            <input id="sheet-blank-count" v-model="blankCount" type="number" min="1" max="200" class="w-16 rounded-lg border border-outline-variant bg-surface-container-lowest p-1.5 text-center font-mono" />
                            <span class="text-on-surface-variant">câu</span>
                            <UiButton variant="secondary" size="sm" @click="makeBlankSheet">Tạo</UiButton>
                        </div>
                    </div>
                </div>

                <UiEmptyState v-if="!questions.length" icon="checklist" title="Phiếu chưa có câu nào" description="Tải file PDF để hệ thống tạo phiếu, hoặc tạo phiếu trống theo số câu rồi dán đáp án." />

                <div v-for="(q, idx) in questions" :key="q.id">
                    <p v-if="showSection(idx)" class="mb-1.5 mt-1 font-bold uppercase tracking-wider text-on-surface-variant">{{ q.section }}</p>
                    <div :class="['relative space-y-2 rounded-xl border p-2.5 pr-10', isGradable(q) && !String(q.correct_answer ?? '').trim() ? 'border-warning/60 bg-warning/5' : 'border-surface-container-highest bg-surface-container-lowest']">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <label :for="`sheet-num-${q.id}`" class="font-bold text-on-surface">Câu</label>
                            <input :id="`sheet-num-${q.id}`" v-model="q.number" type="text" maxlength="10" class="w-12 rounded-lg border border-outline-variant bg-surface-container-lowest p-1.5 text-center font-mono font-bold" />
                            <div class="w-[150px]"><UiSelect v-model="q.skill" :options="skillOptions" aria-label="Kỹ năng" /></div>
                            <div class="w-[150px]"><UiSelect :model-value="q.type" :options="typeOptions" aria-label="Dạng câu" @update:model-value="setType(q, $event)" /></div>
                            <input v-model.number="q.points" type="number" min="0.25" step="0.25" aria-label="Điểm" title="Điểm của câu" class="w-14 rounded-lg border border-outline-variant bg-surface-container-lowest p-1.5 text-center font-mono" />
                            <span class="text-on-surface-variant" aria-hidden="true">đ</span>
                            <UiButton variant="ghost" size="sm" icon="close" class="absolute right-1.5 top-1.5" title="Xóa câu" aria-label="Xóa câu" @click="removeRow(idx)" />
                        </div>

                        <div v-if="q.type === 'multiple_choice'" class="flex flex-wrap items-center gap-1.5">
                            <span class="font-semibold text-on-surface-variant">Đáp án đúng:</span>
                            <button
                                v-for="opt in q.options"
                                :key="opt.key"
                                type="button"
                                :aria-pressed="q.correct_answer === opt.key"
                                :title="opt.text || `Phương án ${opt.key}`"
                                :class="['h-8 w-8 rounded-full border font-mono text-sm font-black transition', q.correct_answer === opt.key ? 'border-tertiary bg-tertiary text-white' : 'border-outline-variant bg-surface-container-lowest text-on-surface hover:border-tertiary']"
                                @click="q.correct_answer = opt.key"
                            >
                                {{ opt.key }}
                            </button>
                            <span class="ml-1 text-on-surface-variant">Số phương án</span>
                            <UiButton variant="ghost" size="sm" icon="remove" :disabled="q.options.length <= 2" title="Bớt phương án" aria-label="Bớt phương án" @click="setOptionCount(q, q.options.length - 1)" />
                            <UiButton variant="ghost" size="sm" icon="add" :disabled="q.options.length >= 8" title="Thêm phương án" aria-label="Thêm phương án" @click="setOptionCount(q, q.options.length + 1)" />
                        </div>
                        <UiInput v-else-if="q.type === 'fill_blank'" v-model="q.correct_answer" placeholder="Đáp án đúng (nhiều cách viết: 7 | seven)" aria-label="Đáp án đúng" class="font-mono font-bold" />
                        <p v-else class="italic text-on-surface-variant">{{ q.type === 'essay' ? 'Bài viết: thí sinh viết vào ô Writing, Học vụ chấm tay.' : 'Phần nói: Học vụ chấm tay.' }}</p>

                        <p v-if="q.title" class="line-clamp-2 text-on-surface-variant">{{ q.title }}</p>
                    </div>
                </div>

                <button type="button" class="flex w-full cursor-pointer items-center justify-center gap-1.5 rounded-xl border-2 border-dashed border-primary-container/30 bg-primary-container/5 py-2.5 font-bold text-primary-container transition hover:border-primary-container hover:bg-primary-container/10" @click="addRow">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">add_circle</span>
                    Thêm câu vào phiếu
                </button>
            </div>
        </div>
    </div>
</template>
