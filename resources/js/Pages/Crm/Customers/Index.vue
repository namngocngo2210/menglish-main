<script setup>
/**
 * Danh sách khách (mockup danh-sach-khach): lọc Từ khóa, Nguồn, Người phụ trách, Giai đoạn, Chi nhánh + chip lọc nhanh.
 * Thêm khách mở modal 2xl (nút trên thanh tab); bấm tên / nút sửa → hồ sơ đầy đủ (sửa trực tiếp trong trang).
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import WorkspaceChips from '@/Components/WorkspaceChips.vue';
import CrmHeader from '@/Components/Crm/CrmHeader.vue';
import { currentQuery } from '@/lib/url';

defineOptions({ layout: { title: 'Danh sách khách hàng', workspaceTabs: false } });

const props = defineProps({
    customers: { type: Object, required: true },
    stageOptions: { type: Array, default: () => [] },
    importSkipped: { type: Array, default: null },
    chipCounts: { type: Object, default: () => ({}) },
    filterBranches: { type: Array, default: () => [] },
    filterSales: { type: Array, default: () => [] },
    filterSources: { type: Array, default: () => [] },
});
const sla = computed(() => ['1', 'true'].includes(currentQuery().get('sla') ?? ''));
const testToday = computed(() => ['1', 'true'].includes(currentQuery().get('test_today') ?? ''));
const followUp = computed(() => ['1', 'true'].includes(currentQuery().get('follow_up') ?? ''));
</script>

<template>
    <CrmHeader title="Danh sách khách hàng" />

    <div class="flex flex-col gap-lg">
        <UiAlert v-if="importSkipped && importSkipped.length" type="warning" title="Các dòng bị bỏ qua khi nhập Excel" dismissible>
            <ul class="list-disc pl-5"><li v-for="(line, i) in importSkipped" :key="i">{{ line }}</li></ul>
        </UiAlert>

        <!-- Bộ lọc (mockup danh-sach-khach): Từ khóa, Nguồn, Người phụ trách, Giai đoạn, Chi nhánh -->
        <UiFilterBar :action="route('crm.customers.index')" search="search" placeholder="Tìm tên hoặc SĐT..." :reset-url="route('crm.customers.index')">
            <template #quick><WorkspaceChips :counts="chipCounts" /></template>
            <!-- Giữ lọc nhanh "Chưa liên hệ >24h" / "Hẹn test hôm nay" / "Cần gọi lại" khi lọc thêm -->
            <input v-if="sla" type="hidden" name="sla" value="1" />
            <input v-if="testToday" type="hidden" name="test_today" value="1" />
            <input v-if="followUp" type="hidden" name="follow_up" value="1" />
            <UiSelect name="source" label="Nguồn" :options="filterSources" placeholder="Tất cả nguồn" />
            <UiSelect name="assigned_user_id" label="Người phụ trách" :options="filterSales" placeholder="Tất cả người phụ trách" />
            <UiSelect name="stage" label="Giai đoạn" :options="stageOptions" placeholder="Tất cả giai đoạn" />
            <UiSelect v-if="filterBranches.length" name="branch_id" label="Chi nhánh" :options="filterBranches" placeholder="Tất cả chi nhánh" />
        </UiFilterBar>

        <div id="customer-list">
            <UiDataTable min-width="1020px" sticky="both">
                <table>
                    <thead>
                        <tr>
                            <th>Họ tên</th>
                            <th>Số điện thoại</th>
                            <th>Tên phụ huynh</th>
                            <th>Giai đoạn</th>
                            <th>Người phụ trách</th>
                            <th>Chi nhánh</th>
                            <th>Cập nhật gần nhất</th>
                            <th class="text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in customers.data" :key="c.id" class="group">
                            <td class="whitespace-nowrap">
                                <Link :href="route('crm.customers.show', c.id)" class="font-body-medium text-body-medium text-on-background transition hover:text-primary">{{ c.name }}</Link>
                                <div class="font-code text-caption text-on-surface-variant" :title="c.code">{{ c.short_code }}</div>
                            </td>
                            <td class="whitespace-nowrap font-code text-code text-on-surface-variant">{{ c.phone }}</td>
                            <td class="whitespace-nowrap text-on-surface-variant">{{ c.parent_name || '—' }}</td>
                            <td class="whitespace-nowrap">
                                <span :class="['inline-flex items-center rounded-full border px-2 py-0.5 text-[12px] font-bold', c.stage_badge]">{{ c.stage_label }}</span>
                                <div v-if="c.test_today_at" class="mt-0.5 flex items-center gap-1 font-caption text-caption text-info" :title="'Lịch hẹn test hôm nay · ' + c.test_today_type">
                                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">event</span>Test {{ c.test_today_at }} hôm nay
                                </div>
                                <div v-if="c.follow_up_label" :class="['mt-0.5 flex items-center gap-1 font-caption text-caption', c.follow_up_overdue ? 'text-error' : 'text-warning']" :title="c.follow_up_overdue ? 'Đã quá hạn gọi lại' : 'Hạn gọi lại'">
                                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">call</span>{{ c.follow_up_label }}
                                </div>
                            </td>
                            <td class="whitespace-nowrap">
                                <div v-if="c.assigned_user" class="flex items-center gap-xs">
                                    <UiAvatar :name="c.assigned_user" size="sm" class="!h-6 !w-6 !text-xs" />
                                    <span class="text-on-surface-variant">{{ c.assigned_user }}</span>
                                </div>
                                <span v-else class="text-on-surface-subtle">Chưa phân công</span>
                            </td>
                            <td class="whitespace-nowrap text-on-surface-variant">{{ c.branch ?? '—' }}</td>
                            <td class="whitespace-nowrap font-caption text-caption text-on-surface-variant" :title="c.updated_at">{{ c.updated_label }}</td>
                            <td class="whitespace-nowrap text-right">
                                <div class="flex items-center justify-end gap-xs">
                                    <!-- Sửa = mở hồ sơ đầy đủ ở tab "Thông tin khách hàng" (sửa trực tiếp trong trang) -->
                                    <UiButton variant="ghost" size="sm" icon="edit" :href="route('crm.customers.show', { id: c.id, tab: 'info' })" title="Mở hồ sơ" :aria-label="'Mở hồ sơ ' + c.name" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!customers.data.length">
                            <td colspan="8">
                                <UiEmptyState icon="search_off" title="Không tìm thấy khách hàng" description="Thử đổi từ khóa hoặc xóa bộ lọc." />
                            </td>
                        </tr>
                    </tbody>
                </table>
                <template #footer><UiPagination :paginator="customers" unit="khách" /></template>
            </UiDataTable>
        </div>
    </div>
</template>
