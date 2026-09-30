<script setup>
/**
 * Xác nhận chính thức (mockup epic-6/khach-hang-chot-thanh-cong-xac-nhan): tiêu đề + số học viên, băng nhắc "Chờ xếp lớp",
 * lọc Chi nhánh / Lớp học / Tìm kiếm, bảng Khách đã có lớp (checklist hồ sơ nhập học, Lưu tiến độ / Xác nhận chính thức) + popup xác nhận.
 * A6 Q5: không có trạng thái "Học thử" trên hồ sơ học viên. Checklist hồ sơ nhập học: tài khoản, Zalo, giáo trình.
 */
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import CrmHeader from '@/Components/Crm/CrmHeader.vue';
import WaitingClassBanner from '@/Components/Crm/WaitingClassBanner.vue';
import { urlWith } from '@/lib/url';

defineOptions({ layout: { title: 'Xác nhận chính thức', workspaceTabs: false } });

defineProps({
    enrollments: { type: Object, required: true },
    status: { type: String, default: 'pending' },
    pendingCount: { type: Number, default: 0 },
    totalCount: { type: Number, default: 0 },
    filterClasses: { type: Array, default: () => [] },
    filterBranches: { type: Array, default: () => [] },
    checklistLabels: { type: Object, default: () => ({}) },
    studentAccount: { type: Object, default: null },
    waitingCount: { type: Number, default: 0 },
});
const confirmForm = ref(null);
const confirmName = ref('');
const confirmOpen = ref(false);
function askConfirm(enrollment) {
    confirmForm.value = 'confirm-' + enrollment.id;
    confirmName.value = enrollment.student?.name ?? '';
    confirmOpen.value = true;
}
</script>

