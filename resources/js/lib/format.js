/**
 * Định dạng hiển thị dùng chung — cùng kết quả với PHP để trang Vue hiện giống hệt trang Blade cũ.
 *
 *   formatMoney(1500000)            → "1.500.000 đ"   (App\Support\Money::format)
 *   formatNumber(1234.5, 1)         → "1.234,5"        (number_format($v, 1, ',', '.'))
 *   formatDate(iso, 'd/m/Y H:i')    → "30/09/2026 12:36" theo múi giờ ứng dụng (Asia/Ho_Chi_Minh)
 *   shortCode('HV-01M3GK...')       → "HV-…F1NWC"      (App\Support\DisplayCode::short)
 */

const APP_TIMEZONE = 'Asia/Ho_Chi_Minh';

/** Làm tròn "xa số 0" như PHP round()/number_format(). */
function roundHalfAway(value, decimals) {
    const factor = 10 ** decimals;
    return (Math.sign(value) * Math.round(Math.abs(value) * factor + Number.EPSILON)) / factor;
}

export function formatNumber(value, decimals = 0, decPoint = ',', thousandsSep = '.') {
    const number = Number(value);
    if (value === null || value === '' || value === undefined || Number.isNaN(number)) return '';
    const rounded = roundHalfAway(Math.abs(number), decimals);
    const [int, frac] = rounded.toFixed(decimals).split('.');
    const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, thousandsSep);
    const sign = number < 0 && rounded !== 0 ? '-' : '';
    return sign + grouped + (decimals > 0 ? decPoint + frac : '');
}

/** Điểm trung bình: tối đa 1 số lẻ theo kiểu Việt ("85,5"), null → "—". */
export function formatScore(value) {
    return value === null || value === undefined ? '—' : Number(value).toLocaleString('vi-VN', { maximumFractionDigits: 1 });
}

/** Tỷ lệ phần trăm: như formatScore kèm "%" ("92,5%"), null → "—". */
export function formatPercent(value) {
    return value === null || value === undefined ? '—' : formatScore(value) + '%';
}

/** null / không phải số → "—". sign = true thêm "+" cho số dương. unit = '' để bỏ đơn vị. */
export function formatMoney(value, unit = 'đ', sign = false) {
    const number = Number(value);
    if (value === null || value === '' || value === undefined || typeof value === 'boolean' || Number.isNaN(number)) return '—';
    const prefix = number < 0 ? '-' : sign && number > 0 ? '+' : '';
    return prefix + formatNumber(Math.abs(number), 0) + (unit !== '' ? ' ' + unit : '');
}

const partsFormatter = new Intl.DateTimeFormat('en-GB', {
    timeZone: APP_TIMEZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
    weekday: 'short',
});

const WEEKDAYS = { Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6, Sun: 0 };
const VI_WEEKDAYS = ['Chủ nhật', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7'];

/** Chuỗi "Y-m-d" (không giờ) coi là ngày theo lịch, không đổi múi giờ. */
function toDate(value) {
    if (value instanceof Date) return value;
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) return new Date(value + 'T00:00:00+07:00');
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(value)) return new Date(value.replace(' ', 'T') + '+07:00');
    return new Date(value);
}

/** Tách ngày giờ theo múi giờ ứng dụng. */
function dateParts(value) {
    if (value === null || value === undefined || value === '') return null;
    const date = toDate(value);
    if (Number.isNaN(date.getTime())) return null;
    const parts = Object.fromEntries(partsFormatter.formatToParts(date).map((p) => [p.type, p.value]));
    return {
        Y: parts.year,
        y: parts.year.slice(-2),
        m: parts.month,
        n: String(Number(parts.month)),
        d: parts.day,
        j: String(Number(parts.day)),
        H: parts.hour,
        G: String(Number(parts.hour)),
        i: parts.minute,
        s: parts.second,
        w: WEEKDAYS[parts.weekday],
    };
}

/**
 * Định dạng ngày giờ theo ký hiệu của PHP date(): d m Y y j n H G i s; "l" = thứ tiếng Việt ("Thứ 2", "Chủ nhật").
 * Chuỗi rỗng / null → '' (trang tự quyết hiện "—").
 */
export function formatDate(value, pattern = 'd/m/Y') {
    const p = dateParts(value);
    if (!p) return '';
    let out = '';
    for (let i = 0; i < pattern.length; i++) {
        const ch = pattern[i];
        if (ch === '\\' && i + 1 < pattern.length) {
            out += pattern[++i];
        } else if (ch === 'l') {
            out += VI_WEEKDAYS[p.w];
        } else if (ch in p && ch !== 'w') {
            out += p[ch];
        } else {
            out += ch;
        }
    }
    return out;
}

const ULID_CODE = /((?:[A-Z]{1,6}-)+(?:\d{4}-)?)([0-9A-HJKMNP-TV-Z]{26})/;

export function shortCode(code) {
    const text = code === null || code === undefined ? '' : String(code);
    return new RegExp('^' + ULID_CODE.source + '$').test(text) ? shortenCodesIn(text) : text;
}

export function shortenCodesIn(text) {
    return String(text ?? '').replace(new RegExp('\\b' + ULID_CODE.source + '\\b', 'g'), (_, prefix, ulid) => prefix + '…' + ulid.slice(-6));
}

/** Chữ cái đầu cho avatar ("Nguyễn Anh Tuấn" → "NA"). */
export function initials(name) {
    const words = String(name ?? '').trim().split(/\s+/u).filter(Boolean);
    return (words.length ? words : ['?']).slice(0, 2).map((w) => Array.from(w)[0]).join('').toLocaleUpperCase('vi');
}

/** crc32 như PHP crc32() — chọn màu avatar cố định theo tên. */
export function crc32(text) {
    let crc = -1;
    for (const byte of new TextEncoder().encode(String(text))) {
        crc ^= byte;
        for (let k = 0; k < 8; k++) crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1));
    }
    return (crc ^ -1) >>> 0;
}
