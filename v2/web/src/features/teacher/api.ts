// API cổng giáo viên (/api/v1/teacher/*): lịch dạy hôm nay / tuần, check-in, giao bài tập, nhập điểm mini test, nhận xét
// buổi học, chấm bài nộp, báo cáo giảng dạy tháng.
import { request, upload } from '../../api'

export type Option = { value: string; label: string }
export type Msg = { message: string }

export type ClassRef = { id: string; name: string; code: string; branch_name: string }

export type CheckinWindow = {
  deadline: string
  deadline_iso: string
  remaining_seconds: number
  expired: boolean
  warning: boolean
}

export type Shift = {
  session_id: string
  class_id: string
  class_name: string
  class_code: string
  branch_name: string
  room_label: string
  type_label: string
  is_support: boolean
  scheduled_time: string
  shift_name: string
  checked_in: boolean
  checkin_time: string
  checkin: CheckinWindow
  is_today: boolean
  date_label: string
  attendance_done: boolean
  student_count: number
  trial_count: number
}

export type PendingSession = { id: string; class_id: string; class_name: string; date: string; time: string; type_label: string }

export type WeekSession = {
  id: string
  class_id: string
  class_name: string
  time: string
  room: string
  type_label: string
  cancelled: boolean
  done: boolean
  can_take: boolean
  checkin_overdue: boolean
}

export type WeekDay = { label: string; date: string; is_today: boolean; sessions: WeekSession[] }

export type Violation = { violation_type: string; date: string; status_label: string }

export type Widgets = {
  attention: { id: string; student_name: string; class_name: string; name: string; score: string }[]
  work_pending: string
  estimate: number | null
  hours_label: string
  month: number
  today_dm: string
  period: string
  month_end_dm: string
  timesheets_total: number
  timesheets_pending: number
  violations_count: number
  latest_violation: Violation | null
}

export type Home = {
  teacher_name: string
  today_label: string
  stats: { total: number; checked_in: number; attendance_done: number; pending: number }
  next_shift: { class_id: string; session_id: string; start_time: string; upcoming: boolean } | null
  shifts: Shift[]
  pending_sessions: PendingSession[]
  week: { label: string; start: string; prev: string; next: string }
  week_days: WeekDay[]
  widgets: Widgets
  warning_minutes: number
  can_checkin: boolean
}

export type CheckinOutcome = { message: string; warning?: string; count: number }

// ───────────── Bài tập ─────────────

export type Category = { key: string; label: string; icon: string; placeholder: string }
export type HomeworkItem = { key: string; label: string; text: string; locked: boolean }

export type HomeworkRow = {
  id: string
  title: string
  due_label: string
  session_date: string
  teacher_name: string
  items: HomeworkItem[]
  description: string
  class_note: string
  youtube_url: string
  audio_url: string
  quizizz_url: string
  deletable: boolean
}

export type HomeworkEdit = {
  id: string
  session_id: string | null
  due_at: string
  class_note: string
  youtube_url: string
  quizizz_url: string
  has_audio: boolean
  items: Record<string, string>
  locked: string[]
}

export type HomeworkPage = {
  classroom: ClassRef
  categories: Category[]
  sessions: Option[]
  selected_session_id: string | null
  due_default: string
  editing: HomeworkEdit | null
  homeworks: HomeworkRow[]
}

export type HomeworkInput = {
  session_id: string | null
  due_at: string
  class_note: string
  youtube_url: string
  quizizz_url: string
  audio_file_id: string | null
  categories: string[]
  items: Record<string, string>
}

export type UploadedAudio = { id: string; name: string; url: string }

// ───────────── Điểm mini test ─────────────

export type Skill = { key: string; label: string }
export type ScoreStudent = { id: string; name: string; has_score: boolean; skills: Record<string, string>; total: string | null; low: boolean }

export type ScoresPage = {
  classroom: ClassRef
  skills: Skill[]
  units: Option[]
  unit_id: string | null
  test_name: string | null
  test_date: string
  selected_student_id: string | null
  current: { max_score: string; skills: Record<string, string>; note: string }
  students: ScoreStudent[]
  scored_count: number
}

export type ScoresQuery = { unit_id?: string; name?: string; test_date?: string; student_id?: string }

