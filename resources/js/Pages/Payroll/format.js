/**
 * Định dạng số riêng của module Lương / KPI — cùng kết quả với các biểu thức PHP của view Blade cũ.
 *   money(1500000)                 → "1.500.000"   number_format($v, 0, ',', '.')
 *   trimNumber(10.5)               → "10,5"        rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',')
 *   trimNumber(85.5, 2, '.', ',')  → "85.5"        rtrim(rtrim(number_format($v, 2), '0'), '.')
 *   hours(1.5)                     → "1.5"         rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.')
 */
import { formatNumber } from '@/lib/format';

export function money(value) {
    return formatNumber(Number(value ?? 0), 0);
}

export function trimNumber(value, decimals = 2, decPoint = ',', thousandsSep = '.') {
    const text = formatNumber(Number(value ?? 0), decimals, decPoint, thousandsSep);
    if (decimals <= 0) return text;
    return text.replace(/0+$/, '').replace(new RegExp('\\' + decPoint + '$'), '');
}

export function hours(value) {
    return trimNumber(value, 2, '.', '');
}

/** Bỏ dấu tiếng Việt để tìm kiếm (giống Str::lower(Str::ascii()) phía server). */
export function searchKey(text) {
    return String(text ?? '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/đ/g, 'd');
}

