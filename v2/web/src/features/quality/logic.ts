// Logic thuần của Chất lượng lớp & Dự án học thuật (định dạng, form ↔ API, lọc lớp theo giáo viên, thiếu sót checklist).
import type {
  AcademicClass,
  AcademicInput,
  FormClass,
  Meeting,
  MeetingInput,
  Milestone,
  MilestoneInput,
  Operation,
  OperationInput,
  ProjectDetail,
  ProjectInput,
} from './api'

export type BadgeTone = 'neutral' | 'success' | 'error' | 'info' | 'warning'

// Màu của Laravel (primary / secondary / success / warning / error / neutral) → tông Badge của v2.
export function badgeTone(color: string | null | undefined): BadgeTone {
  switch (color) {
    case 'success':
      return 'success'
    case 'warning':
      return 'warning'
    case 'error':
      return 'error'
    case 'primary':
      return 'info'
    default:
      return 'neutral'
  }
}

// "2026-10-17" → "17/10/2026"; rỗng → "—".
export function formatDate(ymd: string | null | undefined): string {
  if (!ymd) return '—'
  const [y, m, d] = ymd.slice(0, 10).split('-')
  return d && m && y ? `${d}/${m}/${y}` : ymd
}

// "2026-10-17" → "17/10".
export function dayMonth(ymd: string | null | undefined): string {
  if (!ymd) return '—'
  const [, m, d] = ymd.slice(0, 10).split('-')
  return `${d}/${m}`
}

const VN_OFFSET = 7 * 60

function vnParts(iso: string) {
  const t = new Date(iso)
  if (Number.isNaN(t.getTime())) return null
  const v = new Date(t.getTime() + VN_OFFSET * 60_000)
  const p = (n: number) => String(n).padStart(2, '0')
  return {
    date: `${p(v.getUTCDate())}/${p(v.getUTCMonth() + 1)}`,
    year: v.getUTCFullYear(),
    time: `${p(v.getUTCHours())}:${p(v.getUTCMinutes())}`,
  }
}

// Thời điểm ISO → "HH:mm dd/mm/yyyy" giờ Việt Nam.
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  const p = vnParts(iso)
  return p ? `${p.time} ${p.date}/${p.year}` : iso
}

// Thời điểm ISO → "dd/mm HH:mm" giờ Việt Nam.
export function shortDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  const p = vnParts(iso)
  return p ? `${p.date} ${p.time}` : iso
}

// Tỉ lệ % (1 chữ số lẻ, dấu phẩy) hoặc "—".
export function rate(v: number | null | undefined): string {
  if (v === null || v === undefined) return '—'
  return `${(Math.round(v * 10) / 10).toString().replace('.', ',')}%`
}

// Tuần "12/10 – 18/10/2026".
export function weekLabel(start: string, end: string): string {
  return `${dayMonth(start)} – ${formatDate(end)}`
}

// Thứ Hai của tuần chứa ngày ymd ("" nếu sai).
export function mondayOf(ymd: string): string {
  if (!/^\d{4}-\d{2}-\d{2}/.test(ymd)) return ''
  const d = new Date(`${ymd.slice(0, 10)}T00:00:00Z`)
  if (Number.isNaN(d.getTime())) return ''
  const wd = (d.getUTCDay() + 6) % 7
  d.setUTCDate(d.getUTCDate() - wd)
  return d.toISOString().slice(0, 10)
}

// Tuần (thứ Hai – Chủ nhật) chứa ngày đang chọn: "12/10/2026 - 18/10/2026"; null nếu ngày sai.
export function weekRange(ymd: string): string | null {
  const start = mondayOf(ymd)
  return start ? `${formatDate(start)} - ${formatDate(addDays(start, 6))}` : null
}

export function addDays(ymd: string, n: number): string {
  const d = new Date(`${ymd}T00:00:00Z`)
  d.setUTCDate(d.getUTCDate() + n)
  return d.toISOString().slice(0, 10)
}

