<script setup>
/**
 * Kết quả luyện tập của một học viên (docs/luyen-tap-ngan-hang-cau-hoi.md §7): tổng quan + danh sách lượt đã nộp → xem lại bài.
 */
import { formatNumber } from '@/lib/format';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Kết quả luyện tập' } });

const props = defineProps({
    student: { type: Object, required: true },
    filters: { type: Object, required: true },
    filterOptions: { type: Object, required: true },
    summary: { type: Object, required: true },
    attempts: { type: Object, required: true },
    backUrl: { type: String, required: true },
    keepFilters: { type: Array, default: () => [] },
    returnQuery: { type: Object, default: () => ({}) },
});

const REASONS = { timeout: 'Hết giờ — tự nộp', violations: 'Rời bài quá số lần — tự nộp' };

const score = (value) => (value === null || value === undefined ? '—' : formatNumber(value, 2));
const duration = (seconds) => {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return m > 0 ? `${m} phút ${s} giây` : `${s} giây`;
};
// mang theo bộ lọc + trang hiện tại → nút quay lại của trang xem bài về đúng danh sách này
const attemptUrl = (attempt) => route('practice-results.attempt', { ...props.returnQuery, attempt: attempt.id });
</script>

<template>
    <UiPageHeader :title="student.name" :description="[student.code, student.class_name].filter(Boolean).join(' · ')" :back="backUrl" />

    <!-- keep: giữ bộ lọc lớp / tìm kiếm của màn tổng hợp cho nút quay lại -->
    <UiFilterBar :search="false" :keep="keepFilters" aria-label="Lọc lượt luyện tập">
        <UiSelect name="grade" label="Khối" :options="filterOptions.grades" placeholder="Tất cả khối" />
        <UiSelect name="term" label="Kỳ" :options="filterOptions.terms" placeholder="Tất cả kỳ" />
        <UiSelect name="exam" label="Đề" :options="filterOptions.exams" placeholder="Tất cả đề" />
        <UiDateRange label="Ngày nộp" />
    </UiFilterBar>

    <section class="mb-lg grid gap-md sm:grid-cols-2" aria-label="Tổng quan">
        <UiStatCard label="Lượt đã nộp" :value="formatNumber(summary.attempts)" icon="quiz" tone="primary" />
        <UiStatCard label="Điểm trung bình" :value="score(summary.avg_score)" icon="grade" tone="success" hint="Thang điểm 10" />
    </section>

    <section class="mb-lg rounded-xl border border-surface-variant bg-surface-container-lowest p-md" aria-label="Điểm trung bình theo kỹ năng">
        <h2 class="mb-sm font-body-semibold text-body-semibold text-on-surface">Điểm trung bình theo kỹ năng</h2>
        <dl class="grid gap-sm sm:grid-cols-5">
            <div v-for="(value, skill) in summary.skills" :key="skill" class="rounded-lg bg-surface-container-low px-md py-sm">
                <dt class="font-body-small text-body-small text-on-surface-variant">{{ skill }}</dt>
                <dd class="font-h3 text-h3 text-on-surface">{{ score(value) }}</dd>
            </div>
        </dl>
    </section>

    <UiDataTable min-width="760px">
        <table>
            <thead>
                <tr>
                    <th>Nộp lúc</th>
                    <th>Đề</th>
                    <th>Thời gian làm</th>
                    <th class="text-right">Điểm</th>
                    <th class="text-right">Rời bài</th>
                    <th>Ghi chú</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="a in attempts.data" :key="a.id" :data-href="attemptUrl(a)">
                    <td>
                        <a :href="attemptUrl(a)" class="font-semibold text-on-surface hover:underline">{{ a.submitted_at ? formatDate(a.submitted_at, 'd/m/Y H:i') : '—' }}</a>
                    </td>
                    <td>Khối {{ a.grade }} · {{ a.term_label }}<template v-if="a.exam_label"> · {{ a.exam_label }}</template></td>
                    <td>{{ duration(a.duration_seconds) }}</td>
                    <td class="text-right">
                        <span class="font-code font-bold">{{ score(a.score) }}</span>
                        <span class="block text-xs text-on-surface-variant">Đúng {{ a.correct }}/{{ a.total }}</span>
                    </td>
                    <td class="text-right">
                        <UiBadge v-if="a.violation_count" color="error" pill>{{ a.violation_count }}</UiBadge>
                        <span v-else class="font-code text-on-surface-subtle">0</span>
                    </td>
                    <td class="text-xs text-on-surface-variant">{{ REASONS[a.submit_reason] ?? '' }}</td>
                </tr>
                <tr v-if="!attempts.data.length">
                    <td colspan="6"><UiEmptyState icon="quiz" title="Chưa có lượt luyện tập nào" description="Học viên chưa nộp bài nào phù hợp bộ lọc." /></td>
                </tr>
            </tbody>
        </table>
        <template #footer><UiPagination :paginator="attempts" unit="lượt" :options="[]" /></template>
    </UiDataTable>
</template>
