<script setup>
/**
 * Thanh bộ lọc CHUNG (như <x-ui.filter-bar>): form GET trên MỘT hàng — ô tìm kiếm (giãn) + control trong slot + nút Lọc / Xoá lọc.
 *   <UiFilterBar placeholder="Tìm họ tên, SĐT...">
 *       <UiSelect name="branch_id" label="Chi nhánh" :options="branches" placeholder="Tất cả chi nhánh" />
 *       <UiDateRange label="Ngày tạo" />
 *   </UiFilterBar>
 * - Control trong slot đọc giá trị đang lọc từ URL (theo `name`), dropdown tự có ô tìm (Tom Select), nhãn ẩn (sr-only).
 * - Slot `quick`: hàng lọc nhanh ở đầu khung (vd. <WorkspaceChips />).
 * - Điện thoại (< md): chỉ hiện ô tìm + nút "Bộ lọc (n)".
 * Props: action (mặc định URL hiện tại), search (tên tham số tìm; false = không có ô tìm), placeholder, resetUrl, submitLabel,
 *        keep (tham số giữ lại khi lọc, vd. ['tab'])
 */
import { computed, provide, ref, useId, useSlots } from 'vue';
import { router } from '@inertiajs/vue3';
import UiButton from './UiButton.vue';
import { compactQuery, currentQuery, currentUrl } from '@/lib/url';

const props = defineProps({
    action: { type: String, default: null },
    search: { type: [String, Boolean], default: 'search' },
    placeholder: { type: String, default: 'Tìm kiếm...' },
    resetUrl: { type: String, default: null },
    submitLabel: { type: String, default: 'Lọc' },
    keep: { type: Array, default: () => [] },
});
const slots = useSlots();
provide('uiFilterBar', true);

const more = ref(false);
const uid = useId().replace(/[^A-Za-z0-9_-]/g, '');
const formId = `filters-${uid}`;
const target = computed(() => props.action ?? currentUrl().pathname);
const reset = computed(() => props.resetUrl ?? currentUrl().pathname);
const active = computed(() => {
    const entries = [];
    currentQuery().forEach((value, key) => {
        if (!['page', 'per_page', 'tab'].includes(key) && value !== '') entries.push(key);
    });
    return [...new Set(entries)];
});
const activeFilters = computed(() => active.value.filter((key) => key !== props.search).length);
const searchValue = computed(() => (props.search ? (currentQuery().get(props.search) ?? '') : ''));

function submit(event) {
    const data = {};
    new FormData(event.target).forEach((value, key) => {
        if (key.endsWith('[]')) (data[key.slice(0, -2)] ??= []).push(value);
        else data[key] = value;
    });
    const query = currentQuery();
    for (const key of props.keep) if (query.has(key) && !(key in data)) data[key] = query.get(key);
    const perPage = query.get('per_page');
    if (perPage && !('per_page' in data)) data.per_page = perPage;
    router.get(target.value, compactQuery(data), { preserveScroll: true });
}
</script>

<template>
    <form method="GET" :action="target" role="search" data-filter-bar class="mb-lg rounded-xl border border-surface-container-highest bg-surface-container-lowest p-sm shadow-sm md:p-md" @submit.prevent="submit">
        <div v-if="slots.quick" class="mb-md border-b border-surface-container-highest pb-md empty:hidden"><slot name="quick" /></div>
        <div class="flex flex-col gap-sm md:flex-row md:items-center" data-filter-row>
            <div v-if="search" class="relative" data-filter-search>
                <label :for="`${formId}-search`" class="sr-only">{{ placeholder }}</label>
                <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">search</span>
                <input
                    :id="`${formId}-search`"
                    type="search"
                    :name="search"
                    :value="searchValue"
                    :placeholder="placeholder"
                    class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-sm pl-10 pr-md font-body-base text-body-base text-on-surface placeholder:text-on-surface-subtle focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/50"
                />
            </div>

            <div v-if="slots.default" :id="formId" :class="['hidden flex-col gap-sm md:contents', more ? '!flex' : '']" data-filter-controls>
                <slot />
            </div>

            <div class="flex flex-wrap items-center gap-sm md:ml-auto" data-filter-actions>
                <UiButton v-if="slots.default" type="button" variant="secondary" icon="tune" class="mr-auto md:hidden" :aria-expanded="more ? 'true' : 'false'" :aria-controls="formId" @click="more = !more">
                    Bộ lọc{{ activeFilters ? ` (${activeFilters})` : '' }}
                </UiButton>
                <UiButton v-if="active.length" variant="ghost" :href="reset" icon="filter_alt_off">Xoá lọc</UiButton>
                <UiButton type="submit" variant="secondary" icon="filter_list">{{ submitLabel }}</UiButton>
            </div>
        </div>
    </form>
</template>
