<script setup>
/**
 * Hướng dẫn sử dụng theo vai trò (UserGuideController, nội dung ở resources/guides/<id>.php). Chọn vai trò ở thanh trên
 * (vai trò của mình xếp đầu), mỗi chức năng là một thẻ: ở đâu trong menu, ảnh chụp (bấm để phóng to), các bước, mẹo.
 * Trong nội dung, **chữ đậm** = tên nút / mục cần bấm.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

defineOptions({ layout: { title: 'Hướng dẫn sử dụng', workspaceTabs: false } });

const props = defineProps({
    guides: { type: Array, required: true },
    selected: { type: String, required: true },
});

const current = ref(props.selected);
const query = ref('');
const zoomed = ref(null);

const guide = computed(() => props.guides.find((g) => g.id === current.value) ?? props.guides[0]);

const normalize = (text) => (text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLowerCase();
const sections = computed(() => {
    const needle = normalize(query.value.trim());
    if (!needle) return guide.value.sections;
    return guide.value.sections.filter((s) => normalize([s.title, s.intro, s.menu, ...(s.steps ?? []), ...(s.tips ?? [])].join(' ')).includes(needle));
});

/** "Bấm **Lưu** để ..." → [{ text: 'Bấm ' }, { text: 'Lưu', bold: true }, { text: ' để ...' }] */
const parts = (text) => (text ?? '').split('**').map((t, i) => ({ text: t, bold: i % 2 === 1 })).filter((p) => p.text);

function choose(id) {
    current.value = id;
    query.value = '';
    const url = new URL(window.location.href);
    url.searchParams.set('vai-tro', id);
    url.hash = '';
    window.history.replaceState(window.history.state, '', url);
    window.scrollTo({ top: 0 });
}

