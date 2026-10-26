<script setup>
/**
 * Trình chiếu bài giảng PowerPoint của buổi (docs/kho-syllabus.md §7.4): iframe trình xem Office Online — giữ hiệu ứng, liên
 * kết nhảy slide (trò chơi chọn ô), trigger; không chạy macro VBA. Nút Toàn màn hình phóng khung trình chiếu ra cả màn hình.
 */
import { ref } from 'vue';

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
const fullscreen = () => frame.value?.requestFullscreen?.();
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

    <div ref="frame" class="overflow-hidden rounded-2xl border border-surface-container-highest bg-black shadow-sm">
        <iframe
            :src="embedUrl"
            :title="`${label} — ${title}`"
            class="block h-[calc(100dvh-12rem)] min-h-[320px] w-full border-0"
            allow="fullscreen"
            allowfullscreen
        />
    </div>
    <p class="mt-2 text-xs text-on-surface-subtle">Bấm vào khung trình chiếu rồi dùng phím mũi tên / chuột để chuyển slide, bấm các ô / nút trong slide như trên PowerPoint.</p>
</template>
