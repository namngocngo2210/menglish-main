<script setup>
/** Nút "Đổi vai trò" trên topbar (bản thử nghiệm, chỉ hiện khi trình duyệt đã nhập mã truy cập — App\Support\RoleSwitcher). */
import RoleSwitcherAccounts from './RoleSwitcherAccounts.vue';

defineProps({
    switcher: { type: Object, required: true },
});
</script>

<template>
    <UiDropdown align="right" width="80" content-classes="bg-surface-container-lowest">
        <template #trigger>
            <button type="button" class="flex items-center gap-xs rounded-lg bg-warning-container px-sm py-xs font-body-small text-body-small text-on-warning-container hover:brightness-95 max-md:min-h-11" data-role-switcher>
                <span class="material-symbols-outlined text-[20px]" aria-hidden="true">switch_account</span>
                <span class="hidden sm:inline">Đổi vai trò</span>
                <span v-if="switcher.current" class="hidden font-body-semibold lg:inline">· {{ switcher.current.role }}</span>
            </button>
        </template>
        <template #content>
            <div class="border-b border-surface-container px-md py-sm font-caption text-caption text-on-surface-variant">
                Bản thử nghiệm: chọn vai trò để xem màn hình của tài khoản đó, không cần mật khẩu.
            </div>
            <div class="max-h-[70vh] overflow-y-auto">
                <RoleSwitcherAccounts :groups="switcher.groups" />
            </div>
        </template>
    </UiDropdown>
</template>
