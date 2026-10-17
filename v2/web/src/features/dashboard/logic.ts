// Hàm thuần của Tổng quan: định dạng số, màu nhãn, lời chào, nội dung băng "Báo cáo ngày", dòng biểu đồ thanh.
import type { Me } from '../../api'
import type { ClassMetric, DailyReport, TeamRow, TeacherReport } from './api'

// Quyền mở Tổng quan (route GET /dashboard): mọi nhân sự (portal.staff) hoặc người có bảng điều hành / học thuật.
// Học viên, phụ huynh không có → trang "/" là Trang chủ cổng học viên (routes.tsx, như Laravel).
export const DASHBOARD_PERMS = ['portal.staff', 'dashboard.operations', 'dashboard.academic']

export function seesDashboard(me: Me) {
  return DASHBOARD_PERMS.some((p) => me.permissions.includes(p))
}

// Điểm / tỷ lệ như formatScore / formatPercent của Laravel: tối đa 1 chữ số thập phân, dấu phẩy, null → "—".
export function formatScore(v: number | null | undefined) {
  return v === null || v === undefined ? '—' : v.toLocaleString('vi-VN', { maximumFractionDigits: 1 })
}

export function formatPercent(v: number | null | undefined) {
  return v === null || v === undefined ? '—' : `${formatScore(v)}%`
}

// Số nguyên có phân tách hàng nghìn bằng dấu phẩy (formatNumber của bảng điều hành Laravel).
export function formatCount(v: number | null | undefined) {
  if (v === null || v === undefined) return '—'
  return String(Math.trunc(v)).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
}

// "2026-10-17" hoặc ISO → "17/10/2026"; withTime thêm "HH:MM " phía trước (giờ Việt Nam).
export function formatDate(value: string | null | undefined, withTime = false) {
  if (!value) return ''
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    const [y, m, d] = value.split('-')
    return `${d}/${m}/${y}`
  }
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-GB', {
      timeZone: 'Asia/Ho_Chi_Minh',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    })
      .formatToParts(date)
      .map((p) => [p.type, p.value]),
  )
  const day = `${parts.day}/${parts.month}/${parts.year}`
  return withTime ? `${parts.hour}:${parts.minute} ${day}` : day
}

// Màu nhãn loại lịch hẹn (AgendaCard.vue) theo token có sẵn.
const KIND_CLASS: Record<string, string> = {
  test: 'bg-primary-light text-primary',
  trial: 'bg-success-container text-on-success-container',
  call: 'bg-warning-container text-warning',
  care: 'bg-info-container text-on-info-container',
  bigtest: 'bg-surface-container-highest text-on-surface',
  project: 'bg-error-container text-error',
}

export function kindClass(kind: string) {
  return KIND_CLASS[kind] ?? 'bg-surface-container-high text-on-surface-variant'
}

// Màu số trên thẻ số liệu (tone của UiStatCard).
const TONE_CLASS: Record<string, string> = {
  primary: 'text-primary',
  secondary: 'text-primary-hover',
  success: 'text-success',
  warning: 'text-warning',
  error: 'text-error',
}

export function toneClass(tone: string) {
  return TONE_CLASS[tone] ?? 'text-on-surface'
}

// Số cột lưới thẻ số liệu theo số thẻ (MyWork.vue / RoleWidgets.vue).
export function statGridClass(n: number, personal: boolean) {
  if (n >= 5) return personal ? 'lg:grid-cols-3 xl:grid-cols-5' : 'xl:grid-cols-5'
  if (n === 4 && personal) return 'xl:grid-cols-4'
  return 'xl:grid-cols-3'
}

export type Welcome = { name: string; roles: string; branch: string; salaryUrl: string | null; tasksUrl: string | null }

// Lời chào cho người không có bảng điều hành (DashboardController::__invoke 'welcome'). Lương của tôi: phân hệ Lương
// chưa có ở v2 nên không có nút (TODO(gộp): /portal/my-salary khi có phân hệ Lương).
export function welcomeOf(me: Me): Welcome {
  const primary = me.branches.find((b) => b.primary) ?? me.branches[0]
  return {
    name: me.user.full_name,
    roles: me.roles.map((r) => r.short_label || r.label).join(', ') || 'Nhân viên',
    branch: primary?.name ?? 'Trung tâm',
    salaryUrl: null,
    tasksUrl: me.permissions.includes('work_task.view') ? '/portal/ta-tasks' : null,
  }
}

export type Banner = {
  attention: boolean
  missing: { label: string; href: string }[]
  more: number
  deadline: string | null
  returned: { label: string; href: string; note: string | null }[]
  allDone: boolean
  today: string
  action: { label: string; href: string }
}

// Nội dung băng "Báo cáo ngày" trên Tổng quan: tối đa 5 ngày còn thiếu (mới trước) + "và N ngày khác", hạn của ngày gần
// nhất khi chưa quá hạn, các báo cáo Admin yêu cầu bổ sung, tình trạng hôm nay, nút nộp ngày thiếu gần nhất / hôm nay.
export function bannerOf(d: DailyReport): Banner {
  const first = d.missing[0]
  return {
    attention: d.missing.length > 0 || d.returned.length > 0,
    missing: d.missing.slice(0, 5).map((m) => ({ label: m.label, href: m.href })),
    more: Math.max(0, d.missing.length - 5),
    deadline: first && !first.overdue ? first.deadline : null,
    returned: d.returned,
    allDone: !d.missing.length && !d.returned.length,
    today: d.today_submitted ? 'đã nộp' : 'chưa nộp (hạn 09:00 sáng mai)',
    action: first ? { label: `Nộp báo cáo ${first.label}`, href: first.href } : { label: 'Viết báo cáo hôm nay', href: d.today_href },
  }
}

export type BarRow = { key: string; label: string; value: number | null; hint: string }

export function classBars(classes: ClassMetric[], key: 'attendance' | 'homework' | 'score'): BarRow[] {
  return classes.map((c) => ({ key: c.id, label: c.code || c.name, value: c[key], hint: `${c.name} · ${c.students} học sinh` }))
}

export function teamBars(rows: TeamRow[], key: 'attendance' | 'homework' | 'score'): BarRow[] {
  return rows.map((t) => ({ key: t.id, label: t.name, value: t[key], hint: `${t.classes} lớp · ${t.students} học sinh` }))
}

// Độ rộng thanh (0–100%) theo giá trị trên thang max.
export function barWidth(value: number, max: number) {
  return `${Math.max(0, Math.min(100, (value / max) * 100))}%`
}

export function supportCount(r: TeacherReport) {
  return r.classes.filter((c) => c.need_support).length
}

// Dòng tóm tắt báo cáo tháng giáo viên trong danh sách.
export function reportSummary(r: TeacherReport) {
  if (!r.submitted) return null
  return r.difficulties || r.progress || r.proposals || 'Chỉ báo cáo theo lớp'
}