function onKey(event) {
    if (event.key === 'Escape') zoomed.value = null;
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
watch(zoomed, (img) => document.body.classList.toggle('overflow-hidden', !!img));
</script>

<template>
    <UiPageHeader title="Hướng dẫn sử dụng" icon="help" description="Chọn vai trò để xem từng chức năng: vào ở đâu, màn hình trông thế nào và các bước thực hiện." />

    <nav class="-mx-md mb-lg overflow-x-auto px-md lg:mx-0 lg:px-0" aria-label="Chọn vai trò">
        <div class="flex w-max gap-sm lg:w-auto lg:flex-wrap">
            <button
                v-for="g in guides"
                :key="g.id"
                type="button"
                :aria-pressed="g.id === guide.id"
                :class="[
                    'flex items-center gap-xs whitespace-nowrap rounded-full border px-md py-xs font-body-medium text-body-medium transition',
                    g.id === guide.id ? 'border-primary-container bg-primary-container text-white shadow-sm' : 'border-surface-container-highest bg-surface-container-lowest text-on-surface hover:border-primary-container/40 hover:bg-primary-container/5',
                ]"
                :data-guide-tab="g.id"
                @click="choose(g.id)"
            >
                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ g.icon }}</span>
                {{ g.label }}
                <span v-if="g.own" :class="['rounded-full px-1.5 text-caption font-bold', g.id === guide.id ? 'bg-white/25 text-white' : 'bg-primary-container/10 text-primary']">Của bạn</span>
            </button>
        </div>
    </nav>

    <section class="mb-lg rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm lg:p-lg" data-guide-summary>
        <div class="flex flex-col gap-md md:flex-row md:items-center md:justify-between">
            <div class="flex items-start gap-md">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-primary-container/10 text-primary">
                    <span class="material-symbols-outlined text-[28px]" aria-hidden="true">{{ guide.icon }}</span>
                </span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-sm">
                        <h2 class="font-h2 text-h2 text-on-surface">{{ guide.common ? guide.label : 'Hướng dẫn cho ' + guide.label }}</h2>
                        <UiBadge v-if="guide.own" color="primary" pill>Vai trò của bạn</UiBadge>
                    </div>
                    <p class="mt-xs max-w-3xl text-body-medium text-on-surface-variant">{{ guide.summary }}</p>
                </div>
            </div>
            <div class="w-full md:w-72">
                <UiInput v-model="query" type="search" icon="search" placeholder="Tìm chức năng, nút bấm…" aria-label="Tìm trong hướng dẫn" />
            </div>
        </div>
    </section>

    <div class="flex flex-col gap-lg lg:flex-row lg:items-start">
        <aside class="hidden w-64 shrink-0 lg:sticky lg:top-20 lg:block" aria-label="Mục lục">
            <div class="rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-sm shadow-sm">
                <div class="px-sm py-xs font-caption text-caption font-semibold uppercase tracking-wide text-on-surface-variant">Mục lục</div>
                <a
                    v-for="(s, i) in sections"
                    :key="s.id"
                    :href="'#' + s.id"
                    class="flex items-start gap-sm rounded-xl px-sm py-1.5 text-body-small text-on-surface hover:bg-surface-container-low"
                >
                    <span class="mt-px flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-surface-container text-caption font-bold text-on-surface-variant">{{ guide.sections.indexOf(s) + 1 }}</span>
                    <span>{{ s.title }}</span>
                </a>
            </div>
        </aside>

        <div class="min-w-0 flex-1 space-y-lg">
            <UiEmptyState v-if="!sections.length" icon="search_off" title="Không tìm thấy chức năng phù hợp" description="Thử từ khóa khác, hoặc chọn vai trò khác ở thanh phía trên." />

            <article
                v-for="s in sections"
                :id="s.id"
                :key="guide.id + s.id"
                class="scroll-mt-24 overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-sm"
                data-guide-section
            >
                <header class="flex flex-col gap-sm border-b border-surface-container p-md md:flex-row md:items-start md:justify-between lg:px-lg">
                    <div class="flex min-w-0 items-start gap-sm">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary-container/10 text-primary">
                            <span class="material-symbols-outlined text-[20px]" aria-hidden="true">{{ s.icon ?? 'check_circle' }}</span>
                        </span>
                        <div class="min-w-0">
                            <h3 class="font-h3-card text-h3-card text-on-surface">
                                <span class="text-on-surface-variant">{{ guide.sections.indexOf(s) + 1 }}.</span> {{ s.title }}
                            </h3>
                            <p v-if="s.menu" class="mt-0.5 text-body-small text-on-surface-variant">
                                <span class="material-symbols-outlined mr-0.5 align-[-3px] text-[16px]" aria-hidden="true">near_me</span>Ở đâu: <span class="font-semibold text-on-surface">{{ s.menu }}</span>
                            </p>
                        </div>
                    </div>
                    <UiButton v-if="s.url" :href="s.url" variant="secondary" size="sm" icon="open_in_new" class="shrink-0">Mở màn hình</UiButton>
                </header>

                <div class="space-y-md p-md lg:px-lg">
                    <p v-if="s.intro" class="text-body-medium text-on-surface">
                        <template v-for="(p, k) in parts(s.intro)" :key="k"><strong v-if="p.bold">{{ p.text }}</strong><template v-else>{{ p.text }}</template></template>
                    </p>

                    <div v-if="s.images?.length" :class="['grid gap-md', s.images.length > 1 && s.images.every((img) => img.mobile) ? 'grid-cols-2 sm:grid-cols-3' : 'max-w-5xl']">
                        <figure v-for="img in s.images" :key="img.src" :class="img.mobile ? 'mx-auto w-full max-w-[280px]' : ''">
                            <button type="button" class="group block w-full overflow-hidden rounded-xl border border-surface-container-highest bg-surface-container-low shadow-sm" :aria-label="'Phóng to ảnh: ' + (img.caption ?? s.title)" @click="zoomed = img">
                                <img :src="img.src" :alt="img.caption ?? s.title" loading="lazy" class="block w-full transition group-hover:opacity-90" />
                            </button>
                            <figcaption v-if="img.caption" class="mt-xs text-center text-body-small text-on-surface-variant">{{ img.caption }}</figcaption>
                        </figure>
                    </div>

                    <div v-if="s.steps?.length">
                        <h4 class="mb-sm font-body-semibold text-body-medium text-on-surface">Các bước thực hiện</h4>
                        <ol class="space-y-sm">
                            <li v-for="(step, n) in s.steps" :key="n" class="flex items-start gap-sm text-body-medium text-on-surface">
                                <span class="mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-container text-caption-badge font-bold text-white">{{ n + 1 }}</span>
                                <span class="min-w-0 pt-0.5">
                                    <template v-for="(p, k) in parts(step)" :key="k"><strong v-if="p.bold">{{ p.text }}</strong><template v-else>{{ p.text }}</template></template>
                                </span>
                            </li>
                        </ol>
                    </div>

                    <div v-if="s.tips?.length" class="rounded-xl border border-info/20 bg-info/5 p-sm">
                        <div class="mb-xs flex items-center gap-xs font-body-semibold text-body-small text-info">
                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">lightbulb</span> Lưu ý
                        </div>
                        <ul class="list-inside list-disc space-y-0.5 text-body-small text-on-surface">
                            <li v-for="(tip, n) in s.tips" :key="n">
                                <template v-for="(p, k) in parts(tip)" :key="k"><strong v-if="p.bold">{{ p.text }}</strong><template v-else>{{ p.text }}</template></template>
                            </li>
                        </ul>
                    </div>
                </div>
            </article>
        </div>
    </div>

    <Teleport to="body">
        <div v-if="zoomed" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/80 p-md" role="dialog" aria-modal="true" :aria-label="zoomed.caption ?? 'Ảnh hướng dẫn'" @click.self="zoomed = null">
            <button type="button" class="absolute right-md top-md flex h-10 w-10 items-center justify-center rounded-full bg-white/15 text-white hover:bg-white/25" aria-label="Đóng ảnh" @click="zoomed = null">
                <span class="material-symbols-outlined" aria-hidden="true">close</span>
            </button>
            <figure class="max-h-full max-w-6xl overflow-auto">
                <img :src="zoomed.src" :alt="zoomed.caption ?? ''" class="mx-auto block max-h-[85vh] w-auto rounded-lg" />
                <figcaption v-if="zoomed.caption" class="mt-sm text-center text-body-small text-white/80">{{ zoomed.caption }}</figcaption>
            </figure>
        </div>
    </Teleport>
</template>
