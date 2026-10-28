<script setup>
/**
 * Tab "Báo cáo định kỳ của tôi": báo cáo định kỳ chính + báo cáo có cấu trúc theo vai trò (StaffReportController::reportTabs).
 * `segmented`: nút gạt đặt cạnh ô chọn ngày ở đầu trang (báo cáo ngày, thiết kế 27/10/2026).
 */
import { Link } from '@inertiajs/vue3';

defineProps({
    tabs: { type: Array, default: () => [] },
    segmented: { type: Boolean, default: false },
});
</script>

<template>
    <nav v-if="segmented && tabs.length > 1" class="inline-flex gap-0.5 rounded-xl border border-surface-variant bg-surface-container-lowest p-0.5" aria-label="Loại báo cáo">
        <Link
            v-for="tab in tabs"
            :key="tab.href"
            :href="tab.href"
            :aria-current="tab.active ? 'page' : undefined"
            :class="[
                'inline-flex min-h-[36px] items-center whitespace-nowrap rounded-lg px-md font-body-small text-body-small font-semibold transition-colors',
                tab.active ? 'bg-primary-container/10 text-primary' : 'text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface',
            ]"
        >
            {{ tab.label }}
        </Link>
    </nav>
    <UiTabs v-else-if="!segmented && tabs.length" class="mb-lg">
        <UiTab v-for="tab in tabs" :key="tab.href" :href="tab.href" :active="tab.active">{{ tab.label }}</UiTab>
    </UiTabs>
</template>
