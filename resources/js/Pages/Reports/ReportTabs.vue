<script setup>
/** Tab "Báo cáo định kỳ của tôi": báo cáo định kỳ chính + báo cáo có cấu trúc theo vai trò (StaffReportController::reportTabs). */
defineProps({ tabs: { type: Array, default: () => [] } });
</script>

<template>
    <UiTabs v-if="tabs.length" class="mb-lg">
        <UiTab v-for="tab in tabs" :key="tab.href" :href="tab.href" :active="tab.active">{{ tab.label }}</UiTab>
    </UiTabs>
</template>
