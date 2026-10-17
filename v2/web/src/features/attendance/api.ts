// API Chấm công: chấm công của tôi, lịch sử công, đơn chấm công & nghỉ, duyệt đơn, chấm công hằng ngày, giờ dạy giáo viên,
// chấm tay, lịch sử đồng bộ (MyAttendanceController, StaffAttendanceController, phần chấm công của PayrollController).
import { request, upload } from '../../api'

export type Option = { value: string; label: string }
export type Tone = 'success' | 'warning' | 'error' | 'neutral'
type Msg = { message: string }

export type PenaltyRef = { code: string; status: string }

export type Day = {
  id: string
  date: string
  check_in: string | null
  check_out: string | null
  check_in_photo: string | null
  check_out_photo: string | null
  check_in_distance: number | null
  expected_start: string | null
  expected_end: string | null
  late_minutes: number
  early_minutes: number
  late_excused: boolean
  status_label: string
  status_tone: Tone
  source: 'mobile' | 'request'
  penalty: PenaltyRef | null
}

export type BranchInfo = {
  id: string
  name: string
  address: string | null
  latitude: number | null
  longitude: number | null
  radius: number
  grace: number
  has_location: boolean
}

export type Expected = {
  start: string | null
  end: string | null
  basis: string
  sessions: { shift: string | null; time: string; class: string }[]
}

export type HomePage = {
  branch: BranchInfo | null
  expected: Expected
  today: Day | null
  server_time: string
  locked: string | null
}

export type PunchResult = { message: string; warning: boolean; today: Day }

export type Summary = {
  days: number
  late_count: number
  late_minutes: number
  excused_late: number
  early_count: number
  missing_out: number
  leave_days: number
}

export type HistoryPage = {
  month: string
  month_label: string
  prev_month: string
  next_month: string | null
  summary: Summary
  rows: Day[]
  leaves: { id: string; period: string; reason: string }[]
}

export type RequestType = 'correction' | 'late_early' | 'leave'

export type MyRequest = {
  id: string
  type: RequestType
  type_label: string
  period: string
  reason: string
  status: 'pending' | 'approved' | 'rejected' | 'cancelled'
  status_label: string
  status_tone: Tone
  reviewer: string | null
  rejection_reason: string | null
  created_at: string
}

export type RequestsPage = { types: Option[]; today: string; requests: MyRequest[] }

export type RequestInput = {
  type: string
  date_from: string
  date_to: string
  check_in_time: string
  check_out_time: string
  reason: string
}

export type PendingItem = {
  id: string
  user_name: string
  user_code: string
  branch: string | null
  type: RequestType
  type_label: string
  period: string
  date: string
  reason: string
  current_punch: string | null
  created_at: string
}

export type Meta = { page: number; per_page: number; total: number }

export type DailyRow = {
  user: { id: string; name: string; code: string; branch: string | null }
  attendance?: {
    id: string
    check_in: string | null
    check_out: string | null
    check_in_photo: string | null
    check_in_distance: number | null
    expected: string
    status_label: string
    status_tone: Tone
    source: string
    penalty: string | null
  }
  leave?: string
  summary?: Summary
}

export type DailyPage = {
  view: 'day' | 'month'
  date: string
  month: string
  rows: DailyRow[]
  meta: Meta
  branches: Option[]
  statuses: Option[]
  stats: { staff: number; checked_in: number; late: number; leave: number } | null
  unconfigured: string[]
}

export type DailyFilter = { view: string; date: string; month: string; branch_id: string; status: string; search: string; page: string }

export type PunchDetail = { time: string; photo: string | null; distance: number | null; accuracy: number | null; map: string | null }

export type AttendanceDetail = {
  id: string
  user: string
  code: string
  branch: string | null
  radius: number | null
  date: string
  source: string
  expected_start: string | null
  expected_end: string | null
  in: PunchDetail | null
  out: PunchDetail | null
  status_label: string
  status_tone: Tone
  note: string | null
  penalty: { code: string; url: string } | null
}

export type TimesheetRow = {
  id: string
  user_id: string
  teaching_date: string
  scheduled_time: string | null
  shift_name: string | null
  type: string
  type_label: string
  source: string
  source_label: string
  class_id: string | null
  class_label: string
  teacher_name: string
  checkin_time: string | null
  checkout_time: string | null
  display_checkout: string | null
  adjusted: boolean
  hours: string
  punch_state: 'full' | 'adjusted' | 'missing_in' | 'missing_out'
  punch_state_label: string
  adjustment_reason: string | null
  adjustment_short: string | null
  adjuster_name: string | null
  notes_short: string | null
  status: 'pending_review' | 'valid' | 'invalid'
  status_label: string
  rejection_short: string | null
  reviewer_name: string | null
  locked: boolean
  reject_label: string
  late_minutes: number
  early_leave_minutes: number
  late_notified: boolean
  late_label: string | null
  edit_label: string
}

export type MissingSession = {
  id: string
  date: string
  start_time: string
  end_time: string
  class_label: string
  class_id: string
  branch_id: string
}

