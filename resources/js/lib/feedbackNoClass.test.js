import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// HS-FB-10 / R-09 AC-R08: students without a class must not see stage/class labels.
const src = readFileSync(new URL('../Pages/Portal/Feedback.vue', import.meta.url), 'utf8');

test('stage-completed card and class line are gated by hasClass', () => {
    for (const needle of ['Chặng học đã hoàn thành', 'Lớp: {{ className }}']) {
        const idx = src.indexOf(needle);
        assert.ok(idx > -1, `${needle} not found`);
        const openTag = src.lastIndexOf('<div v-if="hasClass"', idx);
        assert.ok(openTag > -1, `${needle} is not inside a v-if="hasClass" block`);
        // The gated block must be the nearest enclosing top-level card: no other v-if/v-show div between.
        const between = src.slice(openTag + 5, idx);
        assert.ok(!/<div v-(if|show)=/.test(between), `${needle}: nearest gate is not hasClass`);
    }
});
