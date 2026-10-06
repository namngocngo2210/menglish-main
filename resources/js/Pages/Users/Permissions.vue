<script setup>
/**
 * Phân quyền cá nhân (mockup epic-5/phan-quyen-chi-tiet-ca-nhan; RBAC — docs/rbac.md): cùng ma trận với màn Vai trò.
 * Mở từ danh sách / chi tiết nhân sự → modal 7xl (bảng tự cuộn, tiêu đề cột cố định); mở thẳng URL → trang đầy đủ (kèm thẻ số liệu).
 * Mỗi quyền: theo vai trò / cấp thêm / thu hồi; mỗi module: phạm vi dữ liệu theo vai trò hoặc riêng người này;
 * Lớp học còn "Phạm vi áp dụng" theo chi nhánh / lớp cụ thể. Đổi vai trò xong ma trận tính lại theo vai trò mới.
 */
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import PermissionMatrix from './PermissionMatrix.vue';
import PermissionUserCard from './PermissionUserCard.vue';

defineOptions({ layout: { title: 'Phân quyền cá nhân' } });

const props = defineProps({
    asModal: { type: Boolean, default: false },
    user: { type: Object, required: true },
    targetIsSuperAdmin: { type: Boolean, default: false },
    columns: { type: Array, default: () => [] },
    matrix: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({ modules: 0, effective: 0, scopeUnits: 0 }) },
});
const page = usePage();
const matrixRef = ref(null);
const firstError = computed(() => Object.values(page.props.errors ?? {})[0] ?? null);
// Vai trò đổi (modal "Vai trò") → dựng lại ma trận theo quyền vai trò mới.
const matrixKey = computed(() => props.user.roles.join('|'));
const pad = (n) => String(n).padStart(2, '0');
</script>

<template>
    <UiModalFrame
        v-if="asModal"
        :title="`Phân quyền chi tiết — ${user.name}`"
        description="Phân quyền cá nhân thắng quyền theo vai trò: “Thu hồi” chặn quyền vai trò đang cấp, “Cấp thêm” mở quyền vai trò không có. Mọi thay đổi được ghi nhật ký."
        :action="route('users.permissions.update', user.id)"
        method="put"
        submit-label="Lưu phân quyền"
        submit-icon="save"
        :form-options="{ id: 'modal-permission-override-form' }"
        size="7xl"
        fill
    >
        <UiAlert v-if="targetIsSuperAdmin" type="warning" class="mb-md">Tài khoản Super Admin luôn có toàn quyền thao tác (phân quyền cá nhân không thu hẹp được) — chỉ các quyền "đối tượng" có tác dụng.</UiAlert>
        <UiAlert v-if="firstError" type="error" class="mb-md">{{ firstError }}</UiAlert>
        <PermissionUserCard :user="user" :as-modal="true" />
        <PermissionMatrix ref="matrixRef" :key="matrixKey" :columns="columns" :matrix="matrix" :branches="branches" :classes="classes" as-modal />
        <template #footer>
            <UiButton variant="secondary" @click="matrixRef?.reset()">Đặt lại mặc định</UiButton>
        </template>
    </UiModalFrame>

    <template v-else>
        <UiPageHeader :title="`Cấu hình quyền chi tiết — ${user.name}`">
            <template #breadcrumbs>
                <Link :href="route('users.index')" class="inline-flex items-center gap-xs hover:text-primary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">home</span>Người dùng</Link>
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">chevron_right</span>
                <span>Phân quyền cá nhân</span>
            </template>
            <template #actions>
                <UiButton v-if="can('activity_log.view')" variant="secondary" icon="history" :href="route('activity-logs.index', { search: user.name, log_name: 'Người dùng & Phân quyền' })">Xem nhật ký</UiButton>
                <UiButton type="submit" form="permissionOverrideForm" icon="save">Lưu thay đổi</UiButton>
            </template>
        </UiPageHeader>

        <UiAlert type="info" title="Ghi chú bảo mật quan trọng" class="mb-md">
            Mọi thay đổi về phân quyền sẽ được hệ thống tự động ghi lại vào Nhật ký vận hành bao gồm: Người thực hiện, Thời gian, và Nội dung thay đổi chi tiết (trước / sau). Phân quyền cá nhân thắng quyền theo vai trò: "Thu hồi" chặn quyền vai trò đang cấp, "Cấp thêm" mở quyền vai trò không có.
        </UiAlert>
        <UiAlert v-if="targetIsSuperAdmin" type="warning" class="mb-md">Tài khoản Super Admin luôn có toàn quyền thao tác (phân quyền cá nhân không thu hẹp được) — chỉ các quyền "đối tượng" có tác dụng.</UiAlert>
        <UiAlert v-if="firstError" type="error" class="mb-md">{{ firstError }}</UiAlert>

        <PermissionUserCard :user="user" />

        <UiForm id="permissionOverrideForm" :action="route('users.permissions.update', user.id)" method="put">
            <PermissionMatrix :key="matrixKey" :columns="columns" :matrix="matrix" :branches="branches" :classes="classes" />
        </UiForm>

        <div class="mt-md grid grid-cols-1 gap-md sm:grid-cols-3">
            <UiStatCard label="Tổng số Module" :value="`${pad(stats.modules)} danh mục`" icon="apps" />
            <UiStatCard label="Quyền truy cập" :value="`${stats.effective} thao tác cho phép`" icon="verified_user" tone="primary" />
            <UiStatCard label="Phạm vi dữ liệu" :value="`${pad(stats.scopeUnits)} đơn vị quản lý`" icon="domain" tone="secondary" :hint="stats.scopeUnits === 0 ? 'Toàn hệ thống theo vai trò' : null" />
        </div>
    </template>
</template>
