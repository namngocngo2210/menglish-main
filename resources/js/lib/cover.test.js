import test from 'node:test';
import assert from 'node:assert/strict';
import { coverActions } from './cover.js';

const cover = (over = {}) => ({ status: 'open', requested_by: { id: 1 }, i_registered: false, registrations: [], ...over });
const as = (userId, canApprove = false) => ({ userId, canApprove });

test('another teacher can register, the requester cannot', () => {
    assert.equal(coverActions(cover(), as(2)).register, true);
    assert.equal(coverActions(cover(), as(1)).register, false);
    assert.equal(coverActions(cover({ i_registered: true }), as(2)).register, false);
    assert.equal(coverActions(cover({ i_registered: true }), as(2)).unregister, true);
});

test('registration is closed once approved or cancelled', () => {
    for (const status of ['approved', 'cancelled']) {
        const a = coverActions(cover({ status, i_registered: true }), as(2));
        assert.equal(a.register || a.unregister, false);
    }
});

test('approver approves only when someone registered, and can reopen or cancel an approved cover', () => {
    assert.equal(coverActions(cover(), as(9, true)).approve, false);
    assert.equal(coverActions(cover({ registrations: [{ teacher_id: 2 }] }), as(9, true)).approve, true);
    const approved = coverActions(cover({ status: 'approved' }), as(9, true));
    assert.deepEqual([approved.reopen, approved.cancel, approved.approve], [true, true, false]);
});

test('requester may cancel but not approve or reopen', () => {
    const a = coverActions(cover({ registrations: [{ teacher_id: 2 }] }), as(1));
    assert.deepEqual([a.cancel, a.approve, a.reopen], [true, false, false]);
    assert.equal(coverActions(cover({ status: 'cancelled' }), as(1)).cancel, false);
});
