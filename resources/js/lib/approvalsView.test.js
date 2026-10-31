import test from 'node:test';
import assert from 'node:assert/strict';
import { approvalAgeTone, approvalIcon, splitApprovalItems } from './approvalsView.js';

const now = Date.parse('2026-10-31T12:00:00Z');
const hoursAgo = (hours) => new Date(now - hours * 60 * 60 * 1000).toISOString();

test('age tones change at exactly 24 and 72 hours', () => {
    for (const [hours, tone] of [[0, 'neutral'], [23.999, 'neutral'], [24, 'warning'], [71.999, 'warning'], [72, 'error'], [100, 'error']]) {
        assert.equal(approvalAgeTone(hoursAgo(hours), now), tone);
    }
});

test('missing, invalid and future dates have a neutral tone', () => {
    for (const value of [null, undefined, '', 'invalid', hoursAgo(-24)]) {
        assert.equal(approvalAgeTone(value, now), 'neutral');
    }
});

test('source icons include a safe fallback for unknown keys', () => {
    assert.equal(approvalIcon('receipt'), 'receipt_long');
    assert.equal(approvalIcon('work_task'), 'task_alt');
    assert.equal(approvalIcon('staff_attendance_request'), 'schedule');
    for (const key of ['unknown', '', null, undefined, 'toString', '__proto__']) {
        assert.equal(approvalIcon(key), 'fact_check');
    }
});

test('splits the first three without changing items or their identity', () => {
    const items = Array.from({ length: 5 }, (_, id) => ({ ref: `receipt:${id}` }));
    const { first, rest } = splitApprovalItems(items);
    assert.deepEqual(first, items.slice(0, 3));
    assert.deepEqual(rest, items.slice(3));
    assert.equal(first[0], items[0]);
    assert.equal(rest[0], items[3]);
    assert.equal(items.length, 5);
});

test('splits empty, short and exact lists and accepts another limit', () => {
    assert.deepEqual(splitApprovalItems([]), { first: [], rest: [] });
    assert.deepEqual(splitApprovalItems([1, 2]), { first: [1, 2], rest: [] });
    assert.deepEqual(splitApprovalItems([1, 2, 3]), { first: [1, 2, 3], rest: [] });
    assert.deepEqual(splitApprovalItems([1, 2, 3], 1), { first: [1], rest: [2, 3] });
    assert.deepEqual(splitApprovalItems([1, 2], 0), { first: [], rest: [1, 2] });
});
