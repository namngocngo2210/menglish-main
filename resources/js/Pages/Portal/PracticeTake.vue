<script setup>
/**
 * Làm bài luyện tập (docs/luyen-tap-ngan-hang-cau-hoi.md §6.3–6.4). Không dùng khung ứng dụng: bấm "Bắt đầu làm bài" →
 * khoá màn hình (useExamLock, dùng chung với test đầu vào). Mọi câu trên một trang, chia theo kỹ năng; ngữ liệu hiện một
 * lần phía trên các câu dùng chung.
 * - Đồng hồ đếm ngược theo số giây còn lại server gửi; về 0 → tự nộp.
 * - Chọn đáp án → lưu ngay (POST answer, gộp các lần bấm nhanh); lỗi mạng → báo "Thử lại", lựa chọn vẫn giữ trên trang.
 * - Mỗi lần rời bài → POST violation; server đếm, tới lần thứ maxViolations thì tự nộp → chuyển sang trang kết quả.
 * Props không có đáp án (PracticeController::renderTake).
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import BareLayout from '@/Layouts/BareLayout.vue';
import UnderlinedText from '@/Components/Practice/UnderlinedText.vue';
import { confirmDialog } from '@/lib/confirm';
import { postJson } from '@/lib/http';
import { groupPracticeItems } from '@/lib/practiceGroups';
import { route } from '@/lib/route';
import { useExamLock } from '@/lib/useExamLock';

defineOptions({ layout: BareLayout });

const props = defineProps({
    attempt: { type: Object, required: true },
    items: { type: Array, default: () => [] },
});

const SAVE_DELAY_MS = 400;
const CHOICES = ['A', 'B', 'C', 'D'];

const sections = computed(() => groupPracticeItems(props.items));
// finishing: đang nộp / đã hết lượt — khoá chọn đáp án. submitting: đang chuyển sang trang kết quả.
const finishing = ref(false);
const submitting = ref(false);
const attemptUrl = computed(() => route('portal.student.practice.attempt', props.attempt.id));

// ── Đáp án: giữ trên trang, lưu lên server (mỗi câu chỉ gửi lựa chọn mới nhất) ──
const answers = reactive(Object.fromEntries(props.items.map((i) => [i.position, i.chosen ?? null])));
const saveErrors = reactive({});
const pending = new Map(); // position → timer
const inflight = new Map(); // position → Promise
const answeredCount = computed(() => Object.values(answers).filter((v) => v !== null).length);
const unanswered = computed(() => props.items.length - answeredCount.value);

function choose(position, choice) {
    answers[position] = choice;
    clearTimeout(pending.get(position));
    pending.set(position, setTimeout(() => save(position), SAVE_DELAY_MS));
}

async function save(position) {
    pending.delete(position);
    const choice = answers[position];
    const request = (async () => {
        try {
            const { ok, status, data } = await postJson(route('portal.student.practice.answer', props.attempt.id), { position, choice });
            if (ok) {
                // lần bấm sau đã đổi lựa chọn → lần lưu kế tiếp lo
                if (answers[position] === choice) delete saveErrors[position];
                return;
            }
            if (status === 422) {
                // hết giờ / bài đã nộp: server không nhận nữa → sang trang kết quả (trang làm bài không gửi được đáp án sai dạng)
                saveErrors[position] = data?.message || 'Không lưu được câu trả lời.';
                leaveToResult();
                return;
            }
            saveErrors[position] = 'Chưa lưu được câu trả lời.';
        } catch {
            saveErrors[position] = 'Mất kết nối — chưa lưu được câu trả lời.';
        }
    })();
    inflight.set(position, request);
    await request;
    if (inflight.get(position) === request) inflight.delete(position);
}

/** Gửi ngay các câu còn chờ lưu, đợi xong (trước khi nộp bài). */
async function flushAnswers() {
    for (const [position, timer] of [...pending]) {
        clearTimeout(timer);
        save(position);
    }
    await Promise.allSettled([...inflight.values()]);
}

// ── Khoá màn hình & rời bài ──
const violations = ref(props.attempt.violationCount);
const limitReached = ref(false);
const lock = useExamLock({
    // số lần rời bài còn lại (server đếm cả các lần trước khi tải lại trang)
    maxViolations: Math.max(1, props.attempt.maxViolations - props.attempt.violationCount),
    onViolation: reportViolation,
    onLimit: () => {
        limitReached.value = true;
        finishing.value = true;
    },
});
const { active: started, showWarning, resume } = lock;

async function reportViolation(type, localCount) {
    violations.value = props.attempt.violationCount + localCount;
    try {
        const { ok, data } = await postJson(route('portal.student.practice.violation', props.attempt.id), { type });
        if (ok) {
            violations.value = data.count;
            if (data.submitted) return leaveToResult();
        }
    } catch {
        /* mất mạng: vẫn đếm trên trang; tới giới hạn thì nộp bên dưới */
    }
    if (limitReached.value) submit();
}

