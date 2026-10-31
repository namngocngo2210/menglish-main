import test from 'node:test';
import assert from 'node:assert/strict';
import { expandedShiftIds } from './teacherShifts.js';

const shift = (session_id, tone, done = false) => ({ session_id, status: { tone }, steps: [{ done }] });

test('expands every upcoming and live session and collapses ended sessions', () => {
    const shifts = [shift(1, 'muted'), shift(2, 'warning'), shift(3, 'live'), shift(4, 'warning', true)];
    assert.deepEqual(expandedShiftIds(shifts), [2, 3, 4]);
});

test('falls back to the first unfinished session when every session has ended', () => {
    const shifts = [shift(1, 'muted', true), shift(2, 'muted'), shift(3, 'muted')];
    shifts[1].steps.unshift({ done: true });
    assert.deepEqual(expandedShiftIds(shifts), [2]);
    assert.deepEqual(shifts.map((item) => item.session_id), [1, 2, 3]);
    assert.deepEqual(shifts[1].steps, [{ done: true }, { done: false }]);
});

test('keeps ended unfinished sessions collapsed while any live or upcoming session exists', () => {
    assert.deepEqual(expandedShiftIds([shift(1, 'muted'), shift(2, 'live', true)]), [2]);
});

test('collapses all sessions when all have ended and are complete', () => {
    assert.deepEqual(expandedShiftIds([shift(1, 'muted', true), shift(2, 'muted', true)]), []);
});

test('handles an empty schedule', () => {
    assert.deepEqual(expandedShiftIds([]), []);
});
