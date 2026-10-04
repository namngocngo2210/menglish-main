<script setup>
/**
 * Hồ sơ học sinh — danh sách (mockup epic-6/ho-so-hoc-sinh-danh-sach-lien-ket-lop): lọc chi nhánh / lớp / chip 6 trạng thái (A6 Q5),
 * cột Họ tên & Ngày sinh, Thông tin liên hệ, Lớp hiện tại, Ngày bắt đầu học (thâm niên), Trạng thái, thao tác Chi tiết + Liên kết lớp khác (popup, học song song).
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';

defineOptions({ layout: { title: 'Hồ sơ học sinh' } });

const props = defineProps({
    students: { type: Object, required: true },
    branches: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    statusOptions: { type: Object, default: () => ({}) },
    tenureOptions: { type: Array, default: () => [] },
    sortOptions: { type: Array, default: () => [] },
    totalStudents: { type: Number, default: 0 },
    hasFilters: { type: Boolean, default: false },
    linkableClasses: { type: Array, default: () => [] },
});

const linkStudent = ref(null);
const linkOpen = ref(false);
function openLink(student) {
    linkStudent.value = student;
    linkOpen.value = true;
}
/** Lớp liên kết được: cùng chi nhánh với học viên, chưa phải lớp của học viên. */
const linkOptions = computed(() => {
    const st = linkStudent.value;
    if (!st) return [];
    return props.linkableClasses
        .filter((c) => !st.branch_id || c.branch_id === st.branch_id)
        .filter((c) => !st.class_ids.includes(c.id))
        .map((c) => ({ value: c.id, label: `${c.label} — ${c.seats}${c.full ? ' (Đã đủ sĩ số)' : ''}`, disabled: c.full }));
});
const submitFilters = (event) => event.target.form?.requestSubmit();
</script>

