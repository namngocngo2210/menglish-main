// Chạy: npm run test:js (node --test, không cần thư viện test). DOM giả tối thiểu bằng EventTarget của Node.
import assert from 'node:assert/strict';
import { afterEach, beforeEach, test } from 'node:test';
import { createRenderer, nextTick } from 'vue';
import { useExamLock } from './useExamLock.js';

let now = 0;
const realNow = Date.now;
let historyCalls;

function setupDom() {
    const doc = new EventTarget();
    doc.visibilityState = 'visible';
    doc.fullscreenElement = null;
    doc.documentElement = {
        requestFullscreen() {
            doc.fullscreenElement = this;
            return Promise.resolve();
        },
    };
    doc.exitFullscreen = () => {
        doc.fullscreenElement = null;
        return Promise.resolve();
    };
    const win = new EventTarget();
    win.scrollY = 0;
    win.scrollTo = () => {};
    historyCalls = [];
    globalThis.document = doc;
    globalThis.window = win;
    globalThis.history = {
        replaceState: () => historyCalls.push('replace'),
        pushState: () => historyCalls.push('push'),
    };
    globalThis.location = { href: 'http://test/portal/test/ABC' };
    globalThis.requestAnimationFrame = (fn) => fn();
}

const { createApp } = createRenderer({
    createElement: () => ({}),
    createText: () => ({}),
    createComment: () => ({}),
    insert() {},
    remove() {},
    setText() {},
    setElementText() {},
    parentNode: () => null,
    nextSibling: () => null,
    patchProp() {},
});

function mountLock(options = {}) {
    const calls = { violations: [], limit: 0 };
    let lock;
    const app = createApp({
        setup() {
            lock = useExamLock({
                maxViolations: 3,
                onViolation: (type, count) => calls.violations.push([type, count]),
                onLimit: () => calls.limit++,
                ...options,
            });
            return () => null;
        },
    });
    app.mount({});
    return { lock, calls, app };
}

const fire = (target, type, props = {}) => {
    const e = Object.assign(new Event(type, { cancelable: true }), props);
    target.dispatchEvent(e);
    return e;
};
const leave = (type = 'blur') => {
    now += 2000;
    if (type === 'hidden') {
        document.visibilityState = 'hidden';
        fire(document, 'visibilitychange');
        document.visibilityState = 'visible';
    } else {
        fire(window, 'blur');
    }
};

beforeEach(() => {
    setupDom();
    now = 1_000_000;
    Date.now = () => now;
});
afterEach(() => {
    Date.now = realNow;
});

test('chưa bắt đầu: không ghi rời bài, không chặn phím / chuột phải', () => {
    const { lock, calls } = mountLock();
    leave();
    assert.equal(lock.log.value.length, 0);
    assert.equal(calls.violations.length, 0);
    assert.equal(fire(document, 'keydown', { key: 'F12' }).defaultPrevented, false);
    assert.equal(fire(document, 'contextmenu').defaultPrevented, false);
});

test('start(): toàn màn hình, khoá nút Quay lại, chặn phím tắt / sao chép / rời trang', () => {
    const { lock } = mountLock();
    lock.start();
    assert.equal(lock.active.value, true);
    assert.ok(document.fullscreenElement);
    assert.deepEqual(historyCalls, ['replace', 'push']);
    assert.equal(fire(document, 'keydown', { key: 'u', ctrlKey: true }).defaultPrevented, true);
    assert.equal(fire(document, 'keydown', { key: 'a' }).defaultPrevented, false);
    assert.equal(fire(document, 'copy').defaultPrevented, true);
    assert.equal(fire(document, 'selectstart').defaultPrevented, true);
    assert.equal(fire(window, 'beforeunload').defaultPrevented, true);
    fire(window, 'popstate');
    assert.deepEqual(historyCalls, ['replace', 'push', 'replace', 'push']);
});

test('blur + ẩn tab + thoát toàn màn hình trong 1,5 giây tính là 1 lần; resume() đóng cảnh báo', () => {
    const { lock, calls } = mountLock();
    lock.start();
    now += 2000;
    fire(window, 'blur');
    document.visibilityState = 'hidden';
    fire(document, 'visibilitychange');
    document.fullscreenElement = null;
    fire(document, 'fullscreenchange');
    assert.equal(lock.log.value.length, 1);
    assert.equal(lock.log.value[0].type, 'blur');
    assert.deepEqual(calls.violations, [['blur', 1]]);
    assert.equal(lock.showWarning.value, true);

    lock.resume();
    assert.equal(lock.showWarning.value, false);
    assert.ok(document.fullscreenElement);
});

test('tới maxViolations: gọi onLimit một lần, ngừng ghi, cho rời trang', () => {
    const { lock, calls } = mountLock();
    lock.start();
    leave('blur');
    leave('hidden');
    leave('blur');
    assert.equal(calls.limit, 1);
    assert.equal(lock.showWarning.value, false);
    assert.deepEqual(calls.violations, [['blur', 1], ['hidden', 2], ['blur', 3]]);

    leave('blur');
    assert.equal(lock.log.value.length, 3);
    assert.equal(calls.limit, 1);
    assert.equal(fire(window, 'beforeunload').defaultPrevented, false);
    assert.equal(fire(document, 'keydown', { key: 'F5' }).defaultPrevented, false);
});

test('stop() tắt khoá khi đang nộp; resume() bật lại', () => {
    const { lock } = mountLock();
    lock.start();
    lock.stop();
    leave();
    assert.equal(lock.log.value.length, 0);
    assert.equal(fire(window, 'beforeunload').defaultPrevented, false);
    fire(window, 'popstate');
    assert.deepEqual(historyCalls, ['replace', 'push']);

    lock.resume();
    leave();
    assert.equal(lock.log.value.length, 1);
    assert.equal(fire(window, 'beforeunload').defaultPrevented, true);
});

test('huỷ component: gỡ listener và thoát toàn màn hình', async () => {
    const { lock, app } = mountLock();
    lock.start();
    app.unmount();
    await nextTick();
    assert.equal(document.fullscreenElement, null);
    leave();
    assert.equal(lock.log.value.length, 0);
    assert.equal(fire(document, 'contextmenu').defaultPrevented, false);
});
