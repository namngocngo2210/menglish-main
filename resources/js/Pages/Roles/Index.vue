<script setup>
/**
 * Danh sách vai trò (RBAC linh hoạt — docs/rbac.md): tạo, nhân bản, cấu hình quyền, xóa (chỉ khi chưa gán cho ai).
 * Thêm / Đổi tên mở modal (Form.vue), Xóa qua hộp xác nhận; "Cấu hình quyền" (ma trận) vẫn là trang riêng.
 */
import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

defineOptions({ layout: { title: 'Vai trò' } });

defineProps({ roles: { type: Object, required: true } });

const page = usePage();
const del = ref(null);
</script>

<template>
    <UiPageHeader title="Vai trò & phân quyền" description="Mỗi vai trò là một tập quyền theo module + phạm vi dữ liệu. Nhân sự kiêm nhiệm nhiều vai trò được cộng dồn quyền; Phân quyền cá nhân cho phép / chặn riêng từng người.">
        <template v-if="canAny('permission.view', 'role.create')" #actions>
            <UiButton v-if="can('permission.view')" variant="secondary" icon="security" :href="route('permissions.index')">Danh mục quyền</UiButton>
            <UiButton v-if="can('role.create')" icon="add_circle" :href="route('roles.create')" modal="md">Thêm vai trò mới</UiButton>
        </template>
    </UiPageHeader>

    <UiAlert v-if="Object.keys(page.props.errors ?? {}).length" type="error" class="mb-md">{{ Object.values(page.props.errors)[0] }}</UiAlert>

    <div id="role-list">
        <UiDataTable min-width="860px">
            <table>
                <thead>
                    <tr>
                        <th>Tên vai trò</th>
                        <th>Mã định danh</th>
                        <th class="text-center">Số quyền</th>
                        <th class="text-center">Số nhân sự</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="role in roles.data" :key="role.id">
                        <td>
                            <div class="font-semibold text-on-surface">{{ role.label }}</div>
                            <div v-if="role.description" class="font-caption text-caption text-on-surface-variant">{{ role.description }}</div>
                            <UiBadge v-if="role.super_admin" color="primary" :dot="false">Super Admin · bất biến</UiBadge>
                            <UiBadge v-else-if="!role.system" color="info" :dot="false">Tự tạo</UiBadge>
                        </td>
                        <td class="font-code text-on-surface-variant">{{ role.name }}</td>
                        <td class="text-center">{{ role.super_admin ? 'Toàn quyền' : `${role.permissions_count} quyền` }}</td>
                        <td class="text-center">{{ role.users_count }} nhân sự</td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-xs whitespace-nowrap">
                                <template v-if="can('role.update')">
                                    <UiButton size="sm" variant="ghost" icon="edit" :href="route('roles.edit', role.id)" modal="md" title="Đổi tên vai trò" :aria-label="`Đổi tên ${role.name}`" />
                                    <UiButton size="sm" variant="secondary" icon="tune" :href="route('roles.edit', role.id)">{{ role.super_admin ? 'Xem quyền' : 'Cấu hình quyền' }}</UiButton>
                                </template>
                                <UiForm v-if="can('role.create')" :action="route('roles.duplicate', role.id)" method="post" class="inline">
                                    <UiButton size="sm" variant="ghost" icon="content_copy" type="submit" title="Nhân bản vai trò">Nhân bản</UiButton>
                                </UiForm>
                                <UiButton v-if="can('role.delete') && !role.super_admin && role.users_count === 0" size="sm" variant="danger-text" icon="delete" title="Xóa vai trò" @click="del = role">Xóa</UiButton>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="roles" /></template>
        </UiDataTable>
    </div>

    <UiModal v-if="can('role.delete')" :show="!!del" title="Xóa vai trò?" max-width="md" @close="del = null">
        <p>Xóa vai trò <strong class="font-semibold">{{ del?.label }}</strong>?</p>
        <p class="mt-xs font-body-small text-body-small text-on-surface-variant">Chỉ xóa được vai trò chưa gán cho nhân sự nào. Thao tác được ghi vào Nhật ký vận hành.</p>
        <UiForm v-if="del" id="delete-role-form" :action="route('roles.destroy', del.id)" method="delete" back @success="del = null" @error="del = null" />
        <template #footer>
            <UiButton variant="secondary" @click="del = null">Hủy</UiButton>
            <UiButton variant="danger" type="submit" form="delete-role-form" icon="delete">Xóa</UiButton>
        </template>
    </UiModal>
</template>
