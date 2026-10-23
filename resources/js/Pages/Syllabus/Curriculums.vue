<script setup>
/**
 * Kho syllabus (docs/kho-syllabus.md): bảng giáo trình gốc — bấm dòng mở trang chi tiết (Thông tin / Chặng & buổi).
 * Chỉ người có quyền syllabus.library (Học thuật, Quản lý, Admin). Tạo giáo trình mở bằng hộp thoại.
 */
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import GetForm from '@/Components/Syllabus/GetForm.vue';

defineOptions({ layout: { title: 'Kho syllabus' } });

defineProps({
    curriculums: { type: Object, required: true },
    search: { type: String, default: '' },
    courses: { type: Array, default: () => [] },
    levels: { type: Array, default: () => [] },
    newCode: { type: String, default: '' },
});

const creating = ref(false);
</script>

<template>
    <UiPageHeader title="Kho syllabus" description="Syllabus gốc của trung tâm theo chương trình → chặng. Lớp học dùng syllabus được giao từ kho.">
        <template #actions>
            <UiButton variant="secondary" icon="upload_file" :href="route('syllabus.import')">Import giáo trình mới</UiButton>
            <UiButton icon="library_add" @click="creating = true">Tạo giáo trình</UiButton>
        </template>
    </UiPageHeader>

    <UiModal :show="creating" title="Tạo giáo trình mới" max-width="xl" @close="creating = false">
        <UiForm id="new-curriculum-form" :action="route('syllabus.curriculums.store')" method="post" class="grid grid-cols-1 gap-4 p-md md:grid-cols-2" @success="creating = false">
            <UiInput name="code" label="Mã giáo trình" required :value="newCode" />
            <UiInput name="version" label="Phiên bản" required value="v1.0" />
            <UiInput name="title" label="Tên giáo trình" required class="md:col-span-2" placeholder="Global Success 6" />
            <UiSelect name="course_id" label="Khóa học áp dụng" placeholder="-- Chọn khóa học --" value="" :options="courses" />
            <UiInput name="stage_name" label="Tên chặng đầu tiên" placeholder="Chặng 1: Unit 1 – 3" hint="Để trống sẽ đặt là “Chặng 1”." />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="creating = false">Hủy</UiButton>
            <UiButton type="submit" form="new-curriculum-form" icon="save">Tạo giáo trình</UiButton>
        </template>
    </UiModal>

    <UiDataTable min-width="900px">
        <template #header>
            <div class="flex items-center gap-2">
                <h2 class="font-h3 text-h3 text-on-surface">Giáo trình</h2>
                <UiBadge>{{ curriculums.total }} giáo trình</UiBadge>
            </div>
            <GetForm :action="route('syllabus.curriculums.index')" class="flex flex-wrap items-center gap-2">
                <UiInput type="search" name="search" icon="search" :value="search" placeholder="Tìm mã, tên giáo trình..." aria-label="Tìm giáo trình" />
            </GetForm>
        </template>
        <table>
            <thead>
                <tr>
                    <th>Mã</th>
                    <th>Tên giáo trình</th>
                    <th>Phiên bản</th>
                    <th>Khóa · Trình độ</th>
                    <th class="text-right">Chặng / Buổi</th>
                    <th class="text-right">Lớp đang học</th>
                    <th>Cập nhật</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="c in curriculums.data" :key="c.id" :data-href="c.url" class="cursor-pointer">
                    <td class="whitespace-nowrap font-code text-body-small">{{ c.code }}</td>
                    <td>
                        <Link :href="c.url" class="font-semibold text-on-surface hover:text-primary">{{ c.title }}</Link>
                    </td>
                    <td class="whitespace-nowrap">{{ c.version }}</td>
                    <td class="text-on-surface-variant">
                        {{ c.course || '—' }}
                        <span v-if="c.levels" class="block text-body-small">{{ c.levels }}</span>
                    </td>
                    <td class="whitespace-nowrap text-right">{{ c.stages_count }} / {{ c.lessons_count }}</td>
                    <td class="text-right">
                        <UiBadge v-if="c.open_classes_count" color="primary">{{ c.open_classes_count }} lớp</UiBadge>
                        <span v-else class="text-on-surface-subtle">—</span>
                    </td>
                    <td class="whitespace-nowrap">{{ formatDate(c.updated_at) }}</td>
                </tr>
                <tr v-if="!curriculums.data.length">
                    <td colspan="7">
                        <UiEmptyState icon="library_books" :title="search ? 'Không tìm thấy giáo trình phù hợp' : 'Kho syllabus chưa có giáo trình'" description="Tạo giáo trình mới hoặc import từ file mẫu." />
                    </td>
                </tr>
            </tbody>
        </table>
        <template #footer><UiPagination :paginator="curriculums" unit="giáo trình" /></template>
    </UiDataTable>
</template>
