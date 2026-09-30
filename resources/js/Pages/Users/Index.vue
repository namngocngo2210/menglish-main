<script setup>
/**
 * Quản lý Tài khoản & Vai trò (mockup epic-5/quan-ly-tai-khoan-vai-tro).
 * Thêm / Sửa nhân sự (modal 3xl, 3 tab — Form.vue), Phân quyền cá nhân (modal 4xl — Permissions.vue), "Vai trò & kiêm nhiệm" (modal md — Roles.vue);
 * lưu xong danh sách tự cập nhật. Xóa tài khoản qua hộp xác nhận, hồ sơ nhanh ở drawer (ProfileDrawer.vue).
 */
import { ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { openRemoteModal } from '@/lib/remoteModal';
import ProfileDrawer from './ProfileDrawer.vue';

defineOptions({ layout: { title: 'Quản lý Tài khoản & Vai trò' } });

defineProps({
    users: { type: Object, required: true },
    stats: { type: Object, required: true },
    expiringContracts: { type: Number, default: 0 },
    contractWarningDays: { type: Number, default: 30 },
    branches: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
});

const page = usePage();
const drawerOpen = ref(false);
const activeUser = ref(null);
const del = ref(null);

function openProfile(profile) {
    activeUser.value = profile;
    drawerOpen.value = true;
}
</script>

<template>
    <UiPageHeader title="Quản lý Tài khoản & Vai trò" description="Danh sách người dùng, vai trò chính và kiêm nhiệm, hợp đồng lao động.">
        <template v-if="can('user.create')" #actions>
            <UiButton icon="person_add" :href="route('users.create')" modal="3xl">Thêm người dùng mới</UiButton>
        </template>
    </UiPageHeader>

    <UiAlert v-if="Object.keys(page.props.errors ?? {}).length" type="error" class="mb-md">{{ Object.values(page.props.errors)[0] }}</UiAlert>

    <div class="mb-md grid grid-cols-2 gap-md md:grid-cols-4">
        <UiStatCard label="Tổng người dùng" :value="stats.total" icon="group" />
        <UiStatCard label="Đang hoạt động" :value="stats.active" icon="check_circle" tone="success" />
        <UiStatCard label="Khối học thuật" :value="stats.academic" icon="school" tone="secondary" />
        <UiStatCard label="Vô hiệu hóa" :value="stats.locked" icon="block" tone="error" />
    </div>

    <UiAlert v-if="expiringContracts > 0" type="warning" class="mb-md">
        {{ expiringContracts }} người dùng có hợp đồng đã hết hạn hoặc hết hạn trong {{ contractWarningDays }} ngày tới —
        <Link class="font-semibold underline" :href="route('users.index', { status: 'contract_expiring' })">lọc danh sách</Link>.
    </UiAlert>

    <UiFilterBar placeholder="Tìm họ tên, email, SĐT, mã NV...">
        <UiSelect name="branch_id" label="Cơ sở" :options="branches" placeholder="Tất cả cơ sở" />
        <UiSelect name="role" label="Vai trò" :options="roles" placeholder="Tất cả vai trò" />
        <UiSelect name="status" label="Trạng thái" :options="statuses" placeholder="Mọi trạng thái" />
    </UiFilterBar>

    <div id="user-list">
        <UiDataTable min-width="860px">
            <table>
                <thead>
                    <tr>
                        <th>Họ và tên</th>
                        <th>Vai trò</th>
                        <th>Cơ sở</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users.data" :key="user.id">
                        <td>
                            <div class="flex items-center gap-sm">
                                <UiAvatar :name="user.name" />
                                <div class="min-w-0">
                                    <Link :href="route('users.show', user.id)" class="font-semibold text-on-surface hover:text-primary">{{ user.name }}</Link>
                                    <div v-if="user.email" class="max-w-[280px] break-all font-code text-caption text-on-surface-variant">{{ user.email }}</div>
                                    <UiCode :value="user.employee_code" class="block max-w-[280px] truncate font-code text-caption text-on-surface-variant" />
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-xs">
                                <UiBadge v-for="(role, i) in user.roles" :key="role" :color="i === 0 ? 'primary' : 'neutral'" :dot="false" :title="i === 0 ? 'Vai trò chính' : 'Kiêm nhiệm'">{{ role }}{{ i > 0 ? ' (kiêm nhiệm)' : '' }}</UiBadge>
                            </div>
                        </td>
                        <td>{{ user.branch_name ?? 'Chưa gán chi nhánh' }}</td>
                        <td>
                            <div class="flex flex-col items-start gap-xs">
                                <UiBadge v-if="user.locked" color="error">Vô hiệu hóa</UiBadge>
                                <UiBadge v-else color="success">Đang hoạt động</UiBadge>
                                <UiBadge v-if="user.contract_status === 'expired'" color="error" :dot="false" :title="`Hợp đồng kết thúc ${user.contract_end_date}`">HĐ đã hết hạn</UiBadge>
                                <UiBadge v-else-if="user.contract_status === 'expiring'" color="warning" :dot="false">HĐ sắp hết hạn {{ user.contract_end_date }}</UiBadge>
                            </div>
                        </td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-xs">
                                <UiButton v-if="can('permission.override')" variant="ghost" size="sm" icon="admin_panel_settings" :href="route('users.permissions.edit', user.id)" modal="4xl" title="Phân quyền cá nhân" aria-label="Phân quyền cá nhân" />
                                <UiForm
                                    v-if="can('user.lock')"
                                    :action="user.locked ? route('users.unlock', user.id) : route('users.lock', user.id)"
                                    method="post"
                                    class="inline"
                                    :confirm="`${user.locked ? 'Kích hoạt lại' : 'Vô hiệu hóa'} tài khoản ${user.name}?`"
                                >
                                    <UiButton type="submit" variant="ghost" size="sm" :icon="user.locked ? 'check_circle' : 'block'" :title="user.locked ? 'Kích hoạt lại' : 'Vô hiệu hóa'" :aria-label="user.locked ? 'Kích hoạt lại' : 'Vô hiệu hóa'" />
                                </UiForm>
                                <UiButton variant="ghost" size="sm" icon="visibility" title="Xem hồ sơ nhanh" aria-label="Xem hồ sơ nhanh" @click="openProfile(user.profile)" />
                                <UiDropdown width="48">
                                    <template #trigger><UiButton variant="ghost" size="sm" icon="more_vert" aria-label="Thao tác khác" /></template>
                                    <template #content>
                                        <div class="text-left">
                                            <a v-if="can('user.update')" :href="route('users.edit', user.id)" class="flex items-center gap-sm px-md py-xs font-body-small text-body-small hover:bg-surface-container-low" @click.prevent="openRemoteModal(route('users.edit', user.id), { size: '3xl' })"><span class="material-symbols-outlined text-[16px]">edit</span>Sửa thông tin</a>
                                            <a v-if="can('user.assign_role')" :href="route('users.roles.edit', user.id)" class="flex items-center gap-sm px-md py-xs font-body-small text-body-small hover:bg-surface-container-low" @click.prevent="openRemoteModal(route('users.roles.edit', user.id), { size: 'md' })"><span class="material-symbols-outlined text-[16px]">badge</span>Vai trò & kiêm nhiệm</a>
                                            <UiForm v-if="can('user.reset_password')" :action="route('users.reset-password', user.id)" method="post" :confirm="`Đặt lại mật khẩu cho ${user.name}?`">
                                                <button type="submit" class="flex w-full items-center gap-sm px-md py-xs font-body-small text-body-small hover:bg-surface-container-low"><span class="material-symbols-outlined text-[16px]">key</span>Đặt lại mật khẩu</button>
                                            </UiForm>
                                            <button v-if="can('user.delete')" type="button" class="flex w-full items-center gap-sm px-md py-xs font-body-small text-body-small text-error hover:bg-error-container/40" @click="del = user"><span class="material-symbols-outlined text-[16px]">delete</span>Xóa tài khoản</button>
                                        </div>
                                    </template>
                                </UiDropdown>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!users.data.length">
                        <td colspan="5"><UiEmptyState icon="person_search" title="Không tìm thấy người dùng nào phù hợp với bộ lọc" /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><UiPagination :paginator="users" unit="người dùng" /></template>
        </UiDataTable>
    </div>

    <!-- Xác nhận xóa tài khoản (controller giữ redirect + flash như cũ) -->
    <UiModal v-if="can('user.delete')" :show="!!del" title="Xóa tài khoản người dùng?" max-width="md" @close="del = null">
        <p>Xóa tài khoản <strong class="font-semibold">{{ del?.name }}</strong>?</p>
        <UiForm v-if="del" id="delete-user-form" :action="route('users.destroy', del.id)" method="delete" @success="del = null" @error="del = null" />
        <template #footer>
            <UiButton variant="secondary" @click="del = null">Hủy</UiButton>
            <UiButton variant="danger" type="submit" form="delete-user-form" icon="delete">Xóa tài khoản</UiButton>
        </template>
    </UiModal>

    <ProfileDrawer :open="drawerOpen" :user="activeUser" @close="drawerOpen = false" />
</template>
