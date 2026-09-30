<script setup>
/**
 * Chi tiết bài làm & chấm điểm (mockup kh_i_test_online_chi_ti_t): chấm theo thang điểm khối lớp (điểm tự chấm là bản nháp,
 * nhận xét gợi ý theo băng điểm) → "Lưu bản nháp" / "Xác nhận kết quả"; bên dưới đối chiếu từng câu với đáp án chuẩn.
 */
import { computed, ref } from 'vue';
import RubricScoreFields from '@/Components/PlacementTests/RubricScoreFields.vue';

defineOptions({ layout: { title: 'Chi tiết bài làm & chấm điểm' } });

const props = defineProps({
    submission: { type: Object, required: true },
    questions: { type: Array, default: () => [] },
    rubric: { type: Object, required: true },
});

const currentFilter = ref('all');
const correctCount = computed(() => props.questions.filter((q) => q.is_correct).length);
const incorrectCount = computed(() => props.questions.filter((q) => q.is_incorrect).length);

function shouldShow(q) {
    switch (currentFilter.value) {
        case 'correct':
            return q.is_correct;
        case 'incorrect':
            return q.is_incorrect;
        case 'listening':
            return q.skill === 'listening';
        case 'reading':
            return q.skill === 'reading' || q.skill === 'grammar';
        case 'writing':
            return q.skill === 'writing';
        case 'speaking':
            return q.skill === 'speaking';
        default:
            return true;
    }
}

const filters = [
    { key: 'listening', icon: 'headphones', label: 'Listening', on: 'bg-secondary text-white font-bold', off: 'bg-secondary/10 hover:bg-secondary/20 text-secondary font-semibold' },
    { key: 'reading', icon: 'menu_book', label: 'Reading & Grammar', on: 'bg-tertiary text-white font-bold', off: 'bg-tertiary/10 hover:bg-tertiary/20 text-tertiary font-semibold' },
    { key: 'writing', icon: 'edit_note', label: 'Writing', on: 'bg-warning text-white font-bold', off: 'bg-warning-container hover:bg-warning/20 text-on-warning-container font-semibold' },
    { key: 'speaking', icon: 'record_voice_over', label: 'Speaking', on: 'bg-error text-white font-bold', off: 'bg-error/10 hover:bg-error/20 text-error font-semibold' },
];
const skillBadges = {
    listening: { color: 'secondary', icon: 'headphones', label: 'Listening' },
    reading: { color: 'success', icon: 'menu_book', label: 'Reading' },
    grammar: { color: 'accent', icon: 'spellcheck', label: 'Grammar' },
    writing: { color: 'warning', icon: 'edit_note', label: 'Writing Task' },
    speaking: { color: 'error', icon: 'record_voice_over', label: 'Speaking Prompt' },
};
const typeLabel = (type) => ({ multiple_choice: 'Trắc nghiệm 4 lựa chọn', fill_blank: 'Điền từ vào chỗ trống', essay: 'Tự luận Writing' })[type] ?? 'Phỏng vấn Speaking';

function optionClass(opt) {
    if (opt.is_correct && opt.is_chosen) return 'bg-tertiary/10 border-tertiary text-on-tertiary-container ring-2 ring-tertiary/20 font-bold';
    if (opt.is_correct) return 'bg-tertiary/5 border-tertiary/30 text-on-tertiary-container font-bold';
    if (opt.is_chosen) return 'bg-error/10 border-error/30 text-on-error-container font-bold';
    return 'bg-surface-container-lowest border-surface-container-highest text-on-surface-variant';
}
function optionKeyClass(opt) {
    if (opt.is_correct) return 'bg-tertiary text-white';
    if (opt.is_chosen) return 'bg-error text-white';
    return 'bg-surface-container text-on-surface-variant';
}
</script>

