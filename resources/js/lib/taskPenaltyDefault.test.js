import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../Pages/Tasks/Create.vue', import.meta.url), 'utf8');

// TK-2026-0155: penalty amounts are configured in the KPI screen, never on the task form.
test('task create form has no penalty toggle or amount field', () => {
    assert.doesNotMatch(src, /phat_khi_qua_han|muc_phat_qua_han|Phạt khi quá hạn|Số tiền phạt/);
});
