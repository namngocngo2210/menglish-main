<script setup>
/**
 * Buổi học sinh vắng mà buổi bổ trợ dạy lại: nhãn buổi, nội dung (bài / từ vựng / ngữ pháp, lớp đã học gì, nhật ký dạy, BTVN)
 * và tài liệu của buổi trong Kho syllabus (mở xem trực tiếp). Thu gọn được để bảng không quá dài.
 *   <MissedLesson :missed="row.missed" :open="row.is_mine" />
 */
defineProps({
    missed: { type: Object, required: true },
    open: { type: Boolean, default: false },
});
</script>

<template>
    <details :open="open" class="group mt-1.5 rounded-lg border border-surface-container-highest bg-surface-container-low text-xs">
        <summary class="flex cursor-pointer list-none items-center gap-1.5 px-2.5 py-1.5 font-semibold text-on-surface [&::-webkit-details-marker]:hidden">
            <span class="material-symbols-outlined text-[16px] text-on-surface-variant transition-transform group-open:rotate-90" aria-hidden="true">chevron_right</span>
            <span class="min-w-0 flex-1 truncate">Buổi vắng: {{ missed.label }}</span>
            <span v-if="missed.documents?.length" class="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-primary-container/10 px-1.5 py-0.5 font-medium text-primary">
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">attach_file</span>{{ missed.documents.length }}
            </span>
        </summary>
        <div class="space-y-2 border-t border-surface-container-highest px-2.5 py-2">
            <p v-if="missed.content" class="whitespace-pre-line text-on-surface-variant">{{ missed.content }}</p>
            <p v-else class="italic text-on-surface-subtle">Chưa có nội dung bài / báo cáo trực lớp của buổi này.</p>
            <div v-if="missed.documents?.length">
                <p class="mb-1 font-semibold text-on-surface">Tài liệu của buổi</p>
                <ul class="flex flex-wrap gap-1.5">
                    <li v-for="doc in missed.documents" :key="doc.id">
                        <a
                            :href="doc.url"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex min-h-[32px] items-center gap-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-2 py-1 font-medium text-primary hover:border-primary-container hover:bg-primary-container/5"
                        >
                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ doc.icon }}</span>
                            {{ doc.title }}
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </details>
</template>
