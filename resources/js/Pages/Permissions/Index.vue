<script setup>
/** Danh mục quyền hạn: Sửa mở modal (Form.vue), Xóa qua hộp xác nhận ngay trên trang. Lưu / xóa xong danh sách tự cập nhật. */
import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

defineOptions({ layout: { title: 'Danh mục quyền' } });

defineProps({ permissions: { type: Object, required: true } });

const page = usePage();
const del = ref(null);
</script>

<template>
    <UiPageHeader title="Danh mục quyền hạn hệ thống" description="Mỗi quyền có dạng module.action, được gán cho vai trò ở màn Vai trò & phân quyền.">
        <template v-if="can('role.view')" #actions>
            <UiButton variant="secondary" icon="admin_panel_settings" :href="route('roles.index')">Vai trò</UiButton>
        </template>
    </UiPageHeader>

    <UiAlert v-if="Object.keys(page.props.errors ?? {}).length" type="error" class="mb-md">{{ Object.values(page.props.errors)[0] }}</UiAlert>

    <div id="permission-list">
        <UiDataTable min-width="760px">
            <table>
                <thead>
                    <tr>
                        <th>Tên quyền hạn</th>
                        <th>Mã phân quyền</th>
                        <th>Phân hệ chức năng</th>
                        <th class="text-center">Số vai trò áp dụng</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="permission in permissions.data" :key="permission.id">
                        <td class="font-semibold text-on-surface">{{ permission.action_label }}</td>
                        <td class="font-code text-primary">{{ permission.name }}</td>
                        <td>{{ permission.module_label }}</td>
                        <td class="text-center"><UiBadge color="neutral" :dot="false">{{ permission.roles_count }} vai trò</UiBadge></td>
                        <td class="whitespace-nowrap text-right">
                            <UiButton v-if="can('permission.update')" size="sm" variant="ghost" icon="edit" :href="route('permissions.edit', permission.id)" modal="sm" title="Sửa" :aria-label="`Sửa ${permission.name}`" />
                            <UiButton v-if="can('permission.delete')" size="sm" variant="danger-text" icon="delete" title="Xóa" :aria-label="`Xóa ${permission.name}`" @click="del = permission" />
                        </td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="permissions" unit="quyền" /></template>
        </UiDataTable>
    </div>

    <UiModal v-if="can('permission.delete')" :show="!!del" title="Xóa quyền?" max-width="md" @close="del = null">
        <p>Xóa quyền <strong class="font-code font-semibold">{{ del?.name }}</strong>?</p>
        <p class="mt-xs font-body-small text-body-small text-on-surface-variant">Chỉ xóa được quyền chưa gán cho vai trò nào.</p>
        <UiForm v-if="del" id="delete-permission-form" :action="route('permissions.destroy', del.id)" method="delete" back @success="del = null" @error="del = null" />
        <template #footer>
            <UiButton variant="secondary" @click="del = null">Hủy</UiButton>
            <UiButton variant="danger" type="submit" form="delete-permission-form" icon="delete">Xóa</UiButton>
        </template>
    </UiModal>
</template>
