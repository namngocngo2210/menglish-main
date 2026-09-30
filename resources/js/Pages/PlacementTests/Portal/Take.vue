<script setup>
/**
 * Cổng làm bài test đầu vào trực tuyến (công khai, không đăng nhập, không khung ứng dụng).
 * Chế độ thi (PR #35): bấm "Bắt đầu làm bài" → toàn màn hình; rời bài (ẩn tab / mất focus / thoát toàn màn hình) được ghi
 * lại và gửi kèm bài làm; tới MAX_VIOLATIONS lần thì tự động nộp. Khi đang làm bài: chặn quay lại / tải lại trang,
 * chuột phải, sao chép / dán, kéo thả, bôi đen và các phím tắt phổ biến.
 * Props không chứa đáp án (PlacementTestController::portalQuestions).
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import BareLayout from '@/Layouts/BareLayout.vue';

defineOptions({ layout: BareLayout });

const props = defineProps({
    test: { type: Object, required: true },
    listening: { type: Array, default: () => [] },
    reading: { type: Array, default: () => [] },
    writingPrompt: { type: String, default: '' },
    speaking: { type: Object, default: null },
    lead: { type: Object, default: null },
    leadToken: { type: String, default: null },
    maxViolations: { type: Number, default: 3 },
});

const page = usePage();
const errorMessages = computed(() => Object.values(page.props.errors ?? {}));

const formRef = ref(null);
const formWrap = ref(null);
const started = ref(false);
const submitting = ref(false);
const showWarning = ref(false);
const showSubmitting = ref(false);
const autoSubmitted = ref(false);
const log = ref([]);
let lastViolationAt = 0;

const violationLog = computed(() => JSON.stringify(log.value));

const root = () => document.documentElement;
const canFullscreen = () => !!(root().requestFullscreen || root().webkitRequestFullscreen);
const isFullscreen = () => !!(document.fullscreenElement || document.webkitFullscreenElement);
function enterFullscreen() {
    if (!canFullscreen() || isFullscreen()) return;
    const req = root().requestFullscreen || root().webkitRequestFullscreen;
    try {
        const p = req.call(root());
        if (p && p.catch) p.catch(() => {});
    } catch (e) {
        /* trình duyệt từ chối toàn màn hình: vẫn cho làm bài */
    }
}
function exitFullscreen() {
    if (!isFullscreen()) return;
    const exit = document.exitFullscreen || document.webkitExitFullscreen;
    try {
        const p = exit.call(document);
        if (p && p.catch) p.catch(() => {});
    } catch (e) {
        /* bỏ qua */
    }
}

// Tự động nộp: giống form.submit() cũ — bỏ qua kiểm tra required của trình duyệt, gửi các câu đã làm.
async function autoSubmit() {
    submitting.value = true;
    autoSubmitted.value = true;
    showWarning.value = false;
    showSubmitting.value = true;
    await nextTick();
    formRef.value?.submit();
}

function recordViolation(type) {
    if (!started.value || submitting.value) return;
    // Chuyển tab thường bắn cùng lúc blur + visibilitychange + thoát toàn màn hình: tính là 1 lần.
    const now = Date.now();
    if (now - lastViolationAt < 1500) return;
    lastViolationAt = now;

    log.value.push({ type, at: new Date().toISOString() });
    if (log.value.length >= props.maxViolations) {
        autoSubmit();
        return;
    }
    showWarning.value = true;
}

function start() {
    const form = formWrap.value?.querySelector('form');
    for (const name of ['candidate_name', 'candidate_phone']) {
        const input = form?.elements[name];
        if (input && !input.reportValidity()) return;
    }
    enterFullscreen();
    started.value = true;
    // Khoá nút Quay lại (xem onPopstate): mục lịch sử hiện tại thành state rỗng + thêm một mục trùng trang ở trên.
    history.replaceState(null, '', location.href);
    history.pushState(null, '', location.href);
    nextTick(() => window.scrollTo({ top: 0 }));
}

function resume() {
    showWarning.value = false;
    enterFullscreen();
}

function onSubmitStart() {
    submitting.value = true;
}
// Lỗi validate (422): cho làm tiếp, bỏ lớp phủ "đang gửi bài".
function onError() {
    submitting.value = false;
    showSubmitting.value = false;
}

