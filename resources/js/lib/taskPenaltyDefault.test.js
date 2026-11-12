import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../Pages/Tasks/Create.vue', import.meta.url), 'utf8');

test('penalty switch defaults to ON for new tasks', () => {
    assert.match(src, /const penalize = ref\(true\);/);
});

test('penalty block is hidden for recurring tasks and keeps amount required (min 1)', () => {
    assert.match(src, /<div v-if="!isRecurring"[^>]*>[\s\S]*name="phat_khi_qua_han"[\s\S]*<UiInput v-if="penalize"[^>]*name="muc_phat_qua_han"[^>]*required min="1"/);
});

test('hidden fallback input still submits 0 when the switch is unticked', () => {
    assert.match(src, /<input type="hidden" name="phat_khi_qua_han" value="0" \/>/);
});
