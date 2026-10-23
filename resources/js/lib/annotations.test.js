// Chạy: npm run test:js (node --test, không cần thư viện test).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createHistory, hitShape, strokePx, toPixels, toRatio } from './annotations.js';

const pen = (points, width = 2) => ({ type: 'pen', color: '#1f2937', width, points });

test('quy đổi toạ độ tỉ lệ ↔ pixel, kẹp trong trang', () => {
    assert.deepEqual(toRatio(400, 300, 800, 600), [0.5, 0.5]);
    assert.deepEqual(toRatio(-5, 900, 800, 600), [0, 1]);
    assert.deepEqual(toPixels([0.25, 0.5], 1600, 1200), [400, 600]);
});

test('độ dày nét theo bậc và bề rộng trang', () => {
    assert.equal(strokePx(pen([], 2), 800), 4);
    assert.equal(strokePx({ type: 'highlighter', width: 3 }, 1600), 64);
});

test('tẩy trúng nét tự do trong dung sai, trượt khi xa', () => {
    const shape = pen([[0.1, 0.1], [0.5, 0.1]]);
    assert.equal(hitShape(shape, [0.3, 0.1], 800, 600), true);
    assert.equal(hitShape(shape, [0.3, 0.2], 800, 600), false);
});

test('chữ nhật và ellipse chỉ trúng ở viền', () => {
    const rect = { type: 'rect', color: '#dc2626', width: 1, points: [[0.2, 0.2], [0.6, 0.6]] };
    assert.equal(hitShape(rect, [0.2, 0.4], 800, 600), true);
    assert.equal(hitShape(rect, [0.4, 0.4], 800, 600), false);

    const ellipse = { ...rect, type: 'ellipse' };
    assert.equal(hitShape(ellipse, [0.4, 0.2], 800, 600), true);
    assert.equal(hitShape(ellipse, [0.4, 0.4], 800, 600), false);
});

test('chữ trúng trong khung chữ', () => {
    const text = { type: 'text', color: '#2563eb', width: 2, points: [[0.1, 0.1]], text: 'cat', box: [0.2, 0.05] };
    assert.equal(hitShape(text, [0.2, 0.12], 800, 600), true);
    assert.equal(hitShape(text, [0.5, 0.5], 800, 600), false);
});

test('lịch sử: thêm, xoá giữ thứ tự khi hoàn tác', () => {
    const history = createHistory();
    const first = pen([[0, 0], [0.1, 0.1]]);
    const second = pen([[0.2, 0.2], [0.3, 0.3]]);
    history.add(1, first);
    history.add(1, second);
    assert.equal(history.shapesOn(1).length, 2);

    history.remove(1, [first]);
    assert.deepEqual(history.shapesOn(1), [second]);

    assert.equal(history.undo(), true);
    assert.deepEqual(history.shapesOn(1), [first, second]);
});

test('lịch sử: xoá tất cả, hoàn tác, làm lại', () => {
    const history = createHistory();
    history.add(1, pen([[0, 0], [0.1, 0.1]]));
    history.add(3, pen([[0, 0], [0.1, 0.1]]));
    assert.deepEqual(history.pagesWithShapes(), [1, 3]);

    history.clearAll();
    assert.equal(history.isEmpty(), true);

    history.undo();
    assert.deepEqual(history.pagesWithShapes(), [1, 3]);

    assert.equal(history.redo(), true);
    assert.equal(history.isEmpty(), true);
});

test('lịch sử: thao tác mới cắt nhánh làm lại; xoá trang trống không ghi bước', () => {
    const history = createHistory();
    history.add(1, pen([[0, 0], [0.1, 0.1]]));
    history.undo();
    assert.equal(history.canRedo(), true);
    history.add(2, pen([[0, 0], [0.1, 0.1]]));
    assert.equal(history.canRedo(), false);

    history.undo();
    assert.equal(history.canUndo(), false);
    history.clear(5);
    assert.equal(history.canUndo(), false);
    assert.equal(history.undo(), false);
});

test('lịch sử: xoá một trang hoàn tác được', () => {
    const history = createHistory();
    const shape = pen([[0, 0], [0.1, 0.1]]);
    history.add(2, shape);
    history.clear(2);
    assert.deepEqual(history.shapesOn(2), []);
    history.undo();
    assert.deepEqual(history.shapesOn(2), [shape]);
});
