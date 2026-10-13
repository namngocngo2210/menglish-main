<script setup>
/** Báo cáo chữ tự do đã nộp trước khi có màn có cấu trúc (StaffReportController::legacyReports) — bấm để xem lại nội dung. */
import { ref } from 'vue';

defineProps({ items: { type: Array, default: () => [] } });
const viewing = ref(null);
</script>

<template>
    <aside v-if="items.length" class="h-fit rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm" data-legacy-reports>
        <h2 class="mb-sm font-body-small text-body-small font-semibold uppercase text-on-surface-variant">Báo cáo dạng chữ trước đây</h2>
        <ul class="space-y-xs">
            <li v-for="item in items" :key="item.id">
                <button type="button" class="block w-full rounded-lg px-sm py-xs text-left text-on-surface transition-colors hover:bg-surface-container-low" @click="viewing = item">
                    {{ item.title }}
                    <span class="block font-body-small text-body-small text-on-surface-variant">{{ formatDate(item.report_date, 'd/m/Y') }}</span>
                </button>
            </li>
        </ul>
    </aside>

    <UiModal :show="!!viewing" :title="viewing?.title ?? ''" max-width="lg" @close="viewing = null">
        <p v-if="viewing" class="whitespace-pre-line text-on-surface">{{ viewing.content }}</p>
        <template #footer><UiButton variant="secondary" @click="viewing = null">Đóng</UiButton></template>
    </UiModal>
</template>
