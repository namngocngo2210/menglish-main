<script setup>
/**
 * Hộp thoại tải tài liệu lên Kho syllabus, phạm vi điền sẵn theo chỗ bấm: buổi (lessonId), chặng (stageId) hoặc
 * chung cả giáo trình (không có cả hai). Đối tượng xem (GV / TG) và quyền tải về như màn Tài liệu cũ.
 */
import { ref } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    curriculumId: { type: Number, required: true },
    stageId: { type: Number, default: null },
    lessonId: { type: Number, default: null },
    scopeLabel: { type: String, default: '' },
});
const emit = defineEmits(['close']);

const teachers = ref(false);
const assistants = ref(false);
const downloadable = ref(false);
const fileName = ref('');
const dragging = ref(false);
const fileInput = ref(null);

function drop(event) {
    dragging.value = false;
    if (event.dataTransfer.files.length) {
        fileInput.value.files = event.dataTransfer.files;
        fileName.value = event.dataTransfer.files[0].name;
    }
}
function uploaded() {
    teachers.value = assistants.value = downloadable.value = false;
    fileName.value = '';
    emit('close');
}
</script>

<template>
    <UiModal :show="show" title="Tải lên tài liệu" max-width="xl" @close="emit('close')">
        <UiForm id="upload-document-form" :action="route('syllabus.documents.store')" method="post" class="space-y-md" reset-on-success @success="uploaded">
            <input type="hidden" name="curriculum_id" :value="props.curriculumId" />
            <input v-if="props.stageId" type="hidden" name="stage_id" :value="props.stageId" />
            <input v-if="props.lessonId" type="hidden" name="syllabus_lesson_id" :value="props.lessonId" />
            <UiAlert type="info">Tài liệu thuộc: <span class="font-semibold">{{ scopeLabel }}</span></UiAlert>
            <UiInput name="title" label="Tên tài liệu" required placeholder="PPT Unit 1 – Getting started" />

            <UiField label="Chọn đối tượng xem" required hint="Admin, Học thuật, Quản lý luôn xem được mọi tài liệu. Giáo viên chỉ thấy tài liệu của chặng lớp mình đang học.">
                <div class="grid grid-cols-2 gap-sm rounded-lg border border-outline-variant bg-surface-container-low p-md font-body-small text-body-small">
                    <label class="flex cursor-pointer items-center gap-2">
                        <input v-model="teachers" type="checkbox" name="visible_to_teachers" value="1" class="h-4 w-4 rounded border-outline-variant text-primary focus:ring-primary-container" />
                        <span class="font-medium text-on-surface">Giáo viên</span>
                    </label>
                    <label class="flex cursor-pointer items-center gap-2">
                        <input v-model="assistants" type="checkbox" name="visible_to_assistants" value="1" class="h-4 w-4 rounded border-outline-variant text-primary focus:ring-primary-container" />
                        <span class="font-medium text-on-surface">Trợ giảng</span>
                    </label>
                    <label class="col-span-2 flex cursor-pointer items-center gap-2 border-t border-outline-variant pt-sm">
                        <input v-model="downloadable" type="checkbox" name="downloadable" value="1" class="h-4 w-4 rounded border-outline-variant text-primary focus:ring-primary-container" />
                        <span class="font-medium text-on-surface">Cho phép GV/TG tải về (bỏ chọn = chỉ xem trực tuyến)</span>
                    </label>
                </div>
            </UiField>

            <UiField label="Tài liệu đính kèm" name="file" required>
                <label
                    :class="['flex cursor-pointer flex-col items-center justify-center gap-xs rounded-xl border-2 border-dashed px-md py-lg text-center transition-colors', dragging ? 'border-primary-container bg-primary-fixed/30' : 'border-outline-variant bg-surface-container-low hover:border-primary/50']"
                    @dragover.prevent="dragging = true"
                    @dragleave.prevent="dragging = false"
                    @drop.prevent="drop"
                >
                    <span class="material-symbols-outlined text-[36px] text-on-surface-variant">cloud_upload</span>
                    <p class="font-body-small text-body-small text-on-surface-variant">Kéo thả file vào đây hoặc</p>
                    <span class="inline-flex items-center rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-xs font-body-medium text-body-small text-primary">Chọn file từ máy tính</span>
                    <p v-if="!fileName" class="font-caption text-caption text-on-surface-variant">Hỗ trợ PDF, ảnh, audio, video (tối đa 100MB). Slide / Word hãy xuất sang PDF trước khi tải lên.</p>
                    <p v-else class="font-caption text-caption font-semibold text-on-surface">{{ fileName }}</p>
                    <input
                        ref="fileInput"
                        type="file"
                        name="file"
                        required
                        class="sr-only"
                        accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.mp3,.wav,.m4a,.ogg,.mp4,.mov,.webm,.m4v"
                        @change="fileName = $event.target.files[0]?.name || ''"
                    />
                </label>
            </UiField>

            <UiAlert v-show="(teachers || assistants) && !downloadable" type="warning">
                <p class="font-body-small text-body-small">Khóa tải xuống — Giáo viên chỉ được phép xem trực tuyến để bảo vệ tài liệu.</p>
            </UiAlert>
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="emit('close')">Hủy</UiButton>
            <UiButton type="submit" form="upload-document-form" icon="save">Lưu tài liệu</UiButton>
        </template>
    </UiModal>
</template>
