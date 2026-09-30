<script setup>
/**
 * Xác nhận nhập học: tiếp nhận học viên và bàn giao lớp học (checklist giáo trình / nhóm Zalo).
 * Checklist bàn giao chỉ sửa ở MỘT nơi: lượt xếp lớp từ CRM → màn Xác nhận chính thức (ở đây chỉ xem + link);
 * lượt xếp lớp trực tiếp → sửa ngay tại cột Giáo trình / Zalo.
 * Popup "Xếp lớp cho học viên": lớp chọn được theo học viên (cùng chi nhánh, đúng khóa đã chốt nếu đang Chờ xếp lớp — placementRules);
 * mở sẵn khi đến từ bảng Chờ xếp lớp (?student_id=) hoặc khi lỗi validate.
 */
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { can } from '@/lib/can';

defineOptions({ layout: { title: 'Xác nhận nhập học' } });

const props = defineProps({
    enrollments: { type: Object, required: true },
    classes: { type: Array, default: () => [] },
    students: { type: Array, default: () => [] },
    placementRules: { type: [Object, Array], default: () => ({}) },
    crmWaitingCount: { type: Number, default: 0 },
    preselectedId: { type: String, default: '' },
    openEnroll: { type: Boolean, default: false },
    oldClassId: { type: String, default: '' },
    preselectedLead: { type: Object, default: null },
});

const canHandoff = computed(() => can('student.assign_class'));
const canOpenConfirmations = computed(() => canHandoff.value && can('lead.view'));
const enrollOpen = ref(props.openEnroll);
const studentId = ref(props.preselectedId);
const classId = ref(props.oldClassId);
const rule = computed(() => props.placementRules?.[studentId.value] ?? {});
const classOptions = computed(() => {
    const r = rule.value;
    return props.classes
        .filter((c) => (!r.branch_id || Number(c.branch_id) === Number(r.branch_id)) && (!r.course_id || Number(c.course_id) === Number(r.course_id)))
        .map((c) => ({ value: String(c.id), label: c.label }));
});
// Học viên đổi → lớp đang chọn không còn hợp lệ thì chọn lớp phù hợp đầu tiên.
watch(
    classOptions,
    (options) => {
        if (!options.some((c) => c.value === String(classId.value))) classId.value = options.length ? options[0].value : '';
    },
    { immediate: true },
);

const checklist = [
    { field: 'curriculum_delivered', done: 'Đã phát', pending: 'Chờ phát', short: 'Giáo trình' },
    { field: 'zalo_group_added', done: 'Đã thêm', pending: 'Chờ thêm', short: 'Zalo' },
];
const editable = (en) => canHandoff.value && !en.dropped && !en.from_crm;
</script>

