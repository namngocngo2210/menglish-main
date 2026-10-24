// Chạy: npm run test:js (node --test, không cần thư viện test).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { splitUnderline } from './underline.js';

test('tách phần gạch chân [x]', () => {
    assert.deepEqual(splitUnderline('c[oa]st'), [
        { text: 'c', underline: false },
        { text: 'oa', underline: true },
        { text: 'st', underline: false },
    ]);
    assert.deepEqual(splitUnderline('[He] and [she]'), [
        { text: 'He', underline: true },
        { text: ' and ', underline: false },
        { text: 'she', underline: true },
    ]);
});

test('không có ngoặc → một phần thường', () => {
    assert.deepEqual(splitUnderline('Choose the best answer.'), [{ text: 'Choose the best answer.', underline: false }]);
    assert.deepEqual(splitUnderline(''), [{ text: '', underline: false }]);
    assert.deepEqual(splitUnderline(null), [{ text: '', underline: false }]);
});

test('ngoặc không đóng / rỗng giữ nguyên chữ', () => {
    assert.deepEqual(splitUnderline('c[oast'), [{ text: 'c[oast', underline: false }]);
    assert.deepEqual(splitUnderline('a [] b'), [{ text: 'a [] b', underline: false }]);
    assert.deepEqual(splitUnderline('x [y] z ['), [
        { text: 'x ', underline: false },
        { text: 'y', underline: true },
        { text: ' z [', underline: false },
    ]);
});

test('thẻ HTML là chữ thường, không được hiểu là HTML', () => {
    assert.deepEqual(splitUnderline('<b>[x]</b>'), [
        { text: '<b>', underline: false },
        { text: 'x', underline: true },
        { text: '</b>', underline: false },
    ]);
});