const onVisibility = () => {
    if (document.visibilityState === 'hidden') recordViolation('hidden');
};
const onBlur = () => recordViolation('blur');
const onFullscreenChange = () => {
    if (canFullscreen() && !isFullscreen()) recordViolation('fullscreen_exit');
};
// Không cho quay lại trang trước khi đang làm bài. Bấm Quay lại chỉ về mục lịch sử state rỗng của chính trang thi:
// với state rỗng Inertia không dựng lại trang (bài đang làm còn nguyên), chỉ ghi state trang vào mục đó và cuộn lên đầu.
// Bộ xử lý này chạy sau Inertia: đặt lại state rỗng cho mục đó (để lần Quay lại sau vẫn vậy), đẩy lại mục trùng trang
// và trả vị trí cuộn cũ.
let lastScrollY = 0;
const onScroll = () => {
    lastScrollY = window.scrollY;
};
const onPopstate = () => {
    if (!started.value || submitting.value) return;
    const y = lastScrollY;
    history.replaceState(null, '', location.href);
    history.pushState(null, '', location.href);
    requestAnimationFrame(() => requestAnimationFrame(() => window.scrollTo(0, y)));
};
const onBeforeUnload = (e) => {
    if (started.value && !submitting.value) {
        e.preventDefault();
        e.returnValue = '';
    }
};
// Chặn chuột phải, sao chép / dán, kéo thả.
const blockWhileStarted = (e) => {
    if (started.value) e.preventDefault();
};
const BLOCKED_EVENTS = ['contextmenu', 'copy', 'cut', 'paste', 'drop', 'dragstart'];
const onSelectStart = (e) => {
    if (started.value && !e.target.closest?.('input, textarea')) e.preventDefault();
};
// Chặn phím tắt phổ biến: DevTools, xem nguồn, lưu, in, tải lại, sao chép / dán.
const onKeydown = (e) => {
    if (!started.value || submitting.value) return;
    const key = (e.key || '').toLowerCase();
    const mod = e.ctrlKey || e.metaKey;
    const blocked =
        key === 'f12' ||
        key === 'f5' ||
        (mod && e.shiftKey && ['i', 'j', 'c', 'k'].includes(key)) ||
        (mod && ['u', 's', 'p', 'r', 'c', 'x', 'v', 'o', 'f', 'g'].includes(key)) ||
        (e.altKey && ['arrowleft', 'arrowright'].includes(key));
    if (blocked) {
        e.preventDefault();
        e.stopPropagation();
    }
};

onMounted(() => {
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('blur', onBlur);
    document.addEventListener('fullscreenchange', onFullscreenChange);
    document.addEventListener('webkitfullscreenchange', onFullscreenChange);
    window.addEventListener('popstate', onPopstate);
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('beforeunload', onBeforeUnload);
    BLOCKED_EVENTS.forEach((evt) => document.addEventListener(evt, blockWhileStarted));
    document.addEventListener('selectstart', onSelectStart);
    document.addEventListener('keydown', onKeydown, true);
});

onBeforeUnmount(() => {
    document.removeEventListener('visibilitychange', onVisibility);
    window.removeEventListener('blur', onBlur);
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    document.removeEventListener('webkitfullscreenchange', onFullscreenChange);
    window.removeEventListener('popstate', onPopstate);
    window.removeEventListener('scroll', onScroll);
    window.removeEventListener('beforeunload', onBeforeUnload);
    BLOCKED_EVENTS.forEach((evt) => document.removeEventListener(evt, blockWhileStarted));
    document.removeEventListener('selectstart', onSelectStart);
    document.removeEventListener('keydown', onKeydown, true);
    // Nộp xong chuyển sang trang hoàn thành: thoát toàn màn hình như khi tải trang mới.
    exitFullscreen();
});

const selfRates = [
    { value: 'beginner', title: 'Mới bắt đầu / Mất gốc (A1)', text: 'Chưa tự tin phát âm, hay ấp úng khi giao tiếp câu cơ bản.' },
    { value: 'intermediate', title: 'Trung bình (A2 - B1)', text: 'Giao tiếp được câu hoàn chỉnh hàng ngày, phản xạ tương đối ổn.' },
    { value: 'advanced', title: 'Nâng cao (B2 - C1)', text: 'Tự tin thuyết trình, tranh luận học thuật và phản xạ nhanh.' },
];
</script>

