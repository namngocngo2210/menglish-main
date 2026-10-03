<script setup>
/**
 * Báo cáo họp giáo viên theo tuần (mockup "Báo cáo họp giáo viên"): danh sách mới nhất trước, lọc tuần / giáo viên /
 * tình trạng; bấm dòng xem chi tiết; Thêm / Sửa mở modal gồm Thông tin chung, Nội dung chuyên môn, Đề xuất & Hành động.
 * Lớp lọc theo giáo viên đã chọn, hiện chương trình của lớp.
 */
import { computed, ref } from 'vue';
import { formatDate } from '@/lib/format';

defineOptions({ layout: { title: 'Họp giáo viên' } });

const props = defineProps({
    reports: { type: Object, required: true },
    teachers: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    currentWeek: { type: String, default: null },
});

const viewing = ref(null);
const editing = ref(null);
const deleting = ref(null);
const teacherId = ref('');
const classId = ref('');

function openForm(report = {}) {
    viewing.value = null;
    editing.value = report;
    teacherId.value = report.teacher_id ? String(report.teacher_id) : '';
    classId.value = report.class_id ? String(report.class_id) : '';
}

const classOptions = computed(() => {
    const e = editing.value;
    const list = e?.class_id && !props.classes.some((c) => c.value === e.class_id)
        ? [...props.classes, { value: e.class_id, label: e.class, course: e.course, teacher_ids: [e.teacher_id] }]
        : props.classes;
    return teacherId.value ? list.filter((c) => c.teacher_ids.includes(Number(teacherId.value))) : [];
});
const course = computed(() => classOptions.value.find((c) => String(c.value) === String(classId.value))?.course);
function pickTeacher(value) {
    teacherId.value = value;
    if (!classOptions.value.some((c) => String(c.value) === String(classId.value))) {
        classId.value = classOptions.value.length === 1 ? String(classOptions.value[0].value) : '';
    }
}
const week = (r) => `${formatDate(r.week_start, 'd/m')} – ${formatDate(r.week_end, 'd/m/Y')}`;
const NOTES = [
    ['syllabus_note', 'Ghi chú Syllabus'],
    ['scores_note', 'Ghi chú Bảng điểm'],
    ['class_note', 'Ghi chú tình hình lớp / học sinh'],
    ['academic_order', 'Order học thuật'],
    ['recommendation', 'Nhận xét / đề xuất của Học thuật'],
];
</script>

