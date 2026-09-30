<script setup>
/**
 * Bộ lọc dùng chung cho Pipeline / Khách chốt / Khách không chốt (thay crm/partials/list-filters).
 * Lựa chọn từ CrmController::listFilterOptions (filterBranches chỉ có dữ liệu với người xem nhiều chi nhánh).
 */
import { computed } from 'vue';
import WorkspaceChips from '@/Components/WorkspaceChips.vue';
import { urlWith } from '@/lib/url';

const props = defineProps({
    filterBranches: { type: Array, default: () => [] },
    filterSales: { type: Array, default: () => [] },
    filterSources: { type: Array, default: () => [] },
    filterClasses: { type: Array, default: null },
    chipCounts: { type: Object, default: () => ({}) },
    dateLabel: { type: String, default: 'Ngày tạo' },
    exportable: { type: Boolean, default: false },
    searchPlaceholder: { type: String, default: 'Tìm họ tên, số điện thoại...' },
    exportLabel: { type: String, default: 'Xuất Excel' },
});
const exportUrl = (format) => urlWith({ export: format, page: null });
const hasBranches = computed(() => props.filterBranches.length > 0);
</script>

<template>
    <UiFilterBar :placeholder="searchPlaceholder">
        <template #quick><WorkspaceChips :counts="chipCounts" /></template>
        <!-- Thứ tự & nhãn theo mockup pipeline-tong-quan-giai-doan: Nguồn → Người phụ trách → Chi nhánh -->
        <UiSelect name="source" label="Nguồn" :options="filterSources" placeholder="Tất cả nguồn" />
        <UiSelect name="assigned_user_id" label="Người phụ trách" :options="filterSales" placeholder="Tất cả người phụ trách" />
        <UiSelect v-if="hasBranches" name="branch_id" label="Chi nhánh" :options="filterBranches" placeholder="Tất cả chi nhánh" />
        <UiSelect v-if="filterClasses" name="class_id" label="Lớp học" :options="filterClasses" placeholder="Tất cả lớp" />
        <UiDateRange :label="dateLabel" />
        <div v-if="exportable" class="flex items-center gap-xs sm:col-span-2">
            <UiButton variant="secondary" size="sm" icon="download" :href="exportUrl('xlsx')" native>{{ exportLabel }}</UiButton>
            <UiButton variant="ghost" size="sm" :href="exportUrl('csv')" native>CSV</UiButton>
        </div>
    </UiFilterBar>
</template>