// Tham số query (bỏ giá trị rỗng).
export function query(params: Record<string, string | number | undefined>) {
  const q = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== '' && v !== 0) q.set(k, String(v))
  const s = q.toString()
  return s ? `?${s}` : ''
}

const trimOrNull = (s: string) => (s.trim() === '' ? null : s)

// ───────────────────────── Dự giờ vận hành ─────────────────────────

// Lớp đang hoạt động của giáo viên (GV chính hoặc GVNN).
export function classesForTeacher(classes: FormClass[], teacherId: string): FormClass[] {
  if (!teacherId) return []
  return classes.filter((c) => c.teacher_ids.includes(teacherId))
}

// Khi sửa bản ghi của giáo viên / lớp không còn hoạt động: giữ lựa chọn cũ trong danh sách.
export function withCurrent<T extends { value: string; label: string }>(options: T[], current: T | null): T[] {
  if (!current || !current.value || options.some((o) => o.value === current.value)) return options
  return [...options, current]
}

// Đổi giáo viên: giữ lớp nếu vẫn thuộc giáo viên đó, không thì tự chọn khi chỉ còn một lớp.
export function pickClass(options: FormClass[], classId: string): string {
  if (options.some((c) => c.value === classId)) return classId
  return options.length === 1 ? options[0].value : ''
}

export type OperationForm ={ observed_on: string; teacher_id: string; class_id: string; rating: string; notes: Record<string, string> }

export function operationForm(o: Operation | null, today: string): OperationForm {
  const notes: Record<string, string> = {}
  for (const [k, v] of Object.entries(o?.notes ?? {})) notes[k] = v ?? ''
  return { observed_on: o?.observed_on ?? today, teacher_id: o?.teacher_id ?? '', class_id: o?.class_id ?? '', rating: o?.rating ?? '', notes }
}

export function toOperationInput(f: OperationForm): OperationInput {
  const n = (k: string) => trimOrNull(f.notes[k] ?? '')
  return {
    observed_on: f.observed_on,
    teacher_id: f.teacher_id,
    class_id: f.class_id,
    rating: f.rating,
    lesson_content: n('lesson_content'),
    attitude: n('attitude'),
    preparation: n('preparation'),
    technique: n('technique'),
    suggestions: n('suggestions'),
  }
}

// Các mục nội dung đã ghi của một lượt dự giờ, theo thứ tự hiển thị.
export function filledNotes(notes: Record<string, string | null>, fields: { key: string; label: string }[]) {
  return fields.filter((f) => (notes[f.key] ?? '').trim() !== '')
}

// ───────────────────────── Đánh giá dự giờ ─────────────────────────

export type AcademicForm = {
  attendance_rate: string
  pass_rate: string
  observed: boolean
  observed_on: string
  observer_id: string
  action_notes: string
  criteria: Record<string, string>
}

const numText = (v: number | null | undefined) => (v === null || v === undefined ? '' : String(v))

export function academicForm(c: AcademicClass, currentUserId: string): AcademicForm {
  const ev = c.evaluation
  const criteria: Record<string, string> = {}
  for (const [k, v] of Object.entries(ev?.criteria ?? {})) criteria[k] = v ?? ''
  return {
    attendance_rate: numText(ev?.attendance_rate ?? c.suggested_attendance),
    pass_rate: numText(ev?.pass_rate),
    observed: ev ? ev.observed : true,
    observed_on: ev?.observed_on ?? '',
    observer_id: ev?.observer_id ?? currentUserId,
    action_notes: ev?.action_notes ?? '',
    criteria,
  }
}

const numOrNull = (s: string) => (s.trim() === '' ? null : Number(s.replace(',', '.')))

