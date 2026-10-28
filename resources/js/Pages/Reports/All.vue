<script setup>
/**
 * Tổng hợp báo cáo & nhật ký (staff_report.view_all; Quản lý cơ sở chỉ thấy nhân sự chi nhánh mình).
 * Thiết kế lại 27/10/2026 cho gọn: một hàng tab gộp trạng thái kiểm tra + "Chưa nộp" + "Việc follow-up", số cần xử lý gắn ngay trên tab
 * (bỏ 8 ô số liệu); sự vụ khẩn chưa xử lý thành dải cảnh báo, chỉ hiện khi có; bộ lọc một dòng, chọn là lọc ngay
 * (loại báo cáo kèm số đếm, người viết, chi nhánh, khoảng ngày chọn nhanh); trên điện thoại bộ lọc mở thành bảng trượt.
 * Mặc định mở tab "Chờ kiểm tra". Bấm dòng mở "Chi tiết" (Reports/Detail) để kiểm tra, yêu cầu bổ sung, sang báo cáo kế tiếp;
 * "Đã kiểm tra" từng dòng hoặc chọn nhiều dòng rồi đánh dấu một lần. Giờ nộp là lần nộp đầu, kèm nhãn Trễ.
 * Tab "Chưa nộp": Học vụ / Học thuật còn thiếu báo cáo ngày nào (rà soát 16/10/2026 B3, B8, B11).
 * Tab "Việc follow-up" (chủ dự án 17/10/2026): task tạo từ báo cáo (cả báo cáo trực lớp, cả task Admin giao trên báo cáo) theo người viết:
 * đã tạo / đã xong / đang mở / quá hạn trong khoảng ngày tạo (mặc định tháng này), bấm "Xem việc" mở danh sách việc của người đó.
 */
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { compactQuery } from '@/lib/url';
import { openRemoteModal } from '@/lib/remoteModal';

defineOptions({ layout: { title: 'Tổng hợp Báo cáo & Nhật ký' } });

const props = defineProps({
    view: { type: String, default: 'list' },
    reports: { type: Object, required: true },
    missing: { type: Object, default: null },
    followUps: { type: Object, default: null },
    stats: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    types: { type: Array, default: () => [] },
    typeCounts: { type: Object, default: () => ({}) },
    today: { type: String, default: null },
    users: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    canReview: { type: Boolean, default: false },
    openId: { type: Number, default: null },
});

const openDetail = (id) => openRemoteModal(route('reports.detail', id), { size: '7xl', history: true });
const markReviewed = (r, reviewed = true) => router.post(route('reports.review', r.id), { reviewed: reviewed ? 1 : 0 }, { preserveScroll: true });
onMounted(() => props.openId && openDetail(props.openId));

// ── Tab: trạng thái kiểm tra (danh sách) + Chưa nộp + Việc follow-up ──
const review = computed(() => props.filters.review ?? 'pending');
const current = computed(() => (props.view === 'list' ? review.value : props.view));
const tabs = computed(() => [
    { key: 'pending', label: 'Chờ kiểm tra', count: props.stats.pending_review, tone: 'primary' },
    { key: 'returned', label: 'Đã yêu cầu bổ sung', count: props.stats.returned, tone: 'warning' },
    { key: 'done', label: 'Đã kiểm tra' },
    { key: 'all', label: 'Tất cả' },
    { key: 'missing', label: 'Chưa nộp báo cáo ngày', short: 'Chưa nộp', count: props.stats.missing_yesterday, tone: 'error', divider: true },
    { key: 'followups', label: 'Việc follow-up', short: 'Follow-up', count: props.stats.followups_overdue, suffix: ' quá hạn', tone: 'warning' },
]);
const countTone = {
    primary: 'bg-primary-container text-on-primary',
    warning: 'bg-warning-container text-on-warning-container',
    error: 'bg-error text-on-error',
};
const base = computed(() => ({ user_id: props.filters.user_id, branch_id: props.filters.branch_id }));
const tabHref = (key) =>
    route('reports.all', compactQuery(['missing', 'followups'].includes(key) ? { view: key, ...base.value } : { review: key === 'pending' ? null : key, type: props.filters.type, role: props.filters.role, ...(props.view === 'list' ? { from: props.filters.from, to: props.filters.to } : {}), ...base.value }));

