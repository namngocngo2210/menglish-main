<script setup>
/**
 * Danh sách khách (mockup danh-sach-khach): lọc Từ khóa, Nguồn, Người phụ trách, Giai đoạn, Chi nhánh + chip lọc nhanh.
 * Cột Nguồn theo mockup stitch_menglish_v3 "Danh sách khách hàng" (Owen.zip).
 * Thêm khách mở modal 2xl (nút trên thanh tab); bấm tên / nút sửa → hồ sơ đầy đủ (sửa trực tiếp trong trang).
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import WorkspaceChips from '@/Components/WorkspaceChips.vue';
import CrmHeader from '@/Components/Crm/CrmHeader.vue';
import { currentQuery, urlWith } from '@/lib/url';
import { can } from '@/lib/can';
import SlaCountdown from '@/Components/Crm/SlaCountdown.vue';

defineOptions({ layout: { title: 'Danh sách khách hàng', workspaceTabs: false } });

const props = defineProps({
    customers: { type: Object, required: true },
    stageOptions: { type: Array, default: () => [] },
    importSkipped: { type: Array, default: null },
    chipCounts: { type: Object, default: () => ({}) },
    filterBranches: { type: Array, default: () => [] },
    filterSales: { type: Array, default: () => [] },
    filterSources: { type: Array, default: () => [] },
    bulkOwners: { type: Array, default: () => [] },
});
const sla = computed(() => ['1', 'true'].includes(currentQuery().get('sla') ?? ''));
const testToday = computed(() => ['1', 'true'].includes(currentQuery().get('test_today') ?? ''));
const followUp = computed(() => ['1', 'true'].includes(currentQuery().get('follow_up') ?? ''));

// Chọn nhiều khách (trang đang xem) → Phân công lại hàng loạt; Xuất Excel theo bộ lọc đang chọn (mọi trang).
const selected = ref([]);
const bulkOpen = ref(false);
const canBulk = computed(() => can('lead.assign') && props.bulkOwners.length > 0);
const pageIds = computed(() => props.customers.data.map((c) => c.id));
const allSelected = computed(() => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.includes(id)));
function toggleAll(event) {
    selected.value = event.target.checked ? [...pageIds.value] : [];
}
const exportUrl = (format) => urlWith({ export: format, page: null });
const telHref = (phone) => 'tel:' + String(phone ?? '').replace(/[^\d+]/g, '');
const zaloHref = (phone) => 'https://zalo.me/' + String(phone ?? '').replace(/\D/g, '').replace(/^84/, '0');
function onBulkDone() {
    bulkOpen.value = false;
    selected.value = [];
}
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

        <div class="flex flex-wrap items-center justify-between gap-sm">
            <div class="flex flex-wrap items-center gap-sm">
                <template v-if="canBulk">
                    <span class="font-body-small text-body-small text-on-surface-variant">Đã chọn {{ selected.length }} khách</span>
                    <UiButton size="sm" variant="secondary" icon="group" :disabled="!selected.length" data-bulk-reassign @click="bulkOpen = true">Phân công lại hàng loạt</UiButton>
                </template>
            </div>
            <div class="flex items-center gap-xs">
                <UiButton variant="secondary" size="sm" icon="download" :href="exportUrl('xlsx')" native data-export-customers>Xuất Excel</UiButton>
                <UiButton variant="ghost" size="sm" :href="exportUrl('csv')" native>CSV</UiButton>
            </div>
        </div>

        <!-- Điện thoại: thẻ gọn thay bảng (SĐT bấm gọi / mở Zalo, thấy giai đoạn và hạn liên hệ). -->
        <div class="space-y-sm md:hidden" data-mobile-customer-list>
            <div v-for="c in customers.data" :key="'m' + c.id" class="rounded-lg border border-outline-variant/40 bg-surface-container-lowest p-md shadow-level-1">
                <div class="flex items-start justify-between gap-sm">
                    <label v-if="canBulk" class="mt-0.5 shrink-0"><input v-model="selected" type="checkbox" :value="c.id" class="rounded" :aria-label="'Chọn ' + c.name" /></label>
                    <div class="min-w-0 flex-1">
                        <Link :href="route('crm.customers.show', c.id)" class="font-body-semibold text-body-semibold text-on-surface">{{ c.name }}</Link>
                        <div class="font-caption text-caption text-on-surface-variant">{{ c.short_code }} · {{ c.assigned_user ?? 'Chưa phân công' }}</div>
                    </div>
                    <span :class="['shrink-0 rounded-full border px-2 py-0.5 text-[12px] font-bold', c.stage_badge]">{{ c.stage_label }}</span>
                </div>
                <div class="mt-sm flex flex-wrap items-center gap-sm">
                    <a :href="telHref(c.phone)" class="inline-flex items-center gap-1 rounded-full bg-secondary/10 px-sm py-1 font-code text-body-small text-secondary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">call</span>{{ c.phone }}</a>
                    <a :href="zaloHref(c.phone)" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-full bg-info/10 px-sm py-1 font-body-small text-body-small text-info"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">chat</span>Zalo</a>
                    <SlaCountdown v-if="c.sla" :sla="c.sla" compact />
                </div>
                <div v-if="c.follow_up_label" :class="['mt-xs font-caption text-caption', c.follow_up_overdue ? 'text-error' : 'text-warning']">Hạn gọi lại: {{ c.follow_up_label }}</div>
            </div>
            <UiEmptyState v-if="!customers.data.length" icon="search_off" title="Không tìm thấy khách hàng" description="Thử đổi từ khóa hoặc xóa bộ lọc." />
            <UiPagination :paginator="customers" unit="khách" />
        </div>

        <div id="customer-list" class="hidden md:block">
            <UiDataTable min-width="1300px" sticky="both">
                <table>
                    <thead>
                        <tr>
                            <th>
                                <label v-if="canBulk" class="mr-sm inline-flex align-middle"><input type="checkbox" class="rounded" :checked="allSelected" aria-label="Chọn tất cả khách trên trang" @change="toggleAll" /></label>Họ tên
                            </th>
                            <th>Số điện thoại</th>
                            <th>Tên phụ huynh</th>
                            <th>Giai đoạn</th>
                            <th>Hạn SLA liên hệ</th>
                            <th>Người phụ trách</th>
                            <th>Nguồn</th>
                            <th>Chi nhánh</th>
                            <th>Cập nhật gần nhất</th>
                            <th class="text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in customers.data" :key="c.id" class="group">
                            <td class="whitespace-nowrap">
                                <input v-if="canBulk" v-model="selected" type="checkbox" :value="c.id" class="mr-sm rounded align-middle" :aria-label="'Chọn ' + c.name" />
                                <Link :href="route('crm.customers.show', c.id)" class="font-body-medium text-body-medium text-on-background transition hover:text-primary">{{ c.name }}</Link>
                                <div class="font-code text-caption text-on-surface-variant" :title="c.code">{{ c.short_code }}</div>
                            </td>
                            <td class="whitespace-nowrap font-code text-code text-on-surface-variant">
                                <a :href="telHref(c.phone)" class="hover:text-primary" title="Gọi">{{ c.phone }}</a>
                                <a :href="zaloHref(c.phone)" target="_blank" rel="noopener" class="ml-xs font-body-small text-caption text-info hover:underline" title="Mở Zalo">Zalo</a>
                            </td>
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
                                <SlaCountdown v-if="c.sla" :sla="c.sla" compact />
                                <span v-else class="text-on-surface-subtle">—</span>
                            </td>
                            <td class="whitespace-nowrap">
                                <div v-if="c.assigned_user" class="flex items-center gap-xs">
                                    <UiAvatar :name="c.assigned_user" size="sm" class="!h-6 !w-6 !text-xs" />
                                    <span class="text-on-surface-variant">{{ c.assigned_user }}</span>
                                </div>
                                <span v-else class="text-on-surface-subtle">Chưa phân công</span>
                            </td>
                            <td class="whitespace-nowrap text-on-surface-variant" data-col="source">{{ c.source || '—' }}</td>
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
                            <td colspan="10">
                                <UiEmptyState icon="search_off" title="Không tìm thấy khách hàng" description="Thử đổi từ khóa hoặc xóa bộ lọc." />
                            </td>
                        </tr>
                    </tbody>
                </table>
                <template #footer><UiPagination :paginator="customers" unit="khách" /></template>
            </UiDataTable>
        </div>

        <UiModal v-if="canBulk" :show="bulkOpen" title="Phân công lại hàng loạt" max-width="md" @close="bulkOpen = false">
            <p class="mb-3 text-xs text-on-surface-variant">{{ selected.length }} khách đã chọn chuyển cho một người. Người nhận khác cơ sở của khách thì tạo yêu cầu chuyển cơ sở, chờ Admin duyệt.</p>
            <UiForm id="bulk-reassign-form" :action="route('crm.customers.bulk-reassign')" method="post" class="space-y-3 text-xs" @success="onBulkDone">
                <input v-for="id in selected" :key="id" type="hidden" name="ids[]" :value="id" />
                <UiSelect id="bulk_assigned_user_id" name="assigned_user_id" label="Người phụ trách mới" required placeholder="-- Chọn người phụ trách --" :options="bulkOwners" />
                <UiTextarea id="bulk_reason" name="reason" :rows="3" required maxlength="1000" placeholder="Ví dụ: Học vụ cũ nghỉ việc" aria-label="Lý do phân công lại" />
            </UiForm>
            <template #footer>
                <UiButton variant="secondary" @click="bulkOpen = false">Hủy</UiButton>
                <UiButton type="submit" form="bulk-reassign-form" icon="group">Phân công {{ selected.length }} khách</UiButton>
            </template>
        </UiModal>
    </div>
</template>
