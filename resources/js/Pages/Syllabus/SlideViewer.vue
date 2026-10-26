<script setup>
/**
 * Trình chiếu bài giảng PowerPoint của buổi (docs/kho-syllabus.md §7.4): iframe trình xem Office Online — giữ hiệu ứng, liên
 * kết nhảy slide (trò chơi chọn ô), trigger; không chạy macro VBA. Nút Toàn màn hình phóng khung trình chiếu ra cả màn hình.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';

defineOptions({ layout: { title: 'Trình chiếu bài giảng' } });

defineProps({
    title: { type: String, required: true },
    label: { type: String, required: true },
    name: { type: String, default: '' },
    embedUrl: { type: String, required: true },
    publicHost: { type: Boolean, default: true },
    downloadUrl: { type: String, default: null },
});

const frame = ref(null);
// Đang toàn màn hình: khung trình chiếu cao bằng cả màn hình (chiều cao thường trừ phần đầu trang sẽ để lại dải đen bên dưới).
const isFullscreen = ref(false);
const fullscreen = () => frame.value?.requestFullscreen?.();
const syncFullscreen = () => (isFullscreen.value = document.fullscreenElement === frame.value);
onMounted(() => document.addEventListener('fullscreenchange', syncFullscreen));
onBeforeUnmount(() => document.removeEventListener('fullscreenchange', syncFullscreen));
</script>

<template>
    <UiPageHeader :title="title" icon="co_present">
        <template #meta>{{ label }} · {{ name }}</template>
        <template #actions>
            <UiButton v-if="downloadUrl" variant="secondary" icon="download" :href="downloadUrl" native>Tải file</UiButton>
            <UiButton icon="fullscreen" @click="fullscreen">Toàn màn hình</UiButton>
        </template>
    </UiPageHeader>

    <UiAlert v-if="!publicHost" type="warning" class="mb-md">
        Trình xem Office Online của Microsoft chỉ đọc được file từ tên miền công khai — trên máy chạy thử (localhost / mạng nội bộ) khung dưới sẽ báo lỗi. Trên staging / production trình chiếu bình thường.
    </UiAlert>

    <div ref="frame" :class="['overflow-hidden bg-black', isFullscreen ? 'h-screen w-screen' : 'rounded-2xl border border-surface-container-highest shadow-sm']">
        <iframe
            :src="embedUrl"
            :title="`${label} — ${title}`"
            :class="['block w-full border-0', isFullscreen ? 'h-full' : 'h-[calc(100dvh-12rem)] min-h-[320px]']"
            allow="fullscreen"
            allowfullscreen
        />
    </div>
    <p class="mt-2 text-xs text-on-surface-subtle">Bấm vào khung trình chiếu rồi dùng phím mũi tên / chuột để chuyển slide, bấm các ô / nút trong slide như trên PowerPoint.</p>
</template>
