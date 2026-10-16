// Logic thuần của màn báo cáo (không phụ thuộc React): đường dẫn theo loại báo cáo, định dạng ngày giờ Việt Nam,
// nhãn trạng thái kiểm tra, dòng báo cáo tuần Học thuật, gợi ý mức chuyên cần / bài tập của giáo viên.
import type { AllRow, ClassValue, HistoryItem, Review, TeacherClass, WeeklyRow } from './api'

// Màn nhập theo loại báo cáo có cấu trúc (khớp kindPaths ở API).
export const KIND_PATHS: Record<string, string> = {
  daily_ops: '/reports/daily',
  weekly_kpi: '/reports/weekly-kpi',
  academic_weekly: '/reports/academic-weekly',
  academic_monthly: '/reports/academic-monthly',
  academic_quarterly: '/reports/academic-quarterly',
  teacher_monthly: '/reports/teacher-monthly',
  teacher_attendance: '/reports/teacher-monthly',
}

export function kindPath(kind: string) {
  return KIND_PATHS[kind] ?? '/reports'
}

// Tham số kỳ trên URL theo màn: ngày / tuần / tháng / quý.
export function periodParam(path: string) {
  if (path.endsWith('/daily')) return 'date'
  if (path.endsWith('/weekly-kpi') || path.endsWith('/academic-weekly')) return 'week'
  if (path.endsWith('/academic-quarterly')) return 'quarter'
  return 'month'
}

export function readQuery(key: string, search = window.location.search) {
  return new URLSearchParams(search).get(key) ?? ''
}

// Ghi tham số lên thanh địa chỉ (không thêm lịch sử): giữ đường dẫn, bỏ tham số rỗng.
export function writeQuery(params: Record<string, string | number | null | undefined>) {
  const q = new URLSearchParams(window.location.search)
  for (const [k, v] of Object.entries(params)) {
    if (v === null || v === undefined || v === '') q.delete(k)
    else q.set(k, String(v))
  }
  const s = q.toString()
  window.history.replaceState(null, '', window.location.pathname + (s ? `?${s}` : ''))
}

const VN_TZ = 'Asia/Ho_Chi_Minh'
const DATE_ONLY = /^(\d{4})-(\d{2})-(\d{2})$/

// formatDate theo mẫu kiểu Laravel ('H:i d/m/Y', 'd/m', ...), giờ Việt Nam. Chuỗi "Y-m-d" là ngày thuần, không đổi múi.
export function formatDate(value: string | null | undefined, pattern = 'd/m/Y') {
  if (!value) return '—'
  let parts: Record<string, string>
  const m = DATE_ONLY.exec(value)
  if (m) {
    parts = { Y: m[1], m: m[2], d: m[3], H: '00', i: '00' }
  } else {
    const d = new Date(value)
    if (Number.isNaN(d.getTime())) return value
    const f = new Intl.DateTimeFormat('en-GB', {
      timeZone: VN_TZ,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    }).formatToParts(d)
    const get = (t: string) => f.find((p) => p.type === t)?.value ?? ''
    parts = { Y: get('year'), m: get('month'), d: get('day'), H: get('hour'), i: get('minute') }
  }
  return pattern.replace(/[YmdHi]/g, (t) => parts[t])
}

type Tone = 'neutral' | 'success' | 'error' | 'info'

// Nhãn trạng thái kiểm tra ở danh sách Tổng hợp / chi tiết.
export function reviewBadge(r: Pick<AllRow, 'type' | 'status' | 'reviewed_at'>): { label: string; tone: Tone } | null {
  if (r.type === 'journal') return null
  if (r.status === 'returned') return { label: 'Đã yêu cầu bổ sung', tone: 'error' }
  if (r.reviewed_at) return { label: 'Đã kiểm tra', tone: 'success' }
  return { label: 'Chờ kiểm tra', tone: 'info' }
}

// Nhãn một kỳ trong danh sách lịch sử: Nháp / Chưa nộp / Cần bổ sung / Đã kiểm tra.
export function historyBadge(item: HistoryItem): { label: string; tone: Tone } | null {
  if (item.draft) return { label: 'Nháp', tone: 'neutral' }
  if (item.missing) return { label: 'Chưa nộp', tone: 'error' }
  if (item.returned) return { label: 'Cần bổ sung', tone: 'error' }
  if (item.reviewed) return { label: 'Đã kiểm tra', tone: 'success' }
  return null
}

