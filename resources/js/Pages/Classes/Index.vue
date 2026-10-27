<script setup>
/**
 * Danh sách lớp: màn chính của menu Lớp học. Bấm một lớp → Trang lớp.
 * Bố cục: tab trạng thái → MỘT hàng lọc (tìm + Chương trình / Cấp độ / Chi nhánh) → bảng.
 * Chọn mục trong dropdown là lọc ngay (giữ các bộ lọc khác); điện thoại hiện thẻ thay cho bảng cuộn ngang.
 */
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { urlWith } from '@/lib/url';
import { groupSchedule } from '@/lib/schedule';

defineOptions({ layout: { title: 'Danh sách lớp' } });

const props = defineProps({
    classes: { type: Object, required: true },
    branches: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    openCount: { type: Number, default: 0 },
    programCounts: { type: Array, default: () => [] },
    levelCounts: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const tabUrl = (status) => urlWith({ page: null, status });

// Dropdown lọc: chọn lại mục đang chọn = bỏ chọn.
const facets = computed(() =>
    [
        { key: 'program', label: 'Chương trình', options: props.programCounts.map((p) => ({ value: p.label, label: p.label, count: p.total })) },
        // Đổi chương trình thì cấp độ cũ có thể không còn → bỏ luôn cấp độ.
        { key: 'level', label: 'Cấp độ', options: props.levelCounts.map((l) => ({ value: l.label, label: l.label, count: l.total })) },
        // Chi nhánh: backend chỉ gửi chi nhánh có lớp người xem thấy; 1 chi nhánh thì không cần lọc.
        { key: 'branch_id', label: 'Chi nhánh', options: props.branches.length > 1 ? props.branches.map((b) => ({ value: String(b.value), label: b.label, count: null })) : [] },
    ]
        .map((f) => {
            const current = props.filters[f.key] ? String(props.filters[f.key]) : null;
            return { ...f, current, currentLabel: current ? (f.options.find((o) => o.value === current)?.label ?? current) : null };
        })
        .filter((f) => f.options.length || f.current),
);
const facetUrl = (key, value) => urlWith({ page: null, [key]: value, ...(key === 'program' ? { level: null } : {}) });
const hasFilters = computed(() => ['program', 'level', 'branch_id', 'search'].some((k) => props.filters[k]));
const clearUrl = computed(() => urlWith({ page: null, program: null, level: null, branch_id: null, search: null }));

// Tìm kiếm: gõ xong 400ms (hoặc Enter) là lọc, giữ trạng thái trang để ô tìm không mất focus.
const search = ref(props.filters.search ?? '');
let timer = null;
function applySearch() {
    clearTimeout(timer);
    const value = search.value.trim();
    if (value === (props.filters.search ?? '')) return;
    router.get(urlWith({ page: null, search: value || null }), {}, { preserveState: true, preserveScroll: true, replace: true });
}
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(applySearch, 400);
});
watch(
    () => props.filters.search,
    (value) => {
        if ((value ?? '') !== search.value.trim()) search.value = value ?? '';
    },
);

const seatTone = (seat) => (seat.left === 0 ? 'text-error' : seat.needed > 0 ? 'text-warning' : 'text-tertiary');
const seatNote = (seat) => {
    if (seat.needed > 0) return `Thiếu ${seat.needed}/${seat.min} để khai giảng`;
    if (seat.left === null) return 'Không giới hạn';
    if (seat.left === 0) return 'Đã đủ';
    return `Còn ${seat.left} chỗ`;
};
</script>

