<script setup>
/**
 * Nút "Đổi vai trò" trên topbar — chỉ môi trường thử nghiệm (shared prop `roleSwitcher`, App\Support\RoleSwitcher):
 * Super Admin chuyển thẳng sang tài khoản mẫu của từng vai trò để test màn hình, không phải đăng xuất / gõ mật khẩu.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const menu = computed(() => usePage().props.roleSwitcher);
</script>

<template>
    <UiDropdown v-if="menu" align="right" width="w-[320px] max-w-[92vw]" content-classes="bg-surface-container-lowest">
        <template #trigger>
            <button type="button" class="inline-flex items-center gap-xs rounded-full bg-warning-container px-sm py-1.5 font-body-small text-body-small font-semibold text-on-warning-container transition-colors hover:bg-warning-container/70 max-md:min-h-11 max-md:min-w-11 max-md:justify-center" :aria-label="`Đổi vai trò (đang là ${menu.current_role})`" data-role-switcher>
                <span class="material-symbols-outlined text-[20px]" aria-hidden="true">switch_account</span>
                <span class="hidden max-w-[140px] truncate md:inline">{{ menu.current_role }}</span>
            </button>
        </template>
        <template #content>
            <div class="border-b border-surface-container bg-warning-container/40 px-md py-sm">
                <div class="font-body-semibold text-body-small text-on-surface">Đổi vai trò</div>
                <div class="font-caption text-caption text-on-surface-variant">Chỉ có trên bản thử nghiệm. Bấm một tài khoản để vào ngay bằng tài khoản đó.</div>
            </div>
            <Link v-if="menu.origin" :href="menu.origin.url" method="post" as="button" class="flex w-full items-center gap-sm border-b border-surface-container px-md py-sm text-left font-body-medium text-body-medium text-primary hover:bg-surface-container-low" data-role-switcher-origin>
                <span class="material-symbols-outlined text-[20px]" aria-hidden="true">undo</span>
                <span class="min-w-0 truncate">Về tài khoản gốc · {{ menu.origin.name }}</span>
            </Link>
            <div class="max-h-[65vh] overflow-y-auto py-1">
                <div v-for="group in menu.groups" :key="group.role" class="py-1">
                    <div class="px-md pb-0.5 pt-xs font-caption text-caption font-semibold uppercase tracking-wide text-on-surface-variant">{{ group.label }}</div>
                    <component
                        :is="account.current ? 'div' : Link"
                        v-for="account in group.accounts"
                        :key="account.id"
                        data-role-switcher-account
                        v-bind="account.current ? { 'aria-current': 'true' } : { href: account.url, method: 'post', as: 'button' }"
                        :class="['flex w-full items-start gap-sm px-md py-1.5 text-left', account.current ? 'bg-primary-container/10' : 'hover:bg-surface-container-low']"
                    >
                        <span class="material-symbols-outlined mt-0.5 text-[18px]" :class="account.current ? 'text-primary' : 'text-transparent'" aria-hidden="true">check</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-body-medium text-body-medium text-on-surface">
                                {{ account.name }}<span v-if="account.branch" class="font-caption text-caption text-on-surface-variant"> · {{ account.branch }}</span>
                            </span>
                            <span class="block truncate font-caption text-caption text-on-surface-variant">{{ account.note || account.login }}</span>
                        </span>
                    </component>
                </div>
            </div>
        </template>
    </UiDropdown>
</template>
