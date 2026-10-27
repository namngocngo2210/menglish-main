/**
 * Logic hiển thị của màn báo cáo ngày (Reports/DailyOps, thiết kế 27/10/2026), tách khỏi component để kiểm thử bằng node --test:
 * khối "Cần chú ý hôm nay" gom từ mọi khối, bảng lớp trong ngày, khối không phát sinh (thu gọn) và trạng thái nộp.
 */

const TONE_RANK = { error: 0, warning: 1 };

// Dòng chi tiết đáng chú ý: tone cảnh báo / lỗi (DailyReportMetrics gắn khi có việc chưa xong).
export const isAlert = (d) => d?.tone === 'error' || d?.tone === 'warning';

/**
 * Việc cần chú ý của cả báo cáo, gom theo khối + nhãn (vd "Chưa check-in"): số dòng, mức nặng nhất, vài dòng mẫu.
 * @returns {{ key: string, section: string, sectionTitle: string, tag: string, tone: string, count: number, samples: string[] }[]}
 */
export function attentionGroups(sections) {
    const groups = new Map();
    for (const [key, section] of Object.entries(sections ?? {})) {
        for (const item of section.items ?? []) {
            for (const d of item.details ?? []) {
                if (!isAlert(d)) continue;
                const tag = d.tag || item.label;
                const id = `${key}|${tag}`;
                const g = groups.get(id) ?? { key: id, section: key, sectionTitle: section.title, tag, tone: d.tone, count: 0, samples: [] };
                g.count += 1;
                if (d.tone === 'error') g.tone = 'error';
                if (g.samples.length < 3) g.samples.push(d.class ?? d.text);
                groups.set(id, g);
            }
        }
    }
    return [...groups.values()].sort((a, b) => TONE_RANK[a.tone] - TONE_RANK[b.tone] || b.count - a.count);
}

/** Số việc cần chú ý trong một khối. */
export const alertCount = (section) => (section?.items ?? []).reduce((n, item) => n + (item.details ?? []).filter(isAlert).length, 0);

/**
 * Bảng lớp trong ngày của khối Vận hành: dòng "Danh sách lớp" + dòng GV / trợ giảng cùng buổi (`ref`).
 * Báo cáo nộp trước khi có `ref` (số liệu đã chụp) → null, màn hiện thẻ như cũ.
 */
export function classTable(section) {
    const items = section?.items ?? [];
    const list = items.find((i) => i.key === 'classes_list');
    const rows = list?.details ?? [];
    if (!rows.length || !rows.every((d) => d.ref)) return null;
    const issuesOf = (key, ref) => (items.find((i) => i.key === key)?.details ?? []).filter((d) => d.ref === ref);
    return rows.map((d) => ({
        ...d,
        teacherIssues: issuesOf('teachers', d.ref),
        assistantIssues: issuesOf('assistants', d.ref),
    }));
}

/** Chi tiết còn lại của một mục sau khi các dòng đã vào bảng lớp (dòng có `ref`) được bỏ ra. */
export const leftoverDetails = (item, tabled) => (tabled && ['classes_list', 'teachers', 'assistants'].includes(item.key) ? (item.details ?? []).filter((d) => !d.ref) : item.details ?? []);

// "0", "0/0", "0 / 0 hẹn", "0 đ": số bằng 0, có thể kèm đơn vị phía sau.
const ZERO = /^0(\s*\/\s*0)?(\s+\D.*)?$/;
export const hasValue = (item) => item.value !== null && item.value !== undefined;
const isZero = (item) => !hasValue(item) || ZERO.test(String(item.value).trim());

/** Khối không phát sinh gì (mọi số bằng 0, không có dòng chi tiết): thu gọn mặc định. */
export const isQuiet = (section) => (section?.items ?? []).every((item) => isZero(item) && !(item.details ?? []).length);

/** Tóm tắt một dòng cho khối thu gọn: "Số học sinh bổ trợ 0 · Data mới nhận 0". */
export const quietSummary = (section) =>
    (section?.items ?? [])
        .filter(hasValue)
        .map((item) => `${item.label} ${item.value}`)
        .join(' · ');

/**
 * Trạng thái nộp của ngày đang xem.
 * @returns {{ key: 'open'|'overdue'|'submitted'|'late'|'returned'|'reviewed', label: string, color: string }}
 */
export function submitStatus({ submittedAt, late, review, deadlineAt, now = new Date() }) {
    if (submittedAt) {
        if (review?.status === 'returned') return { key: 'returned', label: 'Cần bổ sung', color: 'warning' };
        if (review?.status === 'reviewed') return { key: 'reviewed', label: 'Đã kiểm tra', color: 'info' };
        return late ? { key: 'late', label: 'Nộp trễ', color: 'warning' } : { key: 'submitted', label: 'Đã nộp', color: 'success' };
    }
    if (deadlineAt && new Date(deadlineAt) < now) return { key: 'overdue', label: 'Quá hạn', color: 'error' };
    return { key: 'open', label: 'Chưa nộp', color: 'warning' };
}

/** "còn 16 giờ 15 phút" / "còn 40 phút" tới hạn nộp; quá hạn → null. */
export function timeLeft(deadlineAt, now = new Date()) {
    const ms = new Date(deadlineAt) - now;
    if (!deadlineAt || Number.isNaN(ms) || ms <= 0) return null;
    const minutes = Math.floor(ms / 60000);
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    if (h >= 24) return `còn ${Math.floor(h / 24)} ngày ${h % 24} giờ`;
    return h ? `còn ${h} giờ ${m} phút` : `còn ${m} phút`;
}

/** Ngày trước / sau của "YYYY-MM-DD" (theo lịch, không lệch múi giờ). */
export function shiftDate(date, days) {
    const [y, m, d] = date.split('-').map(Number);
    const t = new Date(Date.UTC(y, m - 1, d + days));
    return t.toISOString().slice(0, 10);
}
