<script setup>
/** Topbar: tiêu đề trang (chữ thuần) · tìm kiếm chung · thông báo · tài khoản. */
import { Link, router } from '@inertiajs/vue3';
import NotificationMenu from './NotificationMenu.vue';

defineProps({
    shell: { type: Object, required: true },
    title: { type: String, default: null },
});
defineEmits(['open-menu']);

function search(event) {
    const q = new FormData(event.target).get('q');
    router.get(event.target.getAttribute('action'), { q });
}
</script>

<template>
    <header class="sticky top-0 z-30 flex h-header-height shrink-0 items-center justify-between gap-md border-b border-surface-container-highest bg-surface px-md lg:px-lg">
        <div class="flex min-w-0 flex-1 items-center gap-md lg:gap-lg">
            <button type="button" class="inline-flex shrink-0 items-center justify-center rounded-lg p-xs text-on-surface-variant hover:bg-surface-container-high max-md:min-h-11 max-md:min-w-11 md:hidden" aria-label="Mở menu" @click="$emit('open-menu')">
                <span class="material-symbols-outlined" aria-hidden="true">menu</span>
            </button>

            <div class="min-w-0 truncate font-h3 text-h3 text-on-surface" data-topbar-title>{{ title || shell.title || 'MEnglish' }}</div>

            <template v-if="shell.search">
                <form method="GET" :action="shell.search.url" role="search" class="relative hidden w-[300px] shrink-0 lg:block" @submit.prevent="search">
                    <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">search</span>
                    <input
                        type="search"
                        name="q"
                        :value="shell.search.query"
                        minlength="2"
                        placeholder="Tìm màn hình, khách, học viên, lớp..."
                        aria-label="Tìm kiếm màn hình, khách hàng, học viên, lớp học"
                        class="w-full rounded-full border-none bg-surface-container-low py-2 pl-10 pr-md font-body-small text-body-small text-on-surface placeholder:text-on-surface-variant/50 focus:ring-2 focus:ring-primary-container/50"
                    />
                </form>
                <Link :href="shell.search.url" class="inline-flex shrink-0 items-center justify-center rounded-lg p-2 text-on-surface-variant hover:bg-surface-container-high max-md:min-h-11 max-md:min-w-11 lg:hidden" aria-label="Tìm kiếm">
                    <span class="material-symbols-outlined" aria-hidden="true">search</span>
                </Link>
            </template>
        </div>

        <div class="flex shrink-0 items-center gap-xs sm:gap-md">
            <NotificationMenu :notifications="shell.notifications" />

            <UiDropdown align="right" width="56">
                <template #trigger>
                    <button type="button" class="flex items-center gap-sm rounded-lg p-xs text-left transition-colors hover:bg-surface-container-low sm:border-l sm:border-surface-container-highest sm:pl-md" aria-label="Tài khoản">
                        <span class="hidden text-right lg:block">
                            <span class="block max-w-[160px] truncate font-body-medium text-body-medium leading-tight text-on-surface">{{ shell.user.name }}</span>
                            <span class="block font-caption text-caption text-on-surface-variant">{{ shell.user.login }}</span>
                        </span>
                        <span class="rounded-full border-2 border-primary-container/20 p-0.5">
                            <UiAvatar :name="shell.user.name ?? '?'" size="sm" />
                        </span>
                    </button>
                </template>
                <template #content>
                    <div class="border-b border-surface-container px-md py-sm">
                        <div class="truncate font-body-semibold text-body-small text-on-surface">{{ shell.user.name }}</div>
                        <div class="truncate font-caption text-caption text-on-surface-variant">{{ shell.user.login }}</div>
                    </div>
                    <Link :href="shell.profile_url" class="flex items-center gap-sm px-md py-sm font-body-medium text-body-medium text-on-surface hover:bg-surface-container-low">
                        <span class="material-symbols-outlined text-[20px] text-on-surface-variant" aria-hidden="true">account_circle</span> Hồ sơ cá nhân
                    </Link>
                    <Link :href="shell.logout_url" method="post" as="button" class="flex w-full items-center gap-sm px-md py-sm font-body-medium text-body-medium text-error hover:bg-error-container/40">
                        <span class="material-symbols-outlined text-[20px]" aria-hidden="true">logout</span> Đăng xuất
                    </Link>
                </template>
            </UiDropdown>
        </div>
    </header>
</template>
