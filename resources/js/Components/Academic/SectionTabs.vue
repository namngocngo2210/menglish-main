<script setup>
/** Tab cấp mục "Báo cáo & sự vụ" (menu Lớp học): hai màn giám sát chung một chỗ. */
</script>

<template>
    <UiTabs class="mb-lg">
        <UiTab icon="assessment" :href="route('academic.dashboards.reports')" :active="routeIs('academic.dashboards.reports')">Báo cáo đào tạo</UiTab>
        <UiTab icon="report" :href="route('academic.dashboards.incidents')" :active="routeIs('academic.dashboards.incidents')">Nhật ký sự vụ</UiTab>
    </UiTabs>
</template>
