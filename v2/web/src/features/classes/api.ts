// API phân hệ Lớp học (/api/v1/classes, /class-attendance, /schedule-config, /class-enrollments).
import { request } from '../../api'

export type Option = { value: string; label: string }
export type Msg = { message: string }
export type ClassStatus = 'pending_schedule' | 'upcoming' | 'active' | 'completed' | 'cancelled'
export type StatusMeta = { key: ClassStatus; label: string; color: string }
export type Seat = { occupied: number; capacity: number; left: number | null; min: number; needed: number; not_started: boolean }
export type NextAction = { label: string; tone: string; tab: string }

export type ClassRow = {
  id: string
  code: string
  name: string
  status: StatusMeta
  meta: string
  schedule_text: string | null
  teacher: string | null
  foreign_teacher: string | null
  seat: Seat
  total_sessions: number
  done_sessions: number
  next: NextAction | null
}

export type ClassList = {
  classes: ClassRow[]
  total: number
  page: number
  per_page: number
  branches: Option[]
  statuses: { key: ClassStatus; label: string; count: number }[]
  open_count: number
  program_counts: { label: string; total: number }[]
  level_counts: { label: string; total: number }[]
  stats: { active: number; pending_schedule: number; short: number; missing_attendance: number }
  can_create: boolean
}

export type ClassFilter = { q?: string; branch_id?: string; status?: string; program?: string; level?: string; page?: number }

export type RoomUsage = { id: string; name: string; code: string; studying: boolean; slots: string[] }
export type RoomOption = { value: string; label: string; name: string; branch_id: string; capacity: number | null; classes: RoomUsage[] }

export type FormValues = {
  name: string
  code: string
  branch_id: string
  program: string
  level: string
  max_capacity: number
  min_students: number
  room_id: string | null
  teacher_id: string | null
  foreign_teacher_id: string | null
  assistant_id: string | null
  tuition_fee: number | null
  notes: string
  status: ClassStatus
  start_date: string | null
  end_date: string | null
  schedule_text: string
}

export type FormOptions = {
  branches: Option[]
  courses: { value: string; label: string; fee: number }[]
  programs: Option[]
  levels: Option[]
  rooms: RoomOption[]
  teachers: Option[]
  foreign_teachers: Option[]
  assistants: Option[]
  values?: FormValues
}

// Dữ liệu gửi khi tạo / sửa lớp: id trống ("") = bỏ chọn.
export type ClassInput = {
  name: string
  code: string
  branch_id: string
  program: string
  level: string
  max_capacity: number | null
  min_students: number | null
  room_id: string
  teacher_id: string
  foreign_teacher_id: string
  tuition_fee: number | null
  notes: string
  assistant_id?: string
  status?: string
  start_date?: string
  end_date?: string
  schedule_text?: string
}

export type Saved = { id: string; message: string; tab?: string }

export type Step = { key: string; label: string; state: string; hint: string | null; tab: string }
export type SessionRow = {
  id: string
  date: string
  weekday: number
  start: string
  end: string
  type: string
  room: string
  teacher: string
  foreign_teacher: string | null
  foreign_teacher_id: string | null
  assistants: string
  cancelled: boolean
  holiday: string | null
  is_today: boolean
  past: boolean
  editable: boolean
}
export type RosterStudent = {
  id: string
  name: string
  code: string
  dob: string | null
  target: string | null
  address: string | null
  parent_name: string | null
  phone: string | null
  notes: string | null
  status: string
  status_label: string
  is_main: boolean
}
export type AttendanceRow = {
  id: string
  date: string
  start: string
  end: string
  state: { key: string; color: string; label: string }
  attendances_count: number
  cancelled: boolean
}

export type ClassPage = {
  klass: {
    id: string
    name: string
    code: string
    status: ClassStatus
    status_meta: StatusMeta
    branch_id: string
    branch: string
    program: string | null
    level: string | null
    room: string | null
    teacher: string | null
    foreign_teacher: string | null
    schedule_text: string | null
    start_date: string | null
    end_date: string | null
    has_schedule_config: boolean
  }
  tab: string
  tabs: { key: string; label: string }[]
  seat: Seat
  steps: Step[]
  can_manage: boolean
  can_delete: boolean
  session_progress: { total: number; done: number }
  next_session: { date: string; start: string; end: string; room: string | null; teacher: string | null } | null
  next_action: NextAction | null
  upcoming_foreign_teachers?: string[]
  students?: RosterStudent[]
  sessions?: SessionRow[]
  upcoming_count: number
  foreign_teacher_options?: Option[]
  attendance_rows?: AttendanceRow[]
  attendance_stats?: { past: number; done: number; missing: number; reports: number }
  can_record_attendance: boolean
}

