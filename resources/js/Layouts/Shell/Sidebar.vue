<script setup>
/**
 * Sidebar ứng dụng (như layouts/navigation.blade.php). Menu + quyền: App\Support\Navigation\AppShell (prop `shell.sidebar`).
 * Responsive: ≥1200px đầy đủ (nút thu gọn → chỉ icon, lưu localStorage, class `sidebar-collapsed` trên <html>) ·
 * 768–1199px luôn dạng icon · <768px drawer (`open`). Dạng icon: rê chuột / focus vào mục hiện tooltip tên mục.
 */
import { nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SidebarLink from './SidebarLink.vue';

const props = defineProps({
    shell: { type: Object, required: true },
    open: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const root = ref(null);
const nav = ref(null);
const collapsed = ref(false);
const moreBelow = ref(false);
const tip = reactive({ show: false, text: '', top: 0, left: 0 });

const isNewSection = (index) => index === 0 || props.shell.sidebar.groups[index].section !== props.shell.sidebar.groups[index - 1].section;

function checkMore() {
    const el = nav.value;
    moreBelow.value = !!el && el.scrollTop + el.clientHeight < el.scrollHeight - 8;
}
let saveTimer = null;
function onScroll() {
    tip.show = false;
    checkMore();
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
        try {
            sessionStorage.setItem('sidebar_scroll_top', String(nav.value?.scrollTop ?? 0));
        } catch {}
    }, 100);
}
function toggleCollapsed() {
    collapsed.value = document.documentElement.classList.toggle('sidebar-collapsed');
    tip.show = false;
    try {
        localStorage.setItem('sidebar_collapsed', collapsed.value ? '1' : '0');
    } catch {}
}
function showTip(event) {
    const item = event.target.closest?.('[data-tooltip]');
    if (!item || (root.value?.offsetWidth ?? 0) > 100) {
        tip.show = false;
        return;
    }
    const rect = item.getBoundingClientRect();
    Object.assign(tip, { show: true, text: item.dataset.tooltip, top: rect.top + rect.height / 2, left: rect.right + 12 });
}

let removeNavigate = null;
onMounted(async () => {
    collapsed.value = document.documentElement.classList.contains('sidebar-collapsed');
    window.addEventListener('resize', checkMore, { passive: true });
    await nextTick();
    const el = nav.value;
    if (el) {
        let saved = null;
        try {
            saved = sessionStorage.getItem('sidebar_scroll_top');
        } catch {}
        if (saved !== null) el.scrollTop = parseInt(saved, 10);
        else el.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest' });
    }
    checkMore();
    // Chuyển trang trên điện thoại → đóng drawer.
    removeNavigate = router.on('navigate', () => emit('close'));
});
onBeforeUnmount(() => {
    window.removeEventListener('resize', checkMore);
    removeNavigate?.();
});
</script>

<template>
    <aside
        ref="root"
        :class="['fixed left-0 top-0 z-40 flex h-full w-sidebar-width flex-col bg-sidebar text-surface-variant shadow-level-3 transition-[transform,width] duration-200 max-md:-translate-x-full md:w-sidebar-collapsed desktop:w-sidebar-width md:shadow-none', open ? 'max-md:!translate-x-0' : '']"
        aria-label="Menu chính"
        data-sidebar
        @mouseover="showTip"
        @focusin="showTip"
        @mouseleave="tip.show = false"
        @focusout="tip.show = false"
    >
        <button
            type="button"
            data-sidebar-toggle
            :data-tooltip="collapsed ? 'Mở rộng menu' : 'Thu gọn menu'"
            :aria-expanded="(!collapsed).toString()"
            aria-label="Thu gọn / mở rộng menu"
            class="absolute -right-3 top-5 z-50 hidden h-6 w-6 items-center justify-center rounded-full border border-surface-container-highest bg-surface-container-lowest text-on-surface-variant shadow-md transition-colors hover:bg-primary-container hover:text-white desktop:flex"
            @click="toggleCollapsed"
        >
            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ collapsed ? 'chevron_right' : 'chevron_left' }}</span>
        </button>

        <div class="flex h-header-height shrink-0 items-center gap-sm px-md md:justify-center md:px-0 desktop:justify-start desktop:px-md" data-sidebar-center>
            <Link :href="shell.sidebar.dashboard?.url ?? '/'" class="flex min-w-0 items-center gap-sm rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container" title="Về trang tổng quan">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-surface-container-lowest">
                    <img src="/images/menglish-logo.png" alt="MEnglish" class="h-full w-full object-contain" width="40" height="40" />
                </span>
                <span class="flex min-w-0 flex-col md:hidden desktop:flex" data-sidebar-text>
                    <span class="font-h2 text-h2 leading-none tracking-tight text-primary-container">MENGLISH</span>
                    <span class="mt-1 text-xs font-semibold uppercase tracking-widest text-surface-variant/60">{{ shell.user.portal_student ? 'Cổng học viên' : 'Hệ thống quản trị' }}</span>
                </span>
            </Link>
            <button type="button" class="ml-auto inline-flex items-center justify-center rounded-lg p-1 text-surface-variant/70 hover:bg-white/10 hover:text-white max-md:min-h-11 max-md:min-w-11 md:hidden" aria-label="Đóng menu" @click="emit('close')">
                <span class="material-symbols-outlined text-[20px]" aria-hidden="true">close</span>
            </button>
        </div>

        <div class="relative flex min-h-0 flex-1 flex-col">
            <nav ref="nav" :class="['sidebar-scrollbar flex-1 space-y-xs overflow-y-auto px-2 py-sm', moreBelow ? '[mask-image:linear-gradient(to_bottom,#000_calc(100%-2rem),transparent)]' : '']" @scroll.passive="onScroll">
                <SidebarLink v-if="shell.sidebar.dashboard" :url="shell.sidebar.dashboard.url" label="Tổng quan" icon="dashboard" :active="shell.sidebar.dashboard.active" id="dashboard" />

                <template v-for="(group, index) in shell.sidebar.groups" :key="group.id">
                    <template v-if="isNewSection(index)">
                        <div class="px-md pb-1 pt-md font-caption text-xs font-semibold uppercase tracking-widest text-surface-variant/70 md:hidden desktop:block" data-menu-section data-sidebar-text>{{ group.section }}</div>
                        <div class="mx-auto my-sm hidden h-px w-8 bg-white/10 md:block desktop:hidden" aria-hidden="true" data-sidebar-divider></div>
                    </template>
                    <SidebarLink :url="group.url" :label="group.label" :icon="group.icon" :active="group.active" :id="group.id" :badge="group.badge" />
                </template>

                <template v-if="shell.sidebar.settings">
                    <div class="mx-md my-sm h-px bg-white/10" aria-hidden="true"></div>
                    <SidebarLink :url="shell.sidebar.settings.url" label="Cài đặt" icon="settings" :active="shell.sidebar.settings.active" id="settings" />
                </template>
            </nav>
            <div v-show="moreBelow" class="flex shrink-0 justify-center border-t border-white/10 py-xs" data-sidebar-more>
                <button type="button" class="inline-flex items-center justify-center rounded-full p-0.5 text-surface-variant/70 hover:bg-white/10 hover:text-white max-md:min-h-11 max-md:min-w-11" aria-label="Cuộn xuống xem thêm mục menu" @click="nav?.scrollBy({ top: nav.clientHeight * 0.6, behavior: 'smooth' })">
                    <span class="material-symbols-outlined text-[20px]" aria-hidden="true">keyboard_arrow_down</span>
                </button>
            </div>
        </div>

        <div class="shrink-0 space-y-xs border-t border-white/10 px-2 py-sm">
            <div class="flex items-center gap-sm px-md py-xs md:justify-center md:px-0 desktop:justify-start desktop:px-md" data-sidebar-center :data-tooltip="`Vai trò: ${shell.user.role}`">
                <span class="h-2 w-2 shrink-0 rounded-full bg-tertiary-container"></span>
                <span class="truncate font-caption text-caption text-surface-variant/70 md:hidden desktop:inline" data-sidebar-text>Vai trò: <span class="font-semibold text-white">{{ shell.user.role }}</span></span>
            </div>
            <Link
                :href="shell.logout_url"
                method="post"
                as="button"
                data-tooltip="Đăng xuất"
                aria-label="Đăng xuất"
                data-sidebar-center
                class="flex w-full items-center gap-md rounded-lg px-md py-sm font-body-medium text-body-medium text-error-container/80 transition-colors hover:bg-error/20 hover:text-error-container md:justify-center md:px-0 desktop:justify-start desktop:px-md"
            >
                <span class="material-symbols-outlined shrink-0" aria-hidden="true">logout</span>
                <span class="md:hidden desktop:inline" data-sidebar-text>Đăng xuất</span>
            </Link>
        </div>

        <div v-show="tip.show" role="tooltip" class="pointer-events-none fixed z-50 -translate-y-1/2 whitespace-nowrap rounded-md bg-inverse-surface px-2.5 py-1.5 text-xs font-semibold text-white shadow-lg ring-1 ring-white/10" :style="{ top: `${tip.top}px`, left: `${tip.left}px` }">{{ tip.text }}</div>
    </aside>
    <Transition enter-active-class="transition-opacity" enter-from-class="opacity-0" leave-active-class="transition-opacity" leave-to-class="opacity-0">
        <div v-show="open" class="fixed inset-0 z-30 bg-black/50 backdrop-blur-xs md:hidden" @click="emit('close')"></div>
    </Transition>
</template>
