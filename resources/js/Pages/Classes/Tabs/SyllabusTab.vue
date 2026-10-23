<script setup>
/**
 * Trang lớp · Syllabus (docs/kho-syllabus.md §7.3 bước 5): chặng lớp đang học, GV đã xác nhận chưa, các buổi hiệu lực của
 * lớp (bản gốc + chỉnh sửa riêng). Học thuật "Sửa cho lớp" (bắt buộc lý do) → buổi có cờ "Chỉnh sửa thủ công", GV được báo.
 */
import { computed, ref } from 'vue';
import { route } from '@/lib/route';

const props = defineProps({
    klass: { type: Object, required: true },
    syllabus: { type: Object, required: true },
});

const editing = ref(null);
const editingKey = computed(() => editing.value?.id ?? 0);
const card = 'rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 shadow-sm';
const label = 'mb-1 block text-xs font-bold uppercase tracking-wider text-on-surface-subtle';
const textFields = [
    ['objectives', 'Mục tiêu', 3],
    ['content', 'Content (nội dung từng tiết)', 4],
    ['procedure', 'Quy trình tiết học', 3],
    ['activity_1', 'Hoạt động gợi ý 1', 2],
    ['activity_2', 'Hoạt động gợi ý 2', 2],
    ['homework_guide', 'Bài tập giấy', 2],
    ['homework_quizizz', 'Bài tập Quizizz', 2],
    ['homework_video', 'Bài tập Video', 2],
    ['output_product', 'Sản phẩm đầu ra', 2],
    ['note', 'Ghi chú', 2],
];
const linkFields = [
    ['ppt_url', 'Link PPT'],
    ['ppt_foreign_url', 'Link PPT GVNN'],
    ['quizizz_url', 'Link Quizizz'],
    ['test_url', 'Link Mini test / Kiểm tra từ'],
];
</script>