// ── Bộ lọc: chọn là lọc ngay ──
function apply(changes) {
    const query = { ...props.filters, ...changes, view: props.view === 'list' ? null : props.view };
    if (props.view !== 'list') Object.assign(query, { review: null, type: null, role: null });
    else if (query.review === 'pending') query.review = null;
    router.get(route('reports.all'), compactQuery(query), { preserveState: true, preserveScroll: true });
}
const hasFilters = computed(() => Boolean(props.filters.type || props.filters.role || props.filters.user_id || props.filters.branch_id || props.filters.from || props.filters.to));
const clearFilters = () => router.get(route('reports.all', compactQuery(props.view === 'list' ? { review: review.value === 'pending' ? null : review.value } : { view: props.view })));

// Loại báo cáo kèm số đếm trong tab hiện tại; "Nhật ký" chỉ có ở tab Tất cả (nhật ký sự vụ không qua kiểm tra).
const typeShort = { daily: 'Ngày', weekly: 'Tuần', monthly: 'Tháng', quarterly: 'Quý', journal: 'Nhật ký' };
const typeChips = computed(() => {
    const keys = Object.keys(typeShort).filter((k) => (review.value === 'all' || k !== 'journal') && (k !== 'quarterly' || props.typeCounts.quarterly));
    const total = Object.values(props.typeCounts).reduce((a, b) => a + b, 0);
    return [{ value: null, label: 'Tất cả', count: total }, ...keys.map((k) => ({ value: k, label: typeShort[k], count: props.typeCounts[k] ?? 0 }))];
});
const typeTag = {
    daily: 'bg-tertiary/10 text-tertiary',
    weekly: 'bg-info-container text-on-info-container',
    monthly: 'bg-accent-container text-on-accent-container',
    quarterly: 'bg-secondary-fixed text-on-secondary-fixed-variant',
    journal: 'bg-on-surface-variant/10 text-on-surface-variant',
};

// Khoảng ngày chọn nhanh. Tab "Chưa nộp" tính đến hôm qua (hôm nay chưa tới hạn nộp), các tab khác đến hôm nay.
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
function presetRange(key) {
    const [y, m, d] = (props.today ?? iso(new Date())).split('-').map(Number);
    const today = new Date(y, m - 1, d);
    const end = new Date(today);
    if (props.view === 'missing') end.setDate(end.getDate() - 1);
    const days = (n) => new Date(end.getFullYear(), end.getMonth(), end.getDate() - n + 1);
    return {
        yesterday: [new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1), new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1)],
        '7d': [days(7), end],
        '30d': [days(30), end],
        month: [new Date(today.getFullYear(), today.getMonth(), 1), end],
        last_month: [new Date(today.getFullYear(), today.getMonth() - 1, 1), new Date(today.getFullYear(), today.getMonth(), 0)],
    }[key]?.map(iso);
}
const presets = computed(() => [
    ...(props.view === 'list' ? [{ value: '', label: 'Mọi thời gian' }] : []),
    { value: 'yesterday', label: 'Hôm qua' },
    { value: '7d', label: '7 ngày qua' },
    { value: '30d', label: '30 ngày qua' },
    { value: 'month', label: 'Tháng này' },
    { value: 'last_month', label: 'Tháng trước' },
    { value: 'custom', label: 'Tự chọn ngày…' },
]);
const range = computed(() => props.missing ?? props.followUps);
const from = computed(() => props.filters.from ?? range.value?.from ?? null);
const to = computed(() => props.filters.to ?? range.value?.to ?? null);
const customOpen = ref(false);
const preset = computed(() => {
    if (customOpen.value) return 'custom';
    if (!from.value && !to.value) return '';
    const hit = presets.value.find((p) => p.value && p.value !== 'custom' && String(presetRange(p.value)) === `${from.value},${to.value}`);
    return hit ? hit.value : 'custom';
});
function choosePreset(value) {
    if (value === 'custom') return (customOpen.value = true);
    customOpen.value = false;
    const [f, t] = presetRange(value) ?? [null, null];
    apply({ from: f, to: t, date: null });
}

