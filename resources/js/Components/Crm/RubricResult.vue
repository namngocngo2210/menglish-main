<script setup>
/**
 * Khối kết quả test đầu vào theo thang điểm khối lớp (BA Q2) trên hồ sơ khách (thay placement-tests/partials/rubric-result).
 * Props: rubric (CrmController::rubricSummary), result (điểm từng kỹ năng / điểm cũ dạng chữ), skills, scorecardUrl, noRubricNotice
 */
import { computed } from 'vue';

const props = defineProps({
    rubric: { type: Object, default: null },
    result: { type: Object, required: true },
    skills: { type: Object, required: true },
    scorecardUrl: { type: String, default: null },
    hasSubmission: { type: Boolean, default: false },
    noRubricNotice: { type: String, default: '' },
});
const fmt = (v) => (v === null || v === undefined ? '—' : String(v));
const fmtTotal = (v) => (v === null || v === undefined ? '—' : String(Math.round(Number(v) * 10) / 10));
const skillStyles = {
    listening: 'bg-secondary/5 border-secondary/20 text-secondary',
    reading_writing: 'bg-tertiary/5 border-tertiary/20 text-tertiary',
    speaking: 'bg-primary/5 border-primary/20 text-primary',
};
const graded = computed(() => props.rubric && !props.rubric.legacy);
// Điểm cũ dạng chữ (vd. "5.5 Overall (L: 5.5, R: 6.0, ...)"): ô vuông chỉ hiện số đầu, phần còn lại thành dòng chữ bên cạnh.
const legacyScore = computed(() => String(props.result.overall_score ?? props.result.fallback_score ?? ''));
const legacyHead = computed(() => legacyScore.value.trim().split(/\s+/u).filter(Boolean));
const legacyBadge = computed(() => {
    const first = legacyHead.value[0] ?? '';
    return first.length > 0 && first.length <= 6 ? first : '—';
});
const legacyDetail = computed(() => {
    const rest = legacyBadge.value === '—' ? legacyScore.value : legacyScore.value.trim().split(/\s+/u).slice(1).join(' ');
    return rest.replace(/^[\s·-]+|[\s·-]+$/gu, '');
});
const suggestedClass = computed(() => props.rubric?.chosen_class ?? props.result.recommended_course);
</script>

<template>
    <div class="space-y-3 text-xs" data-testid="rubric-result">
        <div class="flex flex-wrap items-center justify-between gap-md rounded-xl border border-primary/20 bg-primary-fixed/30 p-md">
            <div class="flex items-center gap-3">
                <div class="flex h-12 w-14 shrink-0 flex-col items-center justify-center overflow-hidden rounded-xl bg-primary-container px-1 font-black text-white shadow-sm">
                    <template v-if="graded">
                        <span class="text-sm leading-none">{{ fmtTotal(rubric.total) }}</span>
                        <span class="text-xs font-semibold tracking-wider opacity-90">/ {{ rubric.max_total }}</span>
                    </template>
                    <span v-else class="text-sm leading-none" :title="legacyScore">{{ legacyBadge }}</span>
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h4 class="font-body-semibold text-body-semibold text-on-surface">Kết quả test đầu vào</h4>
                        <span v-if="graded" class="shadow-2xs rounded-md border border-warning/30 bg-surface-container-lowest px-2 py-0.5 text-xs font-bold text-on-warning-container">{{ rubric.grade_group_label }}</span>
                    </div>
                    <p v-if="!graded && legacyDetail !== ''" class="mt-0.5 text-on-surface-variant">{{ legacyDetail }}</p>
                    <!-- Không có lớp đề xuất (điểm cũ dạng chữ) thì không hiện dòng trống "–" -->
                    <p v-if="suggestedClass" class="mt-0.5 font-semibold text-on-surface">
                        Đề xuất xếp lớp: <span class="font-bold text-primary-container">{{ suggestedClass }}</span>
                    </p>
                    <p v-if="rubric && rubric.draft" class="mt-0.5 inline-flex items-center gap-1 rounded-md bg-warning-container px-2 py-0.5 text-xs font-semibold text-on-warning-container" data-testid="rubric-draft">
                        <span class="material-symbols-outlined text-[14px]">auto_awesome</span>Hệ thống đã tự chấm Nghe, Đọc &amp; Viết — tổng tạm tính, chờ Học vụ nhập điểm Nói và xác nhận
                    </p>
                    <p v-if="rubric && rubric.overridden" class="text-xs text-on-surface-variant">Lớp đề xuất theo thang điểm: <strong>{{ rubric.suggested_class }}</strong> (Học vụ đã chọn lại)</p>
                    <p v-else-if="graded && !rubric.has_rubric" class="text-xs text-on-warning-container">{{ noRubricNotice }}</p>
                </div>
            </div>
            <a v-if="scorecardUrl" :href="scorecardUrl" target="_blank" class="shadow-xs flex shrink-0 items-center gap-1.5 rounded-xl bg-inverse-surface px-3.5 py-2 text-xs font-bold text-white transition hover:bg-inverse-surface/90">
                <span class="material-symbols-outlined text-[15px] text-warning/70">military_tech</span>
                <span>Xem Scorecard</span>
            </a>
        </div>

        <div v-if="rubric && rubric.legacy" class="rounded-xl border border-surface-container-highest bg-surface-container-low p-3 text-xs text-on-surface-variant">
            Bài chấm theo cách cũ (trước khi áp dụng thang điểm khối lớp). Điểm từng kỹ năng:
            Nghe {{ fmt(result.scores.listening) }} · Đọc {{ fmt(result.scores.reading) }} · Viết {{ fmt(result.scores.writing) }} · Nói {{ fmt(result.scores.speaking) }}.
            Sửa điểm để chấm lại theo thang điểm mới.
        </div>
        <div v-else-if="hasSubmission" class="grid grid-cols-1 gap-2 sm:grid-cols-3">
            <div v-for="(label, skill) in skills" :key="skill" :class="['space-y-1 rounded-xl border p-2.5', skillStyles[skill]]">
                <div class="flex items-baseline justify-between gap-2">
                    <span class="text-xs font-bold uppercase text-on-surface">{{ label }}</span>
                    <span class="font-mono text-base font-black">{{ fmt(result.scores[skill]) }}<span v-if="rubric" class="text-xs text-on-surface-variant">/{{ rubric.max[skill] }}</span></span>
                </div>
                <p v-if="rubric && rubric.comments[skill]" class="text-xs leading-relaxed text-on-surface-variant">{{ rubric.comments[skill] }}</p>
            </div>
        </div>

        <div v-if="result.teacher_comments" class="space-y-1 rounded-xl border border-surface-container-highest bg-surface-container-low p-3">
            <div class="flex items-center gap-1 text-xs font-bold text-on-surface-variant">
                <span class="material-symbols-outlined text-[14px] text-primary">rate_review</span>
                <span>Ghi chú của người chấm{{ result.grader ? ' (' + result.grader + ')' : '' }}:</span>
            </div>
            <p class="whitespace-pre-line text-xs leading-relaxed text-on-surface">{{ result.teacher_comments }}</p>
        </div>
    </div>
</template>
