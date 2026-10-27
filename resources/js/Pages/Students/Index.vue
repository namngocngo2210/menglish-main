<script setup>
/**
 * Danh sách học viên (thiết kế lại 27/10/2026 — bớt thông tin): tab trạng thái có số đếm, lọc một hàng (chi nhánh / lớp / thâm niên),
 * mỗi dòng: học viên (tuổi) · lớp (+N lớp liên kết) · SĐT (học viên, không có thì phụ huynh) · học từ · trạng thái · menu ⋯.
 * Bấm dòng mở hồ sơ; menu ⋯: Xem hồ sơ, Liên kết lớp khác (popup, học song song), Thêm phiếu thu.
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { openRemoteModal } from '@/lib/remoteModal';
import { route } from '@/lib/route';
import { urlWith } from '@/lib/url';

defineOptions({ layout: { title: 'Học viên' } });

const props = defineProps({
    students: { type: Object, required: true },
    branches: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    status: { type: String, default: null },
    statusTabs: { type: Array, default: () => [] },
    tenureOptions: { type: Array, default: () => [] },
    sort: { type: String, default: null },
    totalStudents: { type: Number, default: 0 },
    hasFilters: { type: Boolean, default: false },
    linkableClasses: { type: Array, default: () => [] },
});

/** Link tab trạng thái: giữ các bộ lọc khác, về trang 1. */
const tabUrl = (status) => urlWith({ status, 'statuses[]': null, statuses: null, page: null });
const sortUrl = computed(() => urlWith({ sort: props.sort === 'tenure' ? null : 'tenure', page: null }));

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
const openReceipt = (st) => openRemoteModal(route('tuition.receipts.create', { student_id: st.id }), { size: '4xl' });
const menuItem = 'flex w-full items-center gap-sm px-md py-sm text-left font-body-small text-body-small text-on-surface hover:bg-surface-container-low';
</script>

