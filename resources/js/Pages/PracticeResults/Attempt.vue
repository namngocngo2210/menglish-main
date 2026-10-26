<script setup>
/**
 * Xem lại một lượt luyện tập đã nộp (chỉ đọc) ở màn báo cáo: điểm, điểm theo kỹ năng, nhật ký rời bài và từng câu
 * (AttemptReview, dùng chung với trang kết quả ở cổng học viên).
 */
import { computed } from 'vue';
import AttemptReview from '@/Components/Practice/AttemptReview.vue';

defineOptions({ layout: { title: 'Kết quả luyện tập' } });

const props = defineProps({
    student: { type: Object, required: true },
    attempt: { type: Object, required: true },
    items: { type: Array, default: () => [] },
    backUrl: { type: String, required: true },
});

const REASONS = { timeout: 'Hết giờ — bài được tự nộp.', violations: 'Rời bài quá số lần cho phép — bài được tự nộp.' };

const durationText = computed(() => {
    const m = Math.floor(props.attempt.duration_seconds / 60);
    const s = props.attempt.duration_seconds % 60;
    return m > 0 ? `${m} phút ${s} giây` : `${s} giây`;
});
const percent = (s) => (s.total > 0 ? Math.round((s.correct / s.total) * 100) : 0);
</script>

<template>
    <UiPageHeader
        :title="`${student.name} · Khối ${attempt.grade} · ${attempt.term_label}${attempt.exam_label ? ` · ${attempt.exam_label}` : ''}`"
        :description="`Bắt đầu ${formatDate(attempt.started_at, 'd/m/Y H:i')} · làm trong ${durationText}`"
        :back="backUrl"
    />

    <div class="grid gap-lg lg:grid-cols-[minmax(0,1fr)_320px]">
        <section class="order-2 space-y-sm lg:order-1" aria-label="Xem lại bài làm">
            <h2 class="font-h3 text-h3 text-on-surface">Xem lại bài làm</h2>
            <AttemptReview :items="items" />
        </section>

        <aside class="order-1 space-y-md lg:order-2">
            <UiStatCard label="Điểm" :value="`${formatNumber(attempt.score, 2)}/10`" icon="grade" tone="success" :hint="`Đúng ${attempt.correct}/${attempt.total} câu`" />
            <UiAlert v-if="REASONS[attempt.submit_reason]" type="warning">{{ REASONS[attempt.submit_reason] }}</UiAlert>

            <section class="space-y-sm rounded-xl border border-surface-variant bg-surface-container-lowest p-md">
                <h2 class="font-body-semibold text-body-semibold text-on-surface">Theo kỹ năng</h2>
                <div v-for="s in attempt.skill_scores" :key="s.skill" class="space-y-1">
                    <div class="flex items-center justify-between font-body-small text-body-small">
                        <span class="font-semibold text-on-surface">{{ s.skill }}</span>
                        <span class="text-on-surface-variant">{{ s.correct }}/{{ s.total }}</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-surface-container-high" role="progressbar" :aria-label="s.skill" :aria-valuenow="percent(s)" aria-valuemin="0" aria-valuemax="100">
                        <div :class="['h-full rounded-full', percent(s) >= 50 ? 'bg-tertiary' : 'bg-error']" :style="{ width: `${percent(s)}%` }"></div>
                    </div>
                </div>
            </section>

            <section class="space-y-sm rounded-xl border border-surface-variant bg-surface-container-lowest p-md">
                <h2 class="font-body-semibold text-body-semibold text-on-surface">Rời bài: {{ attempt.violation_count }} lần</h2>
                <ul v-if="attempt.violation_log.length" class="space-y-1 font-body-small text-body-small text-on-surface-variant">
                    <li v-for="(v, i) in attempt.violation_log" :key="i" class="flex justify-between gap-sm">
                        <span>{{ v.label }}</span>
                        <span class="shrink-0 font-code">{{ v.at ? formatDate(v.at, 'H:i:s') : '' }}</span>
                    </li>
                </ul>
                <p v-else class="font-body-small text-body-small text-on-surface-variant">Không rời khỏi bài lần nào.</p>
            </section>
        </aside>
    </div>
</template>
