<script setup>
/**
 * Xem PDF và vẽ lên trang để giảng bài (bút, highlight, tẩy, hoàn tác / làm lại, toàn màn hình).
 *   <PdfAnnotator :src="fileUrl" :title="doc.title" :watermark="doc.watermark" />
 * Nét vẽ chỉ nằm trong bộ nhớ: đóng / đổi tài liệu / tải lại trang là mất, không lưu vào giáo trình.
 * watermark khác null (tài liệu "Chỉ xem") → vẽ watermark định danh vào từng trang.
 * Mỗi lần nét vẽ thay đổi phát sự kiện DOM `input` (bubble) để UiModal hỏi trước khi đóng.
 */
import { computed, markRaw, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { COLORS, createHistory } from '@/lib/annotations';
import { confirmDialog } from '@/lib/confirm';
import { openPdf } from '@/lib/pdf';
import AnnotatorPage from './AnnotatorPage.vue';
import AnnotatorToolbar from './AnnotatorToolbar.vue';

const props = defineProps({
    src: { type: String, default: null },
    title: { type: String, default: '' },
    watermark: { type: String, default: null },
});

const TOOLS = ['view', 'pen', 'highlighter', 'eraser'];

const root = ref(null);
const scroller = ref(null);
const list = ref(null);
const pages = shallowRef([]);
const docKey = ref(0);
const status = ref('loading');
const width = ref(0);
const fullscreen = ref(false);

const tool = ref('view');
const size = ref(2);
// Bút và highlight nhớ màu riêng; highlight mặc định vàng.
const penColor = ref(COLORS[1].value);
const highlightColor = ref(COLORS[4].value);
const color = computed({
    get: () => (tool.value === 'highlighter' ? highlightColor.value : penColor.value),
    set: (value) => (tool.value === 'highlighter' ? (highlightColor.value = value) : (penColor.value = value)),
});

let history = createHistory();
// history là đối tượng JS thường: rev tăng sau mỗi thao tác để Vue tính lại.
const rev = ref(0);
const shapesOn = (number) => (rev.value, history.shapesOn(number));
const canUndo = computed(() => (rev.value, history.canUndo()));
const canRedo = computed(() => (rev.value, history.canRedo()));
const hasAny = computed(() => (rev.value, !history.isEmpty()));

function changed() {
    rev.value++;
    root.value?.dispatchEvent(new Event('input', { bubbles: true }));
}

function add(number, shape) {
    history.add(number, shape);
    changed();
}
function erase(number, shapes) {
    history.remove(number, shapes);
    changed();
}
function clearPage(number) {
    history.clear(number);
    changed();
}
async function clearAll() {
    if (!(await confirmDialog({ title: 'Xoá tất cả nét vẽ?', message: 'Có thể hoàn tác.', confirmLabel: 'Xoá', danger: true }))) return;
    history.clearAll();
    changed();
}
function undo() {
    if (history.undo()) changed();
}
function redo() {
    if (history.redo()) changed();
}

let task = null;
let renderToken = 0;

async function load() {
    const token = ++renderToken;
    task?.destroy();
    task = null;
    pages.value = [];
    history = createHistory();
    rev.value++;
    if (!props.src) {
        status.value = 'empty';
        return;
    }
    status.value = 'loading';
    try {
        const loading = await openPdf(props.src);
        if (token !== renderToken) return loading.destroy();
        task = loading;
        const doc = await task.promise;
        const list = await Promise.all(Array.from({ length: doc.numPages }, (_, i) => doc.getPage(i + 1)));
        if (token !== renderToken) return;
        docKey.value = token;
        pages.value = list.map((page) => markRaw(page));
        status.value = 'ready';
    } catch {
        if (token === renderToken) status.value = 'error';
    }
}

function toggleFullscreen() {
    if (document.fullscreenElement) document.exitFullscreen?.();
    else root.value?.requestFullscreen?.();
}
function onFullscreenChange() {
    fullscreen.value = document.fullscreenElement === root.value;
}

let resizeObserver = null;
let resizeTimer = null;
function measure() {
    width.value = list.value?.clientWidth || 0;
}

onMounted(() => {
    measure();
    resizeObserver = new ResizeObserver(() => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(measure, 150);
    });
    resizeObserver.observe(list.value);
    document.addEventListener('fullscreenchange', onFullscreenChange);
    load();
});
watch(() => props.src, load);
onBeforeUnmount(() => {
    renderToken++;
    task?.destroy();
    resizeObserver?.disconnect();
    clearTimeout(resizeTimer);
    document.removeEventListener('fullscreenchange', onFullscreenChange);
});
</script>

<template>
    <div ref="root" data-pdf-annotator :class="['flex flex-col overflow-hidden rounded-lg border border-surface-container-highest bg-surface-container', { 'h-full': fullscreen }]">
        <AnnotatorToolbar
            v-model:tool="tool"
            v-model:color="color"
            v-model:size="size"
            class="shrink-0 border-b border-surface-container-highest"
            :tools="TOOLS"
            :can-undo="canUndo"
            :can-redo="canRedo"
            :has-any="hasAny"
            :fullscreen="fullscreen"
            @undo="undo"
            @redo="redo"
            @clear-all="clearAll"
            @toggle-fullscreen="toggleFullscreen"
        />
        <div ref="scroller" :class="['min-h-0 overflow-y-auto p-3', fullscreen ? 'flex-1' : 'h-[65vh]']">
            <div v-if="status === 'loading'" class="flex items-center justify-center gap-2 p-8 text-xs text-on-surface-variant">
                <span class="material-symbols-outlined animate-spin text-[18px]" aria-hidden="true">progress_activity</span>
                Đang mở tài liệu…
            </div>
            <p v-else-if="status === 'error'" class="rounded-xl border border-error/30 bg-error/5 p-4 text-center text-xs text-on-error-container">Không hiển thị được file PDF trên trình duyệt này.</p>
            <div ref="list" :class="['mx-auto space-y-3', { 'max-w-5xl': fullscreen }]" :aria-label="title">
                <AnnotatorPage
                    v-for="(page, i) in pages"
                    :key="`${docKey}-${i}`"
                    :page="page"
                    :number="i + 1"
                    :width="width"
                    :tool="tool"
                    :color="color"
                    :size="size"
                    :shapes="shapesOn(i + 1)"
                    :root="scroller"
                    :watermark="watermark"
                    @add="add(i + 1, $event)"
                    @erase="erase(i + 1, $event)"
                    @clear="clearPage(i + 1)"
                />
            </div>
        </div>
    </div>
</template>
