<script setup>
/**
 * Luyện phát âm (MH #4): bài nghe của lớp (file nghe GV đính kèm bài tập), ghi âm thật bằng micro (MediaRecorder) —
 * file ghi âm gắn vào ô file ẩn của form nộp bài — và lịch sử bài đã nộp (giáo viên chấm).
 */
import { computed, onBeforeUnmount, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import PortalBottomNav from './PortalBottomNav.vue';
import PortalPageHeader from './PortalPageHeader.vue';
import PortalTopHeader from './PortalTopHeader.vue';

defineOptions({ layout: { title: 'Luyện phát âm', workspaceTabs: false } });

const props = defineProps({
    student: { type: Object, default: null },
    students: { type: Array, default: () => [] },
    unreadCount: { type: Number, default: 0 },
    history: { type: Array, default: () => [] },
    practiceItems: { type: Array, default: () => [] },
});

const params = computed(() => ({ studentId: props.student?.id ?? null }));

const selectedUnit = ref('');
const audioFile = ref(null);
const isRecording = ref(false);
const hasRecording = ref(false);
const previewUrl = ref(null);
const timerSeconds = ref(0);
const error = ref('');
let recorder = null;
let chunks = [];
let intervalId = null;

const timerText = computed(() => {
    const m = String(Math.floor(timerSeconds.value / 60)).padStart(2, '0');
    const s = String(timerSeconds.value % 60).padStart(2, '0');
    return `${m}:${s}`;
});

async function toggleRecording() {
    if (isRecording.value) {
        recorder?.stop();
        return;
    }
    error.value = '';
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
        error.value = 'Trình duyệt không hỗ trợ ghi âm. Hãy dùng Chrome, Edge hoặc Safari bản mới.';
        return;
    }
    let stream;
    try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch {
        error.value = 'Chưa được cấp quyền dùng micro. Hãy cho phép micro rồi thử lại.';
        return;
    }
    chunks = [];
    recorder = new MediaRecorder(stream);
    recorder.ondataavailable = (event) => {
        if (event.data.size > 0) chunks.push(event.data);
    };
    recorder.onstop = () => {
        stream.getTracks().forEach((track) => track.stop());
        clearInterval(intervalId);
        isRecording.value = false;
        attachRecording();
    };
    timerSeconds.value = 0;
    recorder.start();
    isRecording.value = true;
    intervalId = setInterval(() => timerSeconds.value++, 1000);
}

function attachRecording() {
    const type = recorder?.mimeType || 'audio/webm';
    const blob = new Blob(chunks, { type });
    const extension = type.includes('mp4') ? 'm4a' : type.includes('ogg') ? 'ogg' : 'webm';
    const transfer = new DataTransfer();
    transfer.items.add(new File([blob], `ghi-am.${extension}`, { type }));
    audioFile.value.files = transfer.files;
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = URL.createObjectURL(blob);
    hasRecording.value = blob.size > 0;
}

/** Nộp xong: bỏ bản ghi vừa gửi để không nộp trùng. */
function onSubmitted() {
    hasRecording.value = false;
    timerSeconds.value = 0;
    if (audioFile.value) audioFile.value.value = '';
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = null;
}

onBeforeUnmount(() => {
    clearInterval(intervalId);
    if (recorder?.state === 'recording') recorder.stop();
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
});
</script>

