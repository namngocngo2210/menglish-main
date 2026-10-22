<script setup>
/**
 * Một trang PDF có lớp vẽ: canvas trang (pdf.js, kèm watermark nếu "Chỉ xem") + canvas nét vẽ + lớp tạm (xem trước, tẩy).
 * Chỉ vẽ khi trang ở gần vùng nhìn thấy, ra xa thì giải phóng canvas (sách 100+ trang không làm sập trình duyệt).
 * Nét vẽ do PdfAnnotator giữ; trang chỉ báo add / erase / clear.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { drawShape, drawWatermark, hitShape, toRatio } from '@/lib/annotations';

const props = defineProps({
    page: { type: Object, required: true },
    number: { type: Number, required: true },
    width: { type: Number, default: 0 },
    tool: { type: String, default: 'view' },
    color: { type: String, required: true },
    size: { type: Number, default: 2 },
    shapes: { type: Array, default: () => [] },
    root: { type: Object, default: null },
    watermark: { type: String, default: null },
});
const emit = defineEmits(['add', 'erase', 'clear']);

const base = props.page.getViewport({ scale: 1 });
const aspect = `${base.width} / ${base.height}`;
const pdfCanvas = ref(null);
const inkCanvas = ref(null);
const tempCanvas = ref(null);
let visible = false;
let renderTask = null;
let observers = [];
// Thao tác đang kéo: một con trỏ duy nhất (ngón tay thứ hai / lòng bàn tay chạm vào bị bỏ qua).
let active = null;

const drawing = computed(() => props.tool !== 'view');

async function renderPdf() {
    const canvas = pdfCanvas.value;
    if (!visible || !props.width || !canvas) return;
    renderTask?.cancel();
    const viewport = props.page.getViewport({ scale: (props.width / base.width) * Math.min(window.devicePixelRatio || 1, 2) });
    canvas.width = Math.floor(viewport.width);
    canvas.height = Math.floor(viewport.height);
    for (const layer of [inkCanvas.value, tempCanvas.value]) {
        layer.width = canvas.width;
        layer.height = canvas.height;
    }
    redraw();
    const task = props.page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport });
    renderTask = task;
    try {
        await task.promise;
    } catch {
        return; // huỷ do vẽ lại / giải phóng
    }
    if (renderTask !== task) return;
    renderTask = null;
    if (props.watermark) drawWatermark(canvas.getContext('2d'), ['MENGLISH INTERNAL ONLY', props.watermark], canvas.width, canvas.height);
}

function release() {
    visible = false;
    renderTask?.cancel();
    renderTask = null;
    for (const canvas of [pdfCanvas.value, inkCanvas.value, tempCanvas.value]) {
        if (canvas) canvas.width = canvas.height = 0;
    }
}

function redraw() {
    const canvas = inkCanvas.value;
    if (!canvas?.width) return;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    props.shapes.forEach((shape) => active?.erased?.has(shape) || drawShape(ctx, shape, canvas.width, canvas.height));
}

function clearTemp() {
    const canvas = tempCanvas.value;
    canvas?.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
}

function preview(shape) {
    clearTemp();
    drawShape(tempCanvas.value.getContext('2d'), shape, tempCanvas.value.width, tempCanvas.value.height);
}

function pointAt(event) {
    const rect = tempCanvas.value.getBoundingClientRect();
    return toRatio(event.clientX - rect.left, event.clientY - rect.top, rect.width, rect.height);
}

function eraseAt(point) {
    const rect = tempCanvas.value.getBoundingClientRect();
    const hits = props.shapes.filter((shape) => !active.erased.has(shape) && hitShape(shape, point, rect.width, rect.height));
    if (!hits.length) return;
    hits.forEach((shape) => active.erased.add(shape));
    redraw();
}

const stroke = () => ({ type: props.tool, color: props.color, width: props.size, points: active.points });

function onDown(event) {
    if (!drawing.value || active || (event.pointerType === 'mouse' && event.button !== 0)) return;
    event.preventDefault();
    event.currentTarget.setPointerCapture(event.pointerId);
    active = { id: event.pointerId, points: [pointAt(event)], erased: new Set() };
    if (props.tool === 'eraser') eraseAt(active.points[0]);
}

function onMove(event) {
    if (!active || event.pointerId !== active.id) return;
    const point = pointAt(event);
    if (props.tool === 'eraser') {
        eraseAt(point);
    } else if (['pen', 'highlighter'].includes(props.tool)) {
        active.points.push(point);
        preview(stroke());
    }
}

function onUp(event) {
    if (!active || event.pointerId !== active.id) return;
    const done = active;
    const shape = stroke();
    active = null;
    clearTemp();
    if (props.tool === 'eraser') {
        if (done.erased.size) emit('erase', [...done.erased]);
        else redraw();
    } else if (['pen', 'highlighter'].includes(props.tool) && done.points.length > 1) {
        emit('add', shape);
    }
}

function onCancel(event) {
    if (!active || event.pointerId !== active.id) return;
    active = null;
    clearTemp();
    redraw();
}

watch(() => props.shapes, redraw);
watch(() => props.width, renderPdf);
watch(() => props.tool, () => onCancel({ pointerId: active?.id }));

onMounted(() => {
    const options = (margin) => ({ root: props.root, rootMargin: `${margin} 0px` });
    const near = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting && !visible) {
            visible = true;
            renderPdf();
        }
    }, options('100%'));
    const far = new IntersectionObserver(([entry]) => entry.isIntersecting || release(), options('300%'));
    near.observe(pdfCanvas.value.parentElement);
    far.observe(pdfCanvas.value.parentElement);
    observers = [near, far];
});

onBeforeUnmount(() => {
    observers.forEach((observer) => observer.disconnect());
    release();
});
</script>

<template>
    <div class="relative w-full overflow-hidden rounded-md bg-white shadow-sm" :style="{ aspectRatio: aspect }" :aria-label="`Trang ${number}`">
        <canvas ref="pdfCanvas" class="absolute inset-0 h-full w-full" />
        <canvas ref="inkCanvas" class="pointer-events-none absolute inset-0 h-full w-full" />
        <canvas
            ref="tempCanvas"
            :class="['absolute inset-0 h-full w-full', drawing ? 'cursor-crosshair touch-none' : 'pointer-events-none']"
            @pointerdown="onDown"
            @pointermove="onMove"
            @pointerup="onUp"
            @pointercancel="onCancel"
        />
        <button
            v-if="shapes.length"
            type="button"
            class="absolute right-2 top-2 inline-flex items-center gap-1 rounded-lg border border-outline-variant bg-surface-container-lowest/90 px-2 py-1 text-xs text-on-surface-variant shadow-sm hover:text-error"
            title="Xoá nét trang này"
            @click="emit('clear')"
        >
            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">ink_eraser</span>Xoá nét trang này
        </button>
    </div>
</template>
