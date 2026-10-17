// Logic thuần của màn Chấm công (tách khỏi component để test).
import type { Me } from '../../api'
import { can, normalize } from '../../nav'
import type { BranchInfo, Day, Expected, ManualForm, ManualInput, RequestType, TimesheetRow } from './api'

const VN_OFFSET = 7 * 3600 * 1000
const WEEKDAYS = ['Chủ nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy']
const pad = (n: number) => String(n).padStart(2, '0')

// Giờ Việt Nam của mốc thời gian (ms): đồng hồ "HH:MM:SS".
export function clockText(ms: number) {
  const d = new Date(ms + VN_OFFSET)
  return `${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}:${pad(d.getUTCSeconds())}`
}

// "Thứ Bảy, 17/10/2026" theo giờ Việt Nam.
export function dateText(ms: number) {
  const d = new Date(ms + VN_OFFSET)
  return `${WEEKDAYS[d.getUTCDay()]}, ${pad(d.getUTCDate())}/${pad(d.getUTCMonth() + 1)}/${d.getUTCFullYear()}`
}

// "2026-10-17" → "17/10/2026".
export function dateLabel(iso: string) {
  const [y, m, d] = iso.split('-')
  return d && m && y ? `${d}/${m}/${y}` : iso
}

// "2026-10-17" → thứ trong tuần.
export function weekday(iso: string) {
  const [y, m, d] = iso.split('-').map(Number)
  return WEEKDAYS[new Date(Date.UTC(y, m - 1, d)).getUTCDay()]
}

// number_format kiểu Việt Nam: 1234 → "1.234".
export function num(n: number) {
  return Math.round(n)
    .toString()
    .replace(/\B(?=(\d{3})+(?!\d))/g, '.')
}

// Lý do không chấm công được (chưa gán cơ sở, cơ sở chưa cài tọa độ, kỳ lương đã khóa).
export function blocker(branch: BranchInfo | null, locked: string | null) {
  if (!branch) return 'Tài khoản của bạn chưa được gán cơ sở làm việc. Hãy báo Admin / Quản lý cơ sở.'
  if (!branch.has_location) return `Cơ sở ${branch.name} chưa cài toạ độ chấm công. Hãy báo Admin vào Cài đặt → Cơ sở & chi nhánh → Chấm công.`
  return locked
}

export const nextKind = (today: Day | null): 'in' | 'out' => (today?.check_in ? 'out' : 'in')

export function actionLabel(today: Day | null) {
  if (nextKind(today) === 'in') return 'Chấm công vào'
  return today?.check_out ? 'Chấm công ra lại' : 'Chấm công ra'
}

// Dòng giờ phải có mặt dưới tên cơ sở.
export function expectedText(e: Expected) {
  if (!e.start) return `${e.basis} hôm nay, không tính đi muộn`
  return `Giờ vào ${e.start}${e.end ? ` · giờ ra ${e.end}` : ''} (${e.basis.toLocaleLowerCase('vi')})`
}

// Khoảng cách tới cơ sở có nằm trong bán kính.
export function inRange(distance: number | null, branch: BranchInfo | null) {
  return distance !== null && !!branch && distance <= branch.radius
}

// Nhắc đi muộn dưới thẻ "Hôm nay".
export function lateNote(today: Day | null) {
  if (!today?.late_minutes || today.late_excused) return null
  const head = `Đi muộn ${today.late_minutes} phút`
  const tail = 'Có lý do thì gửi đơn "Xin đi muộn / về sớm".'
  return today.penalty ? `${head}: hệ thống đã lập biên bản ${today.penalty.code} chờ giải trình. ${tail}` : `${head}. ${tail}`
}

export const REQUEST_ICONS: Record<RequestType, string> = { correction: 'edit_calendar', late_early: 'schedule', leave: 'beach_access' }
export const REQUEST_HINTS: Record<RequestType, string> = {
  correction: 'Quên / không chấm được công, khai giờ vào – ra',
  late_early: 'Báo trước hoặc giải trình đi muộn, về sớm 1 ngày',
  leave: 'Nghỉ có phép từ ngày đến ngày',
}

export function isRequestType(v: string): v is RequestType {
  return v === 'correction' || v === 'late_early' || v === 'leave'
}

// "Yêu cầu khác theo vai trò" (MyAttendanceController::ROLE_REQUESTS) — chỉ các màn đã có ở v2.
// TODO(gộp): thêm "Đề xuất sửa giáo trình", "Xin điều chỉnh tiến độ" (Giáo trình) và "Gửi ticket hỗ trợ" khi có ở v2.
const ROLE_REQUESTS = [
  { label: 'Hoàn tiền & khất nợ học phí', path: '/tuition/refunds', icon: 'currency_exchange', perms: ['refund_transfer.request'] },
  { label: 'Yêu cầu hủy hóa đơn', path: '/tuition/invoices/cancellations', icon: 'receipt_long', perms: ['invoice.request_cancel'] },
  { label: 'Nhờ hỗ trợ / giao việc', path: '/tasks/new', icon: 'assignment_add', perms: ['work_task.request', 'work_task.create'] },
]
export function roleRequests(me: Me) {
  return ROLE_REQUESTS.filter((r) => can(me, ...r.perms))
}

