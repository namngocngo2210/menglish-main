/**
 * Định dạng tiền phía trình duyệt — cùng kiểu với App\Support\Money::format (PHP): "9.500.000 đ".
 * Dùng trong Alpine: x-text="formatMoney(amount)" (hàm gắn vào window trong app.js).
 */
const formatter = new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 });

export function formatMoney(value, unit = 'đ') {
    const number = Number(value);
    if (value === null || value === '' || Number.isNaN(number)) return '—';
    return formatter.format(Math.round(number)) + (unit ? ' ' + unit : '');
}