<template>
    <CrmHeader title="Xác nhận chính thức" />

    <div class="flex flex-col gap-lg">
        <header>
            <h2 class="flex flex-wrap items-center gap-sm font-h2 text-h2 text-on-surface">
                Khách hàng đã chốt thành công
                <span class="rounded-full bg-primary-container/10 px-md py-xs font-body-small text-body-small font-bold text-primary">{{ formatNumber(totalCount) }} học viên</span>
            </h2>
            <p class="mt-xs font-body-medium text-body-medium text-on-surface-variant">Quản lý danh sách học viên sau khi hoàn tất thủ tục đăng ký và phân bổ lớp học. Học vụ / Quản lý cơ sở kiểm tra hồ sơ nhập học rồi xác nhận học viên chính thức.</p>
        </header>

        <UiAlert v-if="studentAccount" type="warning" title="Mật khẩu tạm của học viên — chỉ hiển thị một lần">
            <div>Tên đăng nhập: <span class="select-all font-code font-semibold">{{ studentAccount.login }}</span></div>
            <div>Mật khẩu tạm: <span class="font-code font-semibold">{{ studentAccount.password }}</span></div>
            <div class="font-caption text-caption">Gửi cho phụ huynh; học viên phải đổi mật khẩu ở lần đăng nhập đầu tiên.</div>
        </UiAlert>

        <!-- 1. Chờ xếp lớp: chỉ băng nhắc + link, xếp lớp làm ở màn Chờ xếp lớp -->
        <WaitingClassBanner :count="waitingCount" />

        <!-- 2. Khách đã có lớp -->
        <section class="flex flex-col gap-md">
            <UiTabs>
                <UiTab :href="urlWith({ status: null, page: null })" :active="status === 'pending'" :count="pendingCount">Chờ xác nhận</UiTab>
                <UiTab :href="urlWith({ status: 'confirmed', page: null })" :active="status === 'confirmed'">Đã xác nhận</UiTab>
            </UiTabs>

            <UiFilterBar placeholder="Tìm kiếm học viên..." class="!mb-0">
                <input type="hidden" name="status" :value="status" />
                <UiSelect name="branch_id" label="Chi nhánh" :options="filterBranches" placeholder="Tất cả chi nhánh" />
                <UiSelect name="class_id" label="Lớp học" :options="filterClasses" placeholder="Tất cả lớp" />
            </UiFilterBar>

            <UiDataTable min-width="960px">
                <template #header>
                    <h2 class="font-h3 text-h3 text-on-surface">Khách đã có lớp <span class="font-body-medium text-body-medium text-on-surface-variant">({{ enrollments.total }})</span></h2>
                </template>
                <table>
                    <thead>
                        <tr>
                            <th>Họ tên</th>
                            <th>Lớp học</th>
                            <th>Trạng thái</th>
                            <th>Hồ sơ nhập học</th>
                            <th class="text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="enrollment in enrollments.data" :id="'enrollment-' + enrollment.id" :key="enrollment.id" class="align-top">
                            <td>
                                <div class="flex items-center gap-sm">
                                    <UiAvatar :name="enrollment.student?.name ?? '?'" size="sm" />
                                    <div>
                                        <Link :href="route('crm.customers.show', enrollment.customer_id)" class="font-body-medium text-body-medium text-on-surface hover:text-primary">{{ enrollment.student?.name }}</Link>
                                        <div class="font-code text-caption text-on-surface-variant">{{ enrollment.student?.phone ?? enrollment.customer_phone }}</div>
                                        <div class="font-code text-xs text-on-surface-subtle"><UiCode :value="enrollment.student?.code" /></div>
                                    </div>
                                </div>
                            </td>
                            <td class="min-w-[180px]">
                                <div class="font-body-medium text-body-medium text-on-surface">{{ enrollment.class.name ?? '—' }}</div>
                                <div class="font-caption text-caption text-on-surface-variant">{{ enrollment.class.branch ?? '—' }}</div>
                                <div class="font-code text-caption text-on-surface-variant">Lớp ID: {{ enrollment.class.code ?? '—' }} · {{ enrollment.class.start_label }}</div>
                            </td>
                            <td class="whitespace-nowrap">
                                <UiBadge v-if="enrollment.student" :color="enrollment.student.status === 'studying' ? 'success' : 'warning'" pill>{{ enrollment.student.status_label }}</UiBadge>
                                <div class="mt-xs font-caption text-caption text-on-surface-variant">Chốt: {{ enrollment.closed_at ?? '—' }}</div>
                            </td>
                            <template v-if="enrollment.confirmed_at">
                                <td>
                                    <UiBadge color="success">Đã xác nhận chính thức</UiBadge>
                                    <div class="mt-xs font-caption text-caption text-on-surface-variant">{{ enrollment.confirmed_at }} · {{ enrollment.confirmed_by }}</div>
                                </td>
                                <td class="text-right">
                                    <span class="inline-flex items-center gap-xs font-body-small text-body-small font-semibold text-tertiary"><span class="material-symbols-outlined text-[18px]">check_circle</span>Đã là học viên</span>
                                </td>
                            </template>
                            <template v-else>
                                <td>
                                    <UiForm :id="'confirm-' + enrollment.id" :action="route('crm.enrollments.confirm', enrollment.id)" method="post" class="flex min-w-[190px] flex-col gap-xs font-body-small text-body-small" @success="confirmOpen = false">
                                        <label v-for="(label, field) in checklistLabels" :key="field" class="flex items-center gap-sm">
                                            <input type="hidden" :name="field" value="0" />
                                            <input type="checkbox" :name="field" value="1" :checked="enrollment.checklist[field]" class="rounded border-outline-variant text-primary-container" />
                                            <span>{{ label }}</span>
                                        </label>
                                    </UiForm>
                                    <UiForm v-if="enrollment.student?.login" :action="route('crm.enrollments.reset-account', enrollment.id)" method="post" class="mt-xs flex flex-wrap items-center gap-xs font-caption text-caption text-on-surface-variant">
                                        <span class="flex min-w-0 max-w-[240px] items-center gap-xs">Tài khoản: <UiCode :value="enrollment.student.login" class="truncate font-code" /></span>
                                        <button type="submit" class="font-semibold text-primary hover:underline">Cấp mật khẩu tạm</button>
                                    </UiForm>
                                </td>
                                <td class="text-right">
                                    <div class="flex flex-col items-end gap-xs">
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-xs whitespace-nowrap rounded-lg border border-outline-variant bg-surface-container-lowest px-sm py-xs font-body-medium text-body-small text-on-surface shadow-sm hover:bg-surface-container-low max-md:min-h-11"
                                            @click="askConfirm(enrollment)"
                                        >
                                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">verified_user</span>Xác nhận chính thức
                                        </button>
                                        <UiButton type="submit" :form="'confirm-' + enrollment.id" name="action" value="save" size="sm" variant="ghost">Lưu tiến độ</UiButton>
                                    </div>
                                </td>
                            </template>
                        </tr>
                        <tr v-if="!enrollments.data.length">
                            <td colspan="5"><UiEmptyState icon="verified_user" :title="status === 'confirmed' ? 'Chưa có học viên được xác nhận' : 'Không có học viên chờ xác nhận'" /></td>
                        </tr>
                    </tbody>
                </table>
                <template #footer><UiPagination :paginator="enrollments" unit="học viên" /></template>
            </UiDataTable>
        </section>

        <!-- Popup xác nhận (mockup) -->
        <UiModal :show="confirmOpen" title="Xác nhận học viên" max-width="md" @close="confirmOpen = false">
            <div class="flex items-start gap-md">
                <span class="material-symbols-outlined rounded-full bg-secondary-fixed p-sm text-secondary">info</span>
                <p class="font-body-base text-body-base text-on-surface-variant">Xác nhận học viên <strong class="text-on-surface">{{ confirmName }}</strong> đã chính thức bắt đầu học? Cần tick đủ hồ sơ nhập học (tài khoản, nhóm Zalo, giáo trình).</p>
            </div>
            <template #footer>
                <UiButton variant="secondary" @click="confirmOpen = false">Hủy</UiButton>
                <UiButton type="submit" name="action" value="confirm" :form="confirmForm" icon="verified_user" @click="confirmOpen = false">Xác nhận chính thức</UiButton>
            </template>
        </UiModal>
    </div>
</template>
