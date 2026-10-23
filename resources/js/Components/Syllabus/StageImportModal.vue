<script setup>
/**
 * Hộp thoại tải file .xlsx theo form Sheet 1 để import 1 chặng: stage = null → thêm chặng mới cuối giáo trình;
 * có stage → cập nhật chặng đó (buổi thứ i của file ghi đè buổi thứ i của chặng). Bước sau là trang Xem trước.
 */
defineProps({
    show: { type: Boolean, default: false },
    curriculumId: { type: Number, required: true },
    stage: { type: Object, default: null },
});
const emit = defineEmits(['close']);
</script>

<template>
    <UiModal :show="show" :title="stage ? `Import ${stage.label} từ file` : 'Thêm chặng từ file'" max-width="lg" @close="emit('close')">
        <UiForm id="stage-import-form" :action="route('syllabus.stage-import.preview', curriculumId)" method="post" class="space-y-md" @success="emit('close')">
            <input v-if="stage" type="hidden" name="stage_id" :value="stage.id" />
            <UiAlert type="info">
                Dùng file theo form Syllabus mẫu (mỗi file = 1 chặng). Dòng nền xám là ví dụ và được bỏ qua.
                <a :href="route('syllabus.stage-import.template')" class="font-semibold text-primary hover:underline">Tải file mẫu</a>
            </UiAlert>
            <UiAlert v-if="stage" type="warning">Buổi trong file ghi đè lần lượt các buổi hiện có của {{ stage.label }}; buổi thừa của chặng sẽ bị xóa. Bạn được xem trước trước khi lưu.</UiAlert>
            <UiField label="File syllabus (.xlsx)" name="file" required>
                <input type="file" name="file" required accept=".xlsx" class="block w-full text-body-small" />
            </UiField>
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="emit('close')">Hủy</UiButton>
            <UiButton type="submit" form="stage-import-form" icon="visibility">Xem trước</UiButton>
        </template>
    </UiModal>
</template>
