/**
 * Gộp lịch học cho gọn: "Thứ 3 18:00-19:30; Thứ 5 18:00-19:30" → [{ days: 'Thứ 3, Thứ 5', time: '18:00–19:30' }].
 * Các buổi cùng giờ (và cùng phòng) gộp một dòng; chuỗi không đúng mẫu "<ngày> <giờ>-<giờ>" giữ nguyên.
 */
const SLOT = /^(.+?)\s+(\d{1,2}[:h]\d{2})\s*[-–]\s*(\d{1,2}[:h]\d{2})\s*(.*)$/u;

export function groupSchedule(text) {
    const source = (text ?? '').trim();
    if (!source) return [];
    const parts = source.split(/[;\n]+/u).map((p) => p.trim()).filter(Boolean);
    const slots = parts.map((p) => p.match(SLOT));
    if (slots.some((m) => !m)) return [{ days: source, time: '' }];

    const groups = [];
    for (const [, day, start, end, extra] of slots) {
        const time = `${start}–${end}${extra ? ` ${extra}` : ''}`;
        const same = groups.find((g) => g.time === time);
        if (same) same.days.push(day);
        else groups.push({ days: [day], time });
    }
    return groups.map((g) => ({ days: g.days.join(', '), time: g.time }));
}
