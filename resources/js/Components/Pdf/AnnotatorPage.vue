<script setup>
/**
 * Một trang PDF có lớp vẽ: canvas trang (pdf.js, kèm watermark nếu "Chỉ xem") + canvas nét vẽ + lớp tạm (xem trước,
 * tẩy, laser). Chỉ vẽ khi trang ở gần vùng nhìn thấy, ra xa thì giải phóng canvas (sách 100+ trang không làm sập
 * trình duyệt). Nét vẽ do PdfAnnotator giữ; trang chỉ báo add / erase / clear.
 */
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { drawShape, drawWatermark, FONT_FAMILY, fontPx, hitShape, SHAPE_TOOLS, toRatio, unit } from '@/lib/annotations';

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

const LASER_COLOR = '#ef4444';
const LASER_MS = 1500;

const base = props.page.getViewport({ scale: 1 });
const aspect = `${base.width} / ${base.height}`;
const pdfCanvas = ref(null);
const inkCanvas = ref(null);
const tempCanvas = ref(null);
const textInput = ref(null);
// Ô chữ đang gõ: { point: [x, y] (tỉ lệ), value }.
const textBox = ref(null);
let visible = false;
let renderTask = null;
let observers = [];
// Thao tác đang kéo: một con trỏ duy nhất (ngón tay thứ hai / lòng bàn tay chạm vào bị bỏ qua).
let active = null;
// Con trỏ đang vẽ của cả tài liệu (PdfAnnotator cung cấp).
const sharedPointer = inject('annotatorPointer', { id: null });
let laser = [];
let laserFrame = null;

const drawing = computed(() => props.tool !== 'view');
const freehand = computed(() => ['pen', 'highlighter'].includes(props.tool));
const textStyle = computed(() => ({
    left: `${textBox.value.point[0] * 100}%`,
    top: `${textBox.value.point[1] * 100}%`,
    fontSize: `${fontPx(props.size, props.width)}px`,
    fontFamily: FONT_FAMILY,
    color: props.color,
}));

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
    // Bỏ ảnh / font pdf.js đã giải mã của trang (không tự dọn với intent hiển thị).
    props.page.cleanup();
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

function rect() {
    return tempCanvas.value.getBoundingClientRect();
}

function pointAt(event) {
    const r = rect();
    return toRatio(event.clientX - r.left, event.clientY - r.top, r.width, r.height);
}

// Khoảng cách hai điểm (tỉ lệ) tính bằng px CSS.
function distancePx([x1, y1], [x2, y2]) {
    const r = rect();
    return Math.hypot((x2 - x1) * r.width, (y2 - y1) * r.height);
}

function eraseAt(point) {
    const r = rect();
    const hits = props.shapes.filter((shape) => !active.erased.has(shape) && hitShape(shape, point, r.width, r.height));
    if (!hits.length) return;
    hits.forEach((shape) => active.erased.add(shape));
    redraw();
}

const stroke = () => ({ type: props.tool, color: props.color, width: props.size, points: active.points });

// ---- Laser: vệt đỏ mờ dần, không lưu ----

function drawLaser() {
    const canvas = tempCanvas.value;
    const now = performance.now();
    laser = laser.filter((p) => now - p.t < LASER_MS);
    if (!canvas?.width) {
        laser = [];
    } else {
        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        const u = unit(canvas.width);
        ctx.save();
        ctx.lineCap = ctx.lineJoin = 'round';
        ctx.strokeStyle = ctx.fillStyle = LASER_COLOR;
        for (let i = 1; i < laser.length; i++) {
            ctx.globalAlpha = 1 - (now - laser[i].t) / LASER_MS;
            ctx.lineWidth = 4 * u;
            ctx.beginPath();
            ctx.moveTo(laser[i - 1].x * canvas.width, laser[i - 1].y * canvas.height);
            ctx.lineTo(laser[i].x * canvas.width, laser[i].y * canvas.height);
            ctx.stroke();
        }
        const head = laser.at(-1);
        if (head && now - head.t < 150) {
            ctx.globalAlpha = 1;
            ctx.beginPath();
            ctx.arc(head.x * canvas.width, head.y * canvas.height, 6 * u, 0, Math.PI * 2);
            ctx.fill();
        }
        ctx.restore();
    }
    laserFrame = laser.length ? requestAnimationFrame(drawLaser) : null;
}

function pushLaser([x, y]) {
    laser.push({ x, y, t: performance.now() });
    laserFrame ??= requestAnimationFrame(drawLaser);
}

// ---- Chữ ----

function openText(point) {
    textBox.value = { point, value: '' };
    nextTick(() => textInput.value?.focus());
}

// Sau Enter / Esc ô chữ biến mất: trả focus về khung để Ctrl+Z vẫn chạy.
function focusAnnotator() {
    pdfCanvas.value?.closest('[data-pdf-annotator]')?.focus({ preventScroll: true });
}

function commitText() {
    const box = textBox.value;
    textBox.value = null;
    const text = box?.value.trim();
    const canvas = inkCanvas.value;
    if (!text || !canvas?.width) return;
    const ctx = canvas.getContext('2d');
    const px = fontPx(props.size, canvas.width);
    ctx.font = `${px}px ${FONT_FAMILY}`;
    const boxSize = [ctx.measureText(text).width / canvas.width, (px * 1.25) / canvas.height];
    emit('add', { type: 'text', color: props.color, width: props.size, points: [box.point], text, box: boxSize });
}