<template>
    <UiPageHeader title="Học viên" :description="`${formatNumber(totalStudents)} học viên`">
        <template #actions>
            <UiButton icon="how_to_reg" :href="route('students.enrollments')">Tiếp nhận &amp; Xếp lớp</UiButton>
        </template>
    </UiPageHeader>

    <!-- Tab trạng thái (bấm là lọc), số đếm theo các bộ lọc còn lại -->
    <UiTabs class="mb-md" data-testid="student-status-tabs">
        <UiTab :href="tabUrl(null)" :active="!status" :count="formatNumber(totalStudents)" preserve-scroll>Tất cả</UiTab>
        <UiTab v-for="tab in statusTabs" :key="tab.value" :href="tabUrl(tab.value)" :active="status === tab.value" :count="formatNumber(tab.count)" preserve-scroll>
            {{ tab.label }}
        </UiTab>
    </UiTabs>

    <UiFilterBar :action="route('students.index')" search="search" placeholder="Tìm tên, SĐT, mã học viên..." :keep="['status', 'sort']">
        <UiSelect name="branch_id" label="Chi nhánh" :options="branches" placeholder="Tất cả chi nhánh" />
        <UiSelect name="class_id" label="Lớp học" :options="classes" placeholder="Tất cả lớp" />
        <UiSelect name="tenure" label="Thâm niên" :options="tenureOptions" placeholder="Mọi thâm niên" />
    </UiFilterBar>

    <UiDataTable min-width="860px">
        <!-- Điện thoại: mỗi học viên một thẻ, bấm là mở hồ sơ -->
        <ul class="divide-y divide-surface-container md:hidden" data-testid="student-cards">
            <li v-for="st in students.data" :key="st.id">
                <Link :href="route('students.show', st.id)" class="flex items-center gap-sm px-md py-sm hover:bg-surface-container-low">
                    <UiAvatar :name="st.name" />
                    <span class="min-w-0 flex-1">
                        <span class="flex items-start justify-between gap-sm">
                            <span class="font-body-medium text-body-medium font-semibold text-on-surface">{{ st.name }}</span>
                            <UiBadge :color="st.status_color" pill class="shrink-0">{{ st.status_label }}</UiBadge>
                        </span>
                        <span class="block truncate font-caption text-caption text-on-surface-variant">
                            {{ st.current_class?.name ?? 'Chưa xếp lớp' }}<template v-if="st.extra_classes"> +{{ st.extra_classes }}</template>
                            · {{ st.phone || st.parent_phone || '—' }}
                        </span>
                    </span>
                </Link>
            </li>
            <li v-if="!students.data.length" class="p-md text-center font-body-small text-body-small text-on-surface-variant">
                {{ hasFilters ? 'Không tìm thấy học viên — thử đổi từ khóa hoặc bỏ bớt bộ lọc.' : 'Chưa có học viên nào.' }}
            </li>
        </ul>
        <table class="hidden md:table">
            <thead>
                <tr>
                    <th>Học viên</th>
                    <th>Lớp</th>
                    <th>SĐT liên hệ</th>
                    <th>
                        <Link :href="sortUrl" preserve-scroll class="inline-flex items-center gap-xs hover:text-primary" :title="sort === 'tenure' ? 'Đang xếp học lâu nhất trước — bấm để về hồ sơ mới nhất' : 'Xếp học lâu nhất trước'">
                            Học từ
                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ sort === 'tenure' ? 'arrow_upward' : 'swap_vert' }}</span>
                        </Link>
                    </th>
                    <th>Trạng thái</th>
                    <th class="w-12"><span class="sr-only">Thao tác</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="st in students.data" :key="st.id" :data-href="route('students.show', st.id)" class="cursor-pointer">
                    <td>
                        <div class="flex items-center gap-sm">
                            <UiAvatar :name="st.name" />
                            <span class="min-w-0">
                                <Link :href="route('students.show', st.id)" class="block font-body-medium text-body-medium font-semibold text-on-surface hover:text-primary">{{ st.name }}</Link>
                                <span v-if="st.age" class="block font-caption text-caption text-on-surface-variant">{{ st.age }}</span>
                            </span>
                        </div>
                    </td>
                    <td>
                        <template v-if="st.current_class">
                            <span class="block max-w-[220px] truncate font-body-small text-body-small font-medium text-on-surface" :title="st.current_class.name">{{ st.current_class.name }}</span>
                            <span v-if="st.extra_classes" class="font-caption text-caption text-on-surface-variant">+{{ st.extra_classes }} lớp liên kết</span>
                        </template>
                        <span v-else class="font-body-small text-body-small text-on-surface-variant">Chưa xếp lớp</span>
                    </td>
                    <td class="whitespace-nowrap font-code text-code text-on-surface">
                        <template v-if="st.phone">{{ st.phone }}</template>
                        <template v-else-if="st.parent_phone">{{ st.parent_phone }} <span class="font-body text-caption text-on-surface-variant">(PH)</span></template>
                        <span v-else class="text-on-surface-variant">—</span>
                    </td>
                    <td data-col="study-started">
                        <template v-if="st.study_started_on">
                            <p class="font-code text-code text-on-surface">{{ st.study_started_on }}</p>
                            <p v-if="st.tenure_label" :class="['flex items-center gap-xs whitespace-nowrap font-caption text-caption', st.long_term ? 'font-semibold text-primary' : 'text-on-surface-variant']" :title="st.long_term ? 'Học viên lâu năm' : null">
                                <span v-if="st.long_term" class="material-symbols-outlined text-[16px]" aria-hidden="true">workspace_premium</span>{{ st.tenure_label }}
                            </p>
                        </template>
                        <span v-else class="font-body-small text-body-small text-on-surface-variant">—</span>
                    </td>
                    <td><UiBadge :color="st.status_color" pill>{{ st.status_label }}</UiBadge></td>
                    <td class="text-right">
                        <UiDropdown width="56">
                            <template #trigger="{ open }">
                                <UiButton variant="ghost" size="sm" icon="more_horiz" :aria-label="`Thao tác với ${st.name}`" :aria-expanded="open ? 'true' : 'false'" />
                            </template>
                            <template #content>
                                <Link :href="route('students.show', st.id)" :class="menuItem" role="menuitem">
                                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">visibility</span>Xem hồ sơ
                                </Link>
                                <button v-if="can('student.assign_class') && st.status !== 'dropped'" type="button" :class="menuItem" role="menuitem" @click="openLink(st)">
                                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">add_link</span>Liên kết lớp khác
                                </button>
                                <button v-if="can('tuition.create')" type="button" :class="menuItem" role="menuitem" @click="openReceipt(st)">
                                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">receipt_long</span>Thêm phiếu thu
                                </button>
                            </template>
                        </UiDropdown>
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
        <template #footer><UiPagination :paginator="students" :options="[10, 20, 50]" unit="học viên" /></template>
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