<template>
    <UiPageHeader title="Xác nhận nhập học" icon="how_to_reg" description="Tiếp nhận học viên và bàn giao lớp học.">
        <template v-if="canHandoff" #actions>
            <UiButton icon="person_add" @click="enrollOpen = true">Xếp lớp cho học viên</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <div v-if="crmWaitingCount > 0" class="flex flex-wrap items-center justify-between gap-sm rounded-xl border border-error/20 bg-error-container/20 px-md py-sm">
            <div class="flex items-center gap-sm font-body-medium text-body-medium text-on-surface">
                <span class="material-symbols-outlined text-error" style="font-variation-settings: 'FILL' 1" aria-hidden="true">error</span>
                <span><strong class="text-error">{{ crmWaitingCount }}</strong> khách chốt từ CRM đang chờ xếp lớp</span>
            </div>
            <UiButton variant="secondary" size="sm" icon="arrow_forward" :href="route('crm.waiting-list')">{{ canHandoff ? 'Xếp lớp' : 'Xem danh sách' }}</UiButton>
        </div>

        <UiDataTable>
            <template #header>
                <h2 class="text-xs font-bold uppercase tracking-wider text-on-surface">Danh sách bàn giao học viên gần đây</h2>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Học viên</th>
                        <th>Lớp học tiếp nhận</th>
                        <th>Giáo viên chủ nhiệm</th>
                        <th>Phát giáo trình</th>
                        <th>Nhóm Zalo lớp</th>
                        <th>Ngày vào lớp</th>
                        <th class="text-right">Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="en in enrollments.data" :key="en.id">
                        <td class="font-bold">{{ en.student_name }}</td>
                        <td class="font-semibold text-primary">{{ en.class_name }}</td>
                        <td>{{ en.teacher ?? 'Chưa phân công' }}</td>
                        <td v-for="item in checklist" :key="item.field">
                            <label v-if="editable(en)" class="inline-flex cursor-pointer items-center gap-1 font-semibold">
                                <input type="checkbox" :name="item.field" value="1" :form="'handoff-' + en.id" :checked="en[item.field]" class="rounded border-outline-variant text-primary-container focus:ring-primary-container/40" :aria-label="`${item.short} — ${en.student_name}`" />
                                <span :class="en[item.field] ? 'text-tertiary' : 'text-warning'">{{ en[item.field] ? item.done : item.pending }}</span>
                            </label>
                            <span v-else :class="['flex items-center gap-1 font-semibold', en[item.field] ? 'text-tertiary' : 'text-warning']">
                                <span class="material-symbols-outlined text-sm">{{ en[item.field] ? 'check_circle' : 'pending' }}</span>
                                {{ en[item.field] ? item.done : item.pending }}
                            </span>
                        </td>
                        <td class="font-code text-on-surface-variant">{{ en.enrolled_at ?? '—' }}</td>
                        <td class="text-right">
                            <UiBadge v-if="en.dropped" color="error" pill>Thôi học</UiBadge>
                            <UiForm v-else-if="editable(en)" :id="'handoff-' + en.id" :action="route('students.enrollments.update', en.id)" method="put" class="inline-flex items-center justify-end gap-2">
                                <UiBadge :color="en.status === 'completed' ? 'success' : 'warning'" pill>{{ en.status === 'completed' ? 'Hoàn tất' : 'Chờ bàn giao' }}</UiBadge>
                                <UiButton type="submit" variant="secondary" size="sm">Lưu</UiButton>
                            </UiForm>
                            <div v-else class="flex flex-col items-end gap-1">
                                <UiBadge :color="en.status === 'completed' ? 'success' : 'warning'" pill>{{ en.status === 'completed' ? 'Hoàn tất' : 'Chờ bàn giao' }}</UiBadge>
                                <Link
                                    v-if="en.from_crm && canOpenConfirmations"
                                    :href="route('crm.confirmations', { search: en.student_code, ...(en.confirmed ? { status: 'confirmed' } : {}) })"
                                    class="inline-flex items-center gap-1 whitespace-nowrap text-xs font-semibold text-primary hover:underline"
                                >
                                    Sửa ở Xác nhận chính thức<span class="material-symbols-outlined text-sm" aria-hidden="true">arrow_forward</span>
                                </Link>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!enrollments.data.length">
                        <td colspan="7"><UiEmptyState title="Chưa có lịch sử bàn giao nào." /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="enrollments" /></template>
        </UiDataTable>
    </div>

    <!-- Xếp lớp cho học viên (cùng động từ "Xếp lớp" với CRM) -->
    <UiModal v-if="canHandoff" :show="enrollOpen" title="Xếp lớp cho học viên" max-width="xl" @close="enrollOpen = false">
        <UiForm id="enroll-student-form" :action="route('students.enrollments.store')" method="post" class="space-y-md" @success="enrollOpen = false">
            <input type="hidden" name="_modal" value="enroll-student" />
            <div v-if="preselectedLead" class="flex flex-wrap items-center justify-between gap-sm rounded-lg border border-warning/30 bg-warning/10 px-md py-sm font-body-small text-body-small text-on-surface">
                <span>
                    <strong>{{ preselectedLead.student_name }}</strong> đã chốt khóa <strong>{{ preselectedLead.course }}</strong> tại <strong>{{ preselectedLead.branch }}</strong>.
                    Chọn lớp đúng khóa đã chốt, hoặc tạo lớp mới nếu chưa có.
                </span>
                <UiButton v-if="can('class.create')" variant="secondary" size="sm" icon="add" :href="route('classes.create')">Tạo lớp mới</UiButton>
            </div>
            <UiSelect v-model="studentId" name="student_id" label="Chọn Học viên" required :options="students" />
            <!-- Chỉ hiện lớp cùng chi nhánh của học viên (chi nhánh + khóa đã chốt nếu đang Chờ xếp lớp); server kiểm tra lại. -->
            <div>
                <UiSelect v-model="classId" name="class_id" label="Chọn Lớp học mục tiêu" required class="font-semibold text-primary" :options="classOptions" :placeholder="classOptions.length ? null : 'Chưa có lớp phù hợp'" />
                <p v-show="rule.branch_name" class="mt-1 font-body-small text-body-small text-on-surface-variant">
                    Lớp tại <strong>{{ rule.branch_name }}</strong><span v-show="rule.course_id">, đúng khóa đã chốt</span>.
                </p>
            </div>
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="enrollOpen = false">Hủy</UiButton>
            <UiButton type="submit" form="enroll-student-form" icon="assignment_turned_in">Xếp lớp</UiButton>
        </template>
    </UiModal>
</template>
