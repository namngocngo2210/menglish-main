/**
 * Lý do phiếu thu chưa thể "Gửi duyệt" (R-11, R-13; bản nháp không bị chặn).
 * Trả về danh sách thông báo; rỗng = hợp lệ. Server vẫn kiểm tra lại (TuitionController::receiptSubmitError).
 */
export function receiptSubmitBlockers({ totalAmount, needsDiscountReason, discountReason, otherFee, otherFeeReason, surchargeInvalid, surchargeAmount, surchargeReason, linesValid, proofRequired, hasProof, vatChoice }) {
    const blockers = [];
    if (needsDiscountReason && !(discountReason || '').trim()) blockers.push('Nhập lý do giảm trừ (không theo ưu đãi có sẵn).');
    if (parseFloat(otherFee) > 0 && !(otherFeeReason || '').trim()) blockers.push('Nhập nội dung khoản thu khác.');
    if (surchargeInvalid) blockers.push('Phụ thu phải lớn hơn 0 (hoặc để trống và xóa lý do phụ thu).');
    else if (surchargeAmount > 0 && !(surchargeReason || '').trim()) blockers.push('Nhập lý do phụ thu.');
    if (!linesValid) blockers.push('Số lượng hàng hóa phụ thu phải từ 1 trở lên.');
    if (proofRequired && !hasProof) blockers.push('Đính kèm minh chứng thanh toán (ủy nhiệm chi / ảnh chuyển khoản / ảnh hóa đơn giấy).');
    if (vatChoice !== '1' && vatChoice !== '0') blockers.push('Chọn có hoặc không xuất hóa đơn đỏ (VAT).');
    if (!(totalAmount >= 1000)) blockers.push('Phiếu cần có số tiền từ 1.000 đ (thu buổi, học liệu, phí thi, khoản khác hoặc phụ thu).');
    return blockers;
}
