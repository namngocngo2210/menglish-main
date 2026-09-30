<script setup>
/**
 * Quản lý Danh mục hệ thống (mockup epic-5/quan-ly-danh-muc-he-thong): tab theo nhóm, bảng danh mục.
 * "Thêm danh mục mới" / Sửa dòng mở modal (Form.vue), Ngừng sử dụng qua hộp xác nhận. Mở thẳng URL edit (?edit=) → modal sửa mở sẵn.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { currentQuery } from '@/lib/url';
import CategoryFields from './CategoryFields.vue';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Quản lý Danh mục hệ thống' } });

const props = defineProps({
    categories: { type: Object, required: true },
    type: { type: String, required: true },
    types: { type: Array, default: () => [] },
    editing: { type: Object, default: null },
    search: { type: String, default: '' },
    canManage: { type: Boolean, default: false },
});

const del = ref(null);
const editOpen = ref(!!props.editing);
watch(() => props.editing, (value) => (editOpen.value = !!value));

const dismissUrl = computed(() => {
    const query = Object.fromEntries(currentQuery());
    delete query.edit;
    return route('system-categories.index', query);
});

function onSearch(event) {
    const q = new FormData(event.target).get('q');
    router.get(route('system-categories.index'), { type: props.type, ...(q ? { q } : {}) }, { preserveScroll: true });
}
</script>

<template>
    <UiPageHeader title="Quản lý Danh mục hệ thống" description="Cấu hình các tham số nền tảng của hệ thống MENGLISH.">
        <template #actions>
            <UiButton variant="secondary" icon="refresh" :href="route('system-categories.index', { type })">Làm mới</UiButton>
            <UiButton v-if="canManage" icon="add_circle" :href="route('system-categories.create', { type })" modal="md">Thêm danh mục mới</UiButton>
        </template>
    </UiPageHeader>

    <UiTabs class="mb-md">
        <UiTab v-for="t in types" :key="t.value" :href="route('system-categories.index', { type: t.value })" :active="type === t.value">{{ t.label }}</UiTab>
    </UiTabs>

    <div id="category-list">
        <UiDataTable>
            <template #header>
                <form method="GET" :action="route('system-categories.index')" class="flex w-full items-center gap-sm" @submit.prevent="onSearch">
                    <input type="hidden" name="type" :value="type" />
                    <div class="flex-1"><UiInput name="q" icon="search" :value="search" placeholder="Tìm mã hoặc tên danh mục..." aria-label="Tìm kiếm" /></div>
                </form>
            </template>
            <table>
                <thead>
                    <tr>
                        <th>Mã</th>
                        <th>Tên danh mục</th>
                        <th class="text-center">Thứ tự</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="category in categories.data" :key="category.id" :class="[editing?.id === category.id ? 'bg-primary-fixed/40' : '', category.is_active ? '' : 'opacity-70']">
                        <td class="font-code">{{ category.code }}</td>
                        <td class="font-medium">{{ category.name }}</td>
                        <td class="text-center font-code">{{ category.sort_order }}</td>
                        <td>
                            <UiBadge v-if="category.is_active" color="success">Đang dùng</UiBadge>
                            <UiBadge v-else color="neutral">Đã ngừng</UiBadge>
                        </td>
                        <td class="text-right">
                            <div v-if="canManage" class="flex items-center justify-end gap-xs">
                                <UiButton size="sm" variant="ghost" icon="edit" :href="route('system-categories.edit', category.id)" modal="md" title="Sửa" :aria-label="`Sửa ${category.name}`" />
                                <UiButton v-if="category.is_active" size="sm" variant="danger-text" icon="block" title="Ngừng sử dụng" :aria-label="`Ngừng sử dụng ${category.name}`" @click="del = category" />
                                <UiForm v-else :action="route('system-categories.reactivate', category.id)" method="post" back>
                                    <UiButton type="submit" size="sm" variant="ghost" icon="settings_backup_restore" title="Kích hoạt lại">Kích hoạt lại</UiButton>
                                </UiForm>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!categories.data.length">
                        <td colspan="5"><UiEmptyState icon="category" title="Chưa có danh mục nào" description="Bấm &quot;Thêm danh mục mới&quot; để thêm giá trị đầu tiên." /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="categories" unit="kết quả" /></template>
        </UiDataTable>
    </div>

    <!-- Xác nhận ngừng sử dụng -->
    <UiModal v-if="canManage" :show="!!del" title="Ngừng sử dụng danh mục?" max-width="md" @close="del = null">
        <p>Ngừng sử dụng <strong class="font-semibold">{{ del?.name }}</strong>?</p>
        <p class="mt-xs font-body-small text-body-small text-on-surface-variant">Dữ liệu cũ vẫn giữ nguyên; có thể kích hoạt lại bất cứ lúc nào.</p>
        <UiForm v-if="del" id="deactivate-category-form" :action="route('system-categories.destroy', del.id)" method="delete" back @success="del = null" @error="del = null" />
        <template #footer>
            <UiButton variant="secondary" @click="del = null">Hủy</UiButton>
            <UiButton variant="danger" type="submit" form="deactivate-category-form" icon="block">Ngừng sử dụng</UiButton>
        </template>
    </UiModal>

    <!-- Sửa khi mở thẳng URL (?edit=): modal mở sẵn; đóng → bỏ edit khỏi thanh địa chỉ. -->
    <UiModal v-if="canManage && editing" :show="editOpen" title="Sửa giá trị danh mục" max-width="md" :dismiss-url="dismissUrl" @close="editOpen = false">
        <UiForm id="category-form" :action="route('system-categories.update', editing.id)" method="put" class="space-y-md" @success="editOpen = false">
            <CategoryFields :category="editing" :type-labels="types" :type-select="false" />
        </UiForm>
        <template #footer>
            <UiButton variant="secondary" @click="editOpen = false">Hủy</UiButton>
            <UiButton type="submit" form="category-form" icon="save">Lưu thông tin</UiButton>
        </template>
    </UiModal>
</template>