export function toAcademicInput(classId: string, month: string, f: AcademicForm): AcademicInput {
  const c = (k: string) => (f.observed ? trimOrNull(f.criteria[k] ?? '') : null)
  return {
    class_id: classId,
    month,
    attendance_rate: numOrNull(f.attendance_rate),
    pass_rate: numOrNull(f.pass_rate),
    observed: f.observed,
    observed_on: f.observed ? f.observed_on || null : null,
    observer_id: f.observed ? f.observer_id || null : null,
    action_notes: f.observed ? trimOrNull(f.action_notes) : null,
    teaching_quality: c('teaching_quality'),
    lesson_content: c('lesson_content'),
    interaction: c('interaction'),
    attitude: c('attitude'),
    effectiveness: c('effectiveness'),
    overall: c('overall'),
  }
}

// ───────────────────────── Checklist ─────────────────────────

// Mục "đến hạn" = Có mà việc cần làm = Không → thiếu sót (ClassChecklist::gaps).
export function checklistGaps(values: Record<string, string | null | undefined>, followUps: Record<string, string[]>): string[] {
  return Object.entries(followUps).flatMap(([due, items]) => (values[due] === 'yes' ? items.filter((i) => values[i] === 'no') : []))
}

// ───────────────────────── Họp giáo viên ─────────────────────────

export type MeetingForm = {
  week_start: string
  teacher_id: string
  class_id: string
  syllabus_note: string
  scores_note: string
  class_note: string
  academic_order: string
  recommendation: string
  status: string
}

export function meetingForm(m: Meeting | null, currentWeek: string): MeetingForm {
  return {
    week_start: m?.week_start ?? currentWeek,
    teacher_id: m?.teacher_id ?? '',
    class_id: m?.class_id ?? '',
    syllabus_note: m?.syllabus_note ?? '',
    scores_note: m?.scores_note ?? '',
    class_note: m?.class_note ?? '',
    academic_order: m?.academic_order ?? '',
    recommendation: m?.recommendation ?? '',
    status: m?.status ?? 'in_progress',
  }
}

export function toMeetingInput(f: MeetingForm): MeetingInput {
  return {
    week_start: f.week_start,
    teacher_id: f.teacher_id,
    class_id: f.class_id || null,
    syllabus_note: trimOrNull(f.syllabus_note),
    scores_note: trimOrNull(f.scores_note),
    class_note: f.class_note,
    academic_order: trimOrNull(f.academic_order),
    recommendation: trimOrNull(f.recommendation),
    status: f.status,
  }
}

// ───────────────────────── Dự án học thuật ─────────────────────────

export type ProjectForm = {
  name: string
  type: string
  owner_id: string
  member_ids: string[]
  start_date: string
  deadline: string
  description: string
  kickoff_notes: string
  kickoff_link: string
}

export function projectForm(p: ProjectDetail['project'] | null): ProjectForm {
  return {
    name: p?.name ?? '',
    type: p?.type ?? 'book',
    owner_id: p?.owner_id ?? '',
    member_ids: p?.member_ids ?? [],
    start_date: p?.start_date ?? '',
    deadline: p?.deadline ?? '',
    description: p?.description ?? '',
    kickoff_notes: p?.kickoff_notes ?? '',
    kickoff_link: p?.kickoff_link ?? '',
  }
}

export function toProjectInput(f: ProjectForm): ProjectInput {
  return {
    name: f.name,
    type: f.type,
    owner_id: f.owner_id,
    member_ids: f.member_ids,
    start_date: f.start_date || null,
    deadline: f.deadline || null,
    description: trimOrNull(f.description),
    kickoff_notes: trimOrNull(f.kickoff_notes),
    kickoff_link: trimOrNull(f.kickoff_link),
  }
}

// Bật / tắt một người trong danh sách thành viên.
export function toggleMember(ids: string[], id: string): string[] {
  return ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]
}

