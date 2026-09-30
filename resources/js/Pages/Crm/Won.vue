<script setup>
/**
 * Khách đã nhập học (mockup crm-ui-mockup/khach-hang-chot-thanh-cong): (1) băng nhắc Chờ xếp lớp, (2) bộ lọc Chi nhánh / Lớp học / Tìm kiếm,
 * (3) số liệu + Khách đã có lớp + Xuất Excel + phân trang. A6: không có "Hủy chốt".
 * Chốt & Xếp lớp xong chuyển về đây: thông báo + link phiếu thu, tài khoản học viên vừa tạo (mật khẩu chỉ hiện một lần).
 */
import { Link } from '@inertiajs/vue3';
import CrmHeader from '@/Components/Crm/CrmHeader.vue';
import ListFilters from '@/Components/Crm/ListFilters.vue';
import WaitingClassBanner from '@/Components/Crm/WaitingClassBanner.vue';
import { urlWith } from '@/lib/url';

defineOptions({ layout: { title: 'Khách đã nhập học', workspaceTabs: false } });

defineProps({
    wonCustomers: { type: Object, required: true },
    totalCount: { type: Number, default: 0 },
    totalContractAmount: { type: Number, default: 0 },
    totalCollectedAmount: { type: Number, default: 0 },
    totalDebtAmount: { type: Number, default: 0 },
    filterClasses: { type: Array, default: () => [] },
    filterBranches: { type: Array, default: () => [] },
    filterSales: { type: Array, default: () => [] },
    filterSources: { type: Array, default: () => [] },
    chipCounts: { type: Object, default: () => ({}) },
    waitingCount: { type: Number, default: 0 },
    closing: { type: Object, default: () => ({}) },
});
const exportUrl = (format) => urlWith({ export: format, page: null });
</script>

