<script setup>
/** Khách thất bại (mockup crm-ui-mockup/khach-khong-chot-lost-deals). A6: khách Thất bại không mở lại, chỉ xem để đối soát. */
import { Link } from '@inertiajs/vue3';
import CrmHeader from '@/Components/Crm/CrmHeader.vue';
import ListFilters from '@/Components/Crm/ListFilters.vue';

defineOptions({ layout: { title: 'Khách thất bại', workspaceTabs: false } });

defineProps({
    lostCustomers: { type: Object, required: true },
    lostTotal: { type: Number, default: 0 },
    chipCounts: { type: Object, default: () => ({}) },
    filterBranches: { type: Array, default: () => [] },
    filterSales: { type: Array, default: () => [] },
    filterSources: { type: Array, default: () => [] },
});
</script>

<template>
    <CrmHeader title="Khách thất bại" />

    <div class="flex flex-col gap-lg">
        <div class="flex flex-col gap-md lg:flex-row lg:items-center">
            <UiStatCard label="Tổng số khách không chốt" :value="formatNumber(lostTotal)" icon="person_off" tone="error" class="shadow-sm lg:min-w-[280px]" />
            <div class="flex-1 [&>form]:mb-0">
                <ListFilters
                    :filter-branches="filterBranches"
                    :filter-sales="filterSales"
                    :filter-sources="filterSources"
                    :chip-counts="chipCounts"
                    date-label="Thời điểm dừng"
                    exportable
                    search-placeholder="Tìm theo lý do không chốt, tên, SĐT..."
                />
            </div>
        </div>

        <UiDataTable min-width="1040px">
            <table>
                <thead>
                    <tr>
                        <th>Họ tên khách hàng</th>
                        <th>Số điện thoại</th>
                        <th>Lý do không chốt</th>
                        <th>Người phụ trách trước khi fail</th>
                        <th>Thời điểm dừng</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="lc in lostCustomers.data" :key="lc.id">
                        <td>
                            <div class="flex items-center gap-sm">
                                <UiAvatar :name="lc.name" size="sm" />
                                <div class="min-w-0">
                                    <Link :href="route('crm.customers.show', lc.id)" class="font-body-medium text-body-medium text-on-surface hover:text-primary">{{ lc.name }}</Link>
                                    <div class="font-caption text-caption text-on-surface-variant">Nhu cầu: {{ lc.course_interest || 'Chưa ghi nhận' }} · {{ lc.branch ?? '—' }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="whitespace-nowrap font-code text-code text-on-surface-variant">{{ lc.phone }}</td>
                        <td class="min-w-[280px] max-w-md">
                            <div class="rounded-lg border border-error/10 bg-error-container/30 p-sm">
                                <p class="line-clamp-3 font-body-small text-body-small text-on-surface" :title="lc.lost_reason">{{ lc.lost_reason ?? 'Chưa ghi nhận lý do' }}</p>
                            </div>
                        </td>
                        <td class="whitespace-nowrap">
                            <div class="flex items-center gap-xs text-on-surface-variant">
                                <span class="material-symbols-outlined text-[18px]">account_circle</span>
                                <span>{{ lc.assigned_user ?? 'Chưa phân công' }}</span>
                            </div>
                        </td>
                        <td class="whitespace-nowrap font-code text-caption text-on-surface-variant">{{ lc.lost_at ?? '—' }}</td>
                        <td class="text-right">
                            <UiButton variant="ghost" size="sm" icon="visibility" :href="route('crm.customers.show', lc.id)" title="Xem chi tiết (không mở lại khách Thất bại)">Xem chi tiết</UiButton>
                        </td>
                    </tr>
                    <tr v-if="!lostCustomers.data.length">
                        <td colspan="6"><UiEmptyState icon="search_off" title="Không có khách không chốt" description="Không có khách thất bại phù hợp bộ lọc." /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="lostCustomers" unit="khách" /></template>
        </UiDataTable>
    </div>
</template>
