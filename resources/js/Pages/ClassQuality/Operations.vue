<script setup>
/**
 * QA Observation — dự giờ đội vận hành (mockup 02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/08): danh sách lượt dự giờ mới nhất trước,
 * bấm dòng xem chi tiết; "Ghi nhận dự giờ mới" / Sửa mở modal. Form chỉ cho chọn giáo viên đang có lớp hoạt động,
 * lớp lọc theo giáo viên đã chọn. Các mục nội dung là tùy chọn, xếp loại bắt buộc.
 */
import { computed, ref } from 'vue';

defineOptions({ layout: { title: 'Dự giờ vận hành' } });

const props = defineProps({
    observations: { type: Object, required: true },
    classes: { type: Array, default: () => [] },
    teachers: { type: Array, default: () => [] },
    filterTeachers: { type: Array, default: () => [] },
    ratings: { type: Array, default: () => [] },
    noteFields: { type: Object, default: () => ({}) },
    canRecord: { type: Boolean, default: false },
    today: { type: String, default: null },
});

const viewing = ref(null);
const editing = ref(null); // null = đóng, {} = ghi nhận mới, bản ghi = sửa
const deleting = ref(null);
const teacherId = ref('');
const classId = ref('');

function openForm(observation = {}) {
    viewing.value = null;
    editing.value = observation;
    teacherId.value = observation.teacher_id ? String(observation.teacher_id) : '';
    classId.value = observation.class_id ? String(observation.class_id) : '';
}

// Khi sửa lượt dự giờ của lớp / giáo viên không còn hoạt động: giữ lựa chọn cũ trong danh sách.
const teacherOptions = computed(() => {
    const e = editing.value;
    if (e?.teacher_id && !props.teachers.some((t) => t.value === e.teacher_id)) return [...props.teachers, { value: e.teacher_id, label: e.teacher }];
    return props.teachers;
});
const classOptions = computed(() => {
    const e = editing.value;
    const list = e?.class_id && !props.classes.some((c) => c.value === e.class_id)
        ? [...props.classes, { value: e.class_id, label: e.class, teacher_ids: [e.teacher_id] }]
        : props.classes;
    return teacherId.value ? list.filter((c) => c.teacher_ids.includes(Number(teacherId.value))) : [];
});
function pickTeacher(value) {
    teacherId.value = value;
    if (!classOptions.value.some((c) => String(c.value) === String(classId.value))) {
        classId.value = classOptions.value.length === 1 ? String(classOptions.value[0].value) : '';
    }
}
const filledNotes = (o) => Object.entries(props.noteFields).filter(([key]) => o.notes[key]);
</script>