<template>
    <UiPageHeader title="Báo cáo họp giáo viên" icon="groups" description="Ghi nhận nội dung họp và thu thập dữ liệu chuyên môn theo tuần.">
        <template v-if="canManage" #actions>
            <UiButton icon="add" @click="openForm()">Thêm báo cáo họp</UiButton>
        </template>
    </UiPageHeader>

    <UiFilterBar :search="false">
        <UiDate name="week" label="Tuần (chọn 1 ngày bất kỳ)" />
        <UiSelect name="teacher_id" label="Giáo viên" :options="teachers" placeholder="Tất cả giáo viên" />
        <UiSelect name="status" label="Tình trạng" :options="statuses" placeholder="Tất cả tình trạng" />
    </UiFilterBar>

    <UiDataTable min-width="960px">
        <table>
            <thead>
                <tr>
                    <th>Tuần</th>
                    <th>Giáo viên</th>
                    <th>Lớp</th>
                    <th>Tình hình lớp / học sinh</th>
                    <th>Tình trạng</th>
                    <th class="text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in reports.data" :key="r.id" class="cursor-pointer" @click="viewing = r">
                    <td class="whitespace-nowrap font-code">{{ week(r) }}</td>
                    <td class="font-semibold text-on-surface">{{ r.teacher }}</td>
                    <td>
                        {{ r.class ?? '—' }}
                        <span v-if="r.course" class="block font-body-small text-body-small text-on-surface-variant">{{ r.course }}</span>
                    </td>
                    <td class="max-w-[320px]"><p class="line-clamp-2">{{ r.class_note }}</p></td>
                    <td><UiBadge :color="r.status_color">{{ r.status_label }}</UiBadge></td>
                    <td class="whitespace-nowrap text-right" @click.stop>
                        <div class="inline-flex items-center gap-xs">
                            <UiButton variant="ghost" size="sm" icon="visibility" @click="viewing = r">Xem</UiButton>
                            <template v-if="canManage">
                                <UiButton variant="ghost" size="sm" icon="edit" :aria-label="`Sửa báo cáo họp ${r.teacher}`" @click="openForm(r)">Sửa</UiButton>
                                <UiButton variant="danger-text" size="sm" icon="delete" :aria-label="`Xóa báo cáo họp ${r.teacher}`" @click="deleting = r" />
                            </template>
                        </div>
                    </td>
                </tr>
                <tr v-if="!reports.data.length">
                    <td colspan="6"><UiEmptyState icon="groups" title="Chưa có báo cáo họp giáo viên nào" /></td>
                </tr>
            </tbody>
        </table>
        <template #footer><UiPagination :paginator="reports" unit="báo cáo" /></template>
    </UiDataTable>

    <!-- Chi tiết -->
    <UiModal :show="!!viewing" title="Báo cáo họp giáo viên" max-width="lg" @close="viewing = null">
        <template v-if="viewing">
            <dl class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <div><dt class="font-body-small text-body-small text-on-surface-variant">Tuần</dt><dd class="font-semibold">{{ week(viewing) }}</dd></div>
                <div><dt class="font-body-small text-body-small text-on-surface-variant">Tình trạng</dt><dd><UiBadge :color="viewing.status_color">{{ viewing.status_label }}</UiBadge></dd></div>
                <div><dt class="font-body-small text-body-small text-on-surface-variant">Giáo viên</dt><dd class="font-semibold">{{ viewing.teacher }}</dd></div>
                <div><dt class="font-body-small text-body-small text-on-surface-variant">Lớp</dt><dd>{{ viewing.class ?? '—' }}<template v-if="viewing.course"> · {{ viewing.course }}</template></dd></div>
                <div><dt class="font-body-small text-body-small text-on-surface-variant">Người ghi</dt><dd>{{ viewing.author }}</dd></div>
            </dl>
            <div class="mt-lg space-y-md">
                <template v-for="[key, label] in NOTES" :key="key">
                    <div v-if="viewing[key]">
                        <h3 class="font-body-small text-body-small font-semibold uppercase text-on-surface-variant">{{ label }}</h3>
                        <p class="mt-xs whitespace-pre-line text-on-surface">{{ viewing[key] }}</p>
                    </div>
                </template>
            </div>
        </template>
        <template #footer>
            <UiButton variant="secondary" @click="viewing = null">Đóng</UiButton>
            <UiButton v-if="canManage && viewing" icon="edit" @click="openForm(viewing)">Sửa</UiButton>
        </template>
    </UiModal>

    <!-- Thêm / sửa -->
    <UiModal :show="!!editing" :title="editing?.id ? 'Sửa báo cáo họp giáo viên' : 'Thêm báo cáo họp giáo viên'" max-width="2xl" @close="editing = null">
        <UiForm
            v-if="editing"
            id="teacher-meeting-form"
            :action="editing.id ? route('class-quality.teacher-meetings.update', editing.id) : route('class-quality.teacher-meetings.store')"
            :method="editing.id ? 'put' : 'post'"
            class="space-y-lg"
            @success="editing = null"
        >
            <section class="space-y-md">
                <h3 class="font-semibold text-on-surface">Thông tin chung</h3>
                <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                    <UiDate name="week_start" label="Tuần (chọn 1 ngày bất kỳ)" required :value="editing.week_start ?? currentWeek" />
                    <UiSelect name="teacher_id" label="Giáo viên" required :options="teachers" placeholder="-- Chọn giáo viên --" :model-value="teacherId" @update:model-value="pickTeacher" />
                    <UiSelect v-model="classId" name="class_id" label="Lớp học" :options="classOptions" :placeholder="teacherId ? '-- Chọn lớp --' : 'Chọn giáo viên trước'" :hint="course ? `Chương trình: ${course}` : null" />
                </div>
            </section>
            <section class="space-y-md">
                <h3 class="font-semibold text-on-surface">Nội dung chuyên môn</h3>
                <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                    <UiTextarea name="syllabus_note" label="Ghi chú Syllabus" hint="Tùy chọn" :rows="3" :value="editing.syllabus_note" />
                    <UiTextarea name="scores_note" label="Ghi chú Bảng điểm" hint="Tùy chọn" :rows="3" :value="editing.scores_note" />
                </div>
                <UiTextarea name="class_note" label="Ghi chú tình hình lớp / học sinh" required :rows="3" :value="editing.class_note" />
            </section>
            <section class="space-y-md">
                <h3 class="font-semibold text-on-surface">Đề xuất & Hành động</h3>
                <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                    <UiTextarea name="academic_order" label="Order học thuật" :rows="3" :value="editing.academic_order" />
                    <UiTextarea name="recommendation" label="Nhận xét / đề xuất của Học thuật" :rows="3" :value="editing.recommendation" />
                </div>
                <div class="sm:w-1/2">
                    <UiSelect name="status" label="Tình trạng xử lý" required :options="statuses" placeholder="-- Chọn tình trạng --" :value="editing.status" />
                </div>
            </section>
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="editing = null">Hủy</UiButton>
            <UiButton type="submit" form="teacher-meeting-form" icon="save">Lưu báo cáo</UiButton>
        </template>
    </UiModal>

    <UiModal :show="!!deleting" title="Xóa báo cáo họp giáo viên" max-width="md" @close="deleting = null">
        <p v-if="deleting">Xóa báo cáo họp với <strong>{{ deleting.teacher }}</strong> tuần <strong>{{ week(deleting) }}</strong>?</p>
        <template #footer>
            <UiButton variant="secondary" @click="deleting = null">Hủy</UiButton>
            <UiForm v-if="deleting" :action="route('class-quality.teacher-meetings.destroy', deleting.id)" method="delete" back @success="deleting = null">
                <UiButton type="submit" variant="danger" icon="delete">Xóa</UiButton>
            </UiForm>
        </template>
    </UiModal>
</template>
