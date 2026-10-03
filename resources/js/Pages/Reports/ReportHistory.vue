<script setup>
/** Danh sách kỳ đã nộp của báo cáo có cấu trúc — bấm để mở lại kỳ đó (xem / sửa). */
import { Link } from '@inertiajs/vue3';
defineProps({
    items: { type: Array, default: () => [] },
    current: { type: String, default: null },
    title: { type: String, default: 'Đã nộp' },
});
</script>

<template>
    <aside class="h-fit rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm">
        <h2 class="mb-sm font-body-small text-body-small font-semibold uppercase text-on-surface-variant">{{ title }}</h2>
        <ul v-if="items.length" class="space-y-xs">
            <li v-for="item in items" :key="item.period">
                <Link
                    :href="item.href"
                    :class="['block rounded-lg px-sm py-xs transition-colors hover:bg-surface-container-low', item.period === current ? 'bg-primary-container/10 font-semibold text-primary' : 'text-on-surface']"
                >
                    {{ item.label }}
                    <span v-if="item.updated_at" class="block font-body-small text-body-small text-on-surface-variant">Cập nhật {{ formatDate(item.updated_at, 'H:i d/m/Y') }}</span>
                </Link>
            </li>
        </ul>
        <p v-else class="font-body-small text-body-small italic text-on-surface-variant">Chưa nộp kỳ nào.</p>
    </aside>
</template>
