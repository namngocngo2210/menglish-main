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
import BackgroundTasks from '@/Components/BackgroundTasks/BackgroundTasks.vue';

defineOptions({ layout: { title: 'Danh sách khách hàng', workspaceTabs: false } });

const props = defineProps({
    customers: { type: Object, required: true },
    stageOptions: { type: Array, default: () => [] },
    importTasks: { type: Array, default: () => [] },
    chipCounts: { type: Object, default: () => ({}) },
    filterBranches: { type: Array, default: () => [] },
    filterSales: { type: Array, default: () => [] },
    filterSources: { type: Array, default: () => [] },
    bulkOwners: { type: Array, default: () => [] },
});
const sla = computed(() => ['1', 'true'].includes(currentQuery().get('sla') ?? ''));
const testToday = computed(() => ['1', 'true'].includes(currentQuery().get('test_today') ?? ''));
const followUp = computed(() => ['1', 'true'].includes(currentQuery().get('follow_up') ?? ''));
const additionalFilterCount = computed(() => (currentQuery().get('stage') ? 1 : 0));

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
function onBulkDone() {
    bulkOpen.value = false;
    selected.value = [];
}
</script>

<template>
    <CrmHeader title="Danh sách khách hàng" />

    <div class="flex min-w-0 flex-col gap-lg">
        <BackgroundTasks :tasks="importTasks" prop-name="importTasks" :reload-only="['customers', 'chipCounts']" />

        <!-- Bộ lọc (mockup danh-sach-khach): Từ khóa, Nguồn, Người phụ trách, Giai đoạn, Chi nhánh -->
        <UiFilterBar :action="route('crm.customers.index')" search="search" placeholder="Tìm tên hoặc SĐT..." :reset-url="route('crm.customers.index')">
            <template #quick><WorkspaceChips :counts="chipCounts" /></template>
            <!-- Giữ lọc nhanh "Chưa liên hệ >24h" / "Hẹn test hôm nay" / "Cần gọi lại" khi lọc thêm -->
            <input v-if="sla" type="hidden" name="sla" value="1" />
            <input v-if="testToday" type="hidden" name="test_today" value="1" />
            <input v-if="followUp" type="hidden" name="follow_up" value="1" />
            <UiSelect name="source" label="Nguồn" :options="filterSources" placeholder="Tất cả nguồn" />
            <UiSelect name="assigned_user_id" label="Người phụ trách" :options="filterSales" placeholder="Tất cả người phụ trách" />
            <UiSelect name="branch_id" label="Chi nhánh" :options="filterBranches" placeholder="Tất cả chi nhánh" />
            <details :open="additionalFilterCount > 0" class="min-w-0 md:!max-w-none md:!flex-none">
                <summary class="min-h-11 cursor-pointer rounded-lg px-sm py-sm text-sm text-on-surface-variant focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/50">Bộ lọc thêm ({{ additionalFilterCount }})</summary>
                <div class="grid min-w-0 grid-cols-1 gap-sm pt-sm">
                    <UiSelect name="stage" label="Giai đoạn" :options="stageOptions" placeholder="Tất cả giai đoạn" />
                </div>
            </details>
        </UiFilterBar>

        <div class="flex flex-wrap items-center justify-between gap-sm">
            <div class="flex min-w-0 flex-wrap items-center gap-sm">
                <span v-if="customers.total != null" class="text-xs text-on-surface-variant">{{ customers.total }} khách hàng</span>
                <template v-if="canBulk && selected.length > 0">
                    <span class="font-body-small text-body-small text-on-surface-variant">Đã chọn {{ selected.length }} khách</span>
                    <UiButton size="sm" variant="secondary" icon="group" data-bulk-reassign @click="bulkOpen = true">Phân công lại hàng loạt</UiButton>
                </template>
            </div>
            <div class="ml-auto flex items-center gap-xs">
                <UiButton variant="secondary" size="sm" icon="download" :href="exportUrl('xlsx')" native data-export-customers>Xuất Excel</UiButton>
                <UiButton variant="ghost" size="sm" :href="exportUrl('csv')" native>CSV</UiButton>
            </div>
        </div>

        <!-- Điện thoại: thẻ gọn thay bảng (SĐT bấm gọi, thấy giai đoạn và hạn liên hệ). -->
        <div class="space-y-sm md:hidden" data-mobile-customer-list>
            <div v-for="c in customers.data" :key="'m' + c.id" class="min-w-0 rounded-lg border border-outline-variant/40 bg-surface-container-lowest p-sm shadow-level-1">
                <div class="flex min-w-0 items-center gap-xs">
                    <label v-if="canBulk" class="inline-flex min-h-11 min-w-11 shrink-0 cursor-pointer items-center justify-center"><input v-model="selected" type="checkbox" :value="c.id" class="rounded" :aria-label="'Chọn ' + c.name" /></label>
                    <Link :href="route('crm.customers.show', c.id)" class="flex min-h-11 min-w-0 flex-1 items-center font-body-semibold text-body-semibold text-on-surface"><span class="truncate">{{ c.name }}</span></Link>
                    <span :class="['shrink-0 rounded-full border px-2 py-0.5 text-xs font-bold', c.stage_badge]">{{ c.stage_label }}</span>
                </div>
                <div class="break-words text-xs text-on-surface-variant"><span class="font-code">{{ c.short_code }}</span> · {{ c.parent_name || '—' }}</div>
                <div class="mt-xs flex min-w-0 flex-wrap items-center gap-sm">
                    <a v-if="c.phone" :href="telHref(c.phone)" :aria-label="'Gọi ' + c.name + ': ' + c.phone" class="inline-flex min-h-11 shrink-0 items-center gap-xs whitespace-nowrap rounded-full bg-secondary/10 px-sm font-code text-xs text-secondary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">call</span>{{ c.phone }}</a>
                    <div v-if="c.sla" class="shrink-0 whitespace-nowrap [&>span]:flex-nowrap"><SlaCountdown :sla="c.sla" compact /></div>
                    <div class="flex min-w-0 flex-1 basis-32 items-center gap-xs text-xs text-on-surface-variant">
                        <UiAvatar :name="c.assigned_user ?? 'Chưa phân công'" size="sm" />
                        <span class="truncate">{{ c.assigned_user ?? 'Chưa phân công' }}</span>
                    </div>
                </div>
                <div v-if="c.test_today_at" class="mt-xs flex items-center gap-xs text-xs text-info" :title="'Lịch hẹn test hôm nay · ' + c.test_today_type">
                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">event</span>Test {{ c.test_today_at }} hôm nay
                </div>
                <div v-if="c.follow_up_label" :class="['mt-xs text-xs', c.follow_up_overdue ? 'text-error' : 'text-warning']">Hạn gọi lại: {{ c.follow_up_label }}</div>
                <div class="mt-xs flex items-center justify-end gap-sm">
                    <UiButton variant="ghost" size="sm" icon="visibility" :href="route('crm.customers.show', c.id)">Xem</UiButton>
                    <UiDropdown>
                        <template #trigger="{ open }">
                            <UiButton variant="ghost" size="sm" class="min-h-11 min-w-11" :aria-label="'Thêm thao tác cho ' + c.name" :aria-expanded="open">⋯</UiButton>
                        </template>
                        <template #content>
                            <Link :href="route('crm.customers.show', { id: c.id, tab: 'info' })" class="flex min-h-11 items-center gap-sm px-md py-sm text-sm text-on-surface hover:bg-surface-container-low focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/50"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">edit</span>Sửa hồ sơ</Link>
                        </template>
                    </UiDropdown>
                </div>
            </div>
            <UiEmptyState v-if="!customers.data.length" icon="search_off" title="Không tìm thấy khách hàng" description="Thử đổi từ khóa hoặc xóa bộ lọc." />
            <UiPagination :paginator="customers" unit="khách" />
        </div>

        <div id="customer-list" class="hidden md:block">
            <UiDataTable min-width="960px" sticky="both">
                <table class="w-full text-left [&_th]:!px-3 [&_td]:!px-3 [&_th]:!py-2.5 [&_td]:!py-2">
                    <thead>
                        <tr>
                            <th class="whitespace-nowrap">
                                <label v-if="canBulk" class="mr-sm inline-flex align-middle"><input type="checkbox" class="rounded" :checked="allSelected" aria-label="Chọn tất cả khách trên trang" @change="toggleAll" /></label>Khách hàng
                            </th>
                            <th class="whitespace-nowrap" title="Tên phụ huynh">Tên phụ huynh</th>
                            <th class="whitespace-nowrap">Giai đoạn</th>
                            <th class="max-w-[8.5rem] !whitespace-normal leading-tight">Liên hệ tiếp theo / SLA</th>
                            <th class="whitespace-nowrap">Phụ trách</th>
                            <th class="whitespace-nowrap" title="Cập nhật gần nhất">Nguồn / Cập nhật</th>
                            <th class="whitespace-nowrap text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in customers.data" :key="c.id" class="group">
                            <td class="max-w-[12rem] whitespace-nowrap">
                                <div class="flex items-start gap-xs">
                                    <input v-if="canBulk" v-model="selected" type="checkbox" :value="c.id" class="mt-1 rounded shrink-0 align-middle" :aria-label="'Chọn ' + c.name" />
                                    <div class="min-w-0">
                                        <Link :href="route('crm.customers.show', c.id)" class="block truncate font-body-medium text-body-medium text-on-background transition hover:text-primary" :title="c.name">{{ c.name }}</Link>
                                        <div class="font-code text-xs text-on-surface-variant" :title="c.code">{{ c.short_code }}</div>
                                        <a v-if="c.phone" :href="telHref(c.phone)" class="mt-xs inline-flex items-center gap-xs font-code text-xs text-on-surface-variant hover:text-primary"><span class="material-symbols-outlined text-[15px]" aria-hidden="true">call</span>{{ c.phone }}</a>
                                    </div>
                                </div>
                            </td>
                            <td class="max-w-[7.5rem] truncate text-on-surface-variant" :title="c.parent_name || 'Tên phụ huynh'">{{ c.parent_name || '—' }}</td>
                            <td class="max-w-[9.5rem]">
                                <span :class="['inline-flex items-center rounded-full border px-2 py-0.5 text-[12px] font-bold leading-tight', c.stage_badge]">{{ c.stage_label }}</span>
                                <div v-if="c.test_today_at" class="mt-0.5 flex items-center gap-1 font-caption text-caption text-info truncate" :title="'Lịch hẹn test hôm nay · ' + c.test_today_type">
                                    <span class="material-symbols-outlined text-[14px] shrink-0" aria-hidden="true">event</span><span class="truncate">Test {{ c.test_today_at }} hôm nay</span>
                                </div>
                                <div v-if="c.follow_up_label" :class="['mt-0.5 flex items-center gap-1 font-caption text-caption truncate', c.follow_up_overdue ? 'text-error' : 'text-warning']" :title="c.follow_up_overdue ? 'Đã quá hạn gọi lại' : 'Hạn gọi lại'">
                                    <span class="material-symbols-outlined text-[14px] shrink-0" aria-hidden="true">call</span><span class="truncate">{{ c.follow_up_label }}</span>
                                </div>
                            </td>
                            <td class="max-w-[10rem]">
                                <div v-if="c.sla" class="leading-tight"><SlaCountdown :sla="c.sla" compact /></div>
                                <span v-else class="text-on-surface-subtle">—</span>
                            </td>
                            <td class="max-w-[8.5rem]">
                                <div v-if="c.assigned_user" class="flex items-center gap-xs">
                                    <UiAvatar :name="c.assigned_user" size="sm" class="!h-6 !w-6 !text-xs shrink-0" />
                                    <span class="truncate text-on-surface-variant" :title="c.assigned_user">{{ c.assigned_user }}</span>
                                </div>
                                <span v-else class="text-on-surface-subtle">Chưa phân công</span>
                                <div v-if="c.branch" class="mt-0.5 truncate text-xs text-on-surface-subtle" :title="c.branch">{{ c.branch }}</div>
                            </td>
                            <td class="max-w-[8rem] text-on-surface-variant">
                                <div class="truncate" data-col="source" :title="c.source">{{ c.source || '—' }}</div>
                                <div class="mt-0.5 truncate text-xs text-on-surface-subtle" :title="c.updated_at ? 'Cập nhật gần nhất: ' + c.updated_at : 'Cập nhật gần nhất'">Cập nhật {{ c.updated_label }}</div>
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <UiButton v-if="c.phone" variant="ghost" size="sm" icon="call" :href="telHref(c.phone)" native :title="'Gọi ' + c.name" class="!px-1.5">Gọi</UiButton>
                                    <UiButton variant="ghost" size="sm" icon="visibility" :href="route('crm.customers.show', c.id)" :title="'Xem ' + c.name" class="!px-1.5">Xem</UiButton>
                                    <UiDropdown>
                                        <template #trigger="{ open }">
                                            <UiButton variant="ghost" size="sm" class="min-h-11 !min-w-9 !px-1" :aria-label="'Thêm thao tác cho ' + c.name" :aria-expanded="open">⋯</UiButton>
                                        </template>
                                        <template #content>
                                            <Link :href="route('crm.customers.show', { id: c.id, tab: 'info' })" class="flex min-h-11 items-center gap-sm px-md py-sm text-sm text-on-surface hover:bg-surface-container-low focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/50"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">edit</span>Sửa hồ sơ</Link>
                                        </template>
                                    </UiDropdown>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!customers.data.length">
                            <td colspan="7">
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