<template>
    <UiPageHeader title="Hồ sơ học sinh" description="Quản lý và tra cứu thông tin học sinh toàn hệ thống.">
        <template #actions>
            <div class="flex items-center gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest px-md py-sm">
                <span class="font-body-small text-body-small text-on-surface-variant">Tổng số học sinh</span>
                <span class="font-h3 text-h3 text-primary" data-testid="student-total">{{ formatNumber(totalStudents) }}</span>
            </div>
            <UiButton variant="secondary" icon="how_to_reg" :href="route('students.enrollments')">Tiếp nhận &amp; Xếp lớp</UiButton>
        </template>
    </UiPageHeader>

    <UiFilterBar :action="route('students.index')" search="search" placeholder="Tìm học sinh hoặc SĐT...">
        <!-- Lọc nhanh theo 6 trạng thái (chọn là lọc ngay) -->
        <template #quick>
            <div class="flex flex-wrap items-center gap-sm">
                <span class="font-label-caps text-label-caps uppercase text-on-surface-variant">Trạng thái</span>
                <label v-for="(statusLabel, statusKey) in statusOptions" :key="statusKey" class="cursor-pointer">
                    <input type="checkbox" name="statuses[]" :value="statusKey" :checked="statuses.includes(statusKey)" class="peer sr-only" @change="submitFilters" />
                    <span class="inline-flex items-center gap-xs rounded-full border border-outline-variant px-md py-xs font-body-small text-body-small text-on-surface-variant transition peer-checked:border-primary-container peer-checked:bg-primary-container/10 peer-checked:font-semibold peer-checked:text-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary-container/40">
                        {{ statusLabel }}
                    </span>
                </label>
            </div>
        </template>
        <UiSelect name="branch_id" label="Chi nhánh" :options="branches" placeholder="Tất cả chi nhánh" />
        <UiSelect name="class_id" label="Lớp học" :options="classes" placeholder="Tất cả các lớp" />
        <UiSelect name="tenure" label="Thâm niên" :options="tenureOptions" placeholder="Mọi thâm niên" />
        <UiSelect name="sort" label="Sắp xếp" :options="sortOptions" placeholder="Hồ sơ mới nhất" />
    </UiFilterBar>

    <UiDataTable min-width="1020px">
        <table>
            <thead>
                <tr>
                    <th>Họ tên &amp; Ngày sinh</th>
                    <th>Thông tin liên hệ</th>
                    <th>Lớp hiện tại</th>
                    <th>Ngày bắt đầu học</th>
                    <th>Trạng thái</th>
                    <th class="text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="st in students.data" :key="st.id">
                    <td>
                        <Link :href="route('students.show', st.id)" class="flex items-center gap-sm">
                            <UiAvatar :name="st.name" />
                            <span class="min-w-0">
                                <span class="block font-body-medium text-body-medium font-semibold text-on-surface hover:text-primary">{{ st.name }}</span>
                                <span class="block font-caption text-caption text-on-surface-variant">{{ st.dob ?? 'Chưa có ngày sinh' }} · <UiCode :value="st.code" class="font-code" /></span>
                            </span>
                        </Link>
                    </td>
                    <td>
                        <p class="font-code text-code text-on-surface">{{ st.phone || '—' }}</p>
                        <p class="font-caption text-caption text-on-surface-variant">{{ st.email ?? 'Chưa có email' }}</p>
                    </td>
                    <td>
                        <template v-if="st.current_class">
                            <span class="inline-flex rounded bg-secondary-fixed px-sm py-[2px] font-code text-caption font-semibold text-on-secondary-fixed" :title="st.current_class.name">{{ st.current_class.code || st.current_class.name }}</span>
                            <span class="mt-xs block max-w-[200px] truncate font-caption text-caption text-on-surface-variant">{{ st.current_class.name }}</span>
                        </template>
                        <span v-else class="inline-flex rounded bg-surface-container-high px-sm py-[2px] font-caption text-caption text-on-surface-variant">Chưa có lớp</span>
                    </td>
                    <td data-col="study-started">
                        <template v-if="st.study_started_on">
                            <p class="font-code text-code text-on-surface">{{ st.study_started_on }}</p>
                            <p v-if="st.tenure_label" :class="['flex items-center gap-xs whitespace-nowrap font-caption text-caption', st.long_term ? 'font-semibold text-primary' : 'text-on-surface-variant']" :title="st.long_term ? 'Học viên lâu năm' : null">
                                <span v-if="st.long_term" class="material-symbols-outlined text-[16px]" aria-hidden="true">workspace_premium</span>{{ st.tenure_label }}
                            </p>
                        </template>
                        <span v-else class="font-caption text-caption text-on-surface-variant">Chưa vào học</span>
                    </td>
                    <td><UiBadge :color="st.status_color" pill>{{ st.status_label }}</UiBadge></td>
                    <td class="whitespace-nowrap text-right">
                        <div class="inline-flex items-center gap-sm">
                            <UiButton variant="ghost" size="sm" icon="visibility" :href="route('students.show', st.id)">Chi tiết</UiButton>
                            <UiButton v-if="can('student.assign_class') && st.status !== 'dropped'" variant="secondary" size="sm" @click="openLink(st)">Liên kết lớp khác</UiButton>
                        </div>
                    </td>
                </tr>
                <tr v-if="!students.data.length">
                    <td colspan="6">
                        <UiEmptyState v-if="hasFilters" icon="search_off" title="Không tìm thấy học viên" description="Thử đổi từ khóa hoặc bỏ bớt bộ lọc.">
                            <UiButton variant="secondary" size="sm" :href="route('students.index')">Xóa bộ lọc</UiButton>
                        </UiEmptyState>
                        <UiEmptyState v-else icon="school" title="Chưa có học viên nào" description="Danh sách chỉ gồm học viên thuộc chi nhánh / lớp bạn được phân quyền." />
                    </td>
                </tr>
            </tbody>
        </table>
        <template #footer><UiPagination :paginator="students" :options="[10, 20, 50]" unit="học sinh" /></template>
    </UiDataTable>

    <!-- Popup "Liên kết lớp khác" (học song song, không đổi lớp chính) -->
    <UiModal v-if="can('student.assign_class')" :show="linkOpen" title="Liên kết lớp khác" max-width="md" @close="linkOpen = false">
        <UiForm
            v-if="linkStudent"
            id="list-link-class-form"
            :action="route('students.link-class', linkStudent.id)"
            method="post"
            class="space-y-md"
            data-testid="list-link-class-form"
            @success="linkOpen = false"
        >
            <p class="font-body-small text-body-small text-on-surface-variant">Học viên <strong>{{ linkStudent.name }}</strong> học thêm lớp này, lớp chính giữ nguyên.</p>
            <UiSelect id="list_link_class" name="class_id" label="Lớp liên kết" required placeholder="-- Chọn lớp cùng chi nhánh --" :options="linkOptions" value="" />
            <p v-show="!linkOptions.length" class="font-caption text-caption text-on-surface-variant">Không còn lớp cùng chi nhánh để liên kết.</p>
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="linkOpen = false">Hủy</UiButton>
            <UiButton type="submit" form="list-link-class-form" icon="add_link">Liên kết lớp</UiButton>
        </template>
    </UiModal>
</template>