const sheetOpen = ref(false);
const activeCount = computed(() => [props.filters.type, props.filters.role, props.filters.user_id, props.filters.branch_id].filter(Boolean).length);

// ── Chọn nhiều báo cáo đang chờ để "Đã kiểm tra" một lần ──
const selectable = computed(() => props.reports.data.filter((r) => r.reviewable && r.status === 'submitted' && !r.reviewed_at).map((r) => r.id));
const selected = ref([]);
const allSelected = computed(() => selectable.value.length > 0 && selectable.value.every((id) => selected.value.includes(id)));
const someSelected = computed(() => selected.value.length > 0 && !allSelected.value);
const toggleAll = () => (selected.value = allSelected.value ? [] : [...selectable.value]);
const reviewSelected = () =>
    router.post(route('reports.review-bulk'), { ids: selected.value }, { preserveScroll: true, onSuccess: () => (selected.value = []) });

// Việc follow-up: mở danh sách việc của từng người viết.
const opened = ref(null);
const toggleWriter = (id) => (opened.value = opened.value === id ? null : id);
const taskColor = (t) => (t.overdue ? 'error' : t.status === 'completed' ? 'success' : t.status === 'canceled' ? 'neutral' : 'info');
const pct = (n, total) => (total ? `${(n / total) * 100}%` : '0%');
</script>