<template>
    <PortalPageHeader title="Luyện phát âm" icon="mic" :back="route('portal.student.homework', params)">
        <template #actions>
            <UiButton variant="secondary" icon="assignment" :href="route('portal.student.homework', params)">Xem bài tập viết</UiButton>
        </template>
    </PortalPageHeader>

    <!-- Khung điện thoại -->
    <div class="relative mx-auto my-4 flex min-h-[844px] max-w-[430px] flex-col overflow-hidden rounded-3xl border border-surface-container-highest bg-surface-container-low pb-24 shadow-2xl md:min-h-0 md:max-w-4xl md:pb-6 md:shadow-sm">
        <PortalTopHeader :student="student" :students="students" title="Luyện phát âm" show-back :back-url="route('portal.student.homework', params)" />

        <div class="flex items-center border-b border-surface-container-highest bg-surface-container-lowest px-3 pt-2">
            <Link :href="route('portal.student.homework', params)" class="flex items-center gap-1.5 border-b-2 border-transparent px-4 py-2 text-xs font-semibold text-on-surface-variant transition hover:text-on-surface">
                <span class="material-symbols-outlined text-[16px]">assignment</span>
                <span>Nộp bài tập</span>
            </Link>
            <Link :href="route('portal.student.pronunciation', params)" class="flex items-center gap-1.5 border-b-2 border-primary-container px-4 py-2 text-xs font-bold text-primary">
                <span class="material-symbols-outlined text-[16px]">mic</span>
                <span>Luyện phát âm</span>
            </Link>
        </div>

        <div class="w-full flex-1 space-y-4 overflow-y-auto p-4">
            <!-- 1. Bài nghe mẫu = file nghe giáo viên đính kèm bài tập của lớp -->
            <section class="space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-2xs">
                <div>
                    <h2 class="text-sm font-bold text-on-surface">Bài nghe của lớp</h2>
                    <p class="mt-0.5 text-xs text-on-surface-variant">File nghe giáo viên gửi kèm bài tập. Nghe, chọn bài rồi thu âm theo.</p>
                </div>

                <div class="space-y-2">
                    <div v-for="(item, i) in practiceItems" :key="i" :class="['rounded-xl border p-2.5 transition', selectedUnit === item.title ? 'border-primary-container/40 bg-primary-container/10' : 'border-surface-container-highest']">
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-xs font-semibold text-on-surface">{{ item.title }}</p>
                                <p class="text-xs text-on-surface-subtle">{{ item.class_name }}</p>
                            </div>
                            <UiButton variant="ghost" size="sm" class="text-primary" @click="selectedUnit = item.title">Chọn</UiButton>
                        </div>
                        <audio controls preload="none" class="mt-2 h-8 w-full" :src="item.audio_url"></audio>
                    </div>
                    <UiEmptyState v-if="!practiceItems.length" icon="headphones" title="Lớp chưa có file nghe" description="Khi giáo viên gửi bài tập kèm file nghe, bài sẽ hiện ở đây. Bạn vẫn có thể tự đặt tên bài và thu âm." />
                </div>
            </section>

            <!-- 2. Ghi âm (micro thật) + nộp bài -->
            <section class="relative flex flex-col items-center justify-center space-y-4 overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-5 text-center shadow-2xs">
                <UiForm :action="route('portal.student.pronunciation.store')" method="post" class="relative z-10 w-full space-y-3 text-left" @success="onSubmitted" #default="{ errors }">
                    <input type="hidden" name="student_id" :value="student?.id" />
                    <input type="hidden" name="duration" :value="timerText" />
                    <input ref="audioFile" type="file" name="audio_file" class="hidden" accept="audio/*" />

                    <UiInput v-model="selectedUnit" name="unit_title" label="Bài đang luyện" required maxlength="255" placeholder="Ví dụ: Unit 1 - Greetings" />

                    <!-- Đồng hồ -->
                    <div class="text-center font-mono text-3xl font-bold tracking-wider text-on-surface">{{ timerText }}</div>

                    <!-- Sóng âm khi đang ghi -->
                    <div v-show="isRecording" class="mx-auto flex h-12 w-full max-w-[200px] items-center justify-center gap-1.5 py-1">
                        <div class="h-4 w-1 animate-pulse rounded-full bg-primary-container" style="animation-delay: 0.1s"></div>
                        <div class="h-8 w-1 animate-pulse rounded-full bg-primary-container" style="animation-delay: 0.3s"></div>
                        <div class="h-11 w-1 animate-pulse rounded-full bg-primary-container" style="animation-delay: 0.2s"></div>
                        <div class="h-6 w-1 animate-pulse rounded-full bg-primary-container" style="animation-delay: 0.5s"></div>
                        <div class="h-10 w-1 animate-pulse rounded-full bg-primary-container" style="animation-delay: 0.4s"></div>
                        <div class="h-7 w-1 animate-pulse rounded-full bg-primary-container" style="animation-delay: 0.2s"></div>
                        <div class="h-9 w-1 animate-pulse rounded-full bg-primary-container" style="animation-delay: 0.6s"></div>
                        <div class="h-5 w-1 animate-pulse rounded-full bg-primary-container" style="animation-delay: 0.3s"></div>
                        <div class="h-12 w-1 animate-pulse rounded-full bg-primary-container" style="animation-delay: 0.1s"></div>
                        <div class="h-6 w-1 animate-pulse rounded-full bg-primary-container" style="animation-delay: 0.4s"></div>
                    </div>

                    <!-- Nút ghi âm -->
                    <div class="flex justify-center py-2">
                        <button type="button" :aria-label="isRecording ? 'Dừng ghi âm' : 'Bắt đầu ghi âm'" class="relative flex h-20 w-20 transform items-center justify-center rounded-full bg-primary-container text-white shadow-xl transition-all hover:scale-105 hover:bg-primary focus:outline-none focus:ring-4 focus:ring-primary-container/30 active:scale-95" @click="toggleRecording">
                            <div v-show="isRecording" class="absolute inset-0 animate-ping rounded-full border-2 border-primary-container opacity-75"></div>
                            <span class="material-symbols-outlined text-3xl" style="font-variation-settings: 'FILL' 1">{{ isRecording ? 'stop' : 'mic' }}</span>
                        </button>
                    </div>
                    <p class="text-center text-xs text-on-surface-variant">{{ isRecording ? 'Chạm để dừng ghi âm' : hasRecording ? 'Nghe lại bên dưới, hoặc chạm micro để ghi lại' : 'Chạm micro để bắt đầu ghi âm' }}</p>
                    <p v-show="error" class="text-xs font-semibold text-error">{{ error }}</p>
                    <p v-if="errors.audio_file" class="text-xs font-semibold text-error">{{ errors.audio_file }}</p>

                    <audio v-show="hasRecording && !isRecording" controls class="h-8 w-full" :src="previewUrl ?? undefined"></audio>

                    <div class="flex justify-center pt-2">
                        <UiButton type="submit" icon="send" class="w-full max-w-[240px]" :disabled="!hasRecording || isRecording">
                            <span>Nộp bài ghi âm</span>
                        </UiButton>
                    </div>
                </UiForm>
            </section>

            <!-- 3. Lịch sử luyện tập -->
            <section class="space-y-3 rounded-2xl border border-surface-container-highest bg-surface-container-lowest p-4 shadow-2xs">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-bold text-on-surface">Lịch sử của bạn</h2>
                    <span class="text-xs font-medium text-on-surface-subtle">Giáo viên chấm điểm</span>
                </div>

                <div class="space-y-2">
                    <div v-for="rec in history" :key="rec.id" class="flex items-center justify-between rounded-xl border border-surface-container-highest/80 bg-surface-container-low p-3 transition hover:bg-surface-container">
                        <div>
                            <p class="text-xs font-bold text-on-surface">{{ rec.title }}</p>
                            <div class="mt-1 flex items-center gap-2 text-xs text-on-surface-variant">
                                <span class="flex items-center gap-0.5">
                                    <span class="material-symbols-outlined text-[13px]">calendar_today</span>
                                    {{ rec.submitted_at }}
                                </span>
                                <span>•</span>
                                <span class="flex items-center gap-0.5 font-mono">
                                    <span class="material-symbols-outlined text-[13px]">timer</span>
                                    {{ rec.duration ?? '—' }}
                                </span>
                            </div>
                            <template v-if="rec.status === 'reviewed' && rec.score">
                                <UiBadge color="success" pill class="mt-1">Giáo viên chấm: {{ rec.score }}</UiBadge>
                                <p v-if="rec.feedback" class="mt-1 text-xs italic text-on-surface-variant">"{{ rec.feedback }}"</p>
                            </template>
                            <UiBadge v-else color="warning" pill class="mt-1">Chờ giáo viên chấm</UiBadge>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <a v-if="rec.audio_path" :href="rec.audio_path" target="_blank" rel="noopener" title="Nghe lại" aria-label="Nghe lại" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-primary hover:bg-primary-container/10">
                                <span class="material-symbols-outlined text-[18px]">play_arrow</span>
                            </a>
                            <UiForm :action="route('portal.student.pronunciation.destroy', rec.id)" method="delete" confirm="Xóa bản ghi âm này?" confirm-label="Xóa" danger>
                                <UiButton type="submit" variant="ghost" size="sm" icon="delete" title="Xóa bản ghi" aria-label="Xóa bản ghi" />
                            </UiForm>
                        </div>
                    </div>
                    <UiEmptyState v-if="!history.length" icon="mic" title="Chưa có bài ghi âm" description="Chọn bài, thu âm và bấm Nộp bài — giáo viên sẽ chấm và phản hồi." />
                </div>
            </section>
        </div>

        <PortalBottomNav active-tab="pronunciation" :student="student" :unread-count="unreadCount" />
    </div>
</template>
