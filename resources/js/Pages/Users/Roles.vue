<script setup>
/** Gán vai trò & chức vụ cho nhân sự: mở từ danh sách / chi tiết → modal; mở thẳng URL → trang đầy đủ. */
import { Link } from '@inertiajs/vue3';
import UserRolesFields from './UserRolesFields.vue';

defineOptions({ layout: { title: 'Gán vai trò' } });

defineProps({
    asModal: { type: Boolean, default: false },
    user: { type: Object, required: true },
    roles: { type: Array, default: () => [] },
});
</script>

<template>
    <UiModalFrame v-if="asModal" :title="`Gán vai trò — ${user.name}`" :description="`${user.branch_name ?? 'Chưa gán chi nhánh'} · ${user.email}`" :action="route('users.roles.update', user.id)" method="put" submit-label="Lưu thay đổi vai trò" submit-icon="save" :form-options="{ id: 'modal-user-roles-form' }">
        <UserRolesFields :roles="roles" :checked="user.roles" />
    </UiModalFrame>

    <template v-else>
        <UiPageHeader :title="`Gán vai trò & Chức vụ — ${user.name}`" description="Chọn một hoặc nhiều vai trò để gán quyền tương ứng cho người dùng này.">
            <template #breadcrumbs>
                <Link :href="route('users.index')" class="inline-flex items-center gap-xs hover:text-primary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">group</span>Người dùng</Link>
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                <span>Gán vai trò chức vụ</span>
            </template>
            <template v-if="can('permission.override')" #actions>
                <UiButton variant="secondary" icon="tune" :href="route('users.permissions.edit', user.id)">Phân quyền chi tiết</UiButton>
            </template>
        </UiPageHeader>

        <div class="grid grid-cols-1 items-start gap-lg lg:grid-cols-12">
            <section class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg text-center lg:col-span-4">
                <div class="flex flex-col items-center gap-xs">
                    <UiAvatar :name="user.name" />
                    <h2 class="font-h3 text-h3 text-on-surface">{{ user.name }}</h2>
                    <p class="font-body-small text-body-small text-on-surface-variant">{{ user.branch_name ?? 'Chưa gán chi nhánh' }}</p>
                    <p class="font-code text-caption text-on-surface-variant">{{ user.email }}</p>
                </div>
                <dl class="space-y-xs border-t border-surface-container pt-md text-left font-body-small text-body-small">
                    <div class="flex justify-between"><dt class="text-on-surface-variant">Mã NV</dt><dd class="font-code">{{ user.employee_code }}</dd></div>
                    <div class="flex justify-between"><dt class="text-on-surface-variant">Số điện thoại</dt><dd class="font-code">{{ user.phone ?? 'Chưa cập nhật' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-on-surface-variant">Số vai trò hiện tại</dt><dd class="font-semibold text-primary">{{ user.roles.length }} vai trò</dd></div>
                </dl>
            </section>

            <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg lg:col-span-8">
                <UiForm id="user-roles-form" :action="route('users.roles.update', user.id)" method="put" class="space-y-md">
                    <UserRolesFields :roles="roles" :checked="user.roles" />
                    <div class="flex justify-end gap-sm border-t border-surface-container pt-md">
                        <UiButton variant="secondary" :href="route('users.index')">Hủy</UiButton>
                        <UiButton type="submit" icon="save">Lưu thay đổi vai trò</UiButton>
                    </div>
                </UiForm>
            </section>
        </div>
    </template>
</template>
