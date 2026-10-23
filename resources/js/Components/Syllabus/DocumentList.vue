<script setup>
/** Danh sách tài liệu gọn (buổi / chặng / chung) trên trang chi tiết giáo trình: xem, tải, xóa. */
defineProps({
    documents: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    emptyText: { type: String, default: 'Chưa có tài liệu.' },
});
</script>

<template>
    <ul v-if="documents.length" class="divide-y divide-surface-container-highest rounded-lg border border-surface-container-highest">
        <li v-for="doc in documents" :key="doc.id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-xs">
            <span class="flex min-w-0 items-center gap-2">
                <span class="material-symbols-outlined text-[18px] text-primary" aria-hidden="true">{{ doc.icon }}</span>
                <span class="truncate font-semibold text-on-surface">{{ doc.title }}</span>
                <span class="whitespace-nowrap font-code uppercase text-on-surface-variant">{{ doc.extension }} · {{ doc.size_human }}</span>
                <UiBadge v-for="label in doc.audience_labels.filter((l) => l === 'Giáo viên' || l === 'Trợ giảng')" :key="label" :dot="false">{{ label }}</UiBadge>
            </span>
            <span class="flex shrink-0 items-center gap-1">
                <UiButton variant="ghost" size="sm" icon="visibility" :href="doc.view_url" native target="_blank" title="Xem" />
                <UiButton v-if="doc.downloadable" variant="ghost" size="sm" icon="download" :href="doc.download_url" native title="Tải xuống" />
                <UiForm v-if="canManage" :action="route('syllabus.documents.destroy', doc.id)" method="delete" :confirm="`Xóa tài liệu ${doc.title}?`" confirm-label="Xóa" danger>
                    <UiButton type="submit" variant="danger-text" size="sm" icon="delete" title="Xóa tài liệu" />
                </UiForm>
            </span>
        </li>
    </ul>
    <p v-else class="text-xs text-on-surface-subtle">{{ emptyText }}</p>
</template>
