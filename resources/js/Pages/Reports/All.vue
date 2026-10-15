<script setup>
/**
 * Tổng hợp báo cáo & nhật ký toàn trung tâm (staff_report.view_all): thống kê theo loại + lọc theo loại / ngày / trạng thái kiểm tra.
 * Báo cáo định kỳ mọi vai trò nộp xong vào hàng "Chờ kiểm tra" và báo Admin (14/10/2026); Admin bấm "Đã kiểm tra".
 * Nút "Chi tiết" mở modal hiện báo cáo như màn người viết nhập (Reports/Detail, chủ dự án 15/10/2026); danh sách chỉ hiện 2 dòng tóm tắt.
 */
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { compactQuery, currentQuery } from '@/lib/url';
import { openRemoteModal } from '@/lib/remoteModal';

defineOptions({ layout: { title: 'Tổng hợp Báo cáo & Nhật ký toàn trung tâm' } });

defineProps({
    reports: { type: Object, required: true },
    stats: { type: Object, required: true },
    types: { type: Array, default: () => [] },
    canReview: { type: Boolean, default: false },
});
const date = computed(() => currentQuery().get('date'));
const review = computed(() => currentQuery().get('review') ?? '');
const reviewOptions = [
    { value: 'pending', label: 'Chờ kiểm tra' },
    { value: 'done', label: 'Đã kiểm tra' },
];
const openDetail = (r) => openRemoteModal(route('reports.detail', r.id), { size: '5xl', history: true });
const markReviewed = (r, reviewed = true) => router.post(route('reports.review', r.id), { reviewed: reviewed ? 1 : 0 }, { preserveScroll: true });

function onFilter(event) {
    router.get(route('reports.all'), compactQuery(Object.fromEntries(new FormData(event.target))), { preserveScroll: true });
}
</script>

<template>
    <UiPageHeader title="Tổng hợp Báo cáo & Nhật ký toàn trung tâm" icon="monitoring" />

    <div class="space-y-6">
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
            <Link :href="route('reports.all', { review: 'pending' })" class="block" data-pending-review>
                <UiStatCard label="Chờ Admin kiểm tra" :value="stats.pending_review" :tone="stats.pending_review ? 'warning' : undefined" />
            </Link>
            <UiStatCard label="Nhật ký" :value="stats.journal" />
            <UiStatCard label="BC Ngày" :value="stats.daily" />
            <UiStatCard label="BC Tuần" :value="stats.weekly" />
            <UiStatCard label="BC Tháng" :value="stats.monthly" />
            <UiStatCard label="Sự vụ khẩn chưa xử lý" :value="stats.urgent_open" tone="error" class="border-error/30" />
        </div>

        <form method="GET" :action="route('reports.all')" class="flex flex-wrap items-center gap-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm" @submit.prevent="onFilter">
            <div class="min-w-[180px] flex-1"><UiSelect name="type" :options="types" placeholder="Tất cả loại" aria-label="Loại báo cáo" /></div>
            <div class="min-w-[180px] flex-1"><UiSelect name="review" :options="reviewOptions" :value="review" placeholder="Mọi trạng thái kiểm tra" aria-label="Trạng thái kiểm tra" /></div>
            <div class="min-w-[160px] flex-1"><UiDate name="date" :value="date" aria-label="Ngày báo cáo" /></div>
            <UiButton type="submit">Lọc</UiButton>
            <UiButton variant="secondary" :href="route('reports.all')">Xóa lọc</UiButton>
        </form>

        <div class="divide-y divide-surface-container-highest rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
            <div v-for="r in reports.data" :key="r.id" class="flex flex-col justify-between gap-3 p-4 sm:flex-row sm:items-start" :data-report-row="r.id">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <UiBadge color="primary" pill :dot="false">{{ r.type_label }}<template v-if="r.for"> · {{ r.for }}</template></UiBadge>
                        <UiBadge v-if="r.type === 'journal'" color="neutral" pill :dot="false">{{ r.severity_label }}</UiBadge>
                        <UiBadge v-else-if="r.reviewed_at" color="success" pill>Đã kiểm tra</UiBadge>
                        <UiBadge v-else color="warning" pill>Chờ kiểm tra</UiBadge>
                        <span class="text-sm font-bold text-on-surface">{{ r.title }}</span>
                    </div>
                    <div class="mt-1 text-xs text-on-surface-subtle">
                        {{ formatDate(r.report_date) }} · <span class="font-semibold text-on-surface-variant">{{ r.user }}</span>
                        <template v-if="r.reviewable && r.submitted_at"> · nộp lúc {{ formatDate(r.submitted_at, 'H:i d/m') }}</template>
                        <template v-if="r.followups_count"> · {{ r.followups_count }} follow-up</template>
                        <template v-if="r.reviewed_at"> · {{ r.reviewed_by ?? 'Admin' }} kiểm tra lúc {{ formatDate(r.reviewed_at, 'H:i d/m') }}</template>
                    </div>
                    <p v-if="r.content" class="mt-1.5 line-clamp-2 whitespace-pre-line text-sm text-on-surface-variant">{{ r.content }}</p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    <UiButton size="sm" variant="secondary" icon="visibility" :data-report-detail-button="r.id" @click="openDetail(r)">Chi tiết</UiButton>
                    <template v-if="canReview && r.reviewable">
                        <UiButton v-if="!r.reviewed_at" size="sm" icon="fact_check" @click="markReviewed(r)">Đã kiểm tra</UiButton>
                        <UiButton v-else size="sm" variant="ghost" icon="undo" @click="markReviewed(r, false)">Bỏ đánh dấu</UiButton>
                    </template>
                </div>
            </div>
            <UiEmptyState v-if="!reports.data.length" icon="inbox" title="Không có báo cáo nào khớp bộ lọc." />
        </div>
        <UiPagination :paginator="reports" :options="[]" />
    </div>
</template>
