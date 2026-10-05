<script setup>
/**
 * Khung giao diện điện thoại cho nhân sự (/m): thanh tiêu đề trên + thanh điều hướng dưới (Chấm công, Lịch sử,
 * Xin duyệt, Cần duyệt, Cá nhân). Trang dùng: defineOptions({ layout: MobileLayout }); số đếm trên thanh dưới lấy từ
 * prop `mobileNav` (MobileStaffController::nav). Trên máy tính khung vẫn gọn giữa màn hình.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { route } from '@/lib/route';
import ToastHost from './Shell/ToastHost.vue';
import ConfirmDialogHost from './Shell/ConfirmDialogHost.vue';
import RemoteModalHost from './Shell/RemoteModalHost.vue';

const props = defineProps({
    flash: { type: Array, default: () => [] },
    mobileNav: { type: Object, default: () => ({}) },
});

const page = usePage();
const TITLES = {
    'Mobile/CheckIn': 'Chấm công',
    'Mobile/History': 'Lịch sử công',
    'Mobile/Requests': 'Xin duyệt',
    'Mobile/Approvals': 'Cần duyệt',
};
const title = computed(() => TITLES[page.component] ?? 'MEnglish');
const tabs = computed(() =>
    [
        { label: 'Chấm công', icon: 'fingerprint', href: route('mobile.home'), component: 'Mobile/CheckIn' },
        { label: 'Lịch sử', icon: 'calendar_month', href: route('mobile.history'), component: 'Mobile/History' },
        { label: 'Xin duyệt', icon: 'outgoing_mail', href: route('mobile.requests'), component: 'Mobile/Requests', badge: props.mobileNav.myPending },
        props.mobileNav.approvals !== null && props.mobileNav.approvals !== undefined
            ? { label: 'Cần duyệt', icon: 'fact_check', href: route('mobile.approvals'), component: 'Mobile/Approvals', badge: props.mobileNav.approvals, alert: true }
            : null,
        { label: 'Bản đầy đủ', icon: 'desktop_windows', href: route('dashboard'), component: null },
    ].filter(Boolean),
);
</script>

<template>
    <div class="min-h-screen bg-surface-container-low">
        <header class="sticky top-0 z-30 border-b border-outline-variant bg-surface-container-lowest/95 pt-[env(safe-area-inset-top)] backdrop-blur">
            <div class="mx-auto flex h-14 max-w-md items-center gap-sm px-md">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-container font-body-semibold text-body-small text-white" aria-hidden="true">M</span>
                <h1 class="min-w-0 flex-1 truncate font-h3 text-h3 text-on-surface">{{ title }}</h1>
                <Link :href="route('notifications.index')" class="flex h-11 w-11 items-center justify-center rounded-full text-on-surface-variant hover:bg-surface-container-high" aria-label="Thông báo">
                    <span class="material-symbols-outlined" aria-hidden="true">notifications</span>
                </Link>
            </div>
        </header>

        <!-- Khung hẹp nằm trong <main> vì app.css ép main { max-width: 100% } (mở /m trên máy tính vẫn gọn giữa màn hình). -->
        <main id="main-content" class="pb-[calc(5.5rem+env(safe-area-inset-bottom))] pt-md">
            <div class="mx-auto max-w-md px-md">
                <slot />
            </div>
        </main>

        <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-outline-variant bg-surface-container-lowest pb-[env(safe-area-inset-bottom)]" aria-label="Điều hướng nhân sự">
            <ul class="mx-auto flex max-w-md">
                <li v-for="tab in tabs" :key="tab.label" class="flex-1">
                    <Link
                        :href="tab.href"
                        class="relative flex min-h-14 flex-col items-center justify-center gap-0.5 px-xs py-xs font-caption text-caption transition-colors"
                        :class="tab.component === page.component ? 'text-primary' : 'text-on-surface-variant hover:text-on-surface'"
                        :aria-current="tab.component === page.component ? 'page' : null"
                    >
                        <span class="material-symbols-outlined text-[24px]" :class="tab.component === page.component ? 'fill' : ''" aria-hidden="true">{{ tab.icon }}</span>
                        <span class="whitespace-nowrap">{{ tab.label }}</span>
                        <span
                            v-if="tab.badge"
                            class="absolute right-1/2 top-1 translate-x-5 rounded-full px-1.5 font-caption text-[11px] leading-4 text-white"
                            :class="tab.alert ? 'bg-error' : 'bg-primary-container'"
                            :aria-label="`${tab.badge} mục`"
                        >{{ tab.badge > 99 ? '99+' : tab.badge }}</span>
                    </Link>
                </li>
            </ul>
        </nav>
    </div>

    <ToastHost :flash="flash" />
    <ConfirmDialogHost />
    <RemoteModalHost />
</template>
