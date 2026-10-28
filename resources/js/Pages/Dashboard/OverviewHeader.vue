<script setup>
/** Đầu trang Tổng quan: lời chào theo buổi, ngày và phạm vi; nút chính của vai trò nằm bên phải (slot). */
defineProps({ greeting: { type: Object, required: true } });
</script>

<template>
    <header class="flex flex-wrap items-end justify-between gap-md" data-overview-header>
        <div class="min-w-0">
            <p class="font-body-small text-body-small text-on-surface-variant">{{ greeting.date }} · {{ greeting.scope }}</p>
            <h2 class="font-h1 text-h1 font-bold text-on-surface">{{ greeting.title }}</h2>
        </div>
        <div class="flex flex-wrap items-center gap-sm">
            <slot />
        </div>
    </header>
</template>
