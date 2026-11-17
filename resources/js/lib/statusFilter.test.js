import test from 'node:test';
import assert from 'node:assert/strict';
import { toggleStatus } from './statusFilter.js';

test('toggleStatus adds and removes a status', () => {
    assert.deepEqual(toggleStatus([], 'studying'), ['studying']);
    assert.deepEqual(toggleStatus(['studying'], 'deferred'), ['studying', 'deferred']);
    assert.deepEqual(toggleStatus(['studying', 'deferred'], 'studying'), ['deferred']);
});