export type AttendanceSheet = {
  classroom: { id: string; name: string; branch_name: string }
  window_hours: number
  session: { id: string; date: string; time: string; room_label: string; type: string; is_past: boolean } | null
  students: { id: string; name: string; code: string; linked: boolean; recorder_name: string | null; status: string; note: string | null }[]
  block_reason: string
  window_reason: string
  on_behalf: boolean
  on_behalf_name: string | null
  recent_sessions: { id: string; label: string; disabled: boolean }[]
  roster_size: number
  statuses: Option[]
}
export type AttendanceRecord = { student_id: string; status: string; note: string }

export type ScheduleData = {
  class_id?: string
  academic_year: string
  start_date: string
  end_date: string
  slot1_day: string
  slot1_start: string
  slot1_end: string
  slot2_day: string
  slot2_start: string
  slot2_end: string
  status?: string
  branch_id?: string
  room_id: string
}
export type ShiftOption = { value: string; label: string; name: string; start: string; end: string; variant: string }
export type ScheduleConfig = {
  can_schedule: boolean
  can_toggle: boolean
  rooms: RoomOption[]
  new_classes: Option[]
  scheduled_classes: Option[]
  academic_years: Option[]
  schedule_data: Record<string, ScheduleData>
  initial: ScheduleData
  holiday_sessions: { id: string; class_name: string; class_code: string; when: string; holiday: string | null; makeup: string | null }[]
  class_search: string
  list_classes: {
    id: string
    name: string
    code: string
    schedule_text: string | null
    teacher: string | null
    status: string
    status_label: string
    has_config: boolean
    room: string | null
  }[]
  weekdays: string[]
  shift_options: Record<string, ShiftOption[]>
  weekday_rule: string
  shift_duration: number
  off_schedule_classes: { class_id: string; class_name: string; class_code: string; branch: string; slots: string[]; sessions: number }[]
}
export type ScheduleInput = Omit<ScheduleData, 'room_id' | 'status' | 'branch_id' | 'class_id'> & {
  class_id: string
  room_id: string
  activate: boolean
  transfer_room: boolean
}

export type Enrollment = {
  id: string
  student_id: string
  student_name: string
  student_code: string
  class_id: string
  class_name: string
  class_code: string
  teacher: string | null
  branch: string
  curriculum_delivered: boolean
  zalo_group_added: boolean
  enrolled_at: string
  left_at: string | null
  status: 'pending' | 'completed' | 'dropped'
  dropped: boolean
  from_crm: boolean
  confirmed: boolean
}
export type EnrollmentList = {
  enrollments: Enrollment[]
  total: number
  page: number
  per_page: number
  classes: { id: string; label: string; branch_id: string; course_id: string | null; seat: string }[]
  students: { value: string; label: string; branch_id: string | null; branch: string | null }[]
  can_assign: boolean
  crm_waiting_count: number
}

function qs(params: Record<string, string | number | undefined | null>) {
  const out = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') out.set(k, String(v))
  const s = out.toString()
  return s ? `?${s}` : ''
}

export const classesApi = {
  list: (f: ClassFilter) => request<ClassList>('GET', `/classes${qs(f)}`),
  formOptions: (classId?: string) => request<FormOptions>('GET', `/classes/form-options${qs({ class_id: classId })}`),
  create: (b: ClassInput) => request<Saved>('POST', '/classes', b),
  update: (id: string, b: ClassInput) => request<Saved>('PUT', `/classes/${id}`, b),
  remove: (id: string) => request<Msg>('DELETE', `/classes/${id}`),
  show: (id: string, tab: string) => request<ClassPage>('GET', `/classes/${id}${qs({ tab })}`),
  assignForeign: (id: string, b: { foreign_teacher_id: string; session_ids: string[]; set_default: boolean }) =>
    request<Msg>('POST', `/classes/${id}/foreign-teacher`, b),

  attendance: (id: string, p: { session?: string; date?: string }) => request<AttendanceSheet>('GET', `/class-attendance/${id}${qs(p)}`),
  saveAttendance: (id: string, b: { session_id?: string; records: AttendanceRecord[] }) => request<Msg>('POST', `/class-attendance/${id}`, b),

  scheduleConfig: (p: { class_id?: string; class_q?: string }) => request<ScheduleConfig>('GET', `/schedule-config${qs(p)}`),
  saveSchedule: (b: ScheduleInput) => request<Msg>('POST', '/schedule-config', b),
  toggle: (classId: string) => request<{ message: string; warning?: string }>('POST', '/schedule-config/toggle', { class_id: classId }),

  enrollments: (p: { student_id?: string; class_id?: string; page?: number }) =>
    request<EnrollmentList>('GET', `/class-enrollments${qs(p)}`),
  enroll: (b: { student_id: string; class_id: string }) => request<Msg>('POST', '/class-enrollments', b),
  link: (b: { student_id: string; class_id: string }) => request<Msg>('POST', '/class-enrollments/link', b),
  handoff: (id: string, b: { curriculum_delivered: boolean; zalo_group_added: boolean }) =>
    request<Msg>('PUT', `/class-enrollments/${id}`, b),
}