<template>
    <Head title="Tổng hợp Báo cáo & Nhật ký" />

    <div class="space-y-4">
        <!-- Sự vụ khẩn: chỉ hiện khi còn sự vụ chưa xử lý -->
        <Link
            v-if="stats.urgent_open"
            :href="route('reports.journal', { severity: 'urgent' })"
            class="flex items-center gap-3 rounded-xl bg-error-container px-4 py-2.5 text-sm font-semibold text-on-error-container hover:bg-error-container/80"
            data-urgent-open
        >
            <span class="material-symbols-outlined text-[20px]" style="font-variation-settings: 'FILL' 1" aria-hidden="true">emergency_home</span>
            <span class="flex-1">{{ stats.urgent_open }} sự vụ khẩn chưa xử lý</span>
            <span class="underline">Xem sự vụ</span>
        </Link>

        <!-- Một hàng tab -->
        <nav class="-mx-4 flex gap-2 overflow-x-auto px-4 md:mx-0 md:gap-1 md:border-b md:border-surface-container-highest md:px-0" aria-label="Chế độ xem">
            <template v-for="t in tabs" :key="t.key">
                <span v-if="t.divider" class="mx-2 hidden h-5 w-px self-center bg-surface-container-highest md:block" aria-hidden="true" />
                <Link
                    :href="tabHref(t.key)"
                    :class="[
                        'flex shrink-0 items-center gap-2 whitespace-nowrap text-sm font-semibold',
                        'h-9 rounded-full border px-3 md:-mb-px md:h-auto md:rounded-none md:border-0 md:border-b-2 md:px-3 md:py-2.5',
                        current === t.key
                            ? 'border-navy-800 bg-navy-800 text-white md:border-primary-container md:bg-transparent md:text-on-surface'
                            : 'border-surface-container-highest bg-surface-container-lowest text-on-surface-variant hover:text-on-surface md:border-transparent md:bg-transparent',
                    ]"
                    :aria-current="current === t.key ? 'page' : null"
                    :data-report-tab="t.key"
                >
                    <span class="md:hidden">{{ t.short ?? t.label }}</span><span class="hidden md:inline">{{ t.label }}</span>
                    <span v-if="t.count" :class="['inline-grid h-5 min-w-5 place-items-center rounded-full px-1.5 text-xs font-bold', countTone[t.tone]]">{{ t.count }}<span class="hidden md:inline">{{ t.suffix }}</span></span>
                </Link>
            </template>
        </nav>

        <!-- Bộ lọc: máy tính một dòng -->
        <div class="hidden flex-wrap items-center gap-2.5 md:flex" data-report-filters>
            <div v-if="view === 'list'" class="flex rounded-xl border border-surface-container-highest bg-surface-container-lowest p-[3px]" role="group" aria-label="Loại báo cáo">
                <button
                    v-for="c in typeChips"
                    :key="c.label"
                    type="button"
                    :class="[
                        'flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[13px] font-semibold',
                        (filters.type ?? null) === c.value ? 'bg-primary-light text-primary' : c.count ? 'text-on-surface-variant hover:text-on-surface' : 'text-on-surface-subtle',
                    ]"
                    :aria-pressed="(filters.type ?? null) === c.value"
                    :data-report-type="c.value ?? 'all'"
                    @click="apply({ type: c.value })"
                >
                    {{ c.label }} <span class="font-semibold opacity-80">{{ c.count }}</span>
                </button>
            </div>
            <div class="flex-1" />
            <UiSelect v-if="view === 'list' && roles.length > 1" class="!w-40" :options="roles" :value="filters.role" placeholder="Mọi vai trò" aria-label="Vai trò" data-role-filter @change="apply({ role: $event.target.value || null })" />
            <UiSelect class="!w-48" :options="users" :value="filters.user_id" placeholder="Mọi người viết" aria-label="Người viết" :searchable="users.length > 8" @change="apply({ user_id: $event.target.value || null })" />
            <UiSelect v-if="branches.length > 1" class="!w-40" :options="branches" :value="filters.branch_id" placeholder="Mọi chi nhánh" aria-label="Chi nhánh" @change="apply({ branch_id: $event.target.value || null })" />
            <UiSelect class="!w-40" :options="presets" :value="preset" :placeholder="null" aria-label="Khoảng ngày" data-date-preset @change="choosePreset($event.target.value)" />
            <template v-if="preset === 'custom'">
                <UiDate :value="from" aria-label="Từ ngày" class="!w-40" @change="apply({ from: $event.target.value || null, date: null })" />
                <span class="text-on-surface-subtle">–</span>
                <UiDate :value="to" aria-label="Đến ngày" class="!w-40" @change="apply({ to: $event.target.value || null, date: null })" />
            </template>
            <button v-if="hasFilters" type="button" class="px-1 text-[13px] font-semibold text-primary hover:underline" @click="clearFilters">Xóa lọc</button>
        </div>

        <!-- Bộ lọc: điện thoại -->
        <div class="flex gap-2 md:hidden">
            <UiSelect class="flex-1" :options="presets" :value="preset" :placeholder="null" aria-label="Khoảng ngày" @change="choosePreset($event.target.value)" />
            <button
                type="button"
                :class="['flex shrink-0 items-center gap-1.5 rounded-xl border px-3 text-[13px] font-semibold', activeCount ? 'border-primary-fixed bg-primary-light text-primary' : 'border-surface-container-highest bg-surface-container-lowest text-on-surface']"
                data-filter-sheet-open
                @click="sheetOpen = true"
            >
                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">tune</span>Bộ lọc<template v-if="activeCount"> · {{ activeCount }}</template>
            </button>
        </div>
        <div v-if="preset === 'custom'" class="flex items-center gap-2 md:hidden">
            <UiDate :value="from" aria-label="Từ ngày" @change="apply({ from: $event.target.value || null, date: null })" />
            <span class="text-on-surface-subtle">–</span>
            <UiDate :value="to" aria-label="Đến ngày" @change="apply({ to: $event.target.value || null, date: null })" />
        </div>

        <Teleport to="body">
            <div v-if="sheetOpen" class="fixed inset-0 z-50 md:hidden" role="dialog" aria-modal="true" aria-label="Bộ lọc" data-filter-sheet>
                <div class="absolute inset-0 bg-navy-900/45" @click="sheetOpen = false" />
                <div class="absolute inset-x-0 bottom-0 max-h-[85vh] overflow-y-auto rounded-t-2xl bg-surface-container-lowest px-4 pb-6 pt-2">
                    <div class="mx-auto mb-3 mt-1 h-1 w-10 rounded bg-surface-container-highest" />
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-base font-bold text-on-surface">Bộ lọc</h3>
                        <button v-if="hasFilters" type="button" class="text-[13px] font-semibold text-primary" @click="clearFilters">Xóa lọc</button>
                    </div>
                    <div v-if="view === 'list'" class="mb-4">
                        <p class="mb-1.5 text-[13px] font-semibold text-on-surface-variant">Loại báo cáo</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="c in typeChips"
                                :key="c.label"
                                type="button"
                                :class="['h-9 rounded-full border px-3 text-[13px] font-semibold', (filters.type ?? null) === c.value ? 'border-primary-fixed bg-primary-light text-primary' : 'border-surface-container-highest text-on-surface-variant']"
                                @click="apply({ type: c.value })"
                            >{{ c.label }} {{ c.count }}</button>
                        </div>
                    </div>
                    <div v-if="view === 'list' && roles.length > 1" class="mb-4">
                        <p class="mb-1.5 text-[13px] font-semibold text-on-surface-variant">Vai trò</p>
                        <UiSelect :options="roles" :value="filters.role" placeholder="Mọi vai trò" aria-label="Vai trò" @change="apply({ role: $event.target.value || null })" />
                    </div>
                    <div class="mb-4">
                        <p class="mb-1.5 text-[13px] font-semibold text-on-surface-variant">Người viết</p>
                        <UiSelect :options="users" :value="filters.user_id" placeholder="Mọi người viết" aria-label="Người viết" @change="apply({ user_id: $event.target.value || null })" />
                    </div>
                    <div v-if="branches.length > 1" class="mb-4">
                        <p class="mb-1.5 text-[13px] font-semibold text-on-surface-variant">Chi nhánh</p>
                        <UiSelect :options="branches" :value="filters.branch_id" placeholder="Mọi chi nhánh" aria-label="Chi nhánh" @change="apply({ branch_id: $event.target.value || null })" />
                    </div>
                    <UiButton class="mt-2 w-full justify-center" @click="sheetOpen = false">
                        <template v-if="view === 'list'">Xem {{ reports.total }} báo cáo</template><template v-else>Xong</template>
                    </UiButton>
                </div>
            </div>
        </Teleport>

        <!-- Chưa nộp: người × ngày còn thiếu báo cáo ngày -->
        <section v-if="view === 'missing'" class="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm" data-missing-reports>
            <p class="flex items-start gap-1.5 border-b border-surface-container-highest bg-surface-container-low/50 px-4 py-2.5 text-xs text-on-surface-variant">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">info</span>
                <span>Từ {{ formatDate(missing.from) }} đến {{ formatDate(missing.to) }}. Hạn nộp 09:00 sáng hôm sau; không tính Chủ nhật, ngày lễ và ngày nghỉ phép đã duyệt.</span>
            </p>
            <ul class="divide-y divide-surface-container-highest">
                <li v-for="row in missing.rows" :key="row.user_id" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:gap-4" :data-missing-user="row.user_id">
                    <div class="sm:w-64 sm:shrink-0">
                        <p class="text-sm font-semibold text-on-surface">{{ row.user }}</p>
                        <p class="text-xs text-on-surface-subtle">{{ row.for }}<template v-if="row.branch"> · {{ row.branch }}</template></p>
                    </div>
                    <div class="flex flex-1 flex-wrap gap-1.5">
                        <UiBadge v-for="d in row.days" :key="d.date" color="error" :dot="false">{{ d.label }}</UiBadge>
                    </div>
                    <p class="shrink-0 text-xs text-on-surface-subtle">thiếu <b class="text-error">{{ row.days.length }}</b> ngày</p>
                </li>
            </ul>
            <UiEmptyState v-if="!missing.rows.length" icon="task_alt" title="Mọi người đã nộp đủ báo cáo ngày trong khoảng này." />
        </section>

        <!-- Việc follow-up từ báo cáo: theo người viết -->
        <section v-else-if="view === 'followups'" class="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm" data-follow-up-summary>
            <p class="border-b border-surface-container-highest bg-surface-container-low/50 px-4 py-2.5 text-xs text-on-surface-variant">
                Task tạo từ báo cáo định kỳ và báo cáo trực lớp (kể cả task Admin giao trên báo cáo), từ {{ formatDate(followUps.from) }} đến {{ formatDate(followUps.to) }}.
                Quá hạn: đã qua hạn xử lý mà chưa xong.
            </p>
            <ul v-if="followUps.rows.length" class="divide-y divide-surface-container-highest">
                <li v-for="row in followUps.rows" :key="row.user_id" :data-follow-up-writer="row.user_id">
                    <button type="button" class="flex w-full flex-col gap-2 px-4 py-3 text-left hover:bg-surface-container-low/50 sm:flex-row sm:items-center sm:gap-6" :aria-expanded="opened === row.user_id" @click="toggleWriter(row.user_id)">
                        <div class="min-w-0 sm:w-64 sm:shrink-0">
                            <p class="text-sm font-semibold text-on-surface">{{ row.user }}</p>
                            <p class="text-xs text-on-surface-subtle">{{ [row.role, row.branch].filter(Boolean).join(' · ') }}</p>
                        </div>
                        <p class="text-sm text-on-surface-variant sm:w-24 sm:shrink-0"><b class="tabular-nums text-on-surface" data-follow-up-total>{{ row.total }}</b> việc</p>
                        <div class="flex-1">
                            <div class="flex h-2 w-full max-w-xs overflow-hidden rounded-full bg-surface-container-high">
                                <i class="block bg-tertiary" :style="{ width: pct(row.done, row.total) }" />
                                <i class="block bg-on-surface-variant/30" :style="{ width: pct(row.open - row.overdue, row.total) }" />
                                <i class="block bg-error" :style="{ width: pct(row.overdue, row.total) }" />
                            </div>
                            <p class="mt-1 text-xs text-on-surface-subtle">{{ row.done }} xong · {{ row.open }} đang mở</p>
                        </div>
                        <span class="flex shrink-0 items-center gap-2">
                            <UiBadge :color="row.overdue ? 'error' : 'success'" :dot="false" data-follow-up-overdue>{{ row.overdue ? row.overdue + ' quá hạn' : 'Không quá hạn' }}</UiBadge>
                            <span class="material-symbols-outlined text-[20px] text-on-surface-subtle" aria-hidden="true">{{ opened === row.user_id ? 'expand_less' : 'expand_more' }}</span>
                        </span>
                    </button>
                    <div v-if="opened === row.user_id" class="bg-surface-container-low px-4 py-3">
                        <ul class="space-y-xs" :data-follow-up-tasks="row.user_id">
                            <li v-for="t in row.tasks" :key="t.id" class="flex flex-wrap items-center gap-x-sm gap-y-0.5 text-sm">
                                <strong class="text-on-surface">{{ t.title }}</strong>
                                <span class="text-on-surface-variant">· {{ t.assignee ?? '—' }} · hạn {{ t.due_date ? formatDate(t.due_date, 'd/m/Y') : '—' }}<template v-if="t.by"> · giao bởi {{ t.by }}</template></span>
                                <UiBadge :color="taskColor(t)">{{ t.overdue && t.status !== 'overdue' ? 'Quá hạn' : t.status_label }}</UiBadge>
                                <span class="basis-full text-xs text-on-surface-subtle">{{ t.source }}</span>
                            </li>
                        </ul>
                        <p v-if="row.total > row.tasks.length" class="mt-xs text-xs text-on-surface-variant">Hiện {{ row.tasks.length }} / {{ row.total }} việc, quá hạn và đang mở trước.</p>
                    </div>
                </li>
            </ul>
            <UiEmptyState v-else icon="task_alt" title="Chưa có task nào tạo từ báo cáo trong khoảng này." />
        </section>

        <!-- Danh sách báo cáo -->
        <template v-else>
            <div class="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm max-md:border-0 max-md:bg-transparent max-md:shadow-none">
                <!-- Hàng tiêu đề cột (máy tính) kiêm "chọn tất cả" -->
                <div class="grid grid-cols-[1rem_8.5rem_minmax(0,1fr)_13rem_7.5rem_16rem] items-center gap-4 border-b border-surface-container-highest bg-surface-container-low/50 px-4 py-2.5 text-xs font-semibold text-on-surface-variant max-md:hidden">
                    <input
                        v-if="canReview && selectable.length"
                        type="checkbox"
                        class="rounded border-outline-variant text-primary-container focus:ring-primary-container"
                        :checked="allSelected"
                        :indeterminate="someSelected"
                        :aria-label="`Chọn tất cả ${selectable.length} báo cáo chờ kiểm tra trên trang`"
                        data-bulk-review
                        @change="toggleAll"
                    />
                    <span v-else />
                    <span>Loại</span>
                    <span>Báo cáo</span>
                    <span>Người nộp</span>
                    <span>Nộp lúc</span>
                    <span />
                </div>
                <label v-if="canReview && selectable.length" class="mb-2 flex cursor-pointer items-center gap-3 rounded-xl border border-surface-container-highest bg-surface-container-low/50 px-4 py-2.5 text-[13px] font-semibold text-on-surface-variant md:hidden">
                    <input type="checkbox" class="rounded border-outline-variant text-primary-container focus:ring-primary-container" :checked="allSelected" :indeterminate="someSelected" @change="toggleAll" />
                    Chọn tất cả {{ selectable.length }} báo cáo chờ kiểm tra
                </label>

                <div class="divide-y divide-surface-container-highest max-md:space-y-2.5 max-md:divide-y-0">
                    <div
                        v-for="r in reports.data"
                        :key="r.id"
                        :class="[
                            'cursor-pointer px-4 py-3 hover:bg-surface-container-low/40 md:grid md:grid-cols-[1rem_8.5rem_minmax(0,1fr)_13rem_7.5rem_16rem] md:items-center md:gap-4',
                            'max-md:flex max-md:gap-3 max-md:rounded-2xl max-md:border max-md:bg-surface-container-lowest max-md:p-3',
                            selected.includes(r.id) ? 'bg-primary-light/50 max-md:border-primary-fixed max-md:bg-primary-light/50' : 'max-md:border-surface-container-highest',
                        ]"
                        :data-report-row="r.id"
                        @click="openDetail(r.id)"
                    >
                        <input
                            v-if="canReview && selectable.includes(r.id)"
                            v-model="selected"
                            type="checkbox"
                            :value="r.id"
                            class="shrink-0 rounded border-outline-variant text-primary-container focus:ring-primary-container max-md:mt-1"
                            :aria-label="`Chọn ${r.title}`"
                            @click.stop
                        />
                        <span v-else class="w-4 shrink-0" aria-hidden="true" />

                        <!-- Điện thoại: thẻ gộp; máy tính: các ô dưới đây thành cột nhờ md:contents -->
                        <div class="min-w-0 flex-1 max-md:space-y-1 md:contents">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span :class="['rounded-md px-2 py-0.5 text-xs font-semibold', typeTag[r.type] ?? typeTag.journal]" data-report-type-cell>
                                    {{ typeShort[r.type] ?? r.type_label }}<template v-if="r.type === 'journal' && r.severity_label"> · {{ r.severity_label }}</template><template v-else-if="r.for"> · {{ r.for }}</template>
                                </span>
                                <UiBadge v-if="r.late" class="md:hidden" color="error" pill :dot="false">Trễ</UiBadge>
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-on-surface max-md:whitespace-normal">{{ r.title }}</p>
                                <p v-if="r.status === 'returned' && r.review_note" class="mt-0.5 truncate text-xs text-warning">Đã yêu cầu: {{ r.review_note }}</p>
                                <p v-else-if="r.reviewed_at" class="mt-0.5 truncate text-xs text-on-surface-subtle">{{ r.reviewed_by ?? 'Admin' }} kiểm tra {{ formatDate(r.reviewed_at, 'H:i d/m') }}</p>
                            </div>
                            <div class="min-w-0 max-md:text-xs" data-report-writer>
                                <p class="truncate text-sm font-semibold text-on-surface-variant max-md:inline max-md:text-xs">{{ r.user }}</p>
                                <p v-if="r.role" class="truncate text-xs text-on-surface-subtle max-md:inline"><span class="md:hidden"> · </span>{{ r.role }}</p>
                            </div>
                            <div class="text-xs text-on-surface-variant">
                                <template v-if="r.reviewable && r.submitted_at">
                                    <span class="md:hidden">Nộp </span>{{ formatDate(r.submitted_at, 'H:i d/m') }}
                                    <UiBadge v-if="r.late" class="ml-1 max-md:hidden" color="error" pill :dot="false" data-late>Trễ</UiBadge>
                                </template>
                                <template v-else>{{ formatDate(r.report_date) }}</template>
                            </div>
                            <div class="flex items-center justify-end gap-2 max-md:pt-1">
                                <template v-if="current === 'all' && r.reviewable">
                                    <UiBadge v-if="r.status === 'returned'" color="warning" pill>Đã yêu cầu bổ sung</UiBadge>
                                    <UiBadge v-else-if="r.reviewed_at" color="success" pill>Đã kiểm tra</UiBadge>
                                    <UiBadge v-else color="warning" pill>Chờ kiểm tra</UiBadge>
                                </template>
                                <span v-if="r.followups_count" class="flex items-center gap-1 rounded-full bg-surface-container-low px-2 py-0.5 text-xs font-semibold text-on-surface-variant" :title="`${r.followups_count} việc follow-up`">
                                    <span class="material-symbols-outlined text-[15px]" aria-hidden="true">task_alt</span>{{ r.followups_count }}
                                </span>
                                <UiButton size="sm" variant="secondary" class="md:hidden" @click.stop="openDetail(r.id)">Chi tiết</UiButton>
                                <template v-if="canReview && r.reviewable">
                                    <UiButton v-if="!r.reviewed_at && r.status === 'submitted'" size="sm" variant="secondary" icon="done" class="!border-primary-fixed !text-primary" @click.stop="markReviewed(r)">Đã kiểm tra</UiButton>
                                    <UiButton v-else-if="r.reviewed_at" size="sm" variant="ghost" icon="undo" @click.stop="markReviewed(r, false)">Bỏ đánh dấu</UiButton>
                                </template>
                                <button type="button" class="hidden h-8 w-8 place-items-center rounded-lg text-on-surface-subtle hover:bg-surface-container-high hover:text-primary md:grid" :data-report-detail-button="r.id" @click.stop="openDetail(r.id)">
                                    <span class="material-symbols-outlined text-[20px]" aria-hidden="true">chevron_right</span><span class="sr-only">Chi tiết</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <UiEmptyState
                        v-if="!reports.data.length"
                        :icon="current === 'pending' ? 'task_alt' : 'inbox'"
                        :title="current === 'pending' && !hasFilters ? 'Đã kiểm tra hết, không còn báo cáo nào chờ.' : 'Không có báo cáo nào khớp bộ lọc.'"
                    />
                </div>
            </div>
            <UiPagination :paginator="reports" :options="[]" />

            <!-- Thanh đánh dấu hàng loạt -->
            <div v-if="selected.length" class="sticky bottom-4 z-20 flex justify-center max-md:bottom-0 max-md:-mx-4" data-bulk-review-bar>
                <div class="flex items-center gap-3 rounded-2xl bg-navy-800 py-2 pl-4 pr-2 text-sm font-semibold text-white shadow-xl max-md:w-full max-md:rounded-none max-md:bg-surface-container-lowest max-md:text-on-surface-variant max-md:shadow-[0_-6px_20px_rgba(17,26,43,0.08)]">
                    <span class="whitespace-nowrap">Đã chọn {{ selected.length }}</span>
                    <button type="button" class="px-2 text-[13px] text-white/70 hover:text-white max-md:hidden" @click="selected = []">Bỏ chọn</button>
                    <UiButton icon="fact_check" class="max-md:flex-1 max-md:justify-center" data-bulk-review-submit @click="reviewSelected">Đánh dấu đã kiểm tra ({{ selected.length }})</UiButton>
                </div>
            </div>
        </template>
    </div>
</template>