<template>
    <Head :title="`${test.title} — Online Placement Test`" />
    <div class="min-h-screen bg-surface-container-low font-sans text-on-surface antialiased">
        <!-- Header -->
        <header class="sticky top-0 z-40 border-b border-surface-container-highest bg-surface-container-lowest shadow-xs">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-container text-lg font-black text-white shadow-sm">M</div>
                    <div>
                        <div class="text-base font-black leading-tight tracking-tight text-on-surface">MEnglish Academy</div>
                        <div class="text-xs font-medium text-on-surface-variant">Hệ Thống Đánh Giá Trình Độ &amp; Xếp Lớp Chuẩn CEFR</div>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <div
                        id="exam-violation-badge"
                        :class="['flex items-center gap-1.5 rounded-xl border border-error/30 bg-error/10 px-3 py-1.5 text-xs font-bold text-error', started ? '' : 'hidden']"
                        title="Số lần rời khỏi bài thi"
                    >
                        <span class="material-symbols-outlined text-[16px]">shield</span>
                        <span>Rời bài: <span id="exam-violation-count">{{ log.length }}</span>/{{ maxViolations }}</span>
                    </div>
                    <div class="flex items-center gap-1.5 rounded-xl border border-primary-container/30 bg-primary-container/10 px-3.5 py-1.5 font-mono text-xs font-bold text-primary">
                        <span class="material-symbols-outlined text-[16px] text-primary">timer</span>
                        <span>Thời gian: {{ test.duration_minutes }} phút</span>
                    </div>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-4xl space-y-6 px-4 py-8">
            <!-- Test Intro Banner -->
            <div class="space-y-3 rounded-3xl bg-gradient-to-r from-primary-container to-warning p-6 text-white shadow-xl md:p-8">
                <div class="flex items-center gap-2">
                    <span class="rounded-full bg-white/20 px-3 py-1 font-mono text-xs font-bold uppercase tracking-wider text-white backdrop-blur-xs">{{ test.code }}</span>
                    <span class="rounded-full bg-white/20 px-3 py-1 text-xs font-semibold text-white backdrop-blur-xs">{{ test.target_level }}</span>
                </div>
                <h1 class="text-2xl font-extrabold tracking-tight md:text-3xl">{{ test.title }}</h1>
                <p class="max-w-2xl text-xs leading-relaxed text-white/90 md:text-sm">
                    Bài kiểm tra gồm {{ test.questions_count }} câu hỏi đánh giá 4 kỹ năng (Nghe, Đọc - Ngữ pháp, Viết và Nói). Sau khi nộp bài, <strong>Học vụ MEnglish</strong> sẽ chấm và gửi kết quả xếp lớp cho phụ huynh.
                </p>
            </div>

            <!-- Form Submission -->
            <!-- submit (đã qua kiểm tra required) nghe ở pha capture của khối bọc form -->
            <div ref="formWrap" @submit.capture="onSubmitStart">
                <UiForm id="exam-form" ref="formRef" :action="route('portal.test.submit', test.code)" method="post" :preserve-scroll="false" class="space-y-6" @error="onError">
                    <UiAlert v-if="errorMessages.length" type="error" title="Vui lòng kiểm tra lại thông tin:">
                        <ul class="list-inside list-disc space-y-0.5">
                            <li v-for="(message, index) in errorMessages" :key="index">{{ message }}</li>
                        </ul>
                    </UiAlert>
                    <input v-if="leadToken" type="hidden" name="lead_token" :value="leadToken" />
                    <input id="exam-violation-input" type="hidden" name="violation_count" :value="String(log.length)" />
                    <input id="exam-violation-log" type="hidden" name="violation_log" :value="violationLog" />
                    <input id="exam-auto-submitted" type="hidden" name="auto_submitted" :value="autoSubmitted ? '1' : '0'" />

                    <!-- 1. Candidate Info -->
                    <div class="space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
                        <h2 class="flex items-center gap-2 border-b border-surface-container-highest pb-2 text-sm font-bold uppercase tracking-wider text-on-surface">
                            <span class="material-symbols-outlined text-primary">person</span>
                            1. Thông tin thí sinh dự thi
                        </h2>
                        <div class="grid grid-cols-1 gap-4 text-xs md:grid-cols-3">
                            <UiInput name="candidate_name" label="Họ và tên thí sinh" :value="lead?.name" required placeholder="Họ và tên" class="font-bold" />
                            <UiInput name="candidate_phone" label="Số điện thoại liên hệ" :value="lead?.phone" required placeholder="VD: 0912 345 678" class="font-mono font-bold" />
                            <UiInput type="email" name="candidate_email" label="Email nhận bảng điểm" :value="lead?.email" placeholder="hocvien@gmail.com" />
                        </div>
                    </div>

                    <!-- Quy chế & nút bắt đầu: phần câu hỏi chỉ hiện sau khi vào chế độ thi -->
                    <div v-if="!started" id="exam-start-card" class="space-y-4 rounded-2xl border border-primary-container/40 bg-surface-container-lowest p-6 shadow-sm">
                        <h2 class="flex items-center gap-2 border-b border-surface-container-highest pb-2 text-sm font-bold uppercase tracking-wider text-on-surface">
                            <span class="material-symbols-outlined text-primary">shield_lock</span>
                            Quy chế làm bài
                        </h2>
                        <ul class="list-inside list-disc space-y-1.5 text-xs leading-relaxed text-on-surface-variant">
                            <li>Khi bấm <strong>Bắt đầu làm bài</strong>, bài thi mở ở chế độ <strong>toàn màn hình</strong>. Không thoát toàn màn hình, không chuyển sang tab, trang web hay ứng dụng khác cho tới khi nộp bài.</li>
                            <li>Bài thi không cho sao chép, dán, bấm chuột phải hay tải lại trang.</li>
                            <li>Mỗi lần rời khỏi bài thi đều được ghi lại và gửi kèm bài làm cho người chấm. Rời bài tới lần thứ <strong>{{ maxViolations }}</strong>, bài sẽ <strong>tự động nộp</strong> với các câu đã làm.</li>
                        </ul>
                        <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                            <span class="text-xs text-on-surface-variant">Điền họ tên và số điện thoại ở trên trước khi bắt đầu.</span>
                            <UiButton id="exam-start-btn" type="button" icon="play_circle" @click="start">Bắt đầu làm bài</UiButton>
                        </div>
                    </div>

                    <div id="exam-body" :class="started ? 'space-y-6' : 'hidden space-y-6'">
                        <!-- 2. Listening Section -->
                        <div v-if="listening.length" class="space-y-5 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
                            <div class="flex items-center justify-between border-b border-surface-container-highest pb-2">
                                <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-on-surface">
                                    <span class="material-symbols-outlined text-secondary">headphones</span>
                                    2. Section 1: Listening Comprehension ({{ listening.length }} câu)
                                </h2>
                                <UiBadge color="secondary" pill>Kỹ năng Nghe</UiBadge>
                            </div>

                            <div class="space-y-6">
                                <div v-for="q in listening" :key="q.answer_key" class="space-y-3 rounded-xl border border-surface-container-highest/80 bg-surface-container-low/50 p-4">
                                    <div class="flex items-start gap-2 text-xs font-bold text-on-surface">
                                        <span class="shrink-0 rounded-md bg-secondary/10 px-2 py-0.5 font-mono text-xs text-on-secondary-fixed">Câu {{ q.number }}</span>
                                        <span>{{ q.title }}</span>
                                    </div>

                                    <div v-if="q.audio_src" class="space-y-2 rounded-xl border border-secondary/30 bg-secondary/10 p-3.5 shadow-2xs">
                                        <div class="flex items-center justify-between text-xs font-bold text-on-secondary-fixed">
                                            <div class="flex items-center gap-1.5">
                                                <span class="material-symbols-outlined animate-pulse text-base text-secondary">volume_up</span>
                                                <span>Băng nghe Audio (Listening Track)</span>
                                            </div>
                                            <span class="text-xs font-semibold italic text-secondary">Bấm nút Play ▶ để nghe</span>
                                        </div>
                                        <audio controls class="h-9 w-full rounded-lg" preload="metadata" :src="q.audio_src">
                                            <source :src="q.audio_src" type="audio/mpeg" />
                                            Trình duyệt của bạn không hỗ trợ phát audio.
                                        </audio>
                                    </div>

                                    <div v-if="q.image_url" class="my-2">
                                        <img :src="q.image_url" alt="Question illustration" class="mx-auto max-h-64 rounded-xl border border-surface-container-highest bg-surface-container-lowest object-contain p-1" />
                                    </div>

                                    <div v-if="q.type === 'fill_blank'" class="pt-1">
                                        <UiInput :name="`answers[${q.answer_key}]`" placeholder="Nhập từ cần điền..." class="font-bold" />
                                    </div>
                                    <div v-else-if="q.options.length" :class="['grid grid-cols-1 gap-2.5 text-xs text-on-surface-variant', q.options.length > 2 ? 'sm:grid-cols-2 lg:grid-cols-3' : 'sm:grid-cols-2']">
                                        <label v-for="opt in q.options" :key="opt.key" class="flex cursor-pointer flex-col rounded-xl border border-surface-container-highest bg-surface-container-lowest p-2.5 transition hover:border-primary-container">
                                            <div class="mb-1.5 flex items-center gap-2">
                                                <input type="radio" :name="`answers[${q.answer_key}]`" :value="opt.key" class="text-primary focus:ring-primary-container" />
                                                <span class="font-bold text-on-surface">{{ opt.key }}.</span>
                                                <span>{{ opt.text }}</span>
                                            </div>
                                            <img v-if="opt.image_url" :src="opt.image_url" :alt="opt.key" class="mx-auto max-h-32 rounded-lg border border-surface-container-highest bg-surface-container-low object-contain p-1" />
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Reading & Grammar Section -->
                        <div v-if="reading.length" class="space-y-5 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
                            <div class="flex items-center justify-between border-b border-surface-container-highest pb-2">
                                <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-on-surface">
                                    <span class="material-symbols-outlined text-tertiary">menu_book</span>
                                    3. Section 2: Reading &amp; Grammar ({{ reading.length }} câu)
                                </h2>
                                <UiBadge color="success" pill>Đọc hiểu &amp; Ngữ pháp</UiBadge>
                            </div>

                            <div class="space-y-6">
                                <div v-for="q in reading" :key="q.answer_key" class="space-y-3 rounded-xl border border-surface-container-highest/80 bg-surface-container-low/50 p-4">
                                    <div class="flex items-start gap-2 text-xs font-bold text-on-surface">
                                        <span class="shrink-0 rounded-md bg-tertiary/10 px-2 py-0.5 font-mono text-xs text-on-tertiary-container">Câu {{ q.number }}</span>
                                        <span>{{ q.title }}</span>
                                    </div>

                                    <div v-if="q.passage" class="rounded-xl border border-tertiary/20 bg-tertiary/5 p-3.5 text-xs font-medium italic leading-relaxed text-on-surface">"{{ q.passage }}"</div>

                                    <div v-if="q.image_url" class="my-2">
                                        <img :src="q.image_url" alt="Illustration" class="mx-auto max-h-64 rounded-xl border border-surface-container-highest bg-surface-container-lowest object-contain p-1" />
                                    </div>

                                    <div v-if="q.type === 'fill_blank'" class="pt-1">
                                        <UiInput :name="`answers[${q.answer_key}]`" placeholder="Nhập câu trả lời..." class="font-bold" />
                                    </div>
                                    <div v-else-if="q.options.length" class="grid grid-cols-1 gap-2 text-xs text-on-surface-variant sm:grid-cols-2">
                                        <label v-for="opt in q.options" :key="opt.key" class="flex cursor-pointer items-center gap-2 rounded-xl border border-surface-container-highest bg-surface-container-lowest p-2.5 transition hover:border-tertiary">
                                            <input type="radio" :name="`answers[${q.answer_key}]`" :value="opt.key" class="text-primary focus:ring-primary-container" />
                                            <span class="font-bold text-on-surface">{{ opt.key }}.</span>
                                            <span>{{ opt.text }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 4. Writing Section -->
                        <div class="space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
                            <div class="flex items-center justify-between border-b border-surface-container-highest pb-2">
                                <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-on-surface">
                                    <span class="material-symbols-outlined text-accent">edit_note</span>
                                    4. Section 3: Writing Task (Viết tự luận)
                                </h2>
                                <span class="rounded-full bg-accent-container px-2.5 py-0.5 text-xs font-bold text-accent">Kỹ năng Viết</span>
                            </div>

                            <div class="space-y-1.5 rounded-xl border border-accent/30 bg-accent-container p-3.5 text-xs text-on-accent-container">
                                <div class="flex items-center gap-1.5 font-bold">
                                    <span class="material-symbols-outlined text-base">help</span>
                                    <span>Đề bài Writing:</span>
                                </div>
                                <p class="leading-relaxed">{{ writingPrompt }}</p>
                            </div>
                            <UiTextarea name="writing_content" rows="5" placeholder="Nhập bài viết của bạn tại đây..." class="leading-relaxed" />
                        </div>

                        <!-- 5. Speaking Section -->
                        <div class="space-y-4 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
                            <div class="flex items-center justify-between border-b border-surface-container-highest pb-2">
                                <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-on-surface">
                                    <span class="material-symbols-outlined text-error">mic</span>
                                    5. Section 4: Speaking &amp; Fluency (Nói &amp; Phản xạ)
                                </h2>
                                <UiBadge color="error" pill>Kỹ năng Nói</UiBadge>
                            </div>

                            <div v-if="speaking" class="space-y-1.5 rounded-xl border border-error/30 bg-error/5 p-3.5 text-xs text-on-error-container">
                                <div class="flex items-center gap-1 font-bold">
                                    <span class="material-symbols-outlined text-base">record_voice_over</span>
                                    <span>Gợi ý chủ đề Speaking: {{ speaking.title }}</span>
                                </div>
                                <div class="whitespace-pre-line font-mono text-xs leading-relaxed text-on-error-container">{{ speaking.cue_points }}</div>
                            </div>

                            <div class="space-y-2">
                                <div class="text-xs font-bold text-on-surface-variant">Tự đánh giá mức độ phản xạ nói tiếng Anh hiện tại:</div>
                                <div class="grid grid-cols-1 gap-3 text-xs sm:grid-cols-3">
                                    <label v-for="rate in selfRates" :key="rate.value" class="block cursor-pointer space-y-1 rounded-xl border border-surface-container-highest bg-surface-container-lowest p-3.5 transition hover:border-primary-container">
                                        <input type="radio" name="speaking_self_rate" :value="rate.value" :checked="rate.value === 'intermediate'" class="text-primary" />
                                        <div class="font-bold text-on-surface">{{ rate.title }}</div>
                                        <p class="text-xs text-on-surface-variant">{{ rate.text }}</p>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="flex items-center justify-between rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-6 shadow-sm">
                            <div class="text-xs text-on-surface-variant">Vui lòng kiểm tra lại câu trả lời trước khi gửi bài thi.</div>
                            <UiButton type="submit" icon="check_circle" class="shadow-lg hover:shadow-xl">Nộp bài</UiButton>
                        </div>
                    </div>
                </UiForm>
            </div>
        </main>

        <!-- Cảnh báo khi thí sinh rời bài thi -->
        <div id="exam-warning" :class="['fixed inset-0 z-[100] flex items-center justify-center bg-inverse-surface/90 p-4', showWarning ? '' : 'hidden']">
            <div class="w-full max-w-md space-y-4 rounded-2xl bg-surface-container-lowest p-6 text-center shadow-xl">
                <span class="material-symbols-outlined text-5xl text-error">warning</span>
                <h2 class="text-lg font-extrabold text-on-surface">Bạn vừa rời khỏi bài thi</h2>
                <p class="text-sm leading-relaxed text-on-surface-variant">
                    Đây là lần thứ <strong id="exam-warning-count">{{ log.length || 1 }}</strong>/{{ maxViolations }}. Lần rời bài đã được ghi lại cho người chấm. Tới lần thứ {{ maxViolations }}, bài sẽ tự động nộp.
                </p>
                <UiButton id="exam-resume-btn" type="button" icon="fullscreen" class="w-full justify-center" @click="resume">Quay lại làm bài</UiButton>
            </div>
        </div>

        <div id="exam-submitting" :class="['fixed inset-0 z-[110] flex items-center justify-center bg-inverse-surface/90 p-4', showSubmitting ? '' : 'hidden']">
            <div class="w-full max-w-md space-y-3 rounded-2xl bg-surface-container-lowest p-6 text-center shadow-xl">
                <span class="material-symbols-outlined text-5xl text-error">block</span>
                <h2 class="text-lg font-extrabold text-on-surface">Bài thi đã tự động nộp</h2>
                <p class="text-sm text-on-surface-variant">Bạn đã rời khỏi bài thi quá số lần cho phép. Đang gửi bài làm...</p>
            </div>
        </div>
    </div>
</template>
