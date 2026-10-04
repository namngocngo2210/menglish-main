<script setup>
/**
 * Hiện file đề PDF ngay trong trang: pdf.js vẽ từng trang thành ảnh theo bề rộng khung (đọc được trên điện thoại,
 * không mở tab mới — trang làm bài khoá chuyển tab). pdf.js chỉ tải khi trang có đề PDF; lỗi → link mở file.
 *   <PdfViewer :src="test.pdf_url" />
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    src: { type: String, default: null },
    // Cho mở file ở tab mới khi không vẽ được (trang làm bài tắt đi vì rời trang bị tính vi phạm).
    allowOpen: { type: Boolean, default: true },
});

const pages = ref(null);
const status = ref('loading');
const pageCount = ref(0);
let task = null;
let renderToken = 0;

async function render() {
    const token = ++renderToken;
    task?.destroy();
    task = null;
    pageCount.value = 0;
    if (pages.value) pages.value.innerHTML = '';
    if (!props.src) {
        status.value = 'empty';
        return;
    }
    status.value = 'loading';
    try {
        const [pdfjs, worker] = await Promise.all([import('pdfjs-dist/legacy/build/pdf.mjs'), import('pdfjs-dist/legacy/build/pdf.worker.min.mjs?worker')]);
        // Worker do Vite đóng gói thành file .js (hosting không phải lúc nào cũng trả đúng kiểu cho file .mjs).
        if (!pdfjs.GlobalWorkerOptions.workerPort) pdfjs.GlobalWorkerOptions.workerPort = new worker.default();
        // isEvalSupported:false: không cho pdf.js dựng hàm bằng eval từ nội dung PDF (file đề do người dùng tải lên).
        task = pdfjs.getDocument({ url: props.src, isEvalSupported: false });
        const doc = await task.promise;
        if (token !== renderToken) return;
        pageCount.value = doc.numPages;
        const width = pages.value?.clientWidth || 800;
        const ratio = Math.min(window.devicePixelRatio || 1, 2);
        for (let n = 1; n <= doc.numPages; n++) {
            const page = await doc.getPage(n);
            if (token !== renderToken || !pages.value) return;
            const viewport = page.getViewport({ scale: (width / page.getViewport({ scale: 1 }).width) * ratio });
            const canvas = document.createElement('canvas');
            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);
            canvas.className = 'block h-auto w-full rounded-lg border border-surface-container-highest bg-white shadow-xs';
            canvas.setAttribute('aria-label', `Trang ${n}/${doc.numPages}`);
            pages.value.appendChild(canvas);
            await page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport }).promise;
            if (n === 1) status.value = 'ready';
        }
        status.value = 'ready';
    } catch (error) {
        if (token === renderToken) status.value = 'error';
    }
}

onMounted(render);
watch(() => props.src, render);
onBeforeUnmount(() => {
    renderToken++;
    task?.destroy();
});
</script>

<template>
    <div class="space-y-2">
        <div v-if="status === 'loading'" class="flex items-center justify-center gap-2 rounded-xl border border-dashed border-outline-variant p-8 text-xs text-on-surface-variant">
            <span class="material-symbols-outlined animate-spin text-[18px]" aria-hidden="true">progress_activity</span>
            Đang mở đề PDF…
        </div>
        <div v-else-if="status === 'error'" class="space-y-2 rounded-xl border border-error/30 bg-error/5 p-4 text-center text-xs text-on-error-container">
            <p>Không hiển thị được file PDF trên trình duyệt này.</p>
            <a v-if="allowOpen && src" :href="src" target="_blank" rel="noopener" class="font-bold text-primary underline">Mở file PDF</a>
        </div>
        <div ref="pages" class="space-y-3" />
        <p v-if="status === 'ready' && pageCount" class="text-center text-xs text-on-surface-variant">{{ pageCount }} trang</p>
    </div>
</template>
