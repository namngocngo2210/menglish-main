<script setup>
/** Tổng hợp báo cáo & nhật ký toàn trung tâm (staff_report.view_all): thống kê theo loại + lọc theo loại / ngày. */
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { compactQuery, currentQuery } from '@/lib/url';

defineOptions({ layout: { title: 'Tổng hợp Báo cáo & Nhật ký toàn trung tâm' } });

defineProps({
    reports: { type: Object, required: true },
    stats: { type: Object, required: true },
    types: { type: Array, default: () => [] },
});
const date = computed(() => currentQuery().get('date'));

function onFilter(event) {
    router.get(route('reports.all'), compactQuery(Object.fromEntries(new FormData(event.target))), { preserveScroll: true });
}
</script>

<template>
    <UiPageHeader title="Tổng hợp Báo cáo & Nhật ký toàn trung tâm" icon="monitoring" />

    <div class="space-y-6">
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
            <UiStatCard label="Nhật ký" :value="stats.journal" />
            <UiStatCard label="BC Ngày" :value="stats.daily" />
            <UiStatCard label="BC Tuần" :value="stats.weekly" />
            <UiStatCard label="BC Tháng" :value="stats.monthly" />
            <UiStatCard label="Sự vụ khẩn chưa xử lý" :value="stats.urgent_open" tone="error" class="border-error/30" />
        </div>

        <form method="GET" :action="route('reports.all')" class="flex flex-wrap items-center gap-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm" @submit.prevent="onFilter">
            <UiSelect name="type" :options="types" placeholder="Tất cả loại" aria-label="Loại báo cáo" />
            <UiDate name="date" :value="date" aria-label="Ngày báo cáo" />
            <UiButton type="submit">Lọc</UiButton>
            <UiButton variant="secondary" :href="route('reports.all')">Xóa lọc</UiButton>
        </form>

        <div class="divide-y divide-surface-container-highest rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
            <div v-for="r in reports.data" :key="r.id" class="flex items-start justify-between gap-3 p-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <UiBadge color="primary" pill :dot="false">{{ r.type_label }}</UiBadge>
                        <UiBadge v-if="r.type === 'journal'" color="neutral" pill :dot="false">{{ r.severity_label }}</UiBadge>
                        <span class="text-sm font-bold text-on-surface">{{ r.title }}</span>
                    </div>
                    <div class="mt-1 text-xs text-on-surface-subtle">
                        {{ formatDate(r.report_date) }} · <span class="font-semibold text-on-surface-variant">{{ r.user }}</span>
                        <template v-if="r.followups_count"> · {{ r.followups_count }} follow-up</template>
                    </div>
                    <p v-if="r.content" class="mt-1.5 line-clamp-2 text-sm text-on-surface-variant">{{ r.content }}</p>
                </div>
            </div>
            <UiEmptyState v-if="!reports.data.length" icon="inbox" title="Không có báo cáo nào khớp bộ lọc." />
        </div>
        <UiPagination :paginator="reports" :options="[]" />
    </div>
</template>
