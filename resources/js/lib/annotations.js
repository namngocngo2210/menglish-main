/**
 * Nét vẽ trên trang PDF (bút, highlight, hình, chữ) — logic thuần, không Vue / DOM, test bằng node --test.
 * Toạ độ theo tỉ lệ trang 0–1 để vẽ lại đúng chỗ khi trang đổi kích thước (toàn màn hình, xoay máy).
 *   { type: 'pen'|'highlighter'|'line'|'arrow'|'rect'|'ellipse'|'text', color, width: 1–3, points: [[x, y], …], text?, box?: [bw, bh] }
 * Nét vẽ chỉ nằm trong bộ nhớ trình duyệt, không lưu vào giáo trình.
 */

// Màu nét vẽ là dữ liệu (không theo theme sáng / tối của giao diện).
export const COLORS = [
    { key: 'black', value: '#1f2937', label: 'Đen' },
    { key: 'red', value: '#dc2626', label: 'Đỏ' },
    { key: 'blue', value: '#2563eb', label: 'Xanh dương' },
    { key: 'green', value: '#16a34a', label: 'Xanh lá' },
    { key: 'yellow', value: '#facc15', label: 'Vàng' },
];

export const SIZES = [1, 2, 3];

export const TOOLS = [
    { key: 'view', icon: 'pan_tool', label: 'Xem' },
    { key: 'pen', icon: 'edit', label: 'Bút' },
    { key: 'highlighter', icon: 'ink_highlighter', label: 'Highlight' },
    { key: 'line', icon: 'horizontal_rule', label: 'Đường thẳng' },
    { key: 'arrow', icon: 'arrow_right_alt', label: 'Mũi tên' },
    { key: 'rect', icon: 'rectangle', label: 'Chữ nhật' },
    { key: 'ellipse', icon: 'circle', label: 'Hình tròn' },
    { key: 'text', icon: 'title', label: 'Chữ' },
    { key: 'eraser', icon: 'ink_eraser', label: 'Tẩy' },
    { key: 'laser', icon: 'point_scan', label: 'Laser' },
];

export const SHAPE_TOOLS = ['line', 'arrow', 'rect', 'ellipse'];

export const FONT_FAMILY = '"Be Vietnam Pro", sans-serif';

const STROKES = { pen: [2, 4, 7], highlighter: [12, 20, 32] };
const FONTS = [16, 24, 36];
const ARROW_ANGLE = (28 * Math.PI) / 180;
const ELLIPSE_STEPS = 48;

/** Đơn vị kích thước: trang rộng 800 px CSS = 1. */
export function unit(pageWidth) {
    return pageWidth / 800;
}

const level = (list, width) => list[Math.min(Math.max((width || 1) - 1, 0), list.length - 1)];

export function strokePx(shape, pageWidth) {
    return level(shape.type === 'highlighter' ? STROKES.highlighter : STROKES.pen, shape.width) * unit(pageWidth);
}

export function fontPx(size, pageWidth) {
    return level(FONTS, size) * unit(pageWidth);
}

const clamp = (value) => Math.min(Math.max(value, 0), 1);

export function toRatio(px, py, w, h) {
    return [clamp(px / w), clamp(py / h)];
}

export function toPixels([x, y], w, h) {
    return [x * w, y * h];
}

function ellipsePoints([[x1, y1], [x2, y2]]) {
    const [cx, cy, rx, ry] = [(x1 + x2) / 2, (y1 + y2) / 2, Math.abs(x2 - x1) / 2, Math.abs(y2 - y1) / 2];

    return Array.from({ length: ELLIPSE_STEPS + 1 }, (_, i) => {
        const angle = (i / ELLIPSE_STEPS) * Math.PI * 2;
        return [cx + rx * Math.cos(angle), cy + ry * Math.sin(angle)];
    });
}

function rectPoints([[x1, y1], [x2, y2]]) {
    return [[x1, y1], [x2, y1], [x2, y2], [x1, y2], [x1, y1]];
}

export function drawShape(ctx, shape, w, h) {
    const px = shape.points.map((p) => toPixels(p, w, h));
    const stroke = strokePx(shape, w);
    ctx.save();
    ctx.strokeStyle = ctx.fillStyle = shape.color;
    ctx.lineWidth = stroke;
    ctx.lineCap = ctx.lineJoin = 'round';

    if (shape.type === 'text') {
        ctx.font = `${fontPx(shape.width, w)}px ${FONT_FAMILY}`;
        ctx.textBaseline = 'top';
        ctx.fillText(shape.text ?? '', px[0][0], px[0][1]);
        ctx.restore();
        return;
    }
    if (shape.type === 'highlighter') {
        ctx.globalAlpha = 0.35;
        ctx.globalCompositeOperation = 'multiply';
    }

    const path = shape.type === 'rect' ? rectPoints(px) : shape.type === 'ellipse' ? ellipsePoints(px) : px;
    ctx.beginPath();
    path.forEach(([x, y], i) => (i ? ctx.lineTo(x, y) : ctx.moveTo(x, y)));
    if (path.length === 1) ctx.lineTo(path[0][0] + 0.01, path[0][1]);
    ctx.stroke();

    if (shape.type === 'arrow' && px.length > 1) {
        const [[x1, y1], [x2, y2]] = [px[0], px.at(-1)];
        const angle = Math.atan2(y2 - y1, x2 - x1);
        const head = 3 * stroke;
        ctx.beginPath();
        ctx.moveTo(x2 - head * Math.cos(angle - ARROW_ANGLE), y2 - head * Math.sin(angle - ARROW_ANGLE));
        ctx.lineTo(x2, y2);
        ctx.lineTo(x2 - head * Math.cos(angle + ARROW_ANGLE), y2 - head * Math.sin(angle + ARROW_ANGLE));
        ctx.stroke();
    }
    ctx.restore();
}