<template>
    <UiPageHeader title="QA Observation — Dự giờ vận hành" icon="visibility" description="Ghi nhận và theo dõi lịch sử dự giờ quan sát lớp học của đội vận hành (QA / Học vụ).">
        <template v-if="canRecord" #actions>
            <UiButton icon="add_circle" @click="openForm()">Ghi nhận dự giờ mới</UiButton>
        </template>
    </UiPageHeader>

    <UiFilterBar :search="false">
        <UiSelect name="teacher_id" label="Giáo viên" :options="filterTeachers" placeholder="Tất cả giáo viên" />
        <UiSelect name="rating" label="Xếp loại" :options="ratings" placeholder="Tất cả xếp loại" />
    </UiFilterBar>

    <UiDataTable min-width="860px">
        <table>
            <thead>
                <tr>
                    <th>Ngày dự giờ</th>
                    <th>Giáo viên</th>
                    <th>Lớp</th>
                    <th>Người dự giờ</th>
                    <th>Xếp loại</th>
                    <th class="text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="o in observations.data" :key="o.id" class="cursor-pointer" tabindex="0" @click="viewing = o" @keydown.enter.self="viewing = o">
                    <td class="whitespace-nowrap">
                        <span class="inline-flex items-center gap-xs font-code">
                            <span class="material-symbols-outlined text-[16px] text-on-surface-variant" aria-hidden="true">calendar_today</span>
                            {{ formatDate(o.observed_on) }}
                        </span>
                    </td>
                    <td>
                        <span class="font-semibold text-on-surface">{{ o.teacher }}</span>
                        <span v-if="o.teacher_code" class="block font-body-small text-body-small text-on-surface-variant">Mã GV: {{ o.teacher_code }}</span>
                    </td>
                    <td>
                        {{ o.class }}
                        <span v-if="o.branch" class="block font-body-small text-body-small text-on-surface-variant">{{ o.branch }}</span>
                    </td>
                    <td>{{ o.observer }}</td>
                    <td><UiBadge :color="o.rating_color">{{ o.rating_label }}</UiBadge></td>
                    <td class="whitespace-nowrap text-right" @click.stop>
                        <div class="inline-flex items-center gap-xs">
                            <UiButton variant="ghost" size="sm" icon="visibility" @click="viewing = o">Xem</UiButton>
                            <template v-if="canRecord">
                                <UiButton variant="ghost" size="sm" icon="edit" :aria-label="`Sửa lượt dự giờ ${o.teacher} ${formatDate(o.observed_on)}`" @click="openForm(o)">Sửa</UiButton>
                                <UiButton variant="danger-text" size="sm" icon="delete" :aria-label="`Xóa lượt dự giờ ${o.teacher} ${formatDate(o.observed_on)}`" @click="deleting = o" />
                            </template>
                        </div>
                    </td>
                </tr>
                <tr v-if="!observations.data.length">
                    <td colspan="6">
                        <UiEmptyState icon="visibility" title="Chưa có lượt dự giờ nào" :description="canRecord ? 'Bấm “Ghi nhận dự giờ mới” sau mỗi buổi quan sát lớp.' : null" />
                    </td>
                </tr>
            </tbody>
        </table>
        <template #footer><UiPagination :paginator="observations" unit="lượt dự giờ" /></template>
    </UiDataTable>

    <!-- Chi tiết lượt dự giờ -->
    <UiModal :show="!!viewing" title="Chi tiết lượt dự giờ" max-width="lg" @close="viewing = null">
        <template v-if="viewing">
            <dl class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <div><dt class="font-body-small text-body-small text-on-surface-variant">Ngày dự giờ</dt><dd class="font-semibold">{{ formatDate(viewing.observed_on) }}</dd></div>
                <div><dt class="font-body-small text-body-small text-on-surface-variant">Xếp loại</dt><dd><UiBadge :color="viewing.rating_color">{{ viewing.rating_label }}</UiBadge></dd></div>
                <div><dt class="font-body-small text-body-small text-on-surface-variant">Giáo viên</dt><dd class="font-semibold">{{ viewing.teacher }}</dd></div>
                <div><dt class="font-body-small text-body-small text-on-surface-variant">Lớp</dt><dd>{{ viewing.class }}</dd></div>
                <div><dt class="font-body-small text-body-small text-on-surface-variant">Người dự giờ</dt><dd>{{ viewing.observer }}</dd></div>
            </dl>
            <div class="mt-lg space-y-md">
                <div v-for="[key, label] in filledNotes(viewing)" :key="key">
                    <h3 class="font-body-small text-body-small font-semibold uppercase text-on-surface-variant">{{ label }}</h3>
                    <p class="mt-xs whitespace-pre-line text-on-surface">{{ viewing.notes[key] }}</p>
                </div>
                <p v-if="!filledNotes(viewing).length" class="italic text-on-surface-variant">Không ghi nội dung quan sát chi tiết.</p>
            </div>
        </template>
        <template #footer>
            <UiButton variant="secondary" @click="viewing = null">Đóng</UiButton>
            <UiButton v-if="canRecord && viewing" icon="edit" @click="openForm(viewing)">Sửa</UiButton>
        </template>
    </UiModal>

    <!-- Ghi nhận / sửa lượt dự giờ -->
    <UiModal :show="!!editing" :title="editing?.id ? 'Sửa lượt dự giờ' : 'Ghi nhận lượt dự giờ mới'" max-width="2xl" @close="editing = null">
        <UiForm
            v-if="editing"
            id="qa-observation-form"
            :action="editing.id ? route('class-quality.operations.update', editing.id) : route('class-quality.operations.store')"
            :method="editing.id ? 'put' : 'post'"
            class="space-y-md"
            @success="editing = null"
        >
            <p class="font-body-small text-body-small text-on-surface-variant">Nhập đầy đủ thông tin buổi quan sát thực tế (các mục nội dung là tùy chọn).</p>
            <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                <UiDate name="observed_on" label="Ngày dự giờ" required :value="editing.observed_on ?? today" :max="today" />
                <UiSelect name="teacher_id" label="Giáo viên" required :options="teacherOptions" placeholder="-- Chọn giáo viên --" :model-value="teacherId" hint="Chỉ hiện giáo viên đang có lớp hoạt động" @update:model-value="pickTeacher" />
                <UiSelect v-model="classId" name="class_id" label="Lớp" required :options="classOptions" :placeholder="teacherId ? '-- Chọn lớp học --' : 'Chọn giáo viên trước'" hint="Lọc theo giáo viên đã chọn" />
            </div>
            <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                <UiTextarea v-for="(label, key) in noteFields" :key="key" :name="key" :label="label" :rows="3" :value="editing.notes?.[key]" />
            </div>
            <div class="sm:w-1/3">
                <UiSelect name="rating" label="Xếp loại" required :options="ratings" placeholder="-- Chọn xếp loại --" :value="editing.rating" hint="Bắt buộc đánh giá" />
            </div>
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="editing = null">Hủy</UiButton>
            <UiButton type="submit" form="qa-observation-form" icon="save">Lưu</UiButton>
        </template>
    </UiModal>

    <!-- Xác nhận xóa -->
    <UiModal :show="!!deleting" title="Xóa lượt dự giờ" max-width="md" @close="deleting = null">
        <p v-if="deleting">Xóa lượt dự giờ <strong>{{ deleting.teacher }}</strong> ngày <strong>{{ formatDate(deleting.observed_on) }}</strong>?</p>
        <template #footer>
            <UiButton variant="secondary" @click="deleting = null">Hủy</UiButton>
            <UiForm v-if="deleting" :action="route('class-quality.operations.destroy', deleting.id)" method="delete" back @success="deleting = null">
                <UiButton type="submit" variant="danger" icon="delete">Xóa</UiButton>
            </UiForm>
        </template>
    </UiModal>
</template>
