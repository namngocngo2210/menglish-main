<script setup>
/** Tiêu đề + tab chung của màn Phòng học: Danh sách phòng · Tra cứu phòng trống · Danh mục loại phòng. */
defineProps({
    counts: { type: Object, default: () => ({}) },
});
</script>

<template>
    <UiPageHeader title="Quản lý phòng học" description="Danh sách phòng học theo chi nhánh, sức chứa, khung giờ lớp đang dùng phòng và phân loại phòng.">
        <template #badges><UiBadge color="primary" :dot="false">Cơ sở vật chất &amp; Học vụ</UiBadge></template>
        <template #actions><slot name="actions" /></template>
    </UiPageHeader>
    <UiTabs class="mb-lg">
        <UiTab :href="route('rooms.index')" :active="routeIs('rooms.index')" icon="meeting_room" :count="counts.rooms">Danh sách phòng</UiTab>
        <UiTab :href="route('rooms.availability')" :active="routeIs('rooms.availability')" icon="event_available">Tra cứu phòng trống</UiTab>
        <UiTab :href="route('rooms.types.index')" :active="routeIs('rooms.types.index')" icon="category" :count="counts.types">Danh mục loại phòng</UiTab>
    </UiTabs>
</template>
