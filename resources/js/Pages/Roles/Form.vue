<script setup>
/**
 * Màn Vai trò (RBAC linh hoạt — docs/rbac.md): tên / mã / mô tả + ma trận quyền theo module
 * (Xem / Thêm / Sửa / Xóa / Duyệt + thao tác khác + phạm vi dữ liệu).
 * Mở từ danh sách → modal chỉ gồm tên / mã / mô tả; mở thẳng URL → trang đầy đủ kèm ma trận quyền.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import RoleFields from './RoleFields.vue';
import RoleMatrix from './RoleMatrix.vue';
import { useBackLink } from '@/lib/backLink';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Vai trò' } });

const props = defineProps({
    asModal: { type: Boolean, default: false },
    role: { type: Object, default: null },
    isSuperAdmin: { type: Boolean, default: false },
    isSystemRole: { type: Boolean, default: false },
    columns: { type: Array, default: () => [] },
    matrix: { type: Array, default: () => [] },
    selected: { type: Array, default: () => [] },
    canAssignPermissions: { type: Boolean, default: false },
});
const back = useBackLink(() => route('roles.index'));
const page = usePage();
const action = computed(() => (props.role ? route('roles.update', props.role.id) : route('roles.store')));
const method = computed(() => (props.role ? 'put' : 'post'));
const readonly = computed(() => props.isSuperAdmin || !props.canAssignPermissions);
const firstError = computed(() => Object.values(page.props.errors ?? {})[0] ?? null);
</script>

<template>
    <UiModalFrame v-if="asModal" :title="role ? 'Đổi tên vai trò' : 'Thêm vai trò mới'" description="Tên hiển thị, mã và mô tả. Quyền của vai trò cấu hình ở màn “Cấu hình quyền”." :action="action" :method="method" submit-label="Lưu vai trò" submit-icon="save">
        <UiAlert v-if="isSuperAdmin" type="info" class="mb-md">Vai trò Super Admin bất biến: chỉ đổi được tên hiển thị và mô tả.</UiAlert>
        <div class="space-y-md">
            <RoleFields :role="role" :is-system-role="isSystemRole" id-prefix="modal-role-" />
        </div>
        <p v-if="!role" class="mt-md font-body-small text-body-small text-on-surface-variant">Vai trò mới chưa có quyền nào — bấm “Cấu hình quyền” ở danh sách sau khi tạo.</p>
    </UiModalFrame>

    <template v-else>
        <UiPageHeader :title="role ? `Cấu hình vai trò — ${role.short_label}` : 'Tạo vai trò mới'" description="Bật / tắt từng quyền theo module và chọn phạm vi dữ liệu. Thay đổi có hiệu lực ngay, được ghi vào Nhật ký vận hành.">
            <template #breadcrumbs>
                <Link :href="route('roles.index')" class="inline-flex items-center gap-xs hover:text-primary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">admin_panel_settings</span>Vai trò</Link>
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                <span>{{ role ? role.short_label : 'Tạo mới' }}</span>
            </template>
            <template #actions>
                <UiButton variant="secondary" icon="arrow_back" :href="back.href" data-back-link>Quay lại</UiButton>
                <UiButton type="submit" form="roleForm" icon="save">Lưu vai trò</UiButton>
            </template>
        </UiPageHeader>

        <UiAlert v-if="firstError" type="error" class="mb-md">{{ firstError }}</UiAlert>
        <UiAlert v-if="isSuperAdmin" type="info" title="Vai trò Super Admin bất biến" class="mb-md" data-testid="super-admin-immutable">
            Super Admin luôn có toàn quyền thao tác và phạm vi "Toàn hệ thống" (không thu hồi được quyền, không xóa được vai trò).
            Chỉ đổi được tên hiển thị và mô tả. Quyền "đối tượng" (cổng học viên / giáo viên…) không áp dụng cho Super Admin.
        </UiAlert>
        <UiAlert v-else-if="!canAssignPermissions" type="warning" class="mb-md">Bạn chỉ xem được ma trận quyền (cần quyền "Phân quyền cho vai trò" để thay đổi).</UiAlert>

        <UiForm id="roleForm" :action="action" :method="method" class="space-y-md">
            <div class="grid grid-cols-1 gap-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md md:grid-cols-3">
                <RoleFields :role="role" :is-system-role="isSystemRole" />
            </div>

            <RoleMatrix :columns="columns" :matrix="matrix" :selected="selected" :readonly="readonly" />

            <div class="flex items-center justify-end gap-sm">
                <UiButton variant="secondary" :href="route('roles.index')">Hủy</UiButton>
                <UiButton type="submit" icon="save">Lưu vai trò</UiButton>
            </div>
        </UiForm>
    </template>
</template>
