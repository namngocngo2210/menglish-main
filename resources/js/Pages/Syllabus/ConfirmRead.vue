<script setup>
/**
 * "Đọc & xác nhận" chặng (docs/kho-syllabus.md §7.3 bước 2): GV đọc tổng quan chặng (mục tiêu, unit, Big Test) đủ
 * reading.min_seconds giây, cuộn tới cuối trang, mở hết tài liệu chặng, tick cam kết rồi mới bấm XÁC NHẬN được.
 * Máy chủ kiểm lại thời gian đọc + tài liệu đã xem (ConfirmReading). Học thuật mở trang này ở chế độ chỉ xem.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { route } from '@/lib/route';

defineOptions({ layout: { title: 'Đọc & xác nhận syllabus' } });

const props = defineProps({
    assignment: { type: Object, required: true },
    stage: { type: Object, default: null },
    documents: { type: Array, default: () => [] },
    reading: { type: Object, required: true },
    confirmUrl: { type: String, required: true },
});

const elapsed = ref(props.reading.read_seconds);
const reachedEnd = ref(false);
const acknowledged = ref(false);
const end = ref(null);
let timer = null;
let observer = null;

onMounted(() => {
    if (!props.reading.can_confirm) return;
    timer = setInterval(() => {
        elapsed.value += 1;
        if (elapsed.value >= props.reading.min_seconds) clearInterval(timer);
    }, 1000);
    observer = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting) {
            reachedEnd.value = true;
            observer.disconnect();
        }
    });
    if (end.value) observer.observe(end.value);
});
onBeforeUnmount(() => {
    clearInterval(timer);
    observer?.disconnect();
});

const secondsLeft = computed(() => Math.max(0, props.reading.min_seconds - elapsed.value));
const viewedCount = computed(() => props.documents.filter((d) => d.viewed_at).length);
const steps = computed(() => [
    { done: secondsLeft.value === 0 && reachedEnd.value, label: secondsLeft.value > 0 ? `Đọc tổng quan chặng (còn ${secondsLeft.value} giây)` : reachedEnd.value ? 'Đã đọc tổng quan chặng' : 'Cuộn đọc tới cuối trang' },
    { done: props.reading.documents_done, label: props.documents.length ? `Mở hết tài liệu chặng (${viewedCount.value}/${props.documents.length})` : 'Chặng không có tài liệu phải mở' },
    { done: acknowledged.value, label: 'Tick cam kết đã đọc' },
]);
const ready = computed(() => steps.value.every((s) => s.done));
</script>

<template>
    <UiPageHeader title="Đọc & xác nhận syllabus" :back="route('syllabus.teaching-stages')">
        <template #meta>Lớp {{ assignment.class_name }} · {{ assignment.stage_label }}</template>
    </UiPageHeader>

    <div class="mx-auto max-w-4xl space-y-5 pb-10 text-sm">
        <UiAlert v-if="assignment.confirmed_at" type="success" :title="`Đã xác nhận lúc ${assignment.confirmed_at}${assignment.confirmed_by ? ' · ' + assignment.confirmed_by : ''}`">
            {{ assignment.evidence || 'Xác nhận trước khi có bước bắt đọc.' }}
        </UiAlert>
        <UiAlert v-else-if="reading.can_confirm" type="warning" title="Đọc kỹ trước khi xác nhận">
            Đọc hết tổng quan chặng bên dưới và mở từng tài liệu chặng, sau đó tick cam kết và bấm XÁC NHẬN ở cuối trang.
            <template v-if="assignment.confirm_due"> Hạn xác nhận: <strong>{{ assignment.confirm_due }}</strong> (quá hạn bị lập biên bản).</template>
        </UiAlert>
        <UiAlert v-else type="info">Trang GV phải đọc trước khi xác nhận chặng. Bạn đang xem ở chế độ chỉ xem.</UiAlert>

        <section class="rounded-xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm">
            <h2 class="mb-3 flex items-center gap-2 font-bold text-on-surface"><span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">info</span>Thông tin chặng</h2>
            <dl class="grid grid-cols-2 gap-3 text-xs md:grid-cols-5">
                <div><dt class="font-bold uppercase text-on-surface-subtle">Giáo trình</dt><dd class="font-semibold text-on-surface">{{ assignment.curriculum || '—' }}</dd></div>
                <div><dt class="font-bold uppercase text-on-surface-subtle">Bắt đầu</dt><dd class="font-semibold text-on-surface">{{ assignment.start_date || '—' }}</dd></div>
                <div><dt class="font-bold uppercase text-on-surface-subtle">Số buổi</dt><dd class="font-semibold text-on-surface">{{ assignment.planned_sessions ?? '—' }}</dd></div>
                <div><dt class="font-bold uppercase text-on-surface-subtle">Dự kiến kết thúc</dt><dd class="font-semibold text-on-surface">{{ assignment.deadline || '—' }}</dd></div>
                <div><dt class="font-bold uppercase text-on-surface-subtle">Hạn xác nhận</dt><dd class="font-semibold text-error">{{ assignment.confirm_due || '—' }}</dd></div>
            </dl>
        </section>

        <section class="rounded-xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm">
            <h2 class="mb-3 flex items-center gap-2 font-bold text-on-surface"><span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">flag</span>Mục tiêu chặng</h2>
            <p v-if="stage?.description" class="whitespace-pre-line leading-relaxed text-on-surface-variant">{{ stage.description }}</p>
            <p v-else class="italic text-on-surface-subtle">Học thuật chưa nhập mục tiêu chặng.</p>
            <a v-if="stage?.overview_link" :href="stage.overview_link" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-1 font-semibold text-primary hover:underline">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">open_in_new</span>Mở tổng quan chặng
            </a>
        </section>

        <section class="rounded-xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm">
            <h2 class="mb-3 flex items-center gap-2 font-bold text-on-surface"><span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">view_list</span>Các unit trong chặng</h2>
            <div v-if="stage?.units?.length" class="overflow-hidden rounded-lg border border-surface-container-highest text-xs">
                <div class="hidden grid-cols-[6rem_1fr_5rem] bg-surface-container-high/60 px-3 py-1.5 font-bold uppercase tracking-wider text-on-surface-subtle sm:grid">
                    <span>Unit</span><span>Tên &amp; mục tiêu</span><span class="text-right">Số buổi</span>
                </div>
                <div v-for="u in stage.units" :key="u.id" class="grid grid-cols-[1fr_auto] gap-x-3 gap-y-0.5 border-t border-surface-container-highest px-3 py-2 first:border-t-0 sm:grid-cols-[6rem_1fr_5rem] sm:first:border-t">
                    <span class="font-bold text-primary">Unit {{ u.unit_number }}</span>
                    <span class="text-right text-on-surface-variant sm:order-last">{{ u.lessons }} buổi</span>
                    <div class="col-span-2 sm:col-span-1">
                        <p class="font-semibold text-on-surface">{{ u.title }}</p>
                        <p v-if="u.objectives" class="whitespace-pre-line text-on-surface-variant">{{ u.objectives }}</p>
                    </div>
                </div>
            </div>
            <p v-else class="italic text-on-surface-subtle">Chặng chưa có unit nào.</p>
        </section>

        <section class="rounded-xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm">
            <h2 class="mb-3 flex items-center gap-2 font-bold text-on-surface"><span class="material-symbols-outlined text-[20px] text-secondary" aria-hidden="true">quiz</span>Big Test cuối chặng</h2>
            <p class="font-semibold text-secondary">{{ stage?.big_test_title || 'Big Test cuối chặng' }}</p>
            <p v-if="stage?.big_test_note" class="whitespace-pre-line text-on-surface-variant">{{ stage.big_test_note }}</p>
        </section>

        <section class="rounded-xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-sm">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 font-bold text-on-surface"><span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">folder_open</span>Tài liệu chặng</h2>
                <UiBadge v-if="documents.length" :color="reading.documents_done ? 'success' : 'warning'" :dot="false" pill>Đã xem {{ viewedCount }}/{{ documents.length }}</UiBadge>
            </div>
            <ul v-if="documents.length" class="divide-y divide-surface-container-highest rounded-lg border border-surface-container-highest">
                <li v-for="doc in documents" :key="doc.id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-xs">
                    <span class="flex min-w-0 items-center gap-2">
                        <span class="material-symbols-outlined text-[18px] text-primary" aria-hidden="true">{{ doc.icon }}</span>
                        <span class="truncate font-semibold text-on-surface">{{ doc.title }}</span>
                        <span class="whitespace-nowrap font-code uppercase text-on-surface-variant">{{ doc.extension }} · {{ doc.size_human }}</span>
                    </span>
                    <span class="flex shrink-0 items-center gap-2">
                        <span v-if="doc.viewed_at" class="inline-flex items-center gap-1 font-semibold text-tertiary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">check_circle</span>Đã xem {{ doc.viewed_at }}</span>
                        <UiButton :variant="doc.viewed_at ? 'ghost' : 'secondary'" size="sm" icon="visibility" :href="doc.url">{{ doc.viewed_at ? 'Xem lại' : 'Mở xem' }}</UiButton>
                    </span>
                </li>
            </ul>
            <p v-else class="italic text-on-surface-subtle">Học thuật chưa tải tài liệu nào cho chặng này.</p>
            <p v-if="documents.length && reading.can_confirm" class="mt-2 text-xs text-on-surface-variant">Mở từng tài liệu, xem ít nhất vài giây rồi bấm "Đánh dấu đã xem" trong hộp xem tài liệu.</p>
        </section>

        <div ref="end" aria-hidden="true"></div>

        <section v-if="reading.can_confirm" class="rounded-xl border-2 border-primary-container bg-surface-container-lowest p-4 shadow-sm">
            <h2 class="mb-3 flex items-center gap-2 font-bold text-on-surface"><span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">task_alt</span>Xác nhận nhận syllabus</h2>
            <ol class="mb-3 space-y-1.5 text-xs">
                <li v-for="(s, i) in steps" :key="i" :class="['flex items-center gap-2', s.done ? 'text-tertiary' : 'text-on-surface-variant']">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ s.done ? 'check_circle' : 'radio_button_unchecked' }}</span>{{ s.label }}
                </li>
            </ol>
            <UiForm :action="confirmUrl" method="post" class="space-y-3">
                <template #default="{ errors }">
                    <UiCheckbox v-model="acknowledged" name="acknowledged" label="Tôi đã đọc tổng quan chặng, đã mở hết tài liệu chặng và nắm rõ mục tiêu, các unit và Big Test của chặng này." />
                    <UiErrors :messages="[errors.reading, errors.acknowledged]" />
                    <UiButton type="submit" icon="task_alt" :disabled="!ready">XÁC NHẬN</UiButton>
                </template>
            </UiForm>
        </section>
    </div>
</template>
