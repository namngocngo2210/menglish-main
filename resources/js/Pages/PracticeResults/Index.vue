<script setup>
/**
 * Kết quả luyện tập (docs/luyen-tap-ngan-hang-cau-hoi.md §7): lọc khối / kỳ / lớp / ngày nộp, thẻ tổng quan và bảng theo
 * học viên (chỉ lượt đã nộp, học viên trong phạm vi người xem) → trang một học viên → xem lại bài.
 */
import { formatNumber } from '@/lib/format';
import { route } from '@/lib/route';
import { compactQuery } from '@/lib/url';

defineOptions({ layout: { title: 'Kết quả luyện tập' } });

const props = defineProps({
    filters: { type: Object, required: true },
    filterOptions: { type: Object, required: true },
    summary: { type: Object, required: true },
    rows: { type: Object, required: true },
});

const score = (value) => (value === null || value === undefined ? '—' : formatNumber(value, 2));

/** Trang học viên mang theo bộ lọc đang xem (khối / kỳ / ngày áp dụng ở đó; lớp / tìm kiếm giữ cho nút quay lại). */
function studentUrl(row) {
    const { grade, term, from, to, class_id, q, mine } = props.filters;
    return route('practice-results.student', { student: row.student_id, ...compactQuery({ grade, term, from, to, class_id, q, mine: mine ? 1 : null }) });
}
</script>

<template>
    <UiPageHeader title="Kết quả luyện tập" description="Lượt luyện tập đã nộp của học viên trong phạm vi bạn phụ trách." />

    <UiFilterBar search="q" placeholder="Tìm tên / mã học viên…" aria-label="Lọc kết quả luyện tập">
        <UiSelect name="grade" label="Khối" :options="filterOptions.grades" placeholder="Tất cả khối" />
        <UiSelect name="term" label="Kỳ" :options="filterOptions.terms" placeholder="Tất cả kỳ" />
        <UiSelect name="class_id" label="Lớp" :options="filterOptions.classes" placeholder="Tất cả lớp" />
        <UiDateRange label="Ngày nộp" />
        <UiCheckbox name="mine" value="1" :checked="filters.mine" label="Chỉ lớp tôi dạy" class="md:self-end md:pb-sm" />
    </UiFilterBar>

    <section class="mb-lg grid gap-md sm:grid-cols-3" aria-label="Tổng quan">
        <UiStatCard label="Lượt đã nộp" :value="formatNumber(summary.attempts)" icon="quiz" tone="primary" />
        <UiStatCard label="Điểm trung bình" :value="score(summary.avg_score)" icon="grade" tone="success" hint="Thang điểm 10" />
        <UiStatCard label="Học viên đã luyện" :value="formatNumber(summary.students)" icon="groups" tone="secondary" />
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

    <UiDataTable min-width="880px">
        <table>
            <thead>
                <tr>
                    <th>Học viên</th>
                    <th>Lớp hiện tại</th>
                    <th class="text-right">Số lượt</th>
                    <th class="text-right">Điểm TB</th>
                    <th class="text-right">Điểm gần nhất</th>
                    <th>Kỹ năng yếu nhất</th>
                    <th class="text-right">Lượt có vi phạm</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows.data" :key="row.student_id" :data-href="studentUrl(row)">
                    <td>
                        <a :href="studentUrl(row)" class="font-semibold text-on-surface hover:underline">{{ row.name }}</a>
                        <span v-if="row.code" class="block font-code text-xs text-on-surface-variant">{{ row.code }}</span>
                    </td>
                    <td>{{ row.class_name ?? '—' }}</td>
                    <td class="text-right font-code">{{ formatNumber(row.attempts) }}</td>
                    <td class="text-right font-code font-bold">{{ score(row.avg_score) }}</td>
                    <td class="text-right">
                        <span class="font-code">{{ score(row.last_score) }}</span>
                        <span v-if="row.last_submitted_at" class="block text-xs text-on-surface-variant">{{ formatDate(row.last_submitted_at, 'd/m/Y H:i') }}</span>
                    </td>
                    <td>
                        <UiBadge v-if="row.weakest_skill" color="warning" pill :dot="false">{{ row.weakest_skill }}</UiBadge>
                        <span v-else class="text-on-surface-subtle">—</span>
                    </td>
                    <td class="text-right">
                        <UiBadge v-if="row.violation_attempts" color="error" pill>{{ row.violation_attempts }}</UiBadge>
                        <span v-else class="font-code text-on-surface-subtle">0</span>
                    </td>
                </tr>
                <tr v-if="!rows.data.length">
                    <td colspan="7"><UiEmptyState icon="quiz" title="Chưa có lượt luyện tập nào" description="Chưa có học viên nào nộp bài phù hợp bộ lọc." /></td>
                </tr>
            </tbody>
        </table>
        <template #footer><UiPagination :paginator="rows" unit="học viên" :options="[]" /></template>
    </UiDataTable>
</template>