<template>
    <UiPageHeader :title="filters.mine ? 'Lớp của tôi' : 'Danh sách lớp'" icon="co_present" :description="filters.mine ? 'Các lớp bạn đang dạy hoặc trợ giảng. Bấm vào lớp để điểm danh, nộp báo cáo, giao bài.' : 'Bấm vào một lớp để xem lịch, học viên, điểm danh và báo cáo buổi.'">
        <template v-if="can('class.create') && !filters.mine" #actions>
            <UiButton v-if="can('student.assign_class')" variant="secondary" icon="upload_file" :href="route('classes.import')">Nhập danh sách lớp</UiButton>
            <UiButton icon="add" :href="route('classes.create')">Tạo lớp</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-md">
        <UiDataTable min-width="820px">
            <template #header>
                <div class="-mx-md -mt-md w-[calc(100%+2*theme(spacing.md))] space-y-sm">
                    <UiTabs class="px-md" aria-label="Lọc theo trạng thái lớp">
                        <UiTab :href="tabUrl(null)" :active="!filters.status" :count="openCount">Đang mở</UiTab>
                        <UiTab v-for="s in statuses" :key="s.key" :href="tabUrl(s.key)" :active="filters.status === s.key" :count="s.count">{{ s.label }}</UiTab>
                    </UiTabs>

                    <div class="flex flex-wrap items-center gap-xs px-md" role="search">
                        <label class="relative min-w-[220px] flex-[1_1_280px]">
                            <span class="sr-only">Tìm lớp</span>
                            <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">search</span>
                            <input
                                v-model="search"
                                type="search"
                                :placeholder="filters.mine ? 'Tìm tên lớp hoặc mã lớp' : 'Tìm tên lớp, mã lớp hoặc giáo viên'"
                                class="h-10 w-full rounded-lg border border-outline-variant bg-surface-container-lowest pl-10 pr-md font-body-base text-body-base text-on-surface placeholder:text-on-surface-subtle focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/50"
                                @keydown.enter.prevent="applySearch"
                            />
                        </label>

                        <UiDropdown v-for="f in facets" :key="f.key" align="left" width="72" :data-facet="f.key">
                            <template #trigger="{ open }">
                                <button
                                    type="button"
                                    :aria-expanded="open ? 'true' : 'false'"
                                    :class="[
                                        'inline-flex h-10 max-w-[260px] items-center gap-xs rounded-lg border px-sm font-body-base text-body-base transition-colors',
                                        f.current ? 'border-primary-container bg-primary-light font-semibold text-on-primary-fixed-variant' : 'border-outline-variant bg-surface-container-lowest text-on-surface hover:bg-surface-container-low',
                                    ]"
                                >
                                    <span class="truncate">{{ f.current ? `${f.label}: ${f.currentLabel}` : f.label }}</span>
                                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">expand_more</span>
                                </button>
                            </template>
                            <template #content>
                                <div class="max-h-80 overflow-y-auto p-1" role="menu" :aria-label="f.label">
                                    <Link :href="facetUrl(f.key, null)" role="menuitem" :class="['flex min-h-10 items-center gap-sm rounded-lg px-sm font-body-base text-body-base hover:bg-surface-container-low', !f.current ? 'font-semibold text-primary' : 'text-on-surface']">
                                        <span class="material-symbols-outlined w-5 text-[18px]" aria-hidden="true">{{ !f.current ? 'check' : '' }}</span>
                                        Tất cả
                                    </Link>
                                    <Link
                                        v-for="o in f.options"
                                        :key="o.value"
                                        :href="facetUrl(f.key, o.value)"
                                        role="menuitem"
                                        :aria-current="f.current === o.value ? 'true' : null"
                                        :class="['flex min-h-10 items-center gap-sm rounded-lg px-sm font-body-base text-body-base hover:bg-surface-container-low', f.current === o.value ? 'bg-primary-light font-semibold text-primary' : 'text-on-surface']"
                                    >
                                        <span class="material-symbols-outlined w-5 text-[18px]" aria-hidden="true">{{ f.current === o.value ? 'check' : '' }}</span>
                                        <span class="flex-1 truncate">{{ o.label }}</span>
                                        <span v-if="o.count !== null" class="rounded-full bg-surface-container-high px-1.5 font-code text-xs leading-5 text-on-surface-variant">{{ o.count }}</span>
                                    </Link>
                                </div>
                            </template>
                        </UiDropdown>

                        <UiButton v-if="hasFilters" variant="ghost" icon="filter_alt_off" :href="clearUrl">Xoá lọc</UiButton>
                        <span class="ml-auto font-body-small text-body-small text-on-surface-variant">{{ classes.total }} lớp</span>
                    </div>
                </div>
            </template>

            <!-- Máy tính: bảng. Bỏ cột Tiến độ / Việc tiếp theo cho gọn; cột Lớp đã có link mở lớp nên bỏ nút "Mở lớp"; Sửa nằm trong menu ⋯. -->
            <table class="hidden md:table">
                <thead>
                    <tr>
                        <th>Lớp</th>
                        <th>Lịch học</th>
                        <th>{{ filters.mine ? 'Vai trò của bạn' : 'Giáo viên' }}</th>
                        <th>Sĩ số</th>
                        <th v-if="can('class.update')" class="w-12"><span class="sr-only">Thao tác</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in classes.data" :key="c.id" class="align-top">
                        <td class="max-w-[300px]">
                            <div class="flex items-center gap-xs">
                                <Link :href="route('classes.show', c.id)" class="font-code text-sm font-bold text-primary hover:underline">{{ c.code }}</Link>
                                <UiBadge :color="c.status.color" pill>{{ c.status.label }}</UiBadge>
                            </div>
                            <Link :href="route('classes.show', c.id)" class="block truncate font-semibold text-on-surface hover:text-primary hover:underline" :title="c.name">{{ c.name }}</Link>
                            <div class="truncate text-xs text-on-surface-subtle" :title="c.meta">{{ c.meta }}</div>
                        </td>
                        <td class="whitespace-nowrap">
                            <template v-if="c.schedule_text">
                                <div v-for="(g, i) in groupSchedule(c.schedule_text)" :key="i" :class="i ? 'mt-1' : ''">
                                    <div class="font-medium">{{ g.days }}</div>
                                    <div v-if="g.time" class="text-xs text-on-surface-variant">{{ g.time }}</div>
                                </div>
                            </template>
                            <span v-else class="text-on-surface-subtle">Chưa xếp lịch</span>
                        </td>
                        <td v-if="filters.mine" class="whitespace-nowrap">
                            <div class="font-semibold">{{ c.my_role }}</div>
                            <div v-if="c.my_role === 'GV chính'" class="text-xs text-on-surface-variant">{{ c.assistant ? `TG: ${c.assistant}` : 'Không có TG' }}</div>
                            <div v-else class="text-xs text-on-surface-variant">GV: {{ c.teacher ?? 'Chưa phân công' }}</div>
                        </td>
                        <td v-else class="whitespace-nowrap">
                            <div>{{ c.teacher ?? 'Chưa phân công' }}</div>
                            <div v-if="c.foreign_teacher" class="text-xs text-on-surface-variant">GVNN: {{ c.foreign_teacher }}</div>
                        </td>
                        <td class="whitespace-nowrap" :data-seats="c.id">
                            <span class="font-bold">{{ c.seat.occupied }}</span><span class="text-on-surface-subtle">/{{ c.seat.capacity || '∞' }}</span>
                            <div :class="['text-xs font-semibold', seatTone(c.seat)]" :title="c.seat.needed > 0 ? `Ngưỡng khai giảng ${c.seat.min} học viên` : null">{{ seatNote(c.seat) }}</div>
                        </td>
                        <td v-if="can('class.update')" class="text-right">
                            <UiDropdown align="right" width="48">
                                <template #trigger><UiButton variant="ghost" size="sm" icon="more_vert" :aria-label="`Thao tác lớp ${c.code}`" /></template>
                                <template #content>
                                    <Link :href="route('classes.show', c.id)" class="flex items-center gap-sm px-md py-sm font-body-base text-body-base hover:bg-surface-container-low">
                                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">open_in_new</span>Mở lớp
                                    </Link>
                                    <Link :href="route('classes.edit', c.id)" class="flex items-center gap-sm px-md py-sm font-body-base text-body-base hover:bg-surface-container-low">
                                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">edit</span>Sửa lớp
                                    </Link>
                                </template>
                            </UiDropdown>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Điện thoại: mỗi lớp một thẻ, bấm cả thẻ để mở lớp. -->
            <ul class="divide-y divide-surface-container md:hidden">
                <li v-for="c in classes.data" :key="c.id">
                    <Link :href="route('classes.show', c.id)" class="block px-md py-sm hover:bg-surface-container-low">
                        <div class="flex items-center justify-between gap-xs">
                            <span class="font-code text-xs font-bold text-primary">{{ c.code }}</span>
                            <UiBadge :color="c.status.color" pill>{{ c.status.label }}</UiBadge>
                        </div>
                        <div class="mt-0.5 font-semibold text-on-surface">{{ c.name }}</div>
                        <div class="text-xs text-on-surface-variant">
                            {{ groupSchedule(c.schedule_text).map((g) => [g.days, g.time].filter(Boolean).join(' ')).join(' · ') || 'Chưa xếp lịch' }} · {{ filters.mine ? c.my_role : (c.teacher ?? 'Chưa phân công') }}
                        </div>
                        <div class="mt-xs text-xs"><b>{{ c.seat.occupied }}</b>/{{ c.seat.capacity || '∞' }} học viên</div>
                    </Link>
                </li>
            </ul>

            <UiEmptyState v-if="!classes.data.length" icon="search_off" title="Không tìm thấy lớp học nào thỏa mãn điều kiện tìm kiếm.">
                <UiButton v-if="hasFilters" variant="secondary" icon="filter_alt_off" :href="clearUrl">Xoá lọc</UiButton>
                <UiButton v-else-if="can('class.create')" icon="add" :href="route('classes.create')">Tạo lớp mới</UiButton>
            </UiEmptyState>

            <template #footer><UiPagination :paginator="classes" unit="lớp" /></template>
        </UiDataTable>
    </div>
</template>
