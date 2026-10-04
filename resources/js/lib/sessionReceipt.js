/**
 * Tính tiền phiếu thu theo sổ buổi phía trình duyệt — cùng công thức với App\Services\Tuition\SessionLedger::calculate.
 * Đổi công thức thì sửa cả hai chỗ.
 *
 *   tong_truoc_giam = tiền buổi + học liệu + thi + khác
 *   tong_phai_thu   = tong_truoc_giam − giảm (giảm chỉ tính trên tiền buổi)
 *   so_tien         = tong_phai_thu + phụ thu
 */
const round2 = (value) => Math.round((Number(value) || 0) * 100) / 100;
const nonNegative = (value) => Math.max(0, round2(value));

/** Tiền học phí của `sessions` buổi: thu hết số buổi còn nợ của khoản học phí → đúng số học phí còn nợ. */
export function sessionValue(quote, sessions) {
    const n = Math.max(0, parseInt(sessions) || 0);
    if (!quote || n <= 0) return 0;
    if (quote.mode === 'contract' && quote.contract_remaining_sessions !== null && n >= quote.contract_remaining_sessions) {
        return round2(quote.tuition_remaining);
    }
    return Math.round(n * (Number(quote.unit_price) || 0));
}

export function calculateSessionReceipt(quote, { sessions = 0, itemsTotal = 0, examFee = 0, otherFee = 0, extra = 0, discount = 0 } = {}) {
    const n = Math.max(0, parseInt(sessions) || 0);
    const value = sessionValue(quote, n);
    const feeDue = n > 0 && quote?.mode === 'contract' ? round2(quote.fee_due) : 0;
    const items = nonNegative(itemsTotal);
    const exam = nonNegative(examFee);
    const other = nonNegative(otherFee);
    const surcharge = nonNegative(extra);
    const disc = Math.min(nonNegative(discount), value);

    const material = items + feeDue;
    const subtotal = value + material + exam + other;
    const totalDue = subtotal - disc;

    return {
        sessionCount: n,
        sessionValue: value,
        feeDue,
        materialFee: material,
        examFee: exam,
        otherFee: other,
        subtotal,
        discount: disc,
        totalDue,
        extra: surcharge,
        amount: totalDue + surcharge,
    };
}