<template>
    <CrmHeader title="Khách đã nhập học" />

    <div class="flex flex-col gap-lg">
        <UiAlert v-if="closing.status" type="success">
            <div class="flex flex-col gap-sm sm:flex-row sm:items-center sm:justify-between">
                <span>{{ closing.status }}</span>
                <UiButton v-if="closing.bill_url" size="sm" icon="receipt_long" :href="closing.bill_url" target="_blank">Xem phiếu thu &amp; mã VietQR</UiButton>
            </div>
        </UiAlert>
        <UiAlert v-if="closing.account" type="warning" title="Tài khoản học viên vừa tạo — chỉ hiển thị một lần">
            <div>Tên đăng nhập: <span class="select-all font-code font-semibold">{{ closing.account.login }}</span></div>
            <div>Mật khẩu tạm: <span class="font-code font-semibold">{{ closing.account.password }}</span></div>
            <div class="font-caption text-caption">Yêu cầu học viên đổi mật khẩu ngay lần đăng nhập đầu tiên.</div>
        </UiAlert>

        <!-- Chờ xếp lớp: chỉ băng nhắc + link, xếp lớp làm ở màn Chờ xếp lớp -->
        <WaitingClassBanner :count="waitingCount" />

        <ListFilters
            :filter-branches="filterBranches"
            :filter-sales="filterSales"
            :filter-sources="filterSources"
            :filter-classes="filterClasses"
            :chip-counts="chipCounts"
            date-label="Ngày chốt"
            search-placeholder="Nhập tên hoặc số điện thoại..."
        />

        <div class="grid grid-cols-2 gap-md lg:grid-cols-4">
            <UiStatCard label="Tổng khách đã chốt" :value="formatNumber(totalCount) + ' học viên'" icon="how_to_reg" tone="primary" />
            <UiStatCard label="Tổng giá trị hợp đồng" :value="formatMoney(totalContractAmount)" icon="description" />
            <UiStatCard label="Thực thu đã duyệt" :value="formatMoney(totalCollectedAmount)" icon="payments" tone="success" />
            <UiStatCard label="Công nợ còn lại" :value="formatMoney(totalDebtAmount)" icon="account_balance_wallet" tone="warning" />
        </div>

        <UiDataTable min-width="980px" sticky="both">
            <template #header>
                <div class="flex items-center gap-sm">
                    <h2 class="font-h3 text-h3 text-on-surface">Khách đã có lớp</h2>
                    <span class="rounded-full bg-primary-container/10 px-sm py-0.5 font-code text-caption font-bold text-primary">{{ formatNumber(wonCustomers.total) }}</span>
                </div>
                <div class="flex items-center gap-xs">
                    <UiButton variant="secondary" size="sm" icon="download" :href="exportUrl('xlsx')" native>Xuất Excel</UiButton>
                    <UiButton variant="ghost" size="sm" :href="exportUrl('csv')" native>CSV</UiButton>
                </div>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Họ tên</th>
                        <th>Số điện thoại</th>
                        <th>Chi nhánh</th>
                        <th>Lớp học</th>
                        <th>Thời điểm chốt</th>
                        <th>Tình trạng thu</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="wc in wonCustomers.data" :key="wc.id">
                        <td class="whitespace-nowrap">
                            <Link :href="route('crm.customers.show', wc.id)" class="font-body-medium text-body-medium text-on-surface hover:text-primary">{{ wc.name }}</Link>
                            <div class="font-caption text-caption text-on-surface-variant">{{ wc.course_interest || '—' }} · Sale: {{ wc.assigned_user ?? 'Chưa phân công' }}</div>
                        </td>
                        <td class="whitespace-nowrap font-code text-code text-on-surface-variant">{{ wc.phone }}</td>
                        <td class="whitespace-nowrap"><span class="rounded bg-surface-container-high px-sm py-0.5 font-body-small text-body-small text-on-surface-variant">{{ wc.branch ?? '—' }}</span></td>
                        <td class="whitespace-nowrap">
                            <div class="flex items-center gap-sm">
                                <span :class="['h-2 w-2 rounded-full', wc.class ? 'bg-tertiary' : 'bg-outline-variant']"></span>
                                <span class="font-body-medium text-body-medium text-on-surface">{{ wc.class?.name ?? 'Chưa xếp lớp' }}</span>
                            </div>
                            <div v-if="wc.class" class="pl-md font-code text-caption text-on-surface-variant">{{ wc.class.code }}</div>
                        </td>
                        <td class="whitespace-nowrap font-code text-code text-on-surface-variant">{{ wc.converted_at ?? '—' }}</td>
                        <td class="whitespace-nowrap">
                            <span :class="['inline-block rounded-full border px-sm py-0.5 text-xs font-bold', wc.tuition_badge]">{{ wc.tuition_label }}</span>
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <div class="flex items-center justify-end gap-sm">
                                <template v-if="wc.enrollment && !wc.enrollment.confirmed">
                                    <UiButton v-if="can('student.assign_class')" variant="secondary" size="sm" icon="verified_user" :href="route('crm.confirmations', { search: wc.student_code })">Xác nhận chính thức</UiButton>
                                    <span v-else class="font-body-small text-body-small font-semibold text-warning">Chờ xác nhận</span>
                                </template>
                                <span v-else-if="wc.enrollment?.confirmed" class="inline-flex items-center gap-xs font-body-small text-body-small font-semibold text-tertiary"><span class="material-symbols-outlined text-[16px]">check_circle</span>Đã là học viên</span>
                                <UiButton variant="ghost" size="sm" icon="visibility" :href="route('crm.customers.show', wc.id)" title="Xem hồ sơ" aria-label="Xem hồ sơ" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!wonCustomers.data.length">
                        <td colspan="7"><UiEmptyState icon="search_off" title="Không có khách đã chốt phù hợp bộ lọc" /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="wonCustomers" unit="kết quả" /></template>
        </UiDataTable>
    </div>
</template>
