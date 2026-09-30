<script setup>
/**
 * Khách chờ xếp lớp (mockup khach-hang-chot-thanh-cong, khu vực 1): học viên đã chốt nhưng chưa có lớp —
 * nơi duy nhất Học vụ xếp lớp cho khách đã chốt. Gợi ý lớp đúng khóa, đúng chi nhánh, còn chỗ (CrmController::waitingClassData).
 */
import { Link } from '@inertiajs/vue3';
import WorkspaceChips from '@/Components/WorkspaceChips.vue';
import CrmHeader from '@/Components/Crm/CrmHeader.vue';

defineOptions({ layout: { title: 'Khách chờ xếp lớp', workspaceTabs: false } });

defineProps({
    waitingLeads: { type: Array, required: true },
    chipCounts: { type: Object, default: () => ({}) },
});
const pad = (n) => String(n).padStart(2, '0');
</script>

<template>
    <CrmHeader title="Khách chờ xếp lớp" />

    <div class="space-y-4">
        <!-- Trang không có bộ lọc riêng: lọc nhanh đặt trong khung riêng như các tab danh sách khác -->
        <div class="rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm">
            <WorkspaceChips :counts="chipCounts" />
        </div>

        <section id="waiting-class" class="overflow-hidden rounded-xl border border-error/20 bg-surface-container-lowest shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-md border-b border-error/10 bg-error-container/30 px-lg py-md">
                <div class="flex items-center gap-sm">
                    <span class="material-symbols-outlined text-error" style="font-variation-settings: 'FILL' 1">error</span>
                    <div>
                        <h2 class="font-h3 text-h3 text-on-surface">Chờ xếp lớp (Cần xử lý gấp)</h2>
                        <p class="font-body-small text-body-small text-on-surface-variant">Hiện có <span class="font-bold text-error">{{ pad(waitingLeads.length) }}</span> học viên đang đợi phân bổ vào lớp mới. Gợi ý lớp đúng khóa, đúng chi nhánh, còn chỗ.</p>
                    </div>
                </div>
                <span class="inline-flex items-center gap-xs rounded-full bg-error/10 px-md py-xs font-caption text-caption font-bold text-error">
                    <span class="material-symbols-outlined text-[14px]">schedule</span>Ưu tiên xử lý
                </span>
            </div>
            <UiDataTable min-width="900px" class="!rounded-none !border-0">
                <table>
                    <thead>
                        <tr>
                            <th>Họ tên</th>
                            <th>Số điện thoại</th>
                            <th>Chi nhánh</th>
                            <th>Thời điểm chốt</th>
                            <th class="text-right">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="lead in waitingLeads" :key="lead.id" class="align-top">
                            <td>
                                <div class="flex items-center gap-sm">
                                    <UiAvatar :name="lead.name" size="sm" />
                                    <div>
                                        <Link :href="route('crm.customers.show', lead.id)" class="font-body-medium text-body-medium text-on-surface hover:text-primary">{{ lead.name }}</Link>
                                        <div class="font-code text-caption text-on-surface-variant"><UiCode :value="lead.student_code" /> · {{ lead.course ?? 'Chưa chọn khóa' }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="font-code text-code text-on-surface-variant">{{ lead.phone }}</td>
                            <td><span class="rounded bg-surface-container-high px-sm py-0.5 font-body-small text-body-small text-on-surface-variant">{{ lead.branch ?? '—' }}</span></td>
                            <td>
                                <div class="font-code text-code text-on-surface">{{ lead.converted_at ?? '—' }}</div>
                                <div v-if="lead.wait_days !== null" :class="['font-caption text-caption', lead.wait_days >= 7 ? 'font-bold text-error' : 'text-on-surface-variant']">Chờ {{ lead.wait_days }} ngày</div>
                            </td>
                            <td class="text-right">
                                <template v-if="can('student.assign_class')">
                                    <UiForm v-if="lead.matches.length" :action="route('crm.customers.assign-class', lead.id)" method="post" class="inline-flex items-center justify-end gap-sm">
                                        <UiSelect name="class_id" value="" :options="lead.matches" placeholder="— Chọn lớp —" required :aria-label="'Lớp xếp cho ' + lead.name" class="max-w-[320px] font-body-small text-body-small" />
                                        <UiButton type="submit" size="sm" variant="secondary" icon="assignment_turned_in">Xếp lớp</UiButton>
                                    </UiForm>
                                    <!-- Chưa có lớp đúng khóa / chi nhánh / còn chỗ: sang màn Xếp lớp (chọn sẵn học viên), ở đó chọn lớp hoặc tạo lớp mới. -->
                                    <Link
                                        v-else
                                        :href="route('students.enrollments', lead.student_id ? { student_id: lead.student_id } : {})"
                                        class="inline-flex items-center gap-xs font-body-small text-body-small font-semibold text-primary underline-offset-2 hover:underline"
                                        title="Chưa có lớp phù hợp — mở luồng xếp lớp"
                                    >
                                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">assignment_turned_in</span>Xếp lớp
                                    </Link>
                                </template>
                                <span v-else class="font-body-small text-body-small text-on-surface-variant">Học vụ sẽ xếp lớp</span>
                            </td>
                        </tr>
                        <tr v-if="!waitingLeads.length">
                            <td colspan="5"><UiEmptyState icon="task_alt" title="Không có học viên nào đang chờ xếp lớp" /></td>
                        </tr>
                    </tbody>
                </table>
            </UiDataTable>
        </section>
    </div>
</template>
