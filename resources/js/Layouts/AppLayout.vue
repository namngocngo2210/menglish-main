<script setup>
/**
 * Layout ứng dụng (thay layouts/app.blade.php) — layout mặc định của mọi trang (app.js), giữ nguyên khi chuyển trang.
 * Nhận toàn bộ props của trang (shell, flash, errors…) + props layout do trang đặt:
 *   defineOptions({ layout: { title: 'Cấu hình ngày nghỉ' } })            // tiêu đề topbar khi render phía server
 *   defineOptions({ layout: { workspaceTabs: false } })                    // trang tự đặt <WorkspaceTabs> chỗ khác
 *   defineOptions({ layout: { hideErrors: true } })                        // trang tự hiện danh sách lỗi
 *   defineOptions({ layout: (props) => ({ title: props.customer.name }) }) // tiêu đề theo dữ liệu
 * Tiêu đề topbar: <UiPageHeader> của trang → `title` → tab / workspace đang mở (server) → "MEnglish".
 */
import { computed, provide, reactive, ref } from 'vue';
import { PAGE_META } from '@/Components/ui/pageMeta';
import { remoteModal } from '@/lib/remoteModal';
import Sidebar from './Shell/Sidebar.vue';
import Topbar from './Shell/Topbar.vue';
import WorkspaceTabs from './Shell/WorkspaceTabs.vue';
import SettingsNav from './Shell/SettingsNav.vue';
import ToastHost from './Shell/ToastHost.vue';
import ConfirmDialogHost from './Shell/ConfirmDialogHost.vue';
import RemoteModalHost from './Shell/RemoteModalHost.vue';

const props = defineProps({
    shell: { type: Object, default: null },
    flash: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    title: { type: String, default: null },
    workspaceTabs: { type: Boolean, default: true },
    hideErrors: { type: Boolean, default: false },
});

const meta = reactive({ title: null });
provide(PAGE_META, meta);
const sidebarOpen = ref(false);

const topbarTitle = computed(() => meta.title || props.title || props.shell?.title);
const errorMessages = computed(() =>
    Object.values(props.errors ?? {}).flatMap((value) => (value && typeof value === 'object' ? Object.values(value) : [value])).filter(Boolean),
);
const showErrors = computed(() => errorMessages.value.length > 0 && !props.hideErrors && !remoteModal.open);
const settingsSections = computed(() => props.shell?.settings ?? []);
</script>

<template>
    <a href="#main-content" class="skip-link">Bỏ qua menu, tới nội dung chính</a>
    <div v-if="shell" class="flex min-h-screen flex-col" @keydown.esc="sidebarOpen = false">
        <Sidebar :shell="shell" :open="sidebarOpen" @close="sidebarOpen = false" />

        <div class="flex min-w-0 flex-1 flex-col transition-[padding] duration-200 md:pl-sidebar-collapsed desktop:pl-sidebar-width" data-sidebar-shell>
            <Topbar :shell="shell" :title="topbarTitle" @open-menu="sidebarOpen = true" />

            <main id="main-content" tabindex="-1" class="flex-1 p-md focus:outline-none lg:p-lg">
                <WorkspaceTabs v-if="workspaceTabs" />

                <UiAlert v-if="showErrors" type="error" title="Vui lòng kiểm tra lại thông tin" class="mb-lg" dismissible data-global-errors>
                    <ul class="list-inside list-disc space-y-0.5">
                        <li v-for="(message, i) in errorMessages" :key="i">{{ message }}</li>
                    </ul>
                </UiAlert>

                <div v-if="settingsSections.length" class="flex flex-col gap-lg lg:flex-row lg:items-start">
                    <SettingsNav :sections="settingsSections" />
                    <div class="min-w-0 flex-1"><slot /></div>
                </div>
                <slot v-else />
            </main>

            <footer class="mt-auto flex select-none flex-col items-center justify-center gap-xs border-t border-surface-container-highest bg-surface px-md py-md font-caption text-caption text-on-surface-variant sm:flex-row lg:px-lg">
                <div class="flex items-center gap-xs">
                    <span class="font-semibold text-on-surface">MENGLISH</span>
                    <span aria-hidden="true">&bull;</span>
                    <span>Hệ thống quản trị giáo dục &amp; học vụ</span>
                </div>
            </footer>
        </div>
    </div>
    <!-- Chưa đăng nhập (vd. trang lỗi): chỉ nội dung -->
    <main v-else id="main-content" class="min-h-screen p-md lg:p-lg"><slot /></main>

    <ToastHost :flash="flash" />
    <ConfirmDialogHost />
    <RemoteModalHost />
</template>
