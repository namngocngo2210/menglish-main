<script setup>
/** Danh sách vai trò để gán cho nhân sự — dùng chung trang đầy đủ và modal (Roles.vue). Vai trò đầu tiên là vai trò chính. */
import { computed } from 'vue';
import { useFormContext, usePage } from '@inertiajs/vue3';

defineProps({
    roles: { type: Array, required: true },
    checked: { type: Array, default: () => [] },
});
const form = useFormContext();
const page = usePage();
const roleError = computed(() => (Object.keys(form?.errors ?? {}).length ? form.errors : (page.props.errors ?? {})).role ?? null);
</script>

<template>
    <UiAlert v-if="roleError" type="error">{{ roleError }}</UiAlert>

    <UiField label="Vai trò (chọn một hoặc nhiều)" name="roles" required hint="Vai trò đầu tiên là vai trò chính; các vai trò còn lại là kiêm nhiệm.">
        <div class="space-y-xs">
            <label v-for="role in roles" :key="role.name" class="group flex cursor-pointer items-center justify-between gap-md rounded-lg border border-outline-variant p-sm transition-colors hover:border-primary-container hover:bg-primary-container/5">
                <span class="flex items-center gap-sm">
                    <input type="checkbox" name="roles[]" :value="role.name" :checked="checked.includes(role.name)" class="rounded border-outline-variant text-primary-container focus:ring-primary-container/40" />
                    <span>
                        <span class="block font-semibold text-on-surface group-hover:text-primary">{{ role.label }}</span>
                        <span class="block font-code text-caption text-on-surface-variant">{{ role.name }}</span>
                    </span>
                </span>
                <UiBadge color="neutral" :dot="false">{{ role.permissions_count }} quyền</UiBadge>
            </label>
        </div>
    </UiField>
</template>
