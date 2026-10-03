/**
 * Ưu đãi học phí phía trình duyệt — cùng công thức với App\Models\Promotion (calculateDiscount / isApplicable / defaultFor).
 * Đổi công thức tính giảm trừ thì sửa cả hai chỗ.
 */
import { formatMoney } from '@/lib/format';

/** Số tiền giảm của ưu đãi trên số tiền gốc (không vượt số tiền gốc). */
export function promotionDiscount(promo, baseAmount) {
    const base = Math.max(0, Number(baseAmount) || 0);
    if (!promo) return 0;
    if (promo.type === 'percent') {
        let disc = (base * Number(promo.value)) / 100;
        if (promo.max_discount_amount && disc > promo.max_discount_amount) disc = Number(promo.max_discount_amount);
        return Math.round(Math.min(base, disc));
    }
    return Math.min(base, Number(promo.value) || 0);
}

/** Ưu đãi áp dụng được cho cơ sở / khóa (ưu đãi không giới hạn cơ sở / khóa áp dụng mọi nơi). */
export function promotionApplies(promo, branchId, courseId) {
    return (!promo.branch_id || String(promo.branch_id) === String(branchId ?? '')) && (!promo.course_id || String(promo.course_id) === String(courseId ?? ''));
}

/** Ưu đãi mặc định cụ thể nhất: đúng cả cơ sở + khóa > đúng khóa > đúng cơ sở > chung. */
export function defaultPromotion(promotions, branchId, courseId) {
    const score = (p) => (p.course_id ? 2 : 0) + (p.branch_id ? 1 : 0);
    return promotions.filter((p) => p.is_default && promotionApplies(p, branchId, courseId)).sort((a, b) => score(b) - score(a))[0] ?? null;
}

/** Nhãn ngắn trong danh sách chọn: "Tên (10% / 500.000 đ) · Mặc định". */
export function promotionLabel(promo) {
    const value = promo.type === 'percent' ? Number(promo.value) + '%' : formatMoney(promo.value);
    const cap = promo.type === 'percent' && promo.max_discount_amount ? ', tối đa ' + formatMoney(promo.max_discount_amount) : '';
    return `${promo.name} (${value}${cap})` + (promo.is_default ? ' · Mặc định' : '') + (promo.is_special ? ' · Ưu đãi riêng' : '');
}