<template>
    <UiPageHeader title="Chi tiết bài làm & chấm điểm" :back="route('placement-tests.index')">
        <template #badges>
            <UiBadge color="primary" pill :dot="false" class="font-bold">{{ submission.score_summary ?? 'Chưa có điểm' }}</UiBadge>
            <UiBadge :color="submission.is_pending ? 'secondary' : 'success'" pill class="uppercase">{{ submission.is_pending ? 'Chờ chấm' : 'Đã chấm điểm' }}</UiBadge>
        </template>
        <template #meta>
            <span class="font-mono">{{ submission.test_title }} · Thí sinh: <strong class="text-on-surface">{{ submission.candidate_name }}</strong> · SĐT: {{ submission.candidate_phone }}</span>
        </template>
        <template #actions>
            <UiButton v-if="submission.customer_id" variant="secondary" icon="person" :href="route('crm.customers.show', submission.customer_id)">Hồ sơ khách</UiButton>
            <a :href="submission.scorecard_url" target="_blank" class="inline-flex items-center gap-1.5 rounded-xl bg-inverse-surface px-3.5 py-2 text-xs font-bold text-white shadow-xs transition hover:bg-inverse-surface/90">
                <span class="material-symbols-outlined text-[16px] text-warning/70">military_tech</span>
                <span>Bảng điểm (Scorecard)</span>
            </a>
            <UiButton variant="secondary" icon="menu_book" :href="route('placement-tests.rubric-guide')">Thang điểm &amp; hướng dẫn nhận xét</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <!-- 1. BẢNG TỔNG HỢP ĐIỂM & FORM CHẤM NHANH -->
        <UiForm :action="route('placement-tests.results.update', submission.id)" method="post" class="space-y-6 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-surface-container-highest pb-3">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-xl text-primary-container">fact_check</span>
                    <h2 class="font-h3 text-h3 text-on-surface">Chấm điểm theo thang điểm khối lớp</h2>
                </div>
                <span class="rounded-md border border-warning/30 bg-warning-container px-2 py-0.5 text-xs font-bold text-on-warning-container">Tổng = Nghe + Đọc &amp; Viết + Nói → lớp đề xuất</span>
                <div class="font-mono text-xs text-on-surface-variant">Nộp bài lúc: {{ formatDate(submission.submitted_at, 'H:i, d/m/Y') }}</div>
            </div>

            <UiAlert v-if="submission.is_pending && (submission.listening_score !== null || submission.reading_score !== null)" type="info">
                Hệ thống đã tự chấm theo đáp án của đề (quy về thang của khối): Nghe {{ submission.listening_score ?? '—' }} · Đọc &amp; Viết {{ submission.reading_writing_score ?? submission.reading_score ?? '—' }}, kèm nhận xét gợi ý từng kỹ năng.
                Học vụ xem lại, sửa điểm / nhận xét nếu cần (bài viết tự luận chấm tay), nhập điểm Nói rồi bấm Xác nhận kết quả.
            </UiAlert>

            <UiAlert v-if="submission.violation_count > 0 || submission.auto_submitted" type="warning" :title="`Thí sinh rời khỏi bài thi ${submission.violation_count} lần${submission.auto_submitted ? ' · bài bị tự động nộp' : ''}`">
                <ul v-if="submission.violations.length" class="list-inside list-disc space-y-0.5">
                    <li v-for="(entry, i) in submission.violations" :key="i">{{ entry.at }}: {{ entry.label }}</li>
                </ul>
            </UiAlert>

            <div class="max-w-3xl">
                <RubricScoreFields :rubric="rubric" />
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-surface-container-highest pt-4">
                <div class="flex items-center gap-2 text-xs text-on-surface-variant">
                    <span class="material-symbols-outlined text-[16px] text-primary">info</span>
                    <span>Khi lưu điểm, hệ thống tính tổng điểm, tra lớp đề xuất và đồng bộ kết quả sang hồ sơ khách CRM.</span>
                </div>
                <div class="flex items-center gap-sm">
                    <UiButton v-if="submission.is_pending" type="submit" variant="secondary" name="action" value="draft">Lưu bản nháp</UiButton>
                    <UiButton type="submit" icon="check" name="action" value="confirm">Xác nhận kết quả</UiButton>
                </div>
            </div>
        </UiForm>

        <!-- 2. BẢNG ĐỐI CHIẾU CÂU HỎI, ĐÁP ÁN CHỌN & ĐÁP ÁN ĐÚNG -->
        <div class="space-y-4 overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
            <!-- Section Header & Filter Toolbar -->
            <div class="flex flex-col justify-between gap-4 border-b border-surface-container-highest pb-4 sm:flex-row sm:items-center">
                <div>
                    <h2 class="flex items-center gap-2 text-base font-black text-on-surface">
                        <span class="material-symbols-outlined text-secondary">checklist_rtl</span>
                        <span>Đối Chiếu Chi Tiết Từng Câu Hỏi &amp; Đáp Án Thí Sinh Đã Chọn</span>
                    </h2>
                    <p class="mt-0.5 text-xs text-on-surface-variant">Xem chi tiết lựa chọn của thí sinh so với đáp án chuẩn của đề, phân tích lỗi sai và lời giải thích.</p>
                </div>

                <!-- Stats Quick Badges -->
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <span class="rounded-xl border border-surface-container-highest bg-surface-container px-2.5 py-1 font-mono text-xs font-bold text-on-surface">Tổng: {{ questions.length }} câu</span>
                    <span class="flex items-center gap-1 rounded-xl border border-tertiary/30 bg-tertiary/10 px-2.5 py-1 font-mono text-xs font-bold text-tertiary">
                        <span class="material-symbols-outlined text-[14px]">check_circle</span>
                        <span>Đúng: <strong>{{ correctCount }}</strong></span>
                    </span>
                    <span class="flex items-center gap-1 rounded-xl border border-error/30 bg-error/10 px-2.5 py-1 font-mono text-xs font-bold text-error">
                        <span class="material-symbols-outlined text-[14px]">cancel</span>
                        <span>Sai: <strong>{{ incorrectCount }}</strong></span>
                    </span>
                </div>
            </div>

            <!-- Filter Buttons -->
            <div class="flex items-center gap-1.5 overflow-x-auto pb-1 text-xs">
                <button type="button" :class="['shrink-0 cursor-pointer rounded-lg px-3 py-1.5 transition', currentFilter === 'all' ? 'bg-inverse-surface font-bold text-white' : 'bg-surface-container font-semibold text-on-surface-variant hover:bg-surface-container-high']" @click="currentFilter = 'all'">Tất Cả ({{ questions.length }})</button>
                <button v-for="f in filters" :key="f.key" type="button" :class="['shrink-0 cursor-pointer rounded-lg px-3 py-1.5 transition', currentFilter === f.key ? f.on : f.off]" @click="currentFilter = f.key">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ f.icon }}</span> {{ f.label }}
                </button>
                <button type="button" :class="['shrink-0 cursor-pointer rounded-lg px-3 py-1.5 transition', currentFilter === 'correct' ? 'bg-tertiary font-bold text-white' : 'bg-tertiary/10 font-semibold text-on-tertiary-container hover:bg-tertiary/20']" @click="currentFilter = 'correct'">✓ Câu Đúng</button>
                <button type="button" :class="['shrink-0 cursor-pointer rounded-lg px-3 py-1.5 transition', currentFilter === 'incorrect' ? 'bg-error font-bold text-white' : 'bg-error/10 font-semibold text-on-error-container hover:bg-error/20']" @click="currentFilter = 'incorrect'">✗ Câu Sai</button>
            </div>

            <!-- Questions List -->
            <div class="space-y-5 pt-2">
                <div v-for="q in questions" v-show="shouldShow(q)" :key="q.number" :class="['rounded-2xl border p-5 transition-all duration-200', q.is_correct ? 'border-tertiary/30 bg-tertiary/5' : q.is_incorrect ? 'border-error/30 bg-error/5' : 'border-surface-container-highest bg-surface-container-low/40']">
                    <!-- Question Header -->
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-surface-container-highest pb-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-inverse-surface font-mono text-xs font-black text-white">#{{ q.number }}</span>
                            <UiBadge v-if="skillBadges[q.skill]" :color="skillBadges[q.skill].color" pill class="font-bold uppercase"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ skillBadges[q.skill].icon }}</span> {{ skillBadges[q.skill].label }}</UiBadge>
                            <span class="rounded-md border border-surface-container-highest bg-surface-container px-2 py-0.5 text-xs font-medium text-on-surface-variant">{{ typeLabel(q.type) }}</span>
                        </div>

                        <!-- Accuracy / Points Badge -->
                        <div class="flex items-center gap-2">
                            <span v-if="q.is_correct" class="flex items-center gap-1 rounded-xl border border-tertiary/30 bg-tertiary/10 px-3 py-1 text-xs font-black text-on-tertiary-container shadow-2xs">
                                <span class="material-symbols-outlined text-[15px] text-tertiary">check_circle</span>
                                <span>CHÍNH XÁC (+{{ q.points }}đ)</span>
                            </span>
                            <span v-else-if="q.is_incorrect" class="flex items-center gap-1 rounded-xl border border-error/30 bg-error/10 px-3 py-1 text-xs font-black text-on-error-container shadow-2xs">
                                <span class="material-symbols-outlined text-[15px] text-error">cancel</span>
                                <span>CHƯA ĐÚNG (0đ)</span>
                            </span>
                            <span v-else-if="q.is_objective && !q.candidate_answer" class="rounded-xl border border-surface-container-highest bg-surface-container px-2.5 py-1 text-xs font-semibold text-on-surface-variant">Chưa có câu trả lời</span>
                            <span class="font-mono text-xs font-bold text-on-surface-subtle">({{ q.points }} điểm)</span>
                        </div>
                    </div>

                    <!-- Audio Player if present -->
                    <div v-if="q.audio_url" class="mt-3 flex items-center gap-3 rounded-xl border border-secondary/30 bg-secondary/5 p-3">
                        <span class="material-symbols-outlined text-xl text-secondary">headphones</span>
                        <div class="flex-1">
                            <span class="mb-1 block text-xs font-bold text-on-secondary-fixed">File Nghe Audio của câu hỏi:</span>
                            <audio controls class="h-8 w-full">
                                <source :src="q.audio_url" type="audio/mpeg" />
                                Trình duyệt không hỗ trợ audio player.
                            </audio>
                        </div>
                    </div>

                    <!-- Passage / Context if present -->
                    <div v-if="q.passage" class="mt-3 space-y-1 rounded-xl border border-surface-container-highest bg-surface-container/80 p-3.5">
                        <span class="block text-xs font-bold uppercase tracking-wider text-on-surface-variant">Đoạn văn đọc hiểu / Bối cảnh:</span>
                        <p class="font-serif text-xs italic leading-relaxed text-on-surface">{{ q.passage }}</p>
                    </div>

                    <!-- Question Title -->
                    <div class="mt-3">
                        <h3 class="text-sm font-bold leading-snug text-on-surface">{{ q.title }}</h3>
                    </div>

                    <!-- MULTIPLE CHOICE OPTIONS -->
                    <div v-if="q.type === 'multiple_choice' && q.options.length" class="mt-3.5 space-y-2">
                        <span class="mb-1 block text-xs font-bold uppercase tracking-wider text-on-surface-subtle">Các lựa chọn &amp; Đối chiếu câu trả lời:</span>
                        <div class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
                            <div v-for="opt in q.options" :key="opt.key" :class="['flex items-start justify-between gap-2.5 rounded-xl border p-3 transition', optionClass(opt)]">
                                <div class="flex items-start gap-2.5">
                                    <span :class="['flex h-6 w-6 shrink-0 items-center justify-center rounded-lg font-mono text-xs font-black', optionKeyClass(opt)]">{{ opt.key }}</span>
                                    <span class="mt-0.5 leading-relaxed">{{ opt.text }}</span>
                                </div>
                                <!-- Badges on the right of each option -->
                                <div class="flex shrink-0 flex-col items-end gap-1">
                                    <span v-if="opt.is_chosen && opt.is_correct" class="flex items-center gap-1 rounded-md bg-tertiary px-2 py-0.5 text-xs font-black text-white shadow-2xs">
                                        <span class="material-symbols-outlined text-[12px]">done_all</span>
                                        <span>Thí sinh chọn (Đúng)</span>
                                    </span>
                                    <span v-else-if="opt.is_chosen" class="flex items-center gap-1 rounded-md bg-error px-2 py-0.5 text-xs font-black text-white shadow-2xs">
                                        <span class="material-symbols-outlined text-[12px]">close</span>
                                        <span>Thí sinh chọn (Sai)</span>
                                    </span>
                                    <span v-else-if="opt.is_correct" class="flex items-center gap-1 rounded-md border border-tertiary/30 bg-tertiary/10 px-2 py-0.5 text-xs font-black text-on-tertiary-container">
                                        <span class="material-symbols-outlined text-[12px]">check</span>
                                        <span>Đáp án đúng của đề</span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- FILL BLANK QUESTION -->
                    <div v-if="q.type === 'fill_blank'" class="mt-3.5 space-y-2 rounded-xl border border-surface-container-highest bg-surface-container-lowest p-3.5 text-xs">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div :class="['rounded-lg border p-2.5', q.is_correct ? 'border-tertiary/30 bg-tertiary/5 text-on-tertiary-container' : 'border-error/30 bg-error/5 text-on-error-container']">
                                <span class="mb-1 block text-xs font-bold uppercase tracking-wider text-on-surface-variant">Câu trả lời của thí sinh:</span>
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-sm font-black">{{ q.candidate_answer || '(Bỏ trống / Chưa điền)' }}</span>
                                    <span v-if="q.is_correct" class="material-symbols-outlined text-base text-tertiary">check_circle</span>
                                    <span v-else class="material-symbols-outlined text-base text-error">cancel</span>
                                </div>
                            </div>
                            <div class="rounded-lg border border-tertiary/30 bg-tertiary/10 p-2.5 text-on-tertiary-container">
                                <span class="mb-1 block text-xs font-bold uppercase tracking-wider text-on-tertiary-container">Đáp án chuẩn của đề bài:</span>
                                <span class="font-mono text-sm font-black text-tertiary">{{ q.correct_answer }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- ESSAY WRITING TASK -->
                    <div v-if="q.skill === 'writing' || q.type === 'essay'" class="mt-3.5 space-y-3">
                        <div class="space-y-2 rounded-xl border border-warning/30 bg-surface-container-lowest p-4 shadow-2xs">
                            <div class="flex items-center justify-between border-b border-warning/20 pb-2">
                                <span class="flex items-center gap-1.5 text-xs font-bold text-on-warning-container">
                                    <span class="material-symbols-outlined text-[16px] text-warning">edit_document</span>
                                    <span>Toàn văn Bài làm Writing của Thí sinh:</span>
                                </span>
                                <span class="rounded-md bg-warning/5 px-2 py-0.5 font-mono text-xs font-bold text-on-warning-container">{{ submission.writing_word_count }} từ</span>
                            </div>
                            <div class="whitespace-pre-wrap rounded-lg bg-warning/5 p-3 font-serif text-xs leading-relaxed text-on-surface">{{ submission.writing_content || 'Thí sinh không nhập nội dung bài viết.' }}</div>
                        </div>

                        <div v-if="q.rubric_note" class="flex items-start gap-2 rounded-xl border border-surface-container-highest bg-surface-container-low p-3 text-xs text-on-surface-variant">
                            <span class="material-symbols-outlined mt-0.5 shrink-0 text-base text-warning">rule</span>
                            <div><strong class="text-on-surface">Hướng dẫn chấm Writing:</strong> {{ q.rubric_note }}</div>
                        </div>
                    </div>

                    <!-- SPEAKING PROMPT TASK -->
                    <div v-if="q.skill === 'speaking' || q.type === 'speaking_prompt'" class="mt-3.5 space-y-3">
                        <div v-if="q.cue_points" class="space-y-1.5 rounded-xl border border-error/30 bg-error/5 p-3.5 text-xs">
                            <span class="block text-xs font-bold uppercase text-on-error-container">Gợi ý chủ đề phỏng vấn (Cue Points):</span>
                            <div class="whitespace-pre-line pl-1 font-medium leading-relaxed text-on-error-container">{{ q.cue_points }}</div>
                        </div>

                        <div v-if="submission.speaking_audio_url" class="flex items-center gap-3 rounded-xl border border-error/30 bg-surface-container-lowest p-3">
                            <span class="material-symbols-outlined text-xl text-error">mic</span>
                            <div class="flex-1">
                                <span class="mb-1 block text-xs font-bold text-on-surface">File ghi âm bài nói của thí sinh:</span>
                                <audio controls class="h-8 w-full">
                                    <source :src="submission.speaking_audio_url" type="audio/mpeg" />
                                </audio>
                            </div>
                        </div>
                    </div>

                    <!-- EXPLANATION & TRANSCRIPT EVIDENCE -->
                    <div v-if="q.explanation" class="mt-3 flex items-start gap-2 rounded-xl border border-warning/25 bg-warning/5 p-3 text-xs">
                        <span class="material-symbols-outlined mt-0.5 shrink-0 text-base text-primary-container">lightbulb</span>
                        <div>
                            <strong class="text-on-warning-container">Giải thích chi tiết &amp; Dẫn chứng bài làm:</strong>
                            <p class="mt-0.5 leading-relaxed text-on-warning-container">{{ q.explanation }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
