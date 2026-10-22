<script setup>
/**
 * Mockup 01_Web_Admin/01_quan_ly_tai_lieu_giao_trinh: danh sách tài liệu; form tải lên (giáo trình → chặng → đối tượng xem → file)
 * mở bằng nút "Tải lên tài liệu" (hộp thoại).
 */
import { computed, ref } from 'vue';
import GetForm from '@/Components/Syllabus/GetForm.vue';
import { currentQuery } from '@/lib/url';

defineOptions({ layout: { title: 'Quản lý tài liệu giáo trình' } });

const props = defineProps({
    documents: { type: Object, required: true },
    curriculums: { type: Array, default: () => [] },
    canUpload: { type: Boolean, default: false },
});

const search = computed(() => currentQuery().get('search') ?? '');
const curriculumOptions = computed(() => props.curriculums.map((c) => ({ value: c.id, label: c.title })));

// Hộp thoại tải lên
const uploading = ref(false);
const curriculum = ref('');
const stage = ref('');
const teachers = ref(false);
const assistants = ref(false);
const downloadable = ref(false);
const fileName = ref('');
const dragging = ref(false);
const fileInput = ref(null);
const stages = computed(() => props.curriculums.find((c) => String(c.id) === String(curriculum.value))?.stages ?? []);

function drop(event) {
    dragging.value = false;
    if (event.dataTransfer.files.length) {
        fileInput.value.files = event.dataTransfer.files;
        fileName.value = event.dataTransfer.files[0].name;
    }
}
function uploaded() {
    uploading.value = false;
    curriculum.value = '';
    stage.value = '';
    teachers.value = assistants.value = downloadable.value = false;
    fileName.value = '';
}
</script>

