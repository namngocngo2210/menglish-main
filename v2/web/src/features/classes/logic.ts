// Logic thuần của phân hệ Lớp học (không phụ thuộc React) để test riêng.
import type { AttendanceRecord, ClassInput, FormValues, ScheduleData, ShiftOption } from './api'

// Đường dẫn: /classes → danh sách; /classes/new → tạo; /classes/<id>[?tab=] → trang lớp; /classes/<id>/edit → sửa;
// /classes/<id>/attendance → điểm danh buổi.
export type ClassRoute =
  | { page: 'list' }
  | { page: 'new' }
  | { page: 'show'; id: string }
  | { page: 'edit'; id: string }
  | { page: 'attendance'; id: string }

export function parseClassPath(path: string): ClassRoute {
  const parts = path.replace(/\/+$/, '').split('/').filter(Boolean)
  if (parts[0] !== 'classes' || parts.length === 1) return { page: 'list' }
  if (parts[1] === 'new' && parts.length === 2) return { page: 'new' }
  const id = decodeURIComponent(parts[1])
  if (parts[2] === 'edit') return { page: 'edit', id }
  if (parts[2] === 'attendance') return { page: 'attendance', id }
  return { page: 'show', id }
}

// Màu badge (warning | secondary | success | neutral | error | primary) → lớp Tailwind.
export function toneClass(tone: string): string {
  switch (tone) {
    case 'success':
      return 'bg-success-container text-on-success-container'
    case 'warning':
      return 'bg-warning-container text-warning'
    case 'error':
      return 'bg-error-container text-on-error-container'
    case 'secondary':
    case 'info':
      return 'bg-info-container text-on-info-container'
    case 'primary':
      return 'bg-primary-light text-primary'
    default:
      return 'bg-surface-container-high text-on-surface-variant'
  }
}

// 2026-03-02 → 02/03/2026.
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso)
  return m ? `${m[3]}/${m[2]}/${m[1]}` : iso
}

const WEEKDAYS = ['Chủ nhật', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7']
// weekday theo dayOfWeek (0 = Chủ nhật).
export function weekdayName(n: number): string {
  return WEEKDAYS[n] ?? ''
}

// Sĩ số "12/20" (0 = không giới hạn → "12").
export function seatText(occupied: number, capacity: number): string {
  return capacity > 0 ? `${occupied}/${capacity}` : String(occupied)
}

export type ClassForm = {
  name: string
  code: string
  branch_id: string
  program: string
  level: string
  max_capacity: string
  min_students: string
  room_id: string
  teacher_id: string
  foreign_teacher_id: string
  assistant_id: string
  tuition_fee: string
  notes: string
  status: string
}

export const emptyForm: ClassForm = {
  name: '',
  code: '',
  branch_id: '',
  program: '',
  level: '',
  max_capacity: '20',
  min_students: '6',
  room_id: '',
  teacher_id: '',
  foreign_teacher_id: '',
  assistant_id: '',
  tuition_fee: '',
  notes: '',
  status: '',
}

export function formFromValues(v: FormValues): ClassForm {
  return {
    name: v.name,
    code: v.code,
    branch_id: v.branch_id,
    program: v.program,
    level: v.level,
    max_capacity: String(v.max_capacity),
    min_students: String(v.min_students),
    room_id: v.room_id ?? '',
    teacher_id: v.teacher_id ?? '',
    foreign_teacher_id: v.foreign_teacher_id ?? '',
    assistant_id: v.assistant_id ?? '',
    tuition_fee: v.tuition_fee == null ? '' : String(v.tuition_fee),
    notes: v.notes,
    status: v.status,
  }
}

function intOrNull(v: string): number | null {
  if (v.trim() === '') return null
  const n = Number(v.replace(/[.,\s]/g, ''))
  return Number.isFinite(n) ? Math.trunc(n) : null
}

// Dữ liệu gửi API; khi sửa gửi kèm trạng thái và trợ giảng cố định (nếu lớp còn).
export function toInput(f: ClassForm, edit: boolean): ClassInput {
  const out: ClassInput = {
    name: f.name.trim(),
    code: f.code.trim(),
    branch_id: f.branch_id,
    program: f.program,
    level: f.level,
    max_capacity: intOrNull(f.max_capacity),
    min_students: intOrNull(f.min_students),
    room_id: f.room_id,
    teacher_id: f.teacher_id,
    foreign_teacher_id: f.foreign_teacher_id,
    tuition_fee: intOrNull(f.tuition_fee),
    notes: f.notes,
  }
  if (edit) {
    out.status = f.status
    out.assistant_id = f.assistant_id
  }
  return out
}

// Đếm học viên "Vắng" / "Vắng có phép" chưa ghi lý do (như kiểm tra phía máy chủ).
export function missingNotes(records: AttendanceRecord[]): number {
  return records.filter((r) => (r.status === 'absent' || r.status === 'excused') && r.note.trim() === '').length
}

// Loại ngày của "Thứ 2"… "Chủ nhật" để lấy danh sách ca.
export function dayType(day: string): 'weekday' | 'saturday' | 'sunday' {
  if (day === 'Thứ 7') return 'saturday'
  if (day === 'Chủ nhật') return 'sunday'
  return 'weekday'
}

// Ca chọn được cho một ngày; "" khi chưa chọn ngày.
export function shiftsFor(options: Record<string, ShiftOption[]>, day: string): ShiftOption[] {
  if (!day) return []
  return options[dayType(day)] ?? []
}

// Cộng phút vào "HH:MM".
export function addMinutes(hm: string, minutes: number): string {
  const m = /^(\d{1,2}):(\d{2})$/.exec(hm)
  if (!m) return ''
  const total = (Number(m[1]) * 60 + Number(m[2]) + minutes + 24 * 60) % (24 * 60)
  return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`
}

export const emptySchedule: ScheduleData = {
  academic_year: '',
  start_date: '',
  end_date: '',
  slot1_day: '',
  slot1_start: '18:00',
  slot1_end: '19:30',
  slot2_day: '',
  slot2_start: '18:00',
  slot2_end: '19:30',
  room_id: '',
}

// Hỏi chuyển phòng (409 room_transfer): dòng đầu là câu hỏi, các dòng sau là lớp bị ảnh hưởng.
export function splitTransfer(message: string): { question: string; lines: string[] } {
  const [question, ...lines] = message.split('\n')
  return { question, lines: lines.filter(Boolean) }
}