export type ScheduleSession = {
  id: string
  class_name: string
  class_code: string
  start_time: string
  end_time: string
  teacher_name: string | null
  state: 'valid' | 'checkin' | 'pending' | 'none'
  state_note: string | null
  source_label: string | null
  can_confirm: boolean
}

export type TeacherTimesheetsPage = {
  can_view_all: boolean
  can_review: boolean
  can_adjust: boolean
  month: string
  month_label: string
  period: { status_label: string; locked: boolean } | null
  period_locked: boolean
  summary: { pending_review: number; valid: number; invalid: number }
  timesheets: TimesheetRow[]
  meta: Meta
  teacher: { id: string; name: string; employee_code: string; branch_name: string | null } | null
  missing_sessions: MissingSession[]
  schedule_day: { date: string; weekday: string } | null
  schedule_sessions: ScheduleSession[]
  sub_pending_count: number
  filter_branches: Option[]
  filter_classes: Option[]
  filter_teachers: Option[]
  types: Option[]
  statuses: Option[]
}

export type TimesheetFilter = {
  month: string
  status: string
  type: string
  search: string
  branch_id: string
  class_id: string
  user_id: string
  day: string
  page: string
}

export type ManualForm = {
  teachers: { id: string; label: string; search: string }[]
  classes: { id: string; label: string; branch: string }[]
  branches: Option[]
  locked_ranges: { from: string; to: string; title: string }[]
  default_branch: string
  today: string
  types: Option[]
}

export type ManualInput = {
  user_id: string
  branch_id: string
  class_id: string
  teaching_date: string
  time_in: string
  time_out: string
  hourly_rate: number | null
  late_minutes: number | null
  early_leave_minutes: number | null
  late_notified: boolean
  type: string
  notes: string
}

export type AdjustInput = {
  time_in: string
  time_out: string
  adjustment_reason: string
  late_minutes: number | null
  early_leave_minutes: number | null
  late_notified: boolean
}

export type SyncLog = {
  id: string
  created_at: string
  device_name: string
  branch: string
  records_count: number
  matched_count: number
  failed_count: number
  skipped_count: number
  status: 'success' | 'partial' | 'failed'
  status_label: string
  error_code: string | null
  error_message: string | null
  error_rows: { employee_code: string | null; employee_name: string | null; code: string | null; message: string }[]
  has_errors: boolean
}

export type SyncPage = { logs: SyncLog[]; meta: Meta; has_any_log: boolean; status_options: Option[] }
export type SyncFilter = { from: string; to: string; status: string; page: string }

export function query(params: Record<string, string>) {
  const q = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) if (v) q.set(k, v)
  const s = q.toString()
  return s ? `?${s}` : ''
}

export type PunchInput = { kind: 'in' | 'out'; latitude: number; longitude: number; accuracy: number; photo: File }

export const attendanceApi = {
  home: () => request<HomePage>('GET', '/attendance/me'),
  punch: (p: PunchInput) => {
    const form = new FormData()
    form.set('kind', p.kind)
    form.set('latitude', String(p.latitude))
    form.set('longitude', String(p.longitude))
    form.set('accuracy', String(Math.round(p.accuracy)))
    form.set('file', p.photo)
    return upload<PunchResult>('/attendance/me/punch', form)
  },
  history: (month: string) => request<HistoryPage>('GET', `/attendance/me/history${query({ month })}`),
  requests: () => request<RequestsPage>('GET', '/attendance/me/requests'),
  submit: (in_: RequestInput) => request<Msg>('POST', '/attendance/me/requests', in_),
  cancel: (id: string) => request<Msg>('POST', `/attendance/me/requests/${id}/cancel`),
  pending: () => request<{ items: PendingItem[] }>('GET', '/attendance/approvals'),
  approve: (id: string) => request<Msg>('POST', `/attendance/approvals/${id}/approve`),
  reject: (id: string, reason: string) => request<Msg>('POST', `/attendance/approvals/${id}/reject`, { reason }),
  daily: (f: DailyFilter) => request<DailyPage>('GET', `/attendance/daily${query(f)}`),
  detail: (id: string) => request<AttendanceDetail>('GET', `/attendance/daily/${id}`),
  teachers: (f: TimesheetFilter) => request<TeacherTimesheetsPage>('GET', `/timesheets/teachers${query(f)}`),
  review: (id: string, decision: 'valid' | 'invalid', rejection_reason = '') =>
    request<Msg>('POST', `/timesheets/teachers/${id}/review`, { decision, rejection_reason }),
  bulkReview: (ids: string[]) => request<Msg>('POST', '/timesheets/teachers/bulk-review', { ids }),
  adjust: (id: string, in_: AdjustInput) => request<Msg>('PUT', `/timesheets/teachers/${id}/adjust`, in_),
  confirm: (sessionId: string) => request<Msg>('POST', `/timesheets/sessions/${sessionId}/confirm`),
  manualForm: () => request<ManualForm>('GET', '/timesheets/manual'),
  storeManual: (in_: ManualInput) => request<Msg>('POST', '/timesheets/manual', in_),
  sync: (f: SyncFilter) => request<SyncPage>('GET', `/timesheets/sync-history${query(f)}`),
  syncErrorsUrl: (id: string) => `/api/v1/timesheets/sync-history/${id}/errors`,
}