function cancelText(event) {
    // Esc chỉ huỷ ô chữ, không đóng hộp thoại tài liệu.
    event.preventDefault();
    event.stopPropagation();
    textBox.value = null;
    focusAnnotator();
}

// ---- Con trỏ ----

function onDown(event) {
    if (!drawing.value || active || sharedPointer.id !== null || (event.pointerType === 'mouse' && event.button !== 0)) return;
    event.preventDefault();
    if (textBox.value) {
        commitText();
        return;
    }
    event.currentTarget.setPointerCapture(event.pointerId);
    sharedPointer.id = event.pointerId;
    active = { id: event.pointerId, points: [pointAt(event)], erased: new Set() };
    if (props.tool === 'eraser') eraseAt(active.points[0]);
    if (props.tool === 'laser') pushLaser(active.points[0]);
}

function onMove(event) {
    const point = pointAt(event);
    // Laser đi theo chuột cả khi không bấm; cảm ứng / bút chỉ khi đang chạm.
    if (props.tool === 'laser' && (event.pointerType === 'mouse' || active?.id === event.pointerId)) {
        pushLaser(point);
        return;
    }
    if (!active || event.pointerId !== active.id) return;
    if (props.tool === 'eraser') {
        eraseAt(point);
    } else if (freehand.value) {
        active.points.push(point);
        preview(stroke());
    } else if (SHAPE_TOOLS.includes(props.tool)) {
        active.points = [active.points[0], point];
        preview(stroke());
    } else if (props.tool === 'text') {
        active.points = [active.points[0], point];
    }
}

function onUp(event) {
    if (!active || event.pointerId !== active.id) return;
    const done = active;
    const shape = stroke();
    active = null;
    sharedPointer.id = null;
    if (props.tool === 'laser') return;
    clearTemp();
    const moved = done.points.length > 1 && distancePx(done.points[0], done.points.at(-1)) > 4;
    if (props.tool === 'eraser') {
        if (done.erased.size) emit('erase', [...done.erased]);
        else redraw();
    } else if (freehand.value && done.points.length > 1) {
        emit('add', shape);
    } else if (SHAPE_TOOLS.includes(props.tool) && moved) {
        emit('add', shape);
    } else if (props.tool === 'text' && !moved) {
        openText(done.points[0]);
    }
}

// Chuột rời trang: tắt vệt laser của trang này.
function onLeave() {
    if (props.tool !== 'laser' || active) return;
    laser = [];
    clearTemp();
}

function onCancel(event) {
    if (!active || event.pointerId !== active.id) return;
    active = null;
    sharedPointer.id = null;
    clearTemp();
    redraw();
}

watch(() => props.shapes, redraw);
watch(() => props.width, renderPdf);
watch(
    () => props.tool,
    () => {
        if (textBox.value) commitText();
        onCancel({ pointerId: active?.id });
        laser = [];
        clearTemp();
    },
);

onMounted(() => {
    const options = (margin) => ({ root: props.root, rootMargin: `${margin} 0px` });
    // Có thể nhận nhiều entry cùng trang (cũ trước) khi luồng chính bận → lấy entry mới nhất.
    const near = new IntersectionObserver((entries) => {
        if (entries.at(-1).isIntersecting && !visible) {
            visible = true;
            renderPdf();
        }
    }, options('100%'));
    // Lề giải phóng nhỏ (150%): mỗi trang giữ 3 canvas độ nét ×2, iPad giới hạn tổng bộ nhớ canvas.
    const far = new IntersectionObserver((entries) => entries.at(-1).isIntersecting || release(), options('150%'));
    near.observe(pdfCanvas.value.parentElement);
    far.observe(pdfCanvas.value.parentElement);
    observers = [near, far];
});

onBeforeUnmount(() => {
    if (active) sharedPointer.id = null;
    observers.forEach((observer) => observer.disconnect());
    if (laserFrame) cancelAnimationFrame(laserFrame);
    release();
});
</script>

<template>
    <div class="relative w-full overflow-hidden rounded-md bg-white shadow-sm" :style="{ aspectRatio: aspect }" :aria-label="`Trang ${number}`">
        <canvas ref="pdfCanvas" class="absolute inset-0 h-full w-full" />
        <canvas ref="inkCanvas" class="pointer-events-none absolute inset-0 h-full w-full" />
        <canvas
            ref="tempCanvas"
            :class="['absolute inset-0 h-full w-full', drawing ? 'touch-none' : 'pointer-events-none', tool === 'text' ? 'cursor-text' : tool === 'laser' ? 'cursor-none' : 'cursor-crosshair']"
            @pointerdown="onDown"
            @pointermove="onMove"
            @pointerup="onUp"
            @pointercancel="onCancel"
            @pointerleave="onLeave"
        />
        <input
            v-if="textBox"
            ref="textInput"
            v-model="textBox.value"
            type="text"
            class="absolute m-0 min-w-[8ch] border border-dashed border-primary bg-white/70 p-0 leading-tight outline-none"
            :style="textStyle"
            aria-label="Nhập chữ lên trang"
            @keydown.enter.prevent="commitText(), focusAnnotator()"
            @keydown.esc="cancelText"
            @blur="commitText"
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
