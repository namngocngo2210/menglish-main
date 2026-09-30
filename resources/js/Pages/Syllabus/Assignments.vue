<script setup>
/**
 * Mockup 01_Web_Admin/03: "Lịch sử phân quyền chặng học"; form "Thiết lập chặng mới" (lớp, GV, chặng, ngày bắt đầu, lưu ý R19)
 * mở bằng nút trên đầu trang (hộp thoại). Chỉnh sửa / đóng tay chặng đang hiệu lực cũng bằng hộp thoại.
 */
import { computed, reactive, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import GetForm from '@/Components/Syllabus/GetForm.vue';
import { currentQuery } from '@/lib/url';

defineOptions({ layout: { title: 'Giao chặng học cho giáo viên' } });

const props = defineProps({
    assignments: { type: Object, required: true },
    teachers: { type: Array, default: () => [] },
    curriculums: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    classCurriculum: { type: Object, default: () => ({}) },
    classOpenStage: { type: Object, default: () => ({}) },
    canManage: { type: Boolean, default: false },
    canOverride: { type: Boolean, default: false },
    canViewLog: { type: Boolean, default: false },
    hasTickets: { type: Boolean, default: false },
});

const search = computed(() => currentQuery().get('search') ?? '');
const classOptions = computed(() => props.classes.map((c) => ({ value: c.id, label: c.name })));
const statusOptions = [
    { value: 'in_progress', label: 'Đang hiệu lực' },
    { value: 'completed', label: 'Đã đóng' },
];
const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

// Hộp thoại "Thiết lập chặng mới"
const creating = ref(false);
const classId = ref('');
const curriculumId = ref('');
const stageId = ref('');
const replace = ref(false);
const stages = computed(() => props.curriculums.find((c) => String(c.id) === curriculumId.value)?.stages ?? []);
function pickClass() {
    const c = props.classCurriculum[classId.value];
    if (c) curriculumId.value = String(c);
    stageId.value = '';
}
function created() {
    creating.value = false;
    classId.value = curriculumId.value = stageId.value = '';
    replace.value = false;
}

// Hộp thoại chỉnh sửa / đóng tay
const editing = ref(null);
const edit = reactive({ user_id: '', start_date: '', deadline: '' });
function openEdit(as) {
    Object.assign(edit, { user_id: as.user_id, start_date: as.start_date ?? '', deadline: as.deadline ?? '' });
    editing.value = as;
}
const closing = ref(null);
const label = (as) => `${as.stage_label} — lớp ${as.class_name ?? ''}`;
</script>

<template>
    <UiPageHeader title="Giao chặng học cho giáo viên" icon="assignment_ind" description="Thiết lập quyền truy cập giáo trình theo từng chặng học cho giáo viên của từng lớp.">
        <template #actions>
            <UiButton v-if="canViewLog" variant="secondary" icon="history" :href="route('activity-logs.index', { log_name: 'Giáo trình & Syllabus' })">Xem log hệ thống</UiButton>
            <UiButton variant="secondary" icon="menu_book" :href="route('syllabus.teacher-view')">Màn GV xem giáo trình</UiButton>
            <UiButton v-if="canManage" icon="add_task" @click="creating = true">Thiết lập chặng mới</UiButton>
        </template>
    </UiPageHeader>

    <div class="grid grid-cols-1 gap-6 2xl:grid-cols-12">
        <!-- Lịch sử phân quyền chặng học -->
        <div class="min-w-0 2xl:col-span-12">
            <UiDataTable min-width="860px">
                <template #header>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-[20px] text-primary">history_edu</span>
                        <h2 class="font-h3 text-h3 text-on-surface">Lịch sử phân quyền chặng học</h2>
                        <UiBadge>{{ assignments.total }} lượt</UiBadge>
                    </div>
                    <GetForm :action="route('syllabus.assignments')" class="flex flex-wrap items-center gap-2">
                        <UiInput type="search" name="search" icon="search" :value="search" placeholder="Tìm tên giáo viên, lớp..." />
                        <UiSelect name="class_id" placeholder="Tất cả lớp" :options="classOptions" />
                        <UiSelect name="status" placeholder="Tất cả trạng thái" :options="statusOptions" />
                    </GetForm>
                </template>
                <table>
                    <thead>
                        <tr>
                            <th>Lớp học</th>
                            <th>Giáo viên</th>
                            <th>Chặng học</th>
                            <th>Thời hạn</th>
                            <th>Trạng thái</th>
                            <th class="text-right">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="as in assignments.data" :key="as.id" class="align-top">
                            <td>
                                <p class="font-body-medium text-body-medium font-semibold text-on-surface">{{ as.class_name ?? 'Chưa gắn lớp' }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">{{ as.class_place || '—' }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">{{ as.curriculum }}</p>
                            </td>
                            <td>
                                <div class="flex items-center gap-sm">
                                    <UiAvatar :name="as.teacher ?? '?'" size="sm" />
                                    <div>
                                        <p class="font-body-medium text-body-small text-on-surface">{{ as.teacher ?? '—' }}</p>
                                        <p v-if="as.teacher_code" class="font-caption text-caption text-on-surface-variant">ID: {{ as.teacher_code }}</p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="inline-flex rounded-md bg-primary-fixed/50 px-sm py-0.5 font-label text-label text-primary">{{ as.stage_label ?? '—' }}</span>
                                <p class="mt-1 font-caption text-caption text-on-surface-variant">{{ as.assigned_chapters }}</p>
                                <p v-if="as.extra_sessions" class="font-caption text-caption text-warning">+{{ as.extra_sessions }} buổi giãn tiến độ</p>
                            </td>
                            <td class="space-y-1 whitespace-nowrap font-caption text-caption">
                                <p class="flex items-center gap-1 text-on-surface"><span class="material-symbols-outlined text-[14px]">calendar_today</span>{{ formatDate(as.start_date) }}</p>
                                <template v-if="as.closed_at">
                                    <p class="flex items-center gap-1 text-on-surface-variant"><span class="material-symbols-outlined text-[14px]">event_busy</span>{{ formatDate(as.closed_at) }}</p>
                                    <p v-if="as.close_reason" class="max-w-[200px] whitespace-normal text-on-surface-variant">{{ as.close_reason }}</p>
                                </template>
                                <template v-else>
                                    <p class="flex items-center gap-1 text-on-surface-variant"><span class="material-symbols-outlined text-[14px]">event_repeat</span>Tự động đóng theo sự kiện</p>
                                    <p v-if="as.deadline" class="text-on-surface-variant">Dự kiến xong: {{ formatDate(as.deadline) }}</p>
                                </template>
                            </td>
                            <td class="whitespace-nowrap">
                                <UiBadge v-if="as.is_open" color="success">Đang hiệu lực</UiBadge>
                                <template v-else>
                                    <UiBadge>Đã đóng</UiBadge>
                                    <p v-if="as.curriculum_completed" class="mt-1 font-caption text-caption text-tertiary">Hoàn thành giáo trình</p>
                                </template>
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <div v-if="as.is_open" class="flex items-center justify-end gap-1">
                                    <UiButton v-if="canManage" variant="ghost" size="sm" icon="edit" title="Chỉnh sửa" @click="openEdit(as)" />
                                    <UiButton v-if="canOverride" variant="secondary" size="sm" icon="lock" @click="closing = as">Đóng tay</UiButton>
                                </div>
                                <span v-else class="font-caption text-caption text-on-surface-variant" :title="as.closing_big_test ? 'Đóng bởi Big Test ' + as.closing_big_test : ''">{{ as.closing_big_test ? 'Big Test ' + as.closing_big_test : '—' }}</span>
                            </td>
                        </tr>
                        <tr v-if="!assignments.data.length">
                            <td colspan="6"><UiEmptyState icon="assignment_ind" title="Chưa có lớp nào được giao chặng" /></td>
                        </tr>
                    </tbody>
                </table>
                <template #footer><UiPagination :paginator="assignments" unit="lượt" /></template>
            </UiDataTable>
        </div>

        <!-- Khung hỗ trợ để cuối trang -->
        <section class="flex items-start justify-between gap-md rounded-xl border border-outline-variant bg-surface-container-low p-lg 2xl:col-span-12">
            <div>
                <h4 class="font-body-medium text-body-medium font-semibold text-on-surface">Cần hỗ trợ?</h4>
                <p class="mt-xs font-body-small text-body-small text-on-surface-variant">Liên hệ bộ phận Học thuật hoặc Kỹ thuật nếu bạn gặp vấn đề trong quá trình phân quyền giáo trình.</p>
                <Link v-if="hasTickets" :href="route('tickets.create')" class="mt-sm inline-flex items-center gap-1 font-body-small text-body-small font-semibold text-primary hover:underline">Tạo ticket hỗ trợ <span class="material-symbols-outlined text-[16px]">arrow_forward</span></Link>
            </div>
            <span class="material-symbols-outlined text-[32px] text-on-surface-variant">contact_support</span>
        </section>
    </div>

    <template v-if="canManage">
        <UiModal :show="creating" title="Thiết lập chặng mới" max-width="xl" @close="creating = false">
            <UiForm id="new-assignment-form" :action="route('syllabus.assignments.store')" method="post" class="space-y-md" reset-on-success @success="created">
                <UiSelect id="assign_class_id" v-model="classId" label="Chọn lớp học" name="class_id" required @change="pickClass">
                    <option value="" disabled>Chọn lớp học đang quản lý...</option>
                    <option v-for="c in classes" :key="c.id" :value="String(c.id)" :selected="String(c.id) === classId">{{ c.name }} ({{ c.code }})</option>
                </UiSelect>
                <UiAlert v-show="classOpenStage[classId]" type="warning" class="font-caption text-caption">
                    Lớp đang học <strong>{{ classOpenStage[classId] }}</strong>. Chặng mới chỉ mở được sau khi chặng này đóng<template v-if="canOverride">, hoặc chọn "Chuyển chặng" bên dưới</template>.
                </UiAlert>

                <UiSelect name="user_id" label="Chọn giáo viên" value="" placeholder="Chọn giáo viên phụ trách... (mặc định GV chính của lớp)" :options="teachers" />

                <UiSelect v-model="curriculumId" label="Giáo trình" name="curriculum_id" hint="Mặc định theo Trình độ của lớp." placeholder="— Theo trình độ của lớp —" @change="stageId = ''">
                    <option v-for="c in curriculums" :key="c.id" :value="String(c.id)" :selected="String(c.id) === curriculumId">{{ c.title }} ({{ c.code }})</option>
                </UiSelect>

                <UiSelect v-model="stageId" label="Chọn chặng học" name="stage_id" hint="Để trống: chặng đầu tiên lớp chưa học xong." placeholder="Chọn chặng giáo trình... (chặng kế tiếp của lớp)">
                    <option v-for="s in stages" :key="s.id" :value="String(s.id)" :selected="String(s.id) === stageId">{{ s.label }}</option>
                </UiSelect>

                <div class="grid grid-cols-2 gap-md">
                    <UiInput type="date" name="start_date" label="Ngày bắt đầu" :value="today" />
                    <UiInput type="date" name="deadline" label="Dự kiến hoàn thành" />
                </div>

                <div v-if="canOverride" class="space-y-2 border-t border-outline-variant pt-sm">
                    <label class="flex items-start gap-2 font-body-small text-body-small text-on-surface">
                        <input v-model="replace" type="checkbox" name="replace_current" value="1" class="mt-0.5 rounded border-outline-variant text-primary focus:ring-primary-container" />
                        <span><strong>Chuyển chặng (Học thuật):</strong> đóng chặng đang mở của lớp rồi mở chặng đã chọn.</span>
                    </label>
                    <div v-show="replace">
                        <UiTextarea name="reason" label="Lý do" rows="2" placeholder="VD: Lớp đã thi chặng 1 ở cơ sở cũ, chuyển thẳng chặng 2" />
                    </div>
                </div>

                <UiAlert type="info" class="font-body-small text-body-small">Mỗi LỚP HỌC chỉ được giao duy nhất 1 chặng học có hiệu lực tại một thời điểm. Hệ thống sẽ tự động đóng chặng hiện tại của lớp và mở chặng kế tiếp khi kết quả Big Test được duyệt gửi.</UiAlert>
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="creating = false">Hủy</UiButton>
                <UiButton type="submit" form="new-assignment-form" icon="send">Xác nhận giao chặng</UiButton>
            </template>
        </UiModal>

        <UiModal :show="!!editing" title="Chỉnh sửa chặng đang hiệu lực" max-width="md" @close="editing = null">
            <UiForm v-if="editing" id="edit-stage-form" :key="editing.id" :action="route('syllabus.assignments.update', editing.id)" method="put" class="space-y-3 p-md" @success="editing = null">
                <p class="font-body-small text-body-small text-on-surface-variant">Chặng: <strong>{{ label(editing) }}</strong>. Muốn đổi sang chặng khác, dùng "Chuyển chặng" (Học thuật, bắt buộc lý do).</p>
                <UiSelect id="edit_user_id" v-model="edit.user_id" label="Giáo viên phụ trách" name="user_id" required :options="teachers" />
                <div class="grid grid-cols-2 gap-md">
                    <UiDate id="edit_start_date" v-model="edit.start_date" label="Ngày bắt đầu" name="start_date" />
                    <UiDate id="edit_deadline" v-model="edit.deadline" label="Dự kiến hoàn thành" name="deadline" />
                </div>
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="editing = null">Hủy</UiButton>
                <UiButton type="submit" form="edit-stage-form" icon="save">Lưu thay đổi</UiButton>
            </template>
        </UiModal>
    </template>

    <UiModal v-if="canOverride" :show="!!closing" title="Đóng tay chặng đang mở" max-width="md" @close="closing = null">
        <UiForm v-if="closing" id="close-stage-form" :key="closing.id" :action="route('syllabus.assignments.close', closing.id)" method="post" class="space-y-3 p-md" @success="closing = null">
            <p class="font-body-small text-body-small text-on-surface-variant">Chặng: <strong>{{ label(closing) }}</strong>. Thông thường chặng tự đóng khi Big Test được duyệt và gửi phụ huynh — chỉ đóng tay khi có ngoại lệ.</p>
            <UiTextarea name="reason" label="Lý do" required rows="3" />
            <label class="flex items-center gap-2 font-body-small text-body-small text-on-surface">
                <input type="checkbox" name="open_next" value="1" checked class="rounded border-outline-variant text-primary focus:ring-primary-container" />
                Mở luôn chặng kế tiếp của giáo trình
            </label>
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="closing = null">Hủy</UiButton>
            <UiButton type="submit" form="close-stage-form" icon="lock">Đóng chặng</UiButton>
        </template>
    </UiModal>
</template>
