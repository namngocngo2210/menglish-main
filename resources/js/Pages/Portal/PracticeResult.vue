<script setup>
/**
 * Kết quả một lượt luyện tập (docs/luyen-tap-ngan-hang-cau-hoi.md §6.5): điểm, điểm theo kỹ năng, số lần rời bài và
 * xem lại từng câu (AttemptReview, dùng chung với màn báo cáo).
 */
import { computed } from 'vue';
import AttemptReview from '@/Components/Practice/AttemptReview.vue';
import { route } from '@/lib/route';
import PortalBottomNav from './PortalBottomNav.vue';
import PortalPageHeader from './PortalPageHeader.vue';
import PortalTopHeader from './PortalTopHeader.vue';

defineOptions({ layout: { title: 'Kết quả luyện tập', workspaceTabs: false } });

const props = defineProps({
    student: { type: Object, default: null },
    students: { type: Array, default: () => [] },
    unreadCount: { type: Number, default: 0 },
    attempt: { type: Object, required: true },
    items: { type: Array, default: () => [] },
});

const backUrl = computed(() => route('portal.student.practice', { studentId: props.student?.id ?? null }));
const scoreText = computed(() => Number(props.attempt.score).toLocaleString('vi-VN', { maximumFractionDigits: 2 }));
const durationText = computed(() => {
    const m = Math.floor(props.attempt.duration_seconds / 60);
    const s = props.attempt.duration_seconds % 60;
    return m > 0 ? `${m} phút ${s} giây` : `${s} giây`;
});
const percent = (s) => (s.total > 0 ? Math.round((s.correct / s.total) * 100) : 0);
</script>

<template>
    <PortalPageHeader title="Kết quả luyện tập" icon="quiz" :back="backUrl" />

    <!-- Khung điện thoại -->
    <div class="relative mx-auto my-4 flex min-h-[844px] max-w-[430px] flex-col overflow-hidden rounded-3xl border border-surface-container-highest bg-surface-container-low pb-24 shadow-2xl md:min-h-0 md:max-w-4xl md:pb-6 md:shadow-sm">
        <PortalTopHeader :student="student" :students="students" title="Kết quả" show-back :back-url="backUrl" />

        <div class="w-full flex-1 space-y-4 overflow-y-auto p-4">
            <!-- Điểm -->
            <section class="space-y-3 rounded-2xl bg-gradient-to-r from-primary-container to-warning p-4 text-white shadow-md">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-extrabold">Khối {{ attempt.grade }} · {{ attempt.term_label }}<template v-if="attempt.exam_label"> · {{ attempt.exam_label }}</template></h2>
                        <p class="mt-0.5 text-xs text-white/90">{{ formatDate(attempt.started_at, 'd/m/Y H:i') }} · làm trong {{ durationText }}</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <span class="text-4xl font-black">{{ scoreText }}</span><span class="text-sm font-semibold text-white/90">/10</span>
                        <p class="text-xs text-white/90">Đúng {{ attempt.correct }}/{{ attempt.total }} câu</p>
                    </div>
                </div>
                <p v-if="attempt.submit_reason === 'timeout'" class="rounded-lg bg-white/15 px-3 py-1.5 text-xs font-semibold">Hết giờ — bài được tự nộp.</p>
                <p v-else-if="attempt.submit_reason === 'violations'" class="rounded-lg bg-white/15 px-3 py-1.5 text-xs font-semibold">Rời bài {{ attempt.violation_count }} lần — bài được tự nộp.</p>
            </section>

            <!-- Điểm theo kỹ năng -->
            <section class="space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-2xs">
                <h2 class="text-sm font-bold text-on-surface">Theo kỹ năng</h2>
                <div v-for="s in attempt.skill_scores" :key="s.skill" class="space-y-1">
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-semibold text-on-surface">{{ s.skill }}</span>
                        <span class="text-on-surface-variant">{{ s.correct }}/{{ s.total }}</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-surface-container-high" role="progressbar" :aria-label="s.skill" :aria-valuenow="percent(s)" aria-valuemin="0" aria-valuemax="100">
                        <div :class="['h-full rounded-full', percent(s) >= 50 ? 'bg-tertiary' : 'bg-error']" :style="{ width: `${percent(s)}%` }"></div>
                    </div>
                </div>
            </section>

            <!-- Rời bài -->
            <section class="space-y-2 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-2xs">
                <h2 class="text-sm font-bold text-on-surface">Rời bài: {{ attempt.violation_count }} lần</h2>
                <ul v-if="attempt.violation_log.length" class="space-y-1 text-xs text-on-surface-variant">
                    <li v-for="(v, i) in attempt.violation_log" :key="i" class="flex justify-between gap-2">
                        <span>{{ v.label }}</span>
                        <span class="shrink-0 font-mono">{{ v.at ? formatDate(v.at, 'H:i:s') : '' }}</span>
                    </li>
                </ul>
                <p v-else class="text-xs text-on-surface-variant">Không rời khỏi bài lần nào.</p>
            </section>

            <!-- Xem lại bài -->
            <section class="space-y-3">
                <h2 class="text-sm font-bold text-on-surface">Xem lại bài làm</h2>
                <AttemptReview :items="items" />
            </section>

            <UiButton icon="quiz" variant="secondary" class="w-full justify-center" :href="backUrl">Về trang Luyện tập</UiButton>
        </div>

        <PortalBottomNav active-tab="practice" :student="student" :unread-count="unreadCount" />
    </div>
</template>
