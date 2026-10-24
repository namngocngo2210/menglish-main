/**
 * Gạch chân trong ngân hàng câu hỏi: dữ liệu lưu `[x]` (vd. "c[oa]st") → các đoạn chữ thường / gạch chân.
 *   splitUnderline('c[oa]st') → [{ text: 'c', underline: false }, { text: 'oa', underline: true }, { text: 'st', underline: false }]
 * Chỉ tách chữ (không sinh HTML) — vẽ bằng <UnderlinedText>, không dùng v-html. Ngoặc không đóng / rỗng giữ nguyên.
 */
const UNDERLINE = /\[([^[\]]+)\]/g;

export function splitUnderline(text) {
    const source = text == null ? '' : String(text);
    const parts = [];
    let last = 0;

    for (const match of source.matchAll(UNDERLINE)) {
        if (match.index > last) parts.push({ text: source.slice(last, match.index), underline: false });
        parts.push({ text: match[1], underline: true });
        last = match.index + match[0].length;
    }
    if (last < source.length || parts.length === 0) parts.push({ text: source.slice(last), underline: false });

    return parts;
}
