<script setup>
/**
 * Tổng hợp báo cáo & nhật ký (staff_report.view_all; Quản lý cơ sở chỉ thấy nhân sự chi nhánh mình): thống kê theo loại,
 * lọc theo loại / trạng thái kiểm tra / người / chi nhánh / khoảng ngày. Báo cáo định kỳ nộp xong vào hàng "Chờ kiểm tra";
 * Admin bấm "Đã kiểm tra" từng dòng, chọn nhiều dòng để kiểm tra hàng loạt, hoặc mở "Chi tiết" (Reports/Detail) để kiểm tra,
 * yêu cầu bổ sung và sang báo cáo kế tiếp. Xem trước là ghi chú người viết; giờ nộp là lần nộp đầu, kèm nhãn Trễ.
 * Tab "Chưa nộp": Học vụ / Học thuật còn thiếu báo cáo ngày nào (rà soát 16/10/2026 B3, B8, B11).
 */
import { computed, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { compactQuery } from '@/lib/url';
import { openRemoteModal } from '@/lib/remoteModal';

defineOptions({ layout: { title: 'Tổng hợp Báo cáo & Nhật ký' } });

const props = defineProps({
    view: { type: String, default: 'list' },
    reports: { type: Object, required: true },
    missing: { type: Object, default: null },
    stats: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    types: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    canReview: { type: Boolean, default: false },
    openId: { type: Number, default: null },
});

const reviewOptions = [
    { value: 'pending', label: 'Chờ kiểm tra' },
    { value: 'returned', label: 'Đã yêu cầu bổ sung' },
    { value: 'done', label: 'Đã kiểm tra' },
];
const openDetail = (id) => openRemoteModal(route('reports.detail', id), { size: '5xl', history: true });
const markReviewed = (r, reviewed = true) => router.post(route('reports.review', r.id), { reviewed: reviewed ? 1 : 0 }, { preserveScroll: true });
onMounted(() => props.openId && openDetail(props.openId));

// Chọn nhiều báo cáo đang chờ để "Đã kiểm tra" một lần.
const selectable = computed(() => props.reports.data.filter((r) => r.reviewable && r.status === 'submitted' && !r.reviewed_at).map((r) => r.id));
const selected = ref([]);
const allSelected = computed(() => selectable.value.length > 0 && selectable.value.every((id) => selected.value.includes(id)));
const toggleAll = () => (selected.value = allSelected.value ? [] : [...selectable.value]);
const reviewSelected = () =>
    router.post(route('reports.review-bulk'), { ids: selected.value }, { preserveScroll: true, onSuccess: () => (selected.value = []) });

const tabHref = (view) => route('reports.all', compactQuery({ view: view === 'missing' ? 'missing' : null, user_id: props.filters.user_id, branch_id: props.filters.branch_id }));

function onFilter(event) {
    const data = Object.fromEntries(new FormData(event.target));
    router.get(route('reports.all'), compactQuery({ ...data, view: props.view === 'missing' ? 'missing' : null }), { preserveScroll: true });
}
</script>

<template>
    <UiPageHeader title="Tổng hợp Báo cáo & Nhật ký" icon="monitoring" />

    <div class="space-y-6">
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-8">
            <Link :href="route('reports.all', { review: 'pending' })" class="block" data-pending-review>
                <UiStatCard label="Chờ kiểm tra" :value="stats.pending_review" :tone="stats.pending_review ? 'warning' : undefined" />
            </Link>
            <Link :href="route('reports.all', { review: 'returned' })" class="block">
                <UiStatCard label="Đã yêu cầu bổ sung" :value="stats.returned" />
            </Link>
            <Link :href="route('reports.all', { view: 'missing' })" class="block" data-missing-yesterday>
                <UiStatCard label="Hôm qua chưa nộp" :value="stats.missing_yesterday" :tone="stats.missing_yesterday ? 'error' : undefined" />
            </Link>
            <UiStatCard label="Nhật ký" :value="stats.journal" />
            <UiStatCard label="BC Ngày" :value="stats.daily" />
            <UiStatCard label="BC Tuần" :value="stats.weekly" />
            <UiStatCard label="BC Tháng" :value="stats.monthly" />
            <UiStatCard label="Sự vụ khẩn chưa xử lý" :value="stats.urgent_open" tone="error" class="border-error/30" />
        </div>

        <nav class="flex gap-2 border-b border-surface-container-highest" aria-label="Chế độ xem">
            <Link
                v-for="t in [{ key: 'list', label: 'Báo cáo đã nộp' }, { key: 'missing', label: 'Chưa nộp báo cáo ngày' }]"
                :key="t.key"
                :href="tabHref(t.key)"
                :class="['-mb-px border-b-2 px-3 py-2 text-sm font-semibold', view === t.key ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface']"
                :data-report-tab="t.key"
            >{{ t.label }}</Link>
        </nav>

        <form method="GET" :action="route('reports.all')" class="grid grid-cols-1 gap-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="onFilter">
            <template v-if="view === 'list'">
                <UiSelect name="type" :options="types" :value="filters.type" placeholder="Tất cả loại" aria-label="Loại báo cáo" />
                <UiSelect name="review" :options="reviewOptions" :value="filters.review" placeholder="Mọi trạng thái kiểm tra" aria-label="Trạng thái kiểm tra" />
            </template>
            <UiSelect name="user_id" :options="users" :value="filters.user_id" placeholder="Mọi người viết" aria-label="Người viết" :searchable="users.length > 8" />
            <UiSelect v-if="branches.length > 1" name="branch_id" :options="branches" :value="filters.branch_id" placeholder="Mọi chi nhánh" aria-label="Chi nhánh" />
            <UiDate name="from" :value="filters.from ?? missing?.from" aria-label="Từ ngày" inline-label="Từ" />
            <UiDate name="to" :value="filters.to ?? missing?.to" aria-label="Đến ngày" inline-label="Đến" />
            <div class="flex gap-2 sm:col-span-2 lg:col-span-2">
                <UiButton type="submit">Lọc</UiButton>
                <UiButton variant="secondary" :href="tabHref(view)">Xóa lọc</UiButton>
            </div>
        </form>

        <!-- Chưa nộp: người × ngày còn thiếu báo cáo ngày -->
        <section v-if="view === 'missing'" class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm" data-missing-reports>
            <p class="border-b border-surface-container-highest px-4 py-3 text-xs text-on-surface-variant">
                Báo cáo ngày còn thiếu từ {{ formatDate(missing.from) }} đến {{ formatDate(missing.to) }}. Không tính Chủ nhật, ngày lễ và ngày nghỉ phép đã duyệt; hạn nộp 09:00 sáng hôm sau.
            </p>
            <ul class="divide-y divide-surface-container-highest">
                <li v-for="row in missing.rows" :key="row.user_id" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between" :data-missing-user="row.user_id">
                    <div>
                        <p class="text-sm font-bold text-on-surface">{{ row.user }}</p>
                        <p class="text-xs text-on-surface-variant">{{ row.for }}<template v-if="row.branch"> · {{ row.branch }}</template> · thiếu {{ row.days.length }} ngày</p>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <UiBadge v-for="d in row.days" :key="d.date" color="error" :dot="false">{{ d.label }}</UiBadge>
                    </div>
                </li>
            </ul>
            <UiEmptyState v-if="!missing.rows.length" icon="task_alt" title="Mọi người đã nộp đủ báo cáo ngày trong khoảng này." />
        </section>

        <template v-else>
            <div v-if="canReview && selectable.length" class="flex flex-wrap items-center gap-3 rounded-xl bg-surface-container-low px-4 py-2 text-sm" data-bulk-review>
                <label class="flex cursor-pointer items-center gap-2 font-semibold text-on-surface">
                    <input type="checkbox" class="rounded border-outline-variant text-primary focus:ring-primary-container" :checked="allSelected" @change="toggleAll" />
                    Chọn tất cả báo cáo chờ kiểm tra trên trang
                </label>
                <UiButton v-if="selected.length" size="sm" icon="fact_check" data-bulk-review-submit @click="reviewSelected">Đánh dấu đã kiểm tra ({{ selected.length }})</UiButton>
            </div>

            <div class="divide-y divide-surface-container-highest rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
                <div v-for="r in reports.data" :key="r.id" class="flex gap-3 p-4" :data-report-row="r.id">
                    <input
                        v-if="canReview && selectable.includes(r.id)"
                        v-model="selected"
                        type="checkbox"
                        :value="r.id"
                        class="mt-1 shrink-0 rounded border-outline-variant text-primary focus:ring-primary-container"
                        :aria-label="`Chọn ${r.title}`"
                    />
                    <span v-else-if="canReview && selectable.length" class="w-4 shrink-0" aria-hidden="true" />
                    <div class="flex min-w-0 flex-1 flex-col justify-between gap-3 sm:flex-row sm:items-start">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <UiBadge color="primary" pill :dot="false">{{ r.type_label }}<template v-if="r.for"> · {{ r.for }}</template></UiBadge>
                                <UiBadge v-if="r.type === 'journal'" color="neutral" pill :dot="false">{{ r.severity_label }}</UiBadge>
                                <UiBadge v-else-if="r.status === 'returned'" color="warning" pill>Đã yêu cầu bổ sung</UiBadge>
                                <UiBadge v-else-if="r.reviewed_at" color="success" pill>Đã kiểm tra</UiBadge>
                                <UiBadge v-else color="warning" pill>Chờ kiểm tra</UiBadge>
                                <UiBadge v-if="r.late" color="error" pill :dot="false" data-late>Trễ</UiBadge>
                                <button type="button" class="text-left text-sm font-bold text-on-surface hover:text-primary" @click="openDetail(r.id)">{{ r.title }}</button>
                            </div>
                            <div class="mt-1 text-xs text-on-surface-subtle">
                                {{ formatDate(r.report_date) }} · <span class="font-semibold text-on-surface-variant">{{ r.user }}</span>
                                <template v-if="r.branch"> · {{ r.branch }}</template>
                                <template v-if="r.reviewable && r.submitted_at"> · nộp lúc {{ formatDate(r.submitted_at, 'H:i d/m') }}</template>
                                <template v-if="r.reviewable && r.updated_at"> · sửa lúc {{ formatDate(r.updated_at, 'H:i d/m') }}</template>
                                <template v-if="r.followups_count"> · {{ r.followups_count }} follow-up</template>
                                <template v-if="r.reviewed_at"> · {{ r.reviewed_by ?? 'Admin' }} kiểm tra lúc {{ formatDate(r.reviewed_at, 'H:i d/m') }}</template>
                            </div>
                            <p v-if="r.preview" class="mt-1.5 line-clamp-2 text-sm text-on-surface-variant" data-report-preview>{{ r.preview }}</p>
                            <p v-if="r.status === 'returned' && r.review_note" class="mt-1 line-clamp-2 text-xs text-warning">Đã yêu cầu: {{ r.review_note }}</p>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2">
                            <UiButton size="sm" variant="secondary" icon="visibility" :data-report-detail-button="r.id" @click="openDetail(r.id)">Chi tiết</UiButton>
                            <template v-if="canReview && r.reviewable">
                                <UiButton v-if="!r.reviewed_at && r.status === 'submitted'" size="sm" icon="fact_check" @click="markReviewed(r)">Đã kiểm tra</UiButton>
                                <UiButton v-else-if="r.reviewed_at" size="sm" variant="ghost" icon="undo" @click="markReviewed(r, false)">Bỏ đánh dấu</UiButton>
                            </template>
                        </div>
                    </div>
                </div>
                <UiEmptyState v-if="!reports.data.length" icon="inbox" title="Không có báo cáo nào khớp bộ lọc." />
            </div>
            <UiPagination :paginator="reports" :options="[]" />
        </template>
    </div>
</template>
