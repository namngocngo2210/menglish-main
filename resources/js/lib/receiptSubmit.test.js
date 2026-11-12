import test from 'node:test';
import assert from 'node:assert/strict';
import { receiptSubmitBlockers } from './receiptSubmit.js';

const ok = { totalAmount: 2000000, needsDiscountReason: false, discountReason: '', otherFee: 0, otherFeeReason: '', surchargeInvalid: false, surchargeAmount: 0, surchargeReason: '', linesValid: true, proofRequired: true, hasProof: true, vatChoice: '0' };

test('valid receipt has no blockers', () => assert.deepEqual(receiptSubmitBlockers(ok), []));

test('transfer without proof is blocked with a specific reason', () => {
    const r = receiptSubmitBlockers({ ...ok, hasProof: false });
    assert.equal(r.length, 1);
    assert.match(r[0], /minh chứng/);
});

test('cash without required proof is not blocked', () => assert.deepEqual(receiptSubmitBlockers({ ...ok, proofRequired: false, hasProof: false }), []));

test('lists every unmet condition', () => {
    const r = receiptSubmitBlockers({ ...ok, needsDiscountReason: true, hasProof: false, surchargeAmount: 5000 });
    assert.equal(r.length, 3);
});

test('unset VAT choice blocks submit; Có/Không both accepted', () => {
    assert.match(receiptSubmitBlockers({ ...ok, vatChoice: '' })[0], /VAT/);
    assert.deepEqual(receiptSubmitBlockers({ ...ok, vatChoice: '1' }), []);
    assert.deepEqual(receiptSubmitBlockers({ ...ok, vatChoice: '0' }), []);
});