// Băng trạng thái kiểm tra cho người viết; null = không hiện.
export function reviewNotice(review: Review | null) {
  if (review?.status === 'returned') return { tone: 'error' as const, title: 'Admin yêu cầu bổ sung' }
  if (review?.status === 'reviewed') return { tone: 'success' as const, title: '' }
  return null
}

// Báo cáo ngày còn thiếu ngoài ngày đang xem: hiện tối đa 6 ngày, phần còn lại gộp "và N ngày khác".
export function missingSummary<T extends { date: string }>(days: T[], current: string, limit = 6) {
  const others = days.filter((d) => d.date !== current)
  return { shown: others.slice(0, limit), extra: Math.max(0, others.length - limit), total: others.length }
}

// ───────────── Báo cáo tuần Học thuật ─────────────

export type Line = WeeklyRow & { uid: number }
let uid = 0

export function blankRow(): Line {
  return { uid: ++uid, content: null, detail: null, deadline: null, progress: null, link: null, report: null, support: null, note: null, source: null }
}

export function toLine(r: Partial<WeeklyRow>): Line {
  return { ...blankRow(), ...r, uid: ++uid }
}

// Bảng ban đầu: tuần đã nộp lấy dòng đã lưu; tuần chưa nộp lấy dòng gợi ý từ hệ thống; trống thì một dòng trắng.
export function initialLines(sections: string[], rows: Record<string, WeeklyRow[]>, suggestions: Record<string, WeeklyRow[]>) {
  const submitted = Object.values(rows ?? {}).some((r) => r?.length)
  return Object.fromEntries(
    sections.map((key) => {
      const start = submitted ? (rows?.[key] ?? []) : (suggestions?.[key] ?? [])
      return [key, start.length ? start.map(toLine) : [blankRow()]]
    }),
  ) as Record<string, Line[]>
}

// Gợi ý chưa có trong bảng (so theo mã nguồn) → nút "Thêm từ hệ thống".
export function missingSuggestions(lines: Line[], suggestions: WeeklyRow[] = []) {
  const have = new Set(lines.map((r) => r.source).filter(Boolean))
  return suggestions.filter((r) => !have.has(r.source))
}

// Dữ liệu gửi lên: bỏ uid, chuỗi trắng thành null (máy chủ bỏ dòng trống).
export function rowsPayload(lines: Record<string, Line[]>) {
  const clean = (v: string | null) => (v && v.trim() ? v : null)
  return Object.fromEntries(
    Object.entries(lines).map(([key, rows]) => [
      key,
      rows.map(({ uid: _uid, ...r }) => ({
        ...r,
        content: clean(r.content),
        detail: clean(r.detail),
        deadline: clean(r.deadline),
        link: clean(r.link),
        report: clean(r.report),
        support: clean(r.support),
        note: clean(r.note),
      })),
    ]),
  ) as Record<string, WeeklyRow[]>
}

// ───────────── Báo cáo tháng giáo viên ─────────────

// Gợi ý chuyên cần (≥ 95% → cao) / bài tập (> 80% → cao) từ số liệu lớp khi chưa chọn.
export function suggestLevels(c: TeacherClass, saved: ClassValue = {}) {
  const pick = (k: string) => (typeof saved[k] === 'string' ? (saved[k] as string) : null)
  return {
    program_progress: pick('program_progress'),
    attendance_level: pick('attendance_level') ?? (c.attendance === null ? null : c.attendance >= 95 ? 'high' : 'low'),
    homework_level: pick('homework_level') ?? (c.homework === null ? null : c.homework > 80 ? 'high' : 'low'),
  }
}

// Quý "2026-Q4" ↔ tháng bắt đầu 10/2026, kỳ "Tháng 10 - Tháng 12 / Năm 2026".
export function quarterPeriod(key: string) {
  const m = /^(\d{4})-Q([1-4])$/.exec(key)
  const year = m ? m[1] : ''
  const start = ((m ? Number(m[2]) : 1) - 1) * 3 + 1
  return { year, start, end: start + 2 }
}

// Báo cáo đang chờ trên trang (chọn để kiểm tra hàng loạt).
export function selectableIds(rows: AllRow[]) {
  return rows.filter((r) => r.reviewable && r.status === 'submitted' && !r.reviewed_at).map((r) => r.id)
}

// Hai bộ ghi chú giống nhau (bỏ khoảng trắng hai đầu, ô thiếu coi như rỗng).
export function sameNotes(a: Record<string, string>, b: Record<string, string>) {
  const norm = (v: unknown) => (v ?? '').toString().trim()
  return [...new Set([...Object.keys(a), ...Object.keys(b)])].every((k) => norm(a[k]) === norm(b[k]))
}
