import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// TK-2026-0128: notification channels are fixed, the screen only edits time milestones.
const src = readFileSync(new URL('../Pages/SystemConfig/DebtReminders.vue', import.meta.url), 'utf8');

test('debt reminders page has no channel selection controls', () => {
    assert.ok(!src.includes('channels'));
    assert.ok(!src.includes('Kênh thông báo" name'));
    assert.ok(!src.includes('kênh từng mốc'));
});

test('debt reminders page still edits milestone timing', () => {
    assert.ok(src.includes('name="timing"'));
    assert.ok(src.includes('name="days"'));
});
