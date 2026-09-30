<script setup>
/**
 * Thanh phân trang (như <x-ui.pagination>): "x - y trong tổng số N" + chọn số dòng + link trang.
 *   <UiPagination :paginator="customers" unit="khách" />
 * paginator: paginator của Laravel gửi xuống (->paginate()->withQueryString(), dạng JSON: current_page, last_page, total, from, to, per_page…);
 *            simplePaginate (không có total) → chỉ nút trước / sau.
 * options: lựa chọn số dòng/trang (mặc định [10, 20, 50, 100, 'all']; [] = ẩn). Link giữ nguyên bộ lọc trên URL.
 */
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { currentQuery, urlWith } from '@/lib/url';
import { formatNumber } from '@/lib/format';

const props = defineProps({
    paginator: { type: Object, required: true },
    options: { type: Array, default: () => [10, 20, 50, 100, 'all'] },
    unit: { type: String, default: 'bản ghi' },
    onEachSide: { type: Number, default: 3 },
});

const p = computed(() => props.paginator ?? {});
const current = computed(() => Number(p.value.current_page ?? 1));
const last = computed(() => (p.value.last_page !== undefined ? Number(p.value.last_page) : null));
const lengthAware = computed(() => p.value.total !== undefined);
const perPage = computed(() => Number(p.value.per_page ?? 15));
const hasMore = computed(() => (last.value !== null ? current.value < last.value : !!p.value.next_page_url));
const hasPages = computed(() => current.value > 1 || hasMore.value);

const perPageOptions = computed(() => {
    let options = [...props.options];
    const requested = currentQuery().get('per_page');
    let selected = requested ?? String(perPage.value);
    // Số dòng mặc định của màn (vd 15, 25) không có trong danh sách → thêm vào để ô chọn hiện đúng số đang dùng.
    if (options.length && selected !== 'all' && perPage.value < 9999 && !options.includes(perPage.value)) {
        selected = String(perPage.value);
        const numeric = [...options.filter((o) => typeof o === 'number'), perPage.value].sort((a, b) => a - b);
        options = options.includes('all') ? [...numeric, 'all'] : numeric;
    }
    return options.map((opt) => ({
        value: String(opt),
        label: opt === 'all' ? 'Tất cả' : String(opt),
        selected: selected === String(opt) || (opt === 'all' && (selected === 'all' || Number(selected) >= 9999)),
    }));
});

// Cửa sổ trang giống Laravel (UrlWindow): đầu · … · quanh trang hiện tại · … · cuối.
const elements = computed(() => {
    if (last.value === null) return [];
    const total = last.value;
    const window = props.onEachSide * 2;
    const range = (a, b) => Array.from({ length: Math.max(0, b - a + 1) }, (_, i) => a + i);
    if (total < window + 8) return range(1, total);
    if (current.value <= window) return [...range(1, window + 2), '...', total - 1, total];
    if (current.value > total - window) return [1, 2, '...', ...range(total - (window + 2), total)];
    return [1, 2, '...', ...range(current.value - props.onEachSide, current.value + props.onEachSide), '...', total - 1, total];
});

const pageUrl = (page) => urlWith({ page: page > 1 ? page : null });
const changePerPage = (event) => router.get(urlWith({ per_page: event.target.value, page: null }), {}, { preserveScroll: true });

const base = 'inline-flex h-11 min-w-11 md:h-8 md:min-w-8 items-center justify-center rounded px-xs font-body-medium text-body-medium transition-colors';
const idle = `${base} text-on-surface hover:bg-surface-container-high`;
const disabled = `${base} cursor-default text-on-surface-variant opacity-30`;
</script>

<template>
    <div class="flex flex-col gap-sm px-md py-md md:flex-row md:items-center md:justify-between">
        <div class="flex flex-wrap items-center gap-sm font-body-small text-body-small text-on-surface-variant">
            <label v-if="options.length" class="flex items-center gap-xs">
                <span>Hiển thị:</span>
                <select class="rounded-lg border border-outline-variant bg-surface-container-lowest py-1 pl-sm pr-lg font-body-small text-body-small text-on-surface focus:border-primary-container focus:ring-primary-container/50" aria-label="Số dòng mỗi trang" @change="changePerPage">
                    <option v-for="opt in perPageOptions" :key="opt.value" :value="opt.value" :selected="opt.selected">{{ opt.label }}</option>
                </select>
                <span>dòng</span>
            </label>
            <span v-if="lengthAware">
                <span class="font-code text-on-surface">{{ p.from ?? 0 }}</span>
                - <span class="font-code text-on-surface">{{ p.to ?? 0 }}</span>
                trong tổng số <span class="font-code text-on-surface">{{ formatNumber(p.total) }}</span> {{ unit }}
            </span>
        </div>
        <nav v-if="hasPages" role="navigation" aria-label="Phân trang" class="flex items-center gap-xs">
            <span v-if="current <= 1" :class="disabled" aria-disabled="true" aria-label="Trang trước"><span class="material-symbols-outlined">chevron_left</span></span>
            <Link v-else :href="pageUrl(current - 1)" rel="prev" :class="idle" aria-label="Trang trước" preserve-scroll><span class="material-symbols-outlined">chevron_left</span></Link>

            <span v-if="last !== null" class="px-sm font-body-small text-body-small text-on-surface-variant sm:hidden">{{ current }} / {{ last }}</span>

            <span class="hidden items-center gap-xs sm:flex">
                <template v-for="(el, i) in elements" :key="i">
                    <span v-if="el === '...'" class="px-sm text-on-surface-variant" aria-disabled="true">...</span>
                    <span v-else-if="el === current" aria-current="page" :class="[base, 'bg-primary-container text-white']">{{ el }}</span>
                    <Link v-else :href="pageUrl(el)" :class="idle" :aria-label="`Trang ${el}`">{{ el }}</Link>
                </template>
            </span>

            <Link v-if="hasMore" :href="pageUrl(current + 1)" rel="next" :class="idle" aria-label="Trang sau"><span class="material-symbols-outlined">chevron_right</span></Link>
            <span v-else :class="disabled" aria-disabled="true" aria-label="Trang sau"><span class="material-symbols-outlined">chevron_right</span></span>
        </nav>
    </div>
</template>
