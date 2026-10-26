<script setup>
/**
 * Bài giảng PowerPoint của 1 buổi (Kho syllabus): PPT GV / PPT GVNN tải lên app (thay link Google Drive), bấm Trình chiếu mở
 * trang iframe Office Online ở tab mới. Học thuật tải lên / thay / xóa; người chỉ xem thấy nút Trình chiếu.
 */
import { route } from '@/lib/route';

defineProps({
    lesson: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
});

const kinds = [
    ['ppt', 'PPT'],
    ['ppt_foreign', 'PPT GVNN'],
];
</script>

<template>
    <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
        <div v-for="[kind, label] in kinds" :key="kind" class="space-y-2 rounded-lg border border-surface-container-highest p-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="flex items-center gap-1 font-semibold text-on-surface"><span class="material-symbols-outlined text-[16px] text-primary" aria-hidden="true">slideshow</span>{{ label }}</p>
                <div v-if="lesson.slides?.[kind]" class="flex items-center gap-1">
                    <UiButton size="sm" icon="co_present" :href="lesson.slides[kind].url" target="_blank">Trình chiếu</UiButton>
                    <UiForm v-if="canManage" :action="route('syllabus.slides.destroy', lesson.slides[kind].id)" method="delete" :confirm="`Xóa ${label} của Buổi ${lesson.session_no}?`" confirm-label="Xóa" danger>
                        <UiButton type="submit" variant="danger-text" size="sm" icon="delete" :title="`Xóa ${label}`" />
                    </UiForm>
                </div>
            </div>
            <p v-if="lesson.slides?.[kind]" class="truncate text-on-surface-variant" :title="lesson.slides[kind].name">{{ lesson.slides[kind].name }} · {{ lesson.slides[kind].size_human }}</p>
            <p v-else class="italic text-on-surface-subtle">Chưa tải file</p>
            <UiForm v-if="canManage" v-slot="{ errors }" :action="route('syllabus.slides.store', lesson.id)" method="post" reset-on-success class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="kind" :value="kind" />
                <input type="file" name="file" required accept=".pptx,.ppt,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/vnd.ms-powerpoint" :aria-label="`Chọn file ${label}`" class="min-w-0 flex-1 text-xs file:mr-2 file:rounded-md file:border-0 file:bg-surface-container-high file:px-2 file:py-1 file:text-xs file:font-semibold" />
                <UiButton type="submit" variant="secondary" size="sm" icon="upload_file">{{ lesson.slides?.[kind] ? 'Thay file' : 'Tải lên' }}</UiButton>
                <p v-if="errors.file" class="w-full text-error">{{ errors.file }}</p>
            </UiForm>
        </div>
    </div>
</template>
