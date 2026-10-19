<script setup>
/**
 * Feedback buổi học (SessionFeedbackController, chủ dự án 17/10/2026) cho Học vụ / Học thuật / Admin — giáo viên không thấy.
 * Tab "Phụ huynh chấm buổi": phiếu 1–5 sao trên cổng học viên (nhóm góp ý + nội dung). Tab "Học vụ góp ý": góp ý trong báo cáo ngày
 * (Tốt / Cần lưu ý / Có vấn đề, kèm "Đã báo Admin"). Theo tháng của buổi học; ô số liệu tính trên cả tháng trước khi lọc sao / nhóm / tìm.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { compactQuery } from '@/lib/url';

defineOptions({ layout: { title: 'Feedback buổi học' } });

const props = defineProps({
    source: { type: String, required: true },
    month: { type: String, required: true },
    feedback: { type: Object, required: true },
    stats: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    months: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    teachers: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    verdicts: { type: Array, default: () => [] },
    lowRating: { type: Number, default: 2 },
});

const isParent = computed(() => props.source === 'parent');
const tabs = [
    { key: 'parent', label: 'Phụ huynh chấm buổi' },
    { key: 'staff', label: 'Học vụ góp ý' },
];
const tabHref = (key) => route('class-quality.session-feedback', compactQuery({ source: key === 'parent' ? null : key, month: props.month }));
const ratingOptions = computed(() => [
    { value: 'low', label: `Từ ${props.lowRating} sao trở xuống` },
    ...[5, 4, 3, 2, 1].map((n) => ({ value: String(n), label: `${n} sao` })),
]);
const VERDICT_COLOR = { good: 'success', attention: 'warning', problem: 'error' };
</script>

<template>
    <UiPageHeader title="Feedback buổi học" icon="reviews" description="Phụ huynh chấm từng buổi trên cổng học viên và Học vụ góp ý trong báo cáo ngày. Giáo viên không xem được trang này." />

    <div class="space-y-md">
        <nav class="flex gap-2 border-b border-surface-container-highest" aria-label="Nguồn feedback">
            <Link
                v-for="t in tabs"
                :key="t.key"
                :href="tabHref(t.key)"
                :class="['-mb-px border-b-2 px-3 py-2 text-sm font-semibold', source === t.key ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface']"
                :data-feedback-tab="t.key"
            >{{ t.label }}</Link>
        </nav>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4" data-feedback-stats>
            <template v-if="isParent">
                <UiStatCard label="Phiếu chấm trong tháng" :value="stats.total" />
                <UiStatCard label="Điểm trung bình" :value="stats.average === null ? '—' : `${String(stats.average).replace('.', ',')} ★`" />
                <UiStatCard :label="`Từ ${lowRating} sao trở xuống`" :value="stats.low" :tone="stats.low ? 'error' : undefined" />
                <UiStatCard label="Có ghi nội dung" :value="stats.commented" />
            </template>
            <template v-else>
                <UiStatCard label="Góp ý trong tháng" :value="stats.total" />
                <UiStatCard label="Cần lưu ý" :value="stats.attention" :tone="stats.attention ? 'warning' : undefined" />
                <UiStatCard label="Có vấn đề" :value="stats.problem" :tone="stats.problem ? 'error' : undefined" />
                <UiStatCard label="Đã báo Admin" :value="stats.escalated" />
            </template>
        </div>

        <UiFilterBar placeholder="Tìm tên học viên, nội dung..." :keep="['source']">
            <UiSelect name="month" label="Tháng" :options="months" :value="month" />
            <UiSelect name="class_id" label="Lớp" :options="classes" placeholder="Tất cả lớp" :searchable="classes.length > 8" />
            <UiSelect name="teacher_id" label="Giáo viên buổi" :options="teachers" placeholder="Tất cả giáo viên" :searchable="teachers.length > 8" />
            <template v-if="isParent">
                <UiSelect name="rating" label="Số sao" :options="ratingOptions" placeholder="Mọi mức sao" />
                <UiSelect name="category" label="Nhóm góp ý" :options="categories" placeholder="Mọi nhóm" />
            </template>
            <UiSelect v-else name="verdict" label="Đánh giá" :options="verdicts" placeholder="Mọi đánh giá" />
        </UiFilterBar>

        <div class="divide-y divide-surface-container-highest rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
            <article v-for="f in feedback.data" :key="f.id" class="flex flex-col gap-2 p-4" :data-feedback-row="f.id">
                <div class="flex flex-wrap items-center gap-x-sm gap-y-1 text-sm">
                    <span class="font-semibold text-on-surface">{{ f.class ?? 'Lớp đã xóa' }}</span>
                    <span class="text-on-surface-variant">{{ ['· buổi', f.date ? formatDate(f.date) : '—', f.time].filter(Boolean).join(' ') }}{{ f.teacher ? ` · GV ${f.teacher}` : '' }}</span>
                    <span class="flex-1" />
                    <span v-if="isParent && f.rating" :class="['inline-flex items-center gap-0.5', f.rating <= lowRating ? 'text-error' : 'text-warning']" :aria-label="`${f.rating} trên 5 sao`" data-feedback-rating>
                        <span v-for="n in 5" :key="n" class="material-symbols-outlined text-[18px]" :style="n <= f.rating ? `font-variation-settings: 'FILL' 1` : ''" aria-hidden="true">star</span>
                    </span>
                    <UiBadge v-if="!isParent && f.verdict" :color="VERDICT_COLOR[f.verdict] ?? 'neutral'">{{ f.verdict_label }}</UiBadge>
                    <UiBadge v-if="f.escalated" :color="f.escalation_resolved ? 'success' : 'error'" :dot="false">{{ f.escalation_resolved ? 'Đã báo Admin · đã xử lý' : 'Đã báo Admin' }}</UiBadge>
                </div>
                <div v-if="f.categories.length" class="flex flex-wrap gap-1.5">
                    <UiBadge v-for="c in f.categories" :key="c" color="neutral" :dot="false">{{ c }}</UiBadge>
                </div>
                <p v-if="f.content" class="whitespace-pre-line text-sm text-on-surface">{{ f.content }}</p>
                <p v-else class="text-sm italic text-on-surface-variant">Không ghi nội dung.</p>
                <p class="text-xs text-on-surface-subtle">
                    <template v-if="isParent">Phụ huynh học viên <span class="font-semibold text-on-surface-variant">{{ f.student ?? '—' }}</span></template>
                    <template v-else><span class="font-semibold text-on-surface-variant">{{ f.author ?? '—' }}</span></template>
                    <template v-if="f.sent_at"> · gửi lúc {{ formatDate(f.sent_at, 'H:i d/m') }}</template>
                </p>
            </article>
            <UiEmptyState v-if="!feedback.data.length" icon="reviews" :title="isParent ? 'Chưa có phiếu chấm nào khớp bộ lọc.' : 'Chưa có góp ý nào khớp bộ lọc.'" />
        </div>
        <UiPagination :paginator="feedback" />
    </div>
</template>
