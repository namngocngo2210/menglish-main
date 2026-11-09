import test from 'node:test';
import assert from 'node:assert/strict';
import { copyText } from './clipboard.js';

test('copyText falls back to execCommand when navigator.clipboard is unavailable', async () => {
    const calls = [];
    const area = { setAttribute() {}, select() { calls.push('select'); }, style: {}, value: '' };
    globalThis.document = {
        createElement: () => area,
        body: { appendChild() {}, removeChild() {} },
        execCommand: (cmd) => { calls.push(cmd); return true; },
    };
    try {
        assert.equal(await copyText('s3cret', null), true);
        assert.equal(area.value, 's3cret');
        assert.deepEqual(calls, ['select', 'copy']);
    } finally {
        delete globalThis.document;
    }
});

test('copyText uses navigator.clipboard when available', async () => {
    let written = null;
    Object.defineProperty(globalThis, 'navigator', { value: { clipboard: { writeText: async (t) => { written = t; } } }, configurable: true });
    assert.equal(await copyText('abc', null), true);
    assert.equal(written, 'abc');
});