<template>
    <div class="space-y-5">
        <section :class="card">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2 border-b border-surface-container-highest pb-3">
                <h2 class="flex items-center gap-2 text-base font-bold text-on-surface">
                    <span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">menu_book</span>Syllabus của lớp
                </h2>
                <UiButton v-if="can('syllabus.assign')" variant="secondary" size="sm" icon="assignment_ind" :href="route('syllabus.assignments', { class_id: klass.id })">Giao chặng</UiButton>
            </div>
            <UiEmptyState v-if="!syllabus.stage_label" icon="menu_book" title="Lớp chưa được giao chặng" description="Học thuật giao chặng cho lớp ở màn Giao chặng." />
            <dl v-else class="grid grid-cols-1 gap-5 text-xs sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt :class="label">Chặng đang học</dt>
                    <dd class="font-bold text-on-surface">{{ syllabus.stage_label }}</dd>
                    <dd class="text-on-surface-variant">{{ syllabus.curriculum }}</dd>
                </div>
                <div>
                    <dt :class="label">Bắt đầu · số buổi</dt>
                    <dd class="font-bold text-on-surface">{{ syllabus.start_date ? formatDate(syllabus.start_date) : '—' }} · {{ syllabus.planned_sessions ?? '—' }} buổi</dd>
                    <dd class="text-on-surface-variant">Dự kiến kết thúc: {{ syllabus.deadline ? formatDate(syllabus.deadline) : 'TKB chưa đủ buổi' }}</dd>
                </div>
                <div>
                    <dt :class="label">Giáo viên</dt>
                    <dd class="font-bold text-on-surface">{{ syllabus.teacher ?? '—' }}</dd>
                </div>
                <div>
                    <dt :class="label">Xác nhận nhận syllabus</dt>
                    <dd v-if="syllabus.confirmed"><UiBadge color="success">Đã xác nhận</UiBadge> <span class="text-on-surface-variant">{{ syllabus.confirmed_by }} · {{ formatDate(syllabus.confirmed_at, 'd/m/Y H:i') }}</span></dd>
                    <dd v-else class="flex flex-wrap items-center gap-2">
                        <UiBadge color="warning">Chờ GV xác nhận</UiBadge>
                        <UiForm v-if="syllabus.remind_url" :action="syllabus.remind_url" method="post" class="inline">
                            <UiButton type="submit" size="sm" variant="ghost" icon="notifications_active">Nhắc lại</UiButton>
                        </UiForm>
                    </dd>
                </div>
            </dl>
        </section>

        <UiDataTable v-if="syllabus.stage_label" min-width="760px">
            <template #header>
                <h2 class="font-h3 text-h3 text-on-surface">Buổi học của chặng</h2>
                <UiBadge color="primary" :dot="false" pill>{{ syllabus.lessons.length }} buổi</UiBadge>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Buổi</th>
                        <th>Unit</th>
                        <th>Nội dung</th>
                        <th>Loại · mốc</th>
                        <th>Chỉnh sửa thủ công</th>
                        <th class="text-right"><span class="sr-only">Thao tác</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="l in syllabus.lessons" :key="l.id">
                        <td class="whitespace-nowrap font-semibold">Buổi {{ l.session_no }}</td>
                        <td class="text-on-surface-variant">{{ l.unit_number ? `Unit ${l.unit_number}` : '—' }}</td>
                        <td>
                            <p class="font-semibold text-on-surface">{{ l.data.title }}</p>
                            <p v-if="l.data.content" class="line-clamp-2 whitespace-pre-line text-xs text-on-surface-variant">{{ l.data.content }}</p>
                        </td>
                        <td class="space-y-1">
                            <UiBadge v-if="l.data.lesson_type !== 'lesson'">{{ l.type_label }}</UiBadge>
                            <UiBadge v-if="l.milestone_label" color="info">{{ l.milestone_label }}</UiBadge>
                        </td>
                        <td>
                            <template v-if="l.overridden">
                                <UiBadge color="warning">Có</UiBadge>
                                <p class="mt-1 text-xs text-on-surface-variant">Lý do: {{ l.override_reason }}</p>
                                <p v-if="l.override_by" class="text-xs text-on-surface-subtle">{{ l.override_by }} · {{ formatDate(l.override_at, 'd/m/Y H:i') }}</p>
                            </template>
                            <span v-else class="text-xs text-on-surface-subtle">Không</span>
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <template v-if="syllabus.can_edit">
                                <UiButton variant="ghost" size="sm" icon="edit" @click="editing = l">Sửa cho lớp</UiButton>
                                <UiForm v-if="l.overridden" :action="route('classes.syllabus.override.destroy', [klass.id, l.id])" method="delete" class="inline" confirm="Bỏ chỉnh sửa riêng, buổi này quay về bản gốc?">
                                    <UiButton type="submit" variant="ghost" size="sm" icon="undo">Về bản gốc</UiButton>
                                </UiForm>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!syllabus.lessons.length">
                        <td colspan="6"><UiEmptyState icon="description" title="Chặng chưa có buổi học" /></td>
                    </tr>
                </tbody>
            </table>
        </UiDataTable>
    </div>

    <UiModal v-if="syllabus.can_edit" :show="!!editing" :title="editing ? `Sửa Buổi ${editing.session_no} cho lớp ${klass.name}` : ''" max-width="3xl" @close="editing = null">
        <UiForm v-if="editing" id="class-lesson-form" :key="editingKey" :action="route('classes.syllabus.override', [klass.id, editing.id])" method="put" class="space-y-4" @success="editing = null">
            <UiAlert type="info" class="text-xs">Chỉ sửa cho lớp này; các trường không đổi vẫn theo bản gốc. GV của lớp nhận thông báo.</UiAlert>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2"><UiInput name="title" label="Tên buổi" :value="editing.data.title" /></div>
                <UiSelect name="lesson_type" label="Loại buổi" :value="editing.data.lesson_type ?? 'lesson'" :options="syllabus.lesson_types" />
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <UiTextarea v-for="[field, text, rows] in textFields" :key="field" :name="field" :label="text" :rows="rows" :value="editing.data[field]" />
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <UiInput v-for="[field, text] in linkFields" :key="field" type="url" :name="field" :label="text" :value="editing.data[field]" placeholder="https://..." />
            </div>
            <UiSelect name="notice_milestone" label="Mốc thông báo" placeholder="-- Không có --" :value="editing.data.notice_milestone ?? ''" :options="syllabus.milestones" />
            <UiTextarea name="reason" label="Lý do chỉnh sửa riêng cho lớp" required :rows="2" :value="editing.override_reason" />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="editing = null">Hủy</UiButton>
            <UiButton type="submit" form="class-lesson-form" icon="save">Lưu cho lớp</UiButton>
        </template>
    </UiModal>
</template>
