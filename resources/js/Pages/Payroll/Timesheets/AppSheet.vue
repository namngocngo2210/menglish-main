<script setup>
/**
 * Chấm công AppSheet: nhúng ứng dụng AppSheet chấm công trong khung; "Toàn màn hình" bật / tắt fullscreen cho khung nhúng.
 */
import { ref } from 'vue';

defineOptions({ layout: { title: 'Chấm công AppSheet' } });

const APPSHEET_URL = 'https://www.appsheet.com/start/00cb153e-2abe-4a50-bc50-36f9be53a422';
const frame = ref(null);

function toggleAppsheetFullscreen() {
    const iframe = frame.value;
    if (!iframe) return;
    if (!document.fullscreenElement) {
        if (iframe.requestFullscreen) iframe.requestFullscreen();
        else if (iframe.webkitRequestFullscreen) iframe.webkitRequestFullscreen();
        else if (iframe.msRequestFullscreen) iframe.msRequestFullscreen();
    } else if (document.exitFullscreen) {
        document.exitFullscreen();
    }
}
</script>

<template>
    <div>
        <UiPageHeader title="Chấm công AppSheet" icon="schedule">
            <template #actions>
                <UiButton variant="secondary" icon="open_in_new" :href="APPSHEET_URL" native target="_blank" rel="noopener noreferrer">Mở tab mới</UiButton>
                <UiButton icon="fullscreen" @click="toggleAppsheetFullscreen">Toàn màn hình</UiButton>
            </template>
        </UiPageHeader>

        <!-- Khung nhúng AppSheet -->
        <div class="relative flex h-[calc(100vh-175px)] min-h-[650px] flex-col overflow-hidden rounded-3xl border border-surface-container-highest bg-surface-container-lowest shadow-sm">
            <iframe
                id="appsheet-frame"
                ref="frame"
                title="Bảng chấm công AppSheet"
                name="preview-frame"
                allowfullscreen="true"
                frameborder="0"
                :src="APPSHEET_URL"
                class="h-full w-full flex-1 rounded-2xl border-0"
                style="width: 100%; height: 100%; min-height: 600px; border: none"
            ></iframe>
        </div>
    </div>
</template>