// ── Đồng hồ ──
const remaining = ref(props.attempt.remainingSeconds);
let deadlineAt = 0;
let timer = null;
const timeText = computed(() => {
    const m = String(Math.floor(remaining.value / 60)).padStart(2, '0');
    const s = String(remaining.value % 60).padStart(2, '0');
    return `${m}:${s}`;
});

onMounted(() => {
    deadlineAt = Date.now() + props.attempt.remainingSeconds * 1000;
    const tick = () => {
        remaining.value = Math.max(0, Math.ceil((deadlineAt - Date.now()) / 1000));
        if (remaining.value === 0) {
            clearInterval(timer);
            submit();
        }
    };
    tick();
    timer = setInterval(tick, 1000);
});
onBeforeUnmount(() => {
    clearInterval(timer);
    pending.forEach((t) => clearTimeout(t));
});

// ── Nộp bài ──
function leaveToResult() {
    if (submitting.value) return;
    submitting.value = true;
    finishing.value = true;
    lock.stop();
    router.visit(attemptUrl.value, { replace: true });
}

async function submit() {
    if (submitting.value) return;
    submitting.value = true;
    finishing.value = true;
    lock.stop();
    await flushAnswers();
    router.post(route('portal.student.practice.submit', props.attempt.id), {}, {
        replace: true,
        onError: () => {
            submitting.value = false;
        },
    });
}

async function confirmSubmit() {
    const ok = await confirmDialog({
        title: 'Nộp bài?',
        message: unanswered.value > 0 ? `Còn ${unanswered.value} câu chưa trả lời. Nộp bài ngay?` : 'Bạn đã trả lời tất cả các câu. Nộp bài ngay?',
        confirmLabel: 'Nộp bài',
    });
    if (ok) submit();
}

function begin() {
    lock.start();
    window.scrollTo({ top: 0 });
}
</script>

