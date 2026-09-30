<script setup>
/**
 * Nhật ký vận hành (mockup epic-5/nhat-ky-van-hanh): lọc module / người / ngày, bảng + panel "Chi tiết đối chiếu"
 * (so sánh trước / sau, chi tiết kỹ thuật, Hoàn tác cho Admin), Xuất Excel theo bộ lọc.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { currentQuery } from '@/lib/url';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Nhật ký vận hành' } });

const props = defineProps({
    logs: { type: Object, required: true },
    groups: { type: Array, default: () => [] },
    logNames: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    events: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
});

const page = usePage();
const openId = ref(null);
const filterKeys = ['search', 'log_name', 'causer_id', 'date_from', 'date_to', 'event', 'module'];

const query = computed(() => Object.fromEntries(currentQuery()));
const filters = computed(() => Object.fromEntries(Object.entries(query.value).filter(([k]) => filterKeys.includes(k))));
const activeGroup = computed(() => query.value.module ?? null);
const undoError = computed(() => page.props.errors?.undo ?? null);
const statusMessage = computed(() => (page.props.flash ?? []).find((f) => f.type === 'success')?.message ?? null);

const withoutKeys = (keys) => Object.fromEntries(Object.entries(query.value).filter(([k]) => !keys.includes(k)));
const groupUrl = (key) => route('activity-logs.index', key ? { ...withoutKeys(['page']), module: key } : withoutKeys(['module', 'page']));
const chipClass = (active) => ['rounded-full border px-sm py-[2px] font-body-small text-body-small', active ? 'border-primary-container bg-primary-container text-white' : 'border-outline-variant text-on-surface-variant hover:bg-surface-container-low'];

function eventStyle(log) {
    return {
        created: ['add_circle', 'Thêm', 'text-tertiary'],
        updated: ['edit', 'Sửa', 'text-secondary'],
        deleted: ['delete', 'Xóa', 'text-error'],
        restored: ['restore', 'Khôi phục', 'text-tertiary'],
    }[log.event] ?? ['bolt', log.event_label, 'text-on-surface-variant'];
}

function onKey(event) {
    if (event.key === 'Escape') openId.value = null;
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <UiPageHeader title="Nhật ký vận hành" description="Theo dõi và đối soát các thay đổi dữ liệu hệ thống trong thời gian thực. Mỗi thao tác ghi một dòng kèm dữ liệu trước / sau.">
        <template #actions>
            <UiButton variant="secondary" icon="download" :href="route('activity-logs.export', filters)" native>Xuất Excel</UiButton>
            <UiButton variant="secondary" icon="refresh" :href="route('activity-logs.index', filters)">Làm mới</UiButton>
        </template>
    </UiPageHeader>

    <UiAlert v-if="undoError" type="error" class="mb-md">{{ undoError }}</UiAlert>
    <UiAlert v-if="statusMessage" type="success" class="mb-md" dismissible>{{ statusMessage }}</UiAlert>

    <div class="mb-md grid grid-cols-1 gap-md sm:grid-cols-3">
        <UiStatCard label="Tổng số thao tác đã ghi" :value="formatNumber(stats.total)" icon="dataset" />
        <UiStatCard label="Thao tác trong ngày" :value="formatNumber(stats.today)" icon="today" tone="success" />
        <UiStatCard label="Nhân viên hoạt động hôm nay" :value="`${formatNumber(stats.activeUsers)} người`" icon="group" tone="primary" />
    </div>

    <UiFilterBar :action="route('activity-logs.index')" placeholder="Tìm tên người thực hiện, mã bản ghi...">
        <template #quick>
            <div class="flex flex-wrap items-center gap-xs">
                <Link :href="groupUrl(null)" :class="chipClass(!activeGroup)">Tất cả</Link>
                <Link v-for="group in groups" :key="group.value" :href="groupUrl(group.value)" :class="chipClass(activeGroup === group.value)">{{ group.label }}</Link>
                <input v-if="activeGroup" type="hidden" name="module" :value="activeGroup" />
            </div>
        </template>
        <UiSelect name="log_name" label="Phân hệ" :options="logNames" placeholder="Mọi phân hệ" />
        <UiSelect name="causer_id" label="Người thực hiện" :options="users" placeholder="Mọi người thực hiện" />
        <UiSelect name="event" label="Loại thao tác" :options="events" placeholder="Mọi loại" />
        <UiDateRange label="Thời gian" from="date_from" to="date_to" />
    </UiFilterBar>

    <UiDataTable min-width="900px">
        <table>
            <thead>
                <tr>
                    <th>Người thực hiện</th>
                    <th>Thời điểm</th>
                    <th>Module</th>
                    <th>Loại</th>
                    <th>Hành động</th>
                    <th class="text-right"><span class="sr-only">Chi tiết</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="log in logs.data" :key="log.id" :class="['cursor-pointer', openId === log.id ? 'bg-primary-fixed/40' : '']" @click="openId = log.id">
                    <td>
                        <div class="flex items-center gap-sm">
                            <UiAvatar :name="log.causer_name ?? 'Hệ thống'" size="sm" />
                            <div class="min-w-0">
                                <div class="font-semibold text-on-surface">{{ log.causer_name ?? 'Hệ thống tự động' }}</div>
                                <div class="font-caption text-caption text-on-surface-variant">
                                    {{ log.causer_role ?? 'Hệ thống' }}<template v-if="log.causer_id"> <span class="font-code">#{{ log.causer_id }}</span></template>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="whitespace-nowrap font-code text-body-small">{{ formatDate(log.created_at, 'H:i:s') }}<span class="block text-caption text-on-surface-variant">{{ formatDate(log.created_at, 'd/m/Y') }}</span></td>
                    <td><UiBadge color="neutral" :dot="false">{{ log.log_name || 'Hệ thống' }}</UiBadge></td>
                    <td class="whitespace-nowrap">
                        <span :class="['inline-flex items-center gap-xs font-body-small text-body-small', eventStyle(log)[2]]"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ eventStyle(log)[0] }}</span>{{ eventStyle(log)[1] }}</span>
                    </td>
                    <td class="max-w-[380px]">
                        <div class="text-on-surface">{{ log.description }}</div>
                        <div v-if="log.subject" class="font-code text-caption text-on-surface-variant">{{ log.subject }}</div>
                    </td>
                    <td class="text-right"><UiButton variant="ghost" size="sm" icon="visibility" aria-label="Xem chi tiết đối chiếu" @click.stop="openId = log.id" /></td>
                </tr>
                <tr v-if="!logs.data.length">
                    <td colspan="6"><UiEmptyState icon="history" title="Không tìm thấy nhật ký vận hành nào phù hợp với bộ lọc" /></td>
                </tr>
            </tbody>
        </table>
        <template #footer><UiPagination :paginator="logs" unit="nhật ký" /></template>
    </UiDataTable>

    <!-- Panel "Chi tiết đối chiếu" (1 panel / dòng, render sẵn phía server) -->
    <div v-for="log in logs.data" v-show="openId === log.id" :key="`panel-${log.id}`" class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="Chi tiết đối chiếu">
        <div class="absolute inset-0 bg-on-surface/40" @click="openId = null"></div>
        <aside class="absolute inset-y-0 right-0 flex w-full max-w-lg flex-col bg-surface-container-lowest shadow-xl">
            <header class="flex items-start justify-between gap-sm border-b border-surface-container px-md py-sm">
                <div>
                    <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-secondary" aria-hidden="true">info</span>Chi tiết đối chiếu</h2>
                    <p class="font-code text-caption text-on-surface-variant">Bản ghi {{ log.subject ?? `#${log.id}` }}</p>
                </div>
                <UiButton variant="ghost" icon="close" aria-label="Đóng" @click="openId = null" />
            </header>
            <div class="flex-1 space-y-md overflow-y-auto p-md font-body-small text-body-small">
                <dl class="grid grid-cols-2 gap-sm rounded-lg bg-surface-container-low p-sm">
                    <div><dt class="text-on-surface-variant">Module</dt><dd class="font-semibold">{{ log.log_name || 'Hệ thống' }}</dd></div>
                    <div><dt class="text-on-surface-variant">Thao tác bởi</dt><dd class="font-semibold">{{ log.causer_name ?? 'Hệ thống tự động' }}</dd></div>
                    <div><dt class="text-on-surface-variant">Thời điểm</dt><dd class="font-code">{{ formatDate(log.created_at, 'H:i:s d/m/Y') }}</dd></div>
                    <div><dt class="text-on-surface-variant">Loại</dt><dd>{{ log.event_label }}</dd></div>
                    <div class="col-span-2"><dt class="text-on-surface-variant">Nội dung</dt><dd>{{ log.description }}</dd></div>
                </dl>

                <section class="space-y-xs">
                    <h3 class="font-label text-label uppercase text-on-surface-variant">So sánh trước / sau</h3>
                    <p v-if="!log.diff.length" class="italic text-on-surface-variant">Thao tác không có dữ liệu trước / sau (chỉ ghi nhận hành động).</p>
                    <div v-else class="overflow-hidden rounded-lg border border-outline-variant">
                        <div class="grid grid-cols-2 bg-surface-container-low font-label text-label uppercase text-on-surface-variant">
                            <span class="px-sm py-xs">Dữ liệu trước</span><span class="border-l border-outline-variant px-sm py-xs">Dữ liệu sau</span>
                        </div>
                        <div v-for="row in log.diff" :key="row.field" class="grid grid-cols-2 border-t border-outline-variant font-code text-caption">
                            <span :class="['break-all px-sm py-xs', row.old !== row.new ? 'bg-error-container/30 text-error' : '']">{{ row.field }}: "{{ row.old }}"</span>
                            <span :class="['break-all border-l border-outline-variant px-sm py-xs', row.old !== row.new ? 'bg-tertiary-fixed/30 text-tertiary' : '']">{{ row.field }}: "{{ row.new }}"</span>
                        </div>
                    </div>
                </section>

                <section class="space-y-xs rounded-lg bg-inverse-surface/95 p-sm font-code text-caption text-inverse-on-surface">
                    <h3 class="flex items-center gap-xs font-label text-label uppercase"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">terminal</span>Chi tiết kỹ thuật</h3>
                    <p>IP Address: {{ log.ip ?? '—' }}</p>
                    <p class="break-all">User-Agent: {{ log.user_agent ?? '—' }}</p>
                    <p class="break-all">Transaction ID: {{ log.batch_uuid ?? '—' }}</p>
                    <p v-if="log.url" class="break-all">URL: {{ log.url }}</p>
                </section>
                <p v-if="log.undo_error" class="font-caption text-caption italic text-on-surface-variant">Không hoàn tác được: {{ log.undo_error }}</p>
            </div>
            <footer class="flex gap-sm border-t border-surface-container bg-surface-container-low p-md">
                <UiForm v-if="log.undoable" :action="route('activity-logs.undo', log.id)" method="post" class="flex-1" confirm="Khôi phục các giá trị trước của thao tác này?" confirm-label="Khôi phục" @success="openId = null" @error="openId = null">
                    <UiButton type="submit" variant="danger" icon="undo" class="w-full">Hoàn tác</UiButton>
                </UiForm>
                <UiButton variant="secondary" class="flex-1" @click="openId = null">Đóng chi tiết</UiButton>
            </footer>
        </aside>
    </div>
</template>