<template>
    <UiPageHeader title="Quản lý tài liệu giáo trình" description="Quản lý và cập nhật tài liệu cho các khóa học.">
        <template #actions>
            <UiButton variant="secondary" icon="edit_document" :href="route('syllabus.builder')">Soạn syllabus</UiButton>
            <UiButton variant="secondary" icon="menu_book" :href="route('syllabus.teacher-view')">Xem như giáo viên</UiButton>
            <UiButton v-if="canUpload" icon="upload_file" @click="uploading = true">Tải lên tài liệu</UiButton>
        </template>
    </UiPageHeader>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <section class="flex min-w-0 flex-col gap-4 lg:col-span-12">
            <UiDataTable min-width="760px">
                <template #header>
                    <div class="flex items-center gap-2">
                        <h2 class="font-h3 text-h3 text-on-surface">Danh sách tài liệu đã tải lên</h2>
                        <UiBadge>{{ documents.total }} tài liệu</UiBadge>
                    </div>
                    <GetForm class="flex flex-wrap items-center gap-2">
                        <UiSelect name="curriculum_id" placeholder="Tất cả giáo trình" :options="curriculumOptions" />
                        <UiInput name="search" icon="search" :value="search" placeholder="Tìm kiếm tài liệu..." />
                    </GetForm>
                </template>
                <table>
                    <thead>
                        <tr>
                            <th>Tên tài liệu</th>
                            <th>Chặng học</th>
                            <th>Đối tượng xem</th>
                            <th>Trạng thái</th>
                            <th class="text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="doc in documents.data" :key="doc.id">
                            <td>
                                <div class="flex items-center gap-3">
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary-fixed/40 text-primary">
                                        <span class="material-symbols-outlined text-[18px]">{{ doc.icon }}</span>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="line-clamp-1 font-body-medium text-body-medium text-on-surface">{{ doc.title }}</p>
                                        <p class="mt-0.5 font-caption text-caption text-on-surface-variant">
                                            {{ doc.curriculum }} • <span class="font-mono uppercase">{{ doc.extension }}</span> • <span class="font-mono">{{ doc.size_human }}</span>
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap">{{ doc.stage_label || '—' }}</td>
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    <UiBadge v-for="label in doc.audience_labels" :key="label" :color="['Giáo viên', 'Trợ giảng'].includes(label) ? 'primary' : 'neutral'" :dot="false">{{ label }}</UiBadge>
                                </div>
                            </td>
                            <td class="whitespace-nowrap">
                                <UiBadge v-if="doc.downloadable" color="success" :dot="false" pill><span class="material-symbols-outlined text-[14px]">download</span>Có thể tải</UiBadge>
                                <UiBadge v-else color="error" :dot="false" pill><span class="material-symbols-outlined text-[14px]">visibility</span>Chỉ xem online</UiBadge>
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <UiButton v-if="doc.downloadable" variant="ghost" size="sm" icon="download" :href="route('syllabus.documents.file', { id: doc.id, download: 1 })" native title="Tải xuống" />
                                    <UiButton variant="ghost" size="sm" icon="visibility" :href="route('syllabus.teacher-view', { document: doc.id })" title="Xem chi tiết" />
                                    <UiForm v-if="canUpload" :action="route('syllabus.documents.destroy', doc.id)" method="delete" :confirm="`Xóa tài liệu ${doc.title}? File sẽ bị xóa khỏi máy chủ.`" confirm-label="Xóa" danger>
                                        <UiButton type="submit" variant="danger-text" size="sm" icon="delete" title="Xóa" />
                                    </UiForm>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!documents.data.length">
                            <td colspan="5"><UiEmptyState icon="folder_off" title="Chưa có tài liệu nào" description="Tài liệu được tải lên sẽ hiển thị tại đây." /></td>
                        </tr>
                    </tbody>
                </table>
                <template #footer><UiPagination :paginator="documents" unit="tài liệu" /></template>
            </UiDataTable>
        </section>
    </div>

    <UiModal v-if="canUpload" :show="uploading" title="Tải lên tài liệu mới" max-width="xl" @close="uploading = false">
        <UiEmptyState v-if="!curriculums.length" icon="library_add" title="Chưa có giáo trình" description="Tạo giáo trình ở màn Soạn syllabus trước khi tải tài liệu.">
            <UiButton icon="add" :href="route('syllabus.builder')">Tạo giáo trình</UiButton>
        </UiEmptyState>
        <UiForm v-else id="upload-document-form" :action="route('syllabus.documents.store')" method="post" class="space-y-md" reset-on-success @success="uploaded">
            <UiSelect id="upload_curriculum_id" v-model="curriculum" label="Chọn giáo trình" name="curriculum_id" required placeholder="-- Chọn giáo trình --" @change="stage = ''">
                <option v-for="c in curriculums" :key="c.id" :value="String(c.id)" :selected="String(c.id) === curriculum">{{ c.title }} ({{ c.code }} · {{ c.version }})</option>
            </UiSelect>
            <UiField label="Chọn chặng học" name="stage_id" required hint="Chặng lấy từ màn Soạn syllabus của giáo trình đã chọn.">
                <select v-model="stage" name="stage_id" :required="stages.length > 0" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base">
                    <option value="">-- Chọn chặng học --</option>
                    <option v-for="s in stages" :key="s.id" :value="String(s.id)">{{ s.label }}</option>
                </select>
            </UiField>
            <UiInput name="title" label="Tên tài liệu" required placeholder="IELTS Reading Masterclass - Student Book" />

            <UiField label="Chọn đối tượng xem" required hint="Admin, Học vụ, Học thuật luôn xem được mọi tài liệu.">
                <div class="grid grid-cols-2 gap-sm rounded-lg border border-outline-variant bg-surface-container-low p-md font-body-small text-body-small">
                    <label v-for="role in ['Admin', 'Học vụ', 'Học thuật']" :key="role" class="flex items-center gap-2 text-on-surface-variant">
                        <input type="checkbox" checked disabled class="h-4 w-4 rounded border-outline-variant text-primary opacity-70" />
                        <span>{{ role }}</span>
                    </label>
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
                    <p v-if="!fileName" class="font-caption text-caption text-on-surface-variant">Hỗ trợ PDF, DOCX, PPTX, XLSX, ảnh, audio, video (Tối đa 100MB)</p>
                    <p v-else class="font-caption text-caption font-semibold text-on-surface">{{ fileName }}</p>
                    <input
                        ref="fileInput"
                        type="file"
                        name="file"
                        required
                        class="sr-only"
                        accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.webp,.mp3,.wav,.m4a,.ogg,.mp4,.mov,.webm,.m4v"
                        @change="fileName = $event.target.files[0]?.name || ''"
                    />
                </label>
            </UiField>

            <UiAlert v-show="(teachers || assistants) && !downloadable" type="warning">
                <p class="font-body-small text-body-small">Khóa tải xuống — Giáo viên chỉ được phép xem trực tuyến để bảo vệ tài liệu.</p>
            </UiAlert>
        </UiForm>
        <template v-if="curriculums.length" #footer>
            <UiButton variant="secondary" @click="uploading = false">Hủy</UiButton>
            <UiButton type="submit" form="upload-document-form" icon="save">Lưu tài liệu</UiButton>
        </template>
    </UiModal>
</template>
