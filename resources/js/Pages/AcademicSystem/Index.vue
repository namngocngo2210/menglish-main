<script setup>
/**
 * Gallery 58 màn hình hệ thống MENGLISH (bản mockup, chỉ Admin): lọc theo nhóm, tìm kiếm, xem nhanh trong khung (iframe).
 * Trang đứng riêng, không dùng khung ứng dụng.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import BareLayout from '@/Layouts/BareLayout.vue';

defineOptions({ layout: BareLayout });

const props = defineProps({
    screens: { type: Array, default: () => [] },
    selectedCat: { type: String, default: 'all' },
    nativeMap: { type: Object, default: () => ({}) },
});

const search = ref('');
const currentCat = ref(props.selectedCat || 'all');
const modalOpen = ref(false);
const activeScreen = ref(null);

const countIn = (id) => props.screens.filter((s) => s.category_id === id).length;
const categories = computed(() => [
    { id: 'all', name: 'Tất cả', icon: 'apps', count: props.screens.length },
    { id: '01_Web_Admin', name: 'Web Admin', icon: 'admin_panel_settings', count: countIn('01_Web_Admin') },
    { id: '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu', name: 'Học thuật & Học vụ & KPI', icon: 'monitoring', count: countIn('02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu') },
    { id: '03_Cong_Giao_Vien', name: 'Cổng Giáo Viên', icon: 'co_present', count: countIn('03_Cong_Giao_Vien') },
    { id: '04_Cong_Phu_Huynh_Hoc_Sinh', name: 'Cổng Phụ Huynh', icon: 'family_restroom', count: countIn('04_Cong_Phu_Huynh_Hoc_Sinh') },
]);
const filteredScreens = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.screens.filter((s) => {
        const matchCat = currentCat.value === 'all' || s.category_id === currentCat.value;
        const matchText = !q || s.title_vn.toLowerCase().includes(q) || s.desc.toLowerCase().includes(q) || s.folder_name.toLowerCase().includes(q);
        return matchCat && matchText;
    });
});
const native = (s) => props.nativeMap[`${s.category_id}/${s.folder_name}`];
const screenUrl = (s) => `/academic-system/${s?.category_id}/${s?.folder_name}`;

function openModal(screen) {
    activeScreen.value = screen;
    modalOpen.value = true;
}
function onKeydown(event) {
    if (event.key === 'Escape') modalOpen.value = false;
}
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Head title="Hệ Thống 58 Màn Hình Giao Diện UI/UX" />
    <div class="flex min-h-screen flex-col bg-surface-container-low">
        <header class="sticky top-0 z-30 border-b border-surface-container-highest bg-white shadow-sm">
            <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div class="flex items-center space-x-3">
                        <a :href="route('dashboard')" class="flex h-10 w-10 items-center justify-center rounded-xl bg-warning text-xl font-bold text-white shadow-md shadow-warning-container transition hover:bg-warning">M</a>
                        <div>
                            <h1 class="flex items-center gap-2 text-xl font-bold tracking-tight text-on-surface">
                                MENGLISH System Screens
                                <span class="rounded-full border border-warning/30 bg-warning-container px-2.5 py-0.5 text-xs font-semibold text-warning">58 Giao diện Mới</span>
                            </h1>
                            <p class="text-xs text-on-surface-subtle">Hệ thống Quản trị Đào tạo, Học vụ KPI, Cổng Giáo viên &amp; Phụ huynh/Học sinh</p>
                        </div>
                    </div>

                    <div class="flex w-full items-center gap-3 md:w-auto">
                        <a :href="route('dashboard')" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-surface-container px-3 py-2 text-xs font-semibold text-on-surface-variant transition hover:bg-surface-container-high">
                            <span class="material-symbols-outlined text-sm">arrow_back</span>
                            <span>Về Admin</span>
                        </a>
                        <a :href="route('mockup-hub.index')" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-info-container px-3 py-2 text-xs font-semibold text-info transition hover:bg-info-container">
                            <span class="material-symbols-outlined text-sm">hub</span>
                            <span>Mockup Hub</span>
                        </a>
                        <div class="relative flex-1 md:w-72">
                            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-sm text-on-surface-subtle">search</span>
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Tìm kiếm màn hình..."
                                aria-label="Tìm kiếm màn hình"
                                class="w-full rounded-lg border border-surface-container-highest bg-surface-container py-2 pl-9 pr-4 text-sm text-on-surface placeholder-on-surface-subtle transition-all focus:bg-white focus:outline-none focus:ring-2 focus:ring-warning"
                            />
                        </div>
                    </div>
                </div>

                <div class="no-scrollbar mt-4 flex items-center gap-2 overflow-x-auto border-t border-surface-container-highest pb-1 pt-3">
                    <button
                        v-for="cat in categories"
                        :key="cat.id"
                        type="button"
                        :class="['flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3.5 py-1.5 text-xs transition-all', currentCat === cat.id ? 'bg-warning font-semibold text-white shadow-sm' : 'bg-surface-container font-medium text-on-surface-variant hover:bg-surface-container-high']"
                        @click="currentCat = cat.id"
                    >
                        <span class="material-symbols-outlined text-sm">{{ cat.icon }}</span>
                        <span>{{ cat.name }}</span>
                        <span :class="['rounded-full px-1.5 text-xs', currentCat === cat.id ? 'bg-white/20 text-white' : 'bg-surface-container-high text-on-surface-variant']">{{ cat.count }}</span>
                    </button>
                </div>
            </div>
        </header>

        <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-8 sm:px-6 lg:px-8">
            <div class="mb-4 flex items-center justify-between text-xs text-on-surface-subtle">
                <div>
                    Hiển thị <span class="font-bold text-on-surface">{{ filteredScreens.length }}</span> / 58 màn hình
                </div>
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-warning/30 bg-warning-container px-2.5 py-1 text-xs font-semibold text-on-warning-container">
                        <span class="h-1.5 w-1.5 rounded-full bg-warning"></span>
                        290 Dữ liệu mẫu SEED (5 bản ghi / màn)
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-tertiary/30 bg-tertiary/10 px-2.5 py-1 text-xs font-semibold text-tertiary">
                        <span class="h-1.5 w-1.5 rounded-full bg-tertiary"></span>
                        100% UI nguyên bản theo thiết kế
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                <div v-for="s in filteredScreens" :key="s.folder_name" class="group flex flex-col overflow-hidden rounded-xl border border-surface-container-highest/80 bg-white shadow-sm transition-all hover:border-warning/30 hover:shadow-md">
                    <div class="relative flex h-44 cursor-pointer items-center justify-center overflow-hidden border-b border-surface-container-highest bg-surface-container" @click="openModal(s)" role="button" tabindex="0" @keydown.enter.self.prevent="openModal(s)" @keydown.space.self.prevent="openModal(s)">
                        <img v-if="s.has_png" :src="`/roundcuoi-kieulien/${s.category_id}/${s.folder_name}/screen.png`" :alt="s.title_vn" loading="lazy" class="h-full w-full object-cover object-top transition-transform duration-300 group-hover:scale-105" />
                        <div v-else class="p-4 text-center">
                            <span class="material-symbols-outlined text-4xl text-on-surface-subtle">web</span>
                            <p class="mt-1 text-xs font-medium text-on-surface-subtle">Giao diện HTML</p>
                        </div>
                        <div class="absolute left-2 top-2 rounded-md bg-inverse-surface/80 px-2 py-0.5 font-mono text-xs font-bold text-white backdrop-blur-sm">#{{ s.num }}</div>
                        <div class="absolute inset-0 flex items-center justify-center gap-2 bg-inverse-surface/40 opacity-0 transition-opacity group-hover:opacity-100">
                            <span class="flex items-center gap-1 rounded-lg bg-white/95 px-3 py-1.5 text-xs font-semibold text-on-surface shadow-md">
                                <span class="material-symbols-outlined text-sm">visibility</span> Xem nhanh
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-1 flex-col justify-between p-4">
                        <div>
                            <div class="mb-1 flex items-center justify-between gap-1">
                                <div class="flex items-center gap-1 text-xs font-semibold uppercase tracking-wider text-warning">
                                    <span class="material-symbols-outlined text-xs">{{ s.icon }}</span>
                                    <span>{{ s.category_name.split('(')[0] }}</span>
                                </div>
                                <span v-if="native(s)" class="flex items-center gap-0.5 rounded border border-tertiary/30 bg-tertiary/10 px-1.5 text-xs font-bold text-on-tertiary-container">
                                    <span class="h-1.5 w-1.5 rounded-full bg-tertiary"></span>Native
                                </span>
                            </div>
                            <h3 class="mb-1 line-clamp-2 text-sm font-bold text-on-surface transition-colors group-hover:text-warning">{{ s.title_vn }}</h3>
                            <p class="mb-3 line-clamp-2 text-xs text-on-surface-subtle">{{ s.desc }}</p>
                        </div>

                        <div class="flex items-center justify-between gap-2 border-t border-surface-container-highest pt-2">
                            <a v-if="native(s)" :href="native(s)" target="_blank" rel="noopener noreferrer" class="inline-flex flex-1 items-center justify-center gap-1 rounded-lg bg-tertiary px-3 py-1.5 text-xs font-bold text-white shadow-sm transition-colors hover:bg-tertiary">
                                <span class="material-symbols-outlined text-sm">rocket_launch</span> Mở Native
                            </a>
                            <a v-else :href="screenUrl(s)" target="_blank" rel="noopener noreferrer" class="inline-flex flex-1 items-center justify-center gap-1 rounded-lg bg-warning-container px-3 py-1.5 text-xs font-semibold text-warning shadow-sm transition-colors hover:bg-warning hover:text-white">
                                <span class="material-symbols-outlined text-sm">open_in_new</span> Mở màn hình
                            </a>
                            <button type="button" title="Xem preview" class="rounded-lg border border-surface-container-highest p-1.5 text-on-surface-subtle transition-colors hover:bg-warning-container hover:text-warning" @click="openModal(s)">
                                <span class="material-symbols-outlined text-sm">preview</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div v-show="filteredScreens.length === 0" class="py-16 text-center text-on-surface-subtle">
                <span class="material-symbols-outlined mb-2 text-5xl text-outline">search_off</span>
                <p class="text-base font-semibold text-on-surface-variant">Không tìm thấy màn hình phù hợp</p>
                <p class="mt-1 text-xs text-on-surface-subtle">Thử tìm kiếm với từ khóa khác hoặc chuyển danh mục.</p>
            </div>
        </main>

        <!-- Xem nhanh toàn màn (iframe) -->
        <div v-if="modalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/75 p-3 backdrop-blur-sm sm:p-6" @click.self="modalOpen = false">
            <div class="flex h-[92vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl border border-surface-container-highest bg-white shadow-2xl" role="dialog" aria-modal="true">
                <div class="flex h-14 shrink-0 items-center justify-between bg-inverse-surface px-5 text-white">
                    <div class="flex items-center gap-3">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-warning text-sm font-bold text-white">M</div>
                        <div>
                            <h3 class="flex items-center gap-2 text-sm font-bold text-white">
                                <span>{{ activeScreen?.title_vn }}</span>
                                <span class="rounded bg-white/20 px-2 py-0.5 text-xs text-white">#{{ activeScreen?.num }}</span>
                            </h3>
                            <p class="font-mono text-xs text-inverse-on-surface/70">{{ activeScreen?.category_id }}/{{ activeScreen?.folder_name }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <a :href="screenUrl(activeScreen)" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 rounded-lg bg-warning px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-warning">
                            <span class="material-symbols-outlined text-sm">open_in_new</span>
                            <span>Mở toàn màn hình</span>
                        </a>
                        <button type="button" aria-label="Đóng" class="rounded-lg p-1.5 text-inverse-on-surface/70 transition hover:bg-white/10 hover:text-white" @click="modalOpen = false">
                            <span class="material-symbols-outlined text-xl">close</span>
                        </button>
                    </div>
                </div>
                <div class="flex-1 overflow-hidden bg-surface-container p-2">
                    <iframe :src="screenUrl(activeScreen)" class="h-full w-full rounded-xl border border-outline-variant bg-white shadow-inner" :title="activeScreen?.title_vn"></iframe>
                </div>
            </div>
        </div>

        <footer class="flex flex-wrap items-center justify-center gap-2 border-t border-surface-container-highest bg-white px-4 py-4 text-center text-xs text-on-surface-subtle">
            <span>MENGLISH Education System &bull; 2026</span>
            <span class="text-outline">&bull;</span>
            <span>Phát triển bởi <a href="https://vmst.vn" target="_blank" rel="noopener noreferrer" class="font-bold text-[#ea580c] hover:underline">VMST Media</a></span>
        </footer>
    </div>
</template>
