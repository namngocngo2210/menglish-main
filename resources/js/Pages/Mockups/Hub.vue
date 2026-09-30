<script setup>
/**
 * MEnglish UI Mockup Navigator: toàn bộ màn mockup theo phân hệ — tìm nhanh, lọc theo nhóm, mở màn Laravel thật
 * hoặc xem trước bản HTML gốc trong khung iframe.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';

defineOptions({ layout: { title: 'MEnglish UI Mockup Navigator & Design Hub' } });

defineProps({ modules: { type: Array, default: () => [] } });

const search = ref('');
const activeTab = ref('all');
const preview = ref({ open: false, src: '', title: '', url: '' });

// Nút lọc nhanh: [khoá, nhãn, class khi chọn]
const tabs = [
    ['all', 'Tất cả (71)', 'bg-navy text-white shadow-sm'],
    ['classes', 'Tuyển sinh & Lớp (6)', 'bg-indigo-600 text-white shadow-sm'],
    ['tasks', 'Phân công & TA (9)', 'bg-orange-600 text-white shadow-sm'],
    ['crm', 'CRM (10)', 'bg-primary-container text-white shadow-sm'],
    ['tuition', 'Học phí (8)', 'bg-amber-600 text-white shadow-sm'],
    ['payroll', 'Lương & CC (12)', 'bg-cyan-600 text-white shadow-sm'],
    ['syllabus', 'Syllabus (8)', 'bg-purple-600 text-white shadow-sm'],
    ['tests', 'Đề Test (5)', 'bg-teal-600 text-white shadow-sm'],
];
const typeClasses = {
    kanban: 'bg-emerald-50 text-emerald-700',
    table: 'bg-blue-50 text-blue-700',
    form: 'bg-amber-50 text-amber-700',
    wizard: 'bg-cyan-50 text-cyan-700',
    report: 'bg-purple-50 text-purple-700',
    detail: 'bg-indigo-50 text-indigo-700',
    modal: 'bg-rose-50 text-rose-700',
};

const moduleVisible = (id) => activeTab.value === 'all' || activeTab.value === id || (activeTab.value === 'tests' && ['placement-tests', 'levels'].includes(id));
const screenVisible = (screen) => !search.value || `${screen.name} ${screen.type}`.toLowerCase().includes(search.value.toLowerCase());

function openPreview(screen) {
    preview.value = { open: true, src: screen.preview, title: screen.name, url: screen.url };
}
function onKey(event) {
    if (event.key === 'Escape') preview.value.open = false;
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <UiPageHeader title="MEnglish UI Mockup Navigator & Design Hub" icon="auto_stories" description="Tổng hợp toàn bộ 65 màn hình mockup giao diện và tính năng Laravel tương ứng">
        <template #badges>
            <UiBadge color="success" pill>100% Laravel Integrated</UiBadge>
        </template>
        <template #actions>
            <UiButton icon="dashboard_customize" :href="route('academic-system.index')">Gallery 58 Màn Mới</UiButton>
        </template>
    </UiPageHeader>

    <div class="space-y-6">
        <div class="flex flex-col items-center justify-between gap-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm md:flex-row">
            <div class="relative w-full md:w-96">
                <UiInput v-model="search" icon="search" placeholder="Tìm nhanh trong 65 màn hình (vd: pipeline, giao việc, trợ giảng...)" aria-label="Tìm nhanh màn hình" />
            </div>

            <div class="flex w-full items-center gap-1.5 overflow-x-auto pb-1 md:w-auto md:pb-0">
                <button v-for="[key, label, activeClass] in tabs" :key="key" type="button" :class="['whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-semibold transition', activeTab === key ? activeClass : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high']" @click="activeTab = key">{{ label }}</button>
            </div>
        </div>

        <div class="space-y-8">
            <div v-for="module in modules" v-show="moduleVisible(module.id)" :key="module.id" class="space-y-3">
                <div class="flex items-center justify-between border-b border-surface-container-highest pb-2">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-xl text-primary">{{ module.icon }}</span>
                        <h2 class="text-base font-bold text-on-surface">{{ module.name }}</h2>
                    </div>
                    <span class="rounded-full bg-surface-container px-2.5 py-0.5 text-xs font-semibold text-on-surface-variant">{{ module.badge }}</span>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                    <div v-for="screen in module.screens" v-show="screenVisible(screen)" :key="screen.num + screen.url" class="group flex flex-col justify-between rounded-xl border border-surface-container-highest bg-surface-container-lowest p-4 transition-all hover:border-primary-container/50 hover:shadow-md">
                        <div>
                            <div class="mb-2 flex items-center justify-between">
                                <span class="rounded border border-primary-container/30 bg-primary-container/10 px-2 py-0.5 font-mono text-xs font-bold text-primary">#{{ screen.num }}</span>
                                <span :class="['rounded px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider', typeClasses[screen.type] ?? 'bg-surface-container text-on-surface-variant']">{{ screen.type }}</span>
                            </div>

                            <h3 class="mb-1 line-clamp-1 text-sm font-bold text-on-surface transition group-hover:text-primary">{{ screen.name }}</h3>
                            <p class="mb-4 truncate font-mono text-[11px] text-on-surface-variant/70">{{ screen.src }}</p>
                        </div>

                        <div class="flex items-center gap-2 border-t border-surface-container-highest pt-3">
                            <UiButton size="sm" icon="play_circle" class="flex-1" :href="screen.url">Mở màn hình</UiButton>
                            <UiButton variant="ghost" size="sm" icon="preview" title="Xem Preview HTML gốc" aria-label="Xem Preview HTML gốc" @click="openPreview(screen)" />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Xem trước bản HTML gốc -->
        <div v-show="preview.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm sm:p-6" @click.self="preview.open = false">
            <div class="flex h-[90vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-2xl">
                <div class="flex h-14 shrink-0 items-center justify-between bg-navy px-6 text-white">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-xl text-primary">devices</span>
                        <div>
                            <h3 class="text-sm font-bold text-white">{{ preview.title }}</h3>
                            <span class="font-mono text-[10px] text-white/50">{{ preview.src }}</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <a :href="preview.url" class="inline-flex items-center gap-1.5 rounded-lg bg-primary-container px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-primary-hover">
                            <span class="material-symbols-outlined text-[15px]">open_in_new</span>
                            <span>Mở Live Controller</span>
                        </a>
                        <button type="button" class="rounded-lg p-1.5 text-white/60 transition hover:bg-surface-container-lowest/10 hover:text-white" aria-label="Đóng" @click="preview.open = false">
                            <span class="material-symbols-outlined text-xl">close</span>
                        </button>
                    </div>
                </div>

                <div class="flex-1 overflow-hidden bg-surface-container p-2">
                    <iframe v-if="preview.src" :src="preview.src" class="h-full w-full rounded-xl border border-surface-container-highest bg-surface-container-lowest shadow-inner" frameborder="0"></iframe>
                </div>
            </div>
        </div>
    </div>
</template>
