<script setup>
/**
 * Cấu hình ngày nghỉ (mockup epic-5/cau-hinh-ngay-nghi): danh sách; Thêm/Sửa mở modal (Form.vue), Xóa có hộp xác nhận.
 * Mở thẳng URL create/edit (Form.vue, trang đầy đủ) → danh sách bên trái + form bên phải như mockup.
 */
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import { currentQuery } from '@/lib/url';
import HolidayFields from './HolidayFields.vue';

defineOptions({ layout: { title: 'Cấu hình ngày nghỉ' } });

const props = defineProps({
    holidays: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
    // Chỉ có khi mở thẳng trang Thêm / Sửa (Form.vue).
    holiday: { type: Object, default: null },
    branches: { type: Array, default: null },
    selectedBranchIds: { type: Array, default: () => [] },
    formOpen: { type: Boolean, default: false },
});
const search = computed(() => currentQuery().get('search') ?? '');
const showForm = computed(() => props.formOpen && props.canManage);

function onSearch(event) {
    const value = new FormData(event.target).get('search');
    router.get(route('holidays.index'), value ? { search: value } : {}, { preserveScroll: true });
}
</script>

<template>
    <UiPageHeader title="Cấu hình ngày nghỉ" description="Ngày nghỉ lễ / nghỉ riêng của chi nhánh dùng để sinh lịch học, hủy buổi trùng và xếp buổi học bù.">
        <template v-if="canManage" #actions>
            <UiButton icon="add" :href="route('holidays.create')" modal="md">Thêm ngày nghỉ</UiButton>
        </template>
    </UiPageHeader>

    <UiAlert type="info" title="Lưu ý nghiệp vụ" class="mb-lg">
        Hệ thống hiện tại chỉ hỗ trợ cấu hình ngày nghỉ theo Chi nhánh hoặc Toàn hệ thống. Tính năng cấu hình riêng cho từng lớp cụ thể đang được phát triển.
    </UiAlert>

    <div class="grid grid-cols-1 items-start gap-lg lg:grid-cols-12">
        <section :class="showForm ? 'lg:col-span-8' : 'lg:col-span-12'">
            <UiDataTable min-width="640px">
                <template #header>
                    <h2 class="font-h3 text-h3 text-on-surface">Danh sách ngày nghỉ</h2>
                    <form :action="route('holidays.index')" method="GET" role="search" class="relative" @submit.prevent="onSearch">
                        <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[18px] text-on-surface-variant" aria-hidden="true">search</span>
                        <input type="search" name="search" :value="search" placeholder="Tìm kiếm ngày nghỉ..." aria-label="Tìm kiếm ngày nghỉ" class="w-60 rounded-lg border border-outline-variant bg-surface-container-lowest py-xs pl-9 pr-md font-body-small text-body-small focus:border-primary-container focus:ring-2 focus:ring-primary-container/50" />
                    </form>
                </template>
                <table>
                    <thead>
                        <tr>
                            <th>Tên ngày nghỉ</th>
                            <th>Thời gian</th>
                            <th>Phạm vi áp dụng</th>
                            <th v-if="canManage" class="text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in holidays.data" :key="row.id" :class="{ 'bg-primary-container/5': holiday && holiday.id === row.id }">
                            <td>
                                <div class="font-semibold text-on-surface">{{ row.name }}</div>
                                <div class="font-caption text-caption text-on-surface-variant">Mã: <span class="font-code">{{ row.code }}</span></div>
                            </td>
                            <td>
                                <div class="flex items-center gap-xs whitespace-nowrap font-code text-code">
                                    <span>{{ formatDate(row.start_date) }}</span>
                                    <span class="material-symbols-outlined text-[16px] text-on-surface-variant" aria-hidden="true">arrow_forward</span>
                                    <span>{{ formatDate(row.end_date) }}</span>
                                </div>
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-xs">
                                    <UiBadge v-if="row.is_system_wide" color="primary" :dot="false">Toàn hệ thống</UiBadge>
                                    <UiBadge v-for="name in row.branches" v-else :key="name" color="secondary" :dot="false">{{ name }}</UiBadge>
                                </div>
                            </td>
                            <td v-if="canManage" class="whitespace-nowrap text-right">
                                <div class="inline-flex items-center gap-xs">
                                    <UiButton variant="ghost" icon="edit" :href="route('holidays.edit', row.id)" modal="md" title="Sửa" :aria-label="`Sửa ${row.name}`" />
                                    <UiForm :action="route('holidays.destroy', row.id)" method="delete" :confirm="`Xóa ngày nghỉ ${row.name}?\nBuổi học bị hủy do ngày nghỉ này sẽ được khôi phục, buổi bù tương ứng được gỡ.`" confirm-title="Xóa ngày nghỉ?" confirm-label="Xóa" danger back>
                                        <UiButton type="submit" variant="danger-text" icon="delete" title="Xóa" :aria-label="`Xóa ${row.name}`" />
                                    </UiForm>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!holidays.data.length">
                            <td colspan="4">
                                <UiEmptyState icon="event_available" :title="search ? 'Không tìm thấy ngày nghỉ' : 'Chưa có ngày nghỉ nào'" :description="search ? 'Thử đổi từ khóa tìm kiếm.' : 'Bấm “Thêm ngày nghỉ” để tạo mới.'" />
                            </td>
                        </tr>
                    </tbody>
                </table>
                <template #footer><UiPagination :paginator="holidays" unit="ngày nghỉ" /></template>
            </UiDataTable>
        </section>

        <!-- Form Thêm / Sửa (chỉ khi mở thẳng URL create/edit) -->
        <aside v-if="showForm" class="lg:sticky lg:top-md lg:col-span-4">
            <div class="rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm">
                <div class="flex items-center gap-sm border-b border-surface-container p-md">
                    <span class="material-symbols-outlined text-primary-container" aria-hidden="true">edit_note</span>
                    <h2 class="font-h3 text-h3 text-on-surface">{{ holiday ? 'Sửa ngày nghỉ' : 'Thông tin ngày nghỉ' }}</h2>
                </div>
                <UiForm :action="holiday ? route('holidays.update', holiday.id) : route('holidays.store')" :method="holiday ? 'put' : 'post'" class="space-y-md p-md">
                    <HolidayFields :holiday="holiday" :branches="branches ?? []" :selected-branch-ids="selectedBranchIds" />
                    <div class="flex justify-end gap-sm border-t border-surface-container pt-md">
                        <UiButton variant="secondary" :href="route('holidays.index')">Hủy bỏ</UiButton>
                        <UiButton type="submit">Lưu thông tin</UiButton>
                    </div>
                </UiForm>
            </div>
        </aside>
    </div>
</template>