<template>
    <Head :title="`Luyện tập — ${attempt.term_label}`" />
    <div class="min-h-screen bg-surface-container-low font-sans text-on-surface antialiased">
        <!-- Thanh trên: đồng hồ + số lần rời bài -->
        <header class="sticky top-0 z-40 border-b border-surface-container-highest bg-surface-container-lowest/95 shadow-xs backdrop-blur-md">
            <div class="mx-auto flex max-w-2xl items-center justify-between gap-2 px-4 py-2.5">
                <div class="min-w-0">
                    <div class="truncate text-sm font-black text-primary">Luyện tập · {{ attempt.term_label }}</div>
                    <div class="text-xs text-on-surface-variant">Khối {{ attempt.grade }} · {{ items.length }} câu</div>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <div class="flex items-center gap-1 rounded-xl border border-error/30 bg-error/10 px-2.5 py-1 text-xs font-bold text-error" title="Số lần rời khỏi bài">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">shield</span>
                        <span>Rời bài: {{ violations }}/{{ attempt.maxViolations }}</span>
                    </div>
                    <div
                        role="timer"
                        :aria-label="`Thời gian còn lại ${timeText}`"
                        :class="['flex items-center gap-1 rounded-xl border px-2.5 py-1 font-mono text-sm font-bold', remaining <= 60 ? 'border-error/40 bg-error/10 text-error' : 'border-primary-container/30 bg-primary-container/10 text-primary']"
                    >
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">timer</span>
                        <span>{{ timeText }}</span>
                    </div>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-2xl space-y-5 px-4 py-4 pb-28">
            <!-- Màn bắt đầu -->
            <section v-if="!started" class="space-y-4 rounded-2xl border border-primary-container/40 bg-surface-container-lowest p-5 shadow-sm">
                <h1 class="text-lg font-extrabold text-on-surface">{{ attempt.term_label }} — {{ items.length }} câu</h1>
                <ul class="list-inside list-disc space-y-1.5 text-sm leading-relaxed text-on-surface-variant">
                    <li>Bài làm mở ở chế độ <strong>toàn màn hình</strong>. Không chuyển sang tab hay ứng dụng khác cho tới khi nộp bài.</li>
                    <li>Câu trả lời được lưu ngay khi chọn. Hết giờ, bài <strong>tự nộp</strong>.</li>
                    <li>Mỗi lần rời bài đều được ghi lại. Rời bài tới lần thứ <strong>{{ attempt.maxViolations }}</strong>, bài sẽ <strong>tự nộp</strong>.</li>
                </ul>
                <UiButton type="button" icon="play_circle" class="w-full justify-center" @click="begin">{{ answeredCount > 0 ? 'Tiếp tục làm bài' : 'Bắt đầu làm bài' }}</UiButton>
            </section>

            <!-- Đề -->
            <template v-else>
                <section v-for="section in sections" :key="section.skill + section.blocks[0].key" class="space-y-3">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-primary">{{ section.skill }}</h2>

                    <div v-for="block in section.blocks" :key="block.key" class="space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-2xs">
                        <p v-if="block.instruction" class="text-sm font-semibold text-on-surface"><UnderlinedText :text="block.instruction" /></p>
                        <div v-if="block.passage" class="space-y-2 rounded-xl bg-surface-container-low p-3">
                            <img v-if="block.passage.image_url" :src="block.passage.image_url" alt="Hình của ngữ liệu" class="mx-auto max-h-80 rounded-lg" />
                            <p v-if="block.passage.content" class="whitespace-pre-line text-sm leading-relaxed text-on-surface"><UnderlinedText :text="block.passage.content" /></p>
                        </div>

                        <fieldset v-for="item in block.items" :id="`cau-${item.position}`" :key="item.position" class="space-y-2">
                            <legend class="text-sm text-on-surface">
                                <span class="font-bold">Câu {{ item.position }}.</span>
                                <UnderlinedText v-if="item.stem" :text="item.stem" class="ml-1 whitespace-pre-line" />
                            </legend>
                            <img v-if="item.image_url" :src="item.image_url" :alt="`Hình của câu ${item.position}`" class="mx-auto max-h-64 rounded-lg" />

                            <div class="grid gap-2">
                                <label
                                    v-for="key in CHOICES.filter((k) => k in item.options)"
                                    :key="key"
                                    :class="[
                                        'flex min-h-[48px] cursor-pointer items-center gap-3 rounded-xl border-2 px-3 py-2 text-sm transition has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary',
                                        answers[item.position] === key ? 'border-primary bg-primary-container/10 font-semibold text-on-surface' : 'border-surface-container-highest text-on-surface hover:border-primary-container/60',
                                    ]"
                                >
                                    <input
                                        type="radio"
                                        class="sr-only"
                                        :name="`cau-${item.position}`"
                                        :value="key"
                                        :checked="answers[item.position] === key"
                                        :disabled="finishing"
                                        @change="choose(item.position, key)"
                                    />
                                    <span
                                        :class="[
                                            'flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 text-sm font-bold',
                                            answers[item.position] === key ? 'border-primary bg-primary text-on-primary' : 'border-outline-variant text-on-surface-variant',
                                        ]"
                                        aria-hidden="true"
                                    >{{ key }}</span>
                                    <UnderlinedText :text="item.options[key]" class="flex-1" />
                                </label>
                            </div>
                            <p v-if="saveErrors[item.position]" class="flex items-center gap-2 text-xs font-semibold text-error" role="alert">
                                <span>{{ saveErrors[item.position] }}</span>
                                <button v-if="!finishing" type="button" class="underline underline-offset-2" @click="save(item.position)">Thử lại</button>
                            </p>
                        </fieldset>
                    </div>
                </section>
            </template>
        </main>

        <!-- Thanh nộp bài -->
        <div v-if="started" class="fixed bottom-0 left-0 right-0 z-40 border-t border-surface-container-highest bg-surface-container-lowest/95 backdrop-blur-md">
            <div class="mx-auto flex max-w-2xl items-center justify-between gap-3 px-4 py-3">
                <span class="text-sm text-on-surface-variant">Đã làm <strong class="text-on-surface">{{ answeredCount }}/{{ items.length }}</strong> câu</span>
                <UiButton type="button" icon="send" :disabled="submitting" @click="confirmSubmit">Nộp bài</UiButton>
            </div>
        </div>

        <!-- Cảnh báo rời bài -->
        <div v-if="showWarning" class="fixed inset-0 z-[100] flex items-center justify-center bg-inverse-surface/90 p-4">
            <div role="alertdialog" aria-modal="true" aria-labelledby="practice-warning-title" class="w-full max-w-md space-y-4 rounded-2xl bg-surface-container-lowest p-6 text-center shadow-xl">
                <span class="material-symbols-outlined text-5xl text-error" aria-hidden="true">warning</span>
                <h2 id="practice-warning-title" class="text-lg font-extrabold text-on-surface">Bạn vừa rời khỏi bài làm</h2>
                <p class="text-sm text-on-surface-variant">Đã rời bài <strong class="text-error">{{ violations }}/{{ attempt.maxViolations }}</strong> lần. Tới lần thứ {{ attempt.maxViolations }}, bài sẽ tự nộp.</p>
                <UiButton type="button" icon="fullscreen" class="w-full justify-center" @click="resume">Quay lại làm bài</UiButton>
            </div>
        </div>

        <!-- Đang nộp -->
        <div v-if="submitting" class="fixed inset-0 z-[110] flex items-center justify-center bg-inverse-surface/80 p-4">
            <div class="flex items-center gap-3 rounded-2xl bg-surface-container-lowest px-6 py-4 text-sm font-semibold text-on-surface shadow-xl" role="status">
                <span class="material-symbols-outlined animate-spin text-primary" aria-hidden="true">progress_activity</span>
                Đang nộp bài…
            </div>
        </div>
    </div>
</template>
