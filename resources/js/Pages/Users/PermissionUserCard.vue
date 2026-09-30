<script setup>
/** Thẻ nhân sự đầu màn phân quyền cá nhân. Nút "Vai trò" mở modal gán vai trò (trong modal: thay nội dung modal đang mở). */
defineProps({
    user: { type: Object, required: true },
    asModal: { type: Boolean, default: false },
});
</script>

<template>
    <div class="mb-md flex flex-wrap items-center gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
        <UiAvatar :name="user.name" />
        <div class="min-w-0 flex-1">
            <p class="font-body-medium text-body-medium font-semibold text-on-surface">{{ user.name }} <span class="font-code text-caption text-on-surface-variant">· {{ user.employee_code }}</span></p>
            <p class="font-body-small text-body-small text-on-surface-variant">{{ user.branch_name ?? 'Chưa gán chi nhánh' }} · {{ user.email }}</p>
        </div>
        <div class="flex flex-wrap gap-xs">
            <UiBadge v-for="(role, i) in user.roles" :key="role" :color="i === 0 ? 'primary' : 'neutral'" :dot="false">{{ role }}{{ i > 0 ? ' (kiêm nhiệm)' : '' }}</UiBadge>
        </div>
        <UiButton v-if="can('user.assign_role')" size="sm" variant="ghost" icon="tune" :href="route('users.roles.edit', user.id)" modal="md">Vai trò</UiButton>
    </div>
</template>