// Lọc nhân sự theo ô tìm (không phân biệt hoa thường / dấu).
export function filterStaff<T extends { label: string }>(staff: T[], search: string): T[] {
  const norm = (s: string) =>
    s
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .replace(/đ/g, 'd')
      .replace(/Đ/g, 'D')
      .toLowerCase()
  const q = norm(search.trim())
  return q ? staff.filter((s) => norm(s.label).includes(q)) : staff
}

export type MilestoneForm = {
  title: string
  assignee_id: string
  status: string
  start_date: string
  due_date: string
  target_quantity: string
  unit: string
  description: string
}

export function milestoneForm(m: Milestone | null): MilestoneForm {
  return {
    title: m?.title ?? '',
    assignee_id: m?.assignee_id ?? '',
    status: m?.status ?? 'todo',
    start_date: m?.start_date ?? '',
    due_date: m?.due_date ?? '',
    target_quantity: m ? String(m.target_quantity) : '1',
    unit: m?.unit ?? 'unit',
    description: m?.description ?? '',
  }
}

export function toMilestoneInput(f: MilestoneForm, editing: boolean): MilestoneInput {
  return {
    title: f.title,
    assignee_id: f.assignee_id,
    status: editing ? f.status : '',
    start_date: f.start_date || null,
    due_date: f.due_date || null,
    target_quantity: numOrNull(f.target_quantity),
    unit: f.unit,
    description: trimOrNull(f.description),
  }
}

// "0 / 12 unit" → "12 unit" (khối lượng cần làm của mốc).
export function targetOf(quantityLabel: string): string {
  return quantityLabel.split(' / ')[1] ?? quantityLabel
}

// Mốc người dùng cập nhật được (mốc chưa xong mình nhận, hoặc mọi mốc với người quản lý).
export function updatableMilestones(ms: Milestone[]): Milestone[] {
  return ms.filter((m) => m.can_update && m.status !== 'done')
}

// Tên miền ngắn gọn của link sản phẩm.
export function linkHost(url: string): string {
  try {
    return new URL(url).hostname.replace(/^www\./, '')
  } catch {
    return url
  }
}

// Số ngày còn lại tới hết ngày deadline (âm = đã quá); null khi không có deadline.
export function daysLeft(deadline: string | null | undefined, now: Date): number | null {
  if (!deadline) return null
  const end = new Date(`${deadline}T23:59:59+07:00`)
  return Math.ceil((end.getTime() - now.getTime()) / 86_400_000)
}

// Màu thanh tiến độ: xanh khi xong, đỏ khi có mốc trễ.
export function progressTone(value: number, overdue: boolean): 'done' | 'overdue' | 'normal' {
  if (value >= 100) return 'done'
  return overdue ? 'overdue' : 'normal'
}

// Số tiền "150.000 ₫".
export function money(n: number): string {
  return `${Math.round(n).toLocaleString('vi-VN')} ₫`
}

// Hạn của dự án: "Còn 3 ngày" / "Quá 2 ngày"; null khi không có deadline hoặc dự án đã đóng.
export function deadlineHint(days: number | null, open: boolean): string | null {
  if (days === null || !open) return null
  return days >= 0 ? `Còn ${days} ngày` : `Quá ${-days} ngày`
}

export const isOpenStatus = (status: string) => ['planning', 'active', 'paused'].includes(status)

export const PENALTY_TONES: Record<string, BadgeTone> = {
  pending: 'warning',
  explained: 'info',
  confirmed: 'error',
  fined: 'error',
  deducted: 'error',
  paid: 'success',
  resolved: 'success',
  cancelled: 'neutral',
}

export const MILESTONE_TONES:Record<string, BadgeTone> = { todo: 'neutral', in_progress: 'info', done: 'success' }
export const DEADLINE_BADGE: Record<string, { tone: BadgeTone; label: string }> = {
  overdue: { tone: 'error', label: 'Trễ hạn' },
  soon: { tone: 'warning', label: 'Sắp đến hạn' },
}