/** Watermark định danh lặp kín trang (tài liệu "Chỉ xem"): vẽ thẳng vào canvas trang nên có trong mọi ảnh chụp. */
export function drawWatermark(ctx, lines, w, h) {
    const size = fontPx(1, w);
    const [cellW, cellH] = [w / 2, w / 4];
    ctx.save();
    ctx.globalAlpha = 0.12;
    ctx.fillStyle = '#1f2937';
    ctx.font = `bold ${size}px ${FONT_FAMILY}`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    // Lưới rộng hơn trang để sau khi xoay vẫn phủ kín các góc.
    for (let row = 0, y = -h; y < h * 2; row++, y += cellH) {
        // Hàng so le nửa ô để chữ không thẳng cột.
        for (let x = -w + (row % 2) * (cellW / 2); x < w * 2; x += cellW) {
            ctx.save();
            ctx.translate(x, y);
            ctx.rotate((-20 * Math.PI) / 180);
            lines.forEach((line, i) => ctx.fillText(line, 0, (i - (lines.length - 1) / 2) * size * 1.4));
            ctx.restore();
        }
    }
    ctx.restore();
}

function segmentDistance([px, py], [ax, ay], [bx, by]) {
    const [dx, dy] = [bx - ax, by - ay];
    const lengthSq = dx * dx + dy * dy;
    const t = lengthSq ? Math.min(Math.max(((px - ax) * dx + (py - ay) * dy) / lengthSq, 0), 1) : 0;

    return Math.hypot(px - (ax + t * dx), py - (ay + t * dy));
}

/** Điểm (tỉ lệ) có chạm đối tượng không — dùng cho tẩy; hình chữ nhật / ellipse chỉ tính viền. */
export function hitShape(shape, point, w, h, tolerancePx = 8) {
    const p = toPixels(point, w, h);
    const px = shape.points.map((q) => toPixels(q, w, h));

    if (shape.type === 'text') {
        const [x, y] = px[0];
        const [bw, bh] = toPixels(shape.box ?? [0, 0], w, h);
        return p[0] >= x - tolerancePx && p[0] <= x + bw + tolerancePx && p[1] >= y - tolerancePx && p[1] <= y + bh + tolerancePx;
    }

    const path = shape.type === 'rect' ? rectPoints(px) : shape.type === 'ellipse' ? ellipsePoints(px) : px;
    const reach = tolerancePx + strokePx(shape, w) / 2;
    if (path.length === 1) return Math.hypot(p[0] - path[0][0], p[1] - path[0][1]) <= reach;

    return path.slice(1).some((b, i) => segmentDistance(p, path[i], b) <= reach);
}

/**
 * Nét vẽ theo trang + hoàn tác / làm lại cho cả tài liệu. Bước: { kind: 'add'|'remove', page, shapes, indexes? }
 * hoặc { kind: 'clear', pages: [[page, shapes]] }. Thao tác mới bỏ nhánh làm lại.
 */
export function createHistory() {
    const pages = new Map();
    const done = [];
    let undone = [];

    const list = (page) => {
        if (!pages.has(page)) pages.set(page, []);
        return pages.get(page);
    };

    function apply(step, forward) {
        if (step.kind === 'clear') {
            step.pages.forEach(([page, shapes]) => pages.set(page, forward ? [] : [...shapes]));
            return;
        }
        const shapes = list(step.page);
        const adding = (step.kind === 'add') === forward;
        if (step.kind === 'add') {
            if (adding) shapes.push(...step.shapes);
            else pages.set(step.page, shapes.filter((s) => !step.shapes.includes(s)));
        } else if (adding) {
            // Hoàn tác xoá: chèn lại đúng vị trí cũ (chỉ số tăng dần) để giữ thứ tự vẽ.
            step.indexes.forEach((index, i) => shapes.splice(index, 0, step.shapes[i]));
        } else {
            pages.set(step.page, shapes.filter((s) => !step.shapes.includes(s)));
        }
    }

    function record(step) {
        apply(step, true);
        done.push(step);
        undone = [];
    }

    return {
        shapesOn: (page) => [...(pages.get(page) ?? [])],
        pagesWithShapes: () => [...pages.keys()].filter((page) => pages.get(page).length).sort((a, b) => a - b),
        isEmpty: () => [...pages.values()].every((shapes) => !shapes.length),
        canUndo: () => done.length > 0,
        canRedo: () => undone.length > 0,
        add(page, shape) {
            record({ kind: 'add', page, shapes: [shape] });
        },
        remove(page, shapes) {
            const current = list(page);
            const hits = current.map((s, index) => [s, index]).filter(([s]) => shapes.includes(s));
            if (!hits.length) return;
            record({ kind: 'remove', page, shapes: hits.map(([s]) => s), indexes: hits.map(([, index]) => index) });
        },
        clear(page) {
            const shapes = pages.get(page) ?? [];
            if (shapes.length) record({ kind: 'clear', pages: [[page, [...shapes]]] });
        },
        clearAll() {
            const all = [...pages].filter(([, shapes]) => shapes.length).map(([page, shapes]) => [page, [...shapes]]);
            if (all.length) record({ kind: 'clear', pages: all });
        },
        undo() {
            const step = done.pop();
            if (!step) return false;
            apply(step, false);
            undone.push(step);
            return true;
        },
        redo() {
            const step = undone.pop();
            if (!step) return false;
            apply(step, true);
            done.push(step);
            return true;
        },
    };
}