// Ô tìm nhân viên của form chấm tay: tìm không dấu theo tên / mã / email, luôn giữ người đang chọn.
export function filterTeachers<T extends { id: string; search: string }>(list: T[], q: string, selected: string) {
  const key = normalize(q)
  return key ? list.filter((t) => normalize(t.search).includes(key) || t.id === selected) : list
}

// Lớp của chi nhánh đã chọn (không chọn = mọi lớp).
export function classesOfBranch<T extends { branch: string }>(list: T[], branch: string) {
  return branch ? list.filter((c) => c.branch === branch) : list
}

// Kỳ lương đã khóa chứa ngày (form chấm tay cảnh báo + khóa nút lưu).
export function lockedRangeFor(ranges: ManualForm['locked_ranges'], date: string) {
  return ranges.find((r) => date && date >= r.from && date <= r.to) ?? null
}

export type ManualState = {
  user_id: string
  branch_id: string
  class_id: string
  teaching_date: string
  time_in: string
  time_out: string
  late_minutes: string
  early_leave_minutes: string
  late_notified: boolean
  type: string
  hourly_rate: string
  notes: string
}

// Giá trị đầu của form chấm tay: tham số URL (mở từ "Buổi thiếu chấm công") hoặc mặc định.
export function manualDefaults(form: ManualForm, params: URLSearchParams): ManualState {
  return {
    user_id: params.get('user_id') ?? '',
    branch_id: params.get('branch_id') ?? form.default_branch,
    class_id: params.get('class_id') ?? '',
    teaching_date: params.get('teaching_date') ?? form.today,
    time_in: params.get('time_in') ?? '',
    time_out: params.get('time_out') ?? '',
    late_minutes: '0',
    early_leave_minutes: '0',
    late_notified: false,
    type: 'regular',
    hourly_rate: '',
    notes: '',
  }
}

const toNum = (s: string) => (s.trim() === '' ? null : Number(s))

export function toManualInput(s: ManualState): ManualInput {
  return {
    user_id: s.user_id,
    branch_id: s.branch_id,
    class_id: s.class_id,
    teaching_date: s.teaching_date,
    time_in: s.time_in,
    time_out: s.time_out,
    hourly_rate: toNum(s.hourly_rate),
    late_minutes: toNum(s.late_minutes),
    early_leave_minutes: toNum(s.early_leave_minutes),
    late_notified: s.late_notified,
    type: s.type,
    notes: s.notes,
  }
}

// Ca được chọn để "Chốt bảng công": đang chờ đối soát và chưa khóa.
export function selectableIds(rows: TimesheetRow[]) {
  return rows.filter((r) => r.status === 'pending_review' && !r.locked).map((r) => r.id)
}

// Cột "Ca học".
export function shiftText(ts: Pick<TimesheetRow, 'scheduled_time' | 'shift_name'>) {
  if (!ts.scheduled_time) return 'Ngoài lịch'
  return `${ts.shift_name ? `${ts.shift_name} · ` : 'Ca '}${ts.scheduled_time}`
}

export const PUNCH_TONE: Record<TimesheetRow['punch_state'], 'success' | 'info' | 'warning' | 'error'> = {
  full: 'success',
  adjusted: 'info',
  missing_in: 'warning',
  missing_out: 'error',
}
export const STATUS_TONE: Record<TimesheetRow['status'], 'success' | 'error' | 'warning'> = {
  valid: 'success',
  invalid: 'error',
  pending_review: 'warning',
}
export const statusText = (ts: Pick<TimesheetRow, 'status' | 'status_label'>) => (ts.status === 'pending_review' ? 'Chờ đối soát' : ts.status_label)

// Biểu tượng + màu của trạng thái đợt đồng bộ.
export function syncStyle(status: string): [string, string] {
  if (status === 'success') return ['check_circle', 'text-success']
  if (status === 'partial') return ['warning', 'text-warning']
  return ['error', 'text-error']
}

// Đường dẫn form chấm tay điền sẵn từ một buổi thiếu chấm công.
export function manualUrl(userId: string, s: { class_id: string; branch_id: string; date: string; start_time: string; end_time: string }) {
  const q = new URLSearchParams({ user_id: userId, class_id: s.class_id, branch_id: s.branch_id, teaching_date: s.date, time_in: s.start_time, time_out: s.end_time })
  return `/payroll/timesheets/manual?${q.toString()}`
}