export type ScoreInput = {
  unit_id: string | null
  name: string
  student_id: string | null
  test_date: string
  max_score: string
  skills: Record<string, string>
  note: string
}

export type ScoreSaved = { message: string; unit_id: string | null; name: string | null }

// ───────────── Nhận xét ─────────────

export type RemarkStudent = { id: string; name: string; att_status: string; absent: boolean; remark: Record<string, string> }
export type SessionChoice = { id: string; label: string; disabled: boolean }
export type TrialGuest = { id: string; name: string; test_score: string; status_label: string; notes: string }

export type RemarksPage = {
  classroom: ClassRef
  session: { id: string; label: string } | null
  record_state: 'draft' | 'saved' | null
  block_reason: string
  students: RemarkStudent[]
  recent_sessions: SessionChoice[]
  trial_guests: { scope: 'past' | 'upcoming'; items: TrialGuest[] }
}

export type RemarksInput = {
  session_id: string | null
  action: 'draft' | 'final'
  remarks: Record<string, Record<string, string>>
}

// ───────────── Chấm bài nộp ─────────────

export type Submission = {
  id: string
  status: 'submitted' | 'reviewed'
  student_name: string
  submitted_at: string
  notes: string
  audio_url: string
  unit_title: string
  attachment_url: string
  attachment_name: string
  homework_label: string
  score: string
  feedback: string
}

export type SubmissionsPage = {
  current_class: { id: string; name: string; code: string } | null
  classes: { id: string; name: string; code: string }[]
  active_tab: string
  tabs: Option[]
  submissions: Submission[]
  pronunciation_pending: boolean
}

// ───────────── Báo cáo giảng dạy ─────────────

export type GeneralReport = {
  month: number
  year: number
  period: string
  stats: {
    hours: string
    sessions: number
    valid_hours: string
    valid_sessions: number
    attendance_marked: number
    scores_entered: number
  }
  my_classes: { id: string; name: string; code: string; course: string; is_main: boolean; is_foreign: boolean; status: string }[]
  timesheets: { id: string; date: string; class_name: string; type: string; hours: string; status: string }[]
  work_pending: string
}

function qs(params: Record<string, string | undefined>) {
  const p = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) if (v) p.set(k, v)
  const s = p.toString()
  return s ? `?${s}` : ''
}

const cls = (id: string) => `/teacher/classes/${id}`

export const teacherApi = {
  home: (week?: string) => request<Home>('GET', `/teacher/home${qs({ week })}`),
  checkin: (sessionIds: string[]) => request<CheckinOutcome>('POST', '/teacher/checkin', { session_ids: sessionIds }),
  homework: (id: string, p: { edit?: string; session?: string }) => request<HomeworkPage>('GET', `${cls(id)}/homework${qs(p)}`),
  saveHomework: (id: string, hid: string | null, input: HomeworkInput) =>
    hid
      ? request<{ id: string; message: string }>('PUT', `${cls(id)}/homework/${hid}`, input)
      : request<{ id: string; message: string }>('POST', `${cls(id)}/homework`, input),
  deleteHomework: (id: string, hid: string) => request<Msg>('DELETE', `${cls(id)}/homework/${hid}`),
  uploadAudio: (id: string, file: File) => {
    const form = new FormData()
    form.append('file', file)
    return upload<UploadedAudio>(`${cls(id)}/homework/audio`, form)
  },
  scores: (id: string, p: ScoresQuery) => request<ScoresPage>('GET', `${cls(id)}/scores${qs(p)}`),
  saveScore: (id: string, input: ScoreInput) => request<ScoreSaved>('POST', `${cls(id)}/scores`, input),
  remarks: (id: string, p: { session?: string; date?: string }) => request<RemarksPage>('GET', `${cls(id)}/remarks${qs(p)}`),
  saveRemarks: (id: string, input: RemarksInput) => request<{ session_id: string; message: string }>('POST', `${cls(id)}/remarks`, input),
  submissions: (p: { class_id?: string; type?: string }) => request<SubmissionsPage>('GET', `/teacher/submissions${qs(p)}`),
  mark: (id: string, input: { score: string; feedback: string }) => request<Msg>('POST', `/teacher/submissions/${id}/mark`, input),
  generalReport: (period?: string) => request<GeneralReport>('GET', `/teacher/general-report${qs({ period })}`),
}
