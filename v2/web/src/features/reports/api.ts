// Gọi API báo cáo & nhật ký nhân sự (/api/v1/reports). Kiểu dữ liệu khớp JSON của internal/reports (Go).
import { request } from '../../api'

export type Option = { value: string; label: string }
export type Tab = { kind: string; label: string }
export type Message = { message: string }

export type Review = { status: 'pending' | 'reviewed' | 'returned'; note: string | null; by: string | null; at: string | null }
export type HistoryItem = {
  period: string
  label: string
  updated_at: string | null
  draft: boolean
  returned: boolean
  reviewed: boolean
  missing: boolean
}
export type LegacyReport = { id: string; title: string; content: string | null; report_date: string }
export type LabeledValue = { label: string; value: string }

export type Detail = { text: string; tag?: string; tone?: 'success' | 'warning' | 'error' | 'muted' | 'strong'; url?: string }
export type MetricItem = {
  key: string
  label: string
  value: string | null
  count: number | null
  info: string
  details: Detail[]
  unavailable?: string
}
export type Section = { key: string; title: string; icon: string; items: MetricItem[]; empty?: string }

export type TaskSummary = {
  id: string
  title: string
  due_date: string | null
  assignee: string | null
  status: string
  status_label: string
}
export type DayLink = { date: string; label: string }

export type DailyForm = {
  audience: string
  tabs: Tab[]
  date: string
  date_label: string
  today: string
  deadline: string
  sections: Section[] | null
  computed_at: string | null
  live: boolean
  note_labels: Record<string, string>
  notes: Record<string, string>
  legacy_fields: LabeledValue[]
  tasks: TaskSummary[]
  tasks_enabled: boolean
  assignees: Option[]
  submitted_at: string | null
  first_submitted_at: string | null
  late: boolean
  review: Review | null
  missing_days: DayLink[]
  history: HistoryItem[]
  legacy: LegacyReport[]
}
export type TaskInput = { title: string; content: string | null; due_date: string; assignee_id: string | null }
export type DailyInput = { date: string; refresh: boolean; notes: Record<string, string>; tasks: TaskInput[] }

export type KpiItem = {
  id: string
  code: string
  name: string
  unit: string
  source: 'auto' | 'journal'
  count: number
  info: string
  details: Detail[]
}
export type KpiGroup = { name: string; items: KpiItem[] }
export type NoteGroup = { key: string; label: string; lines: Detail[] }
export type DailySummary = { submitted: number; expected: number; missing: string[]; notes: NoteGroup[] }
export type WeeklyKpiForm = {
  tabs: Tab[]
  week: string
  weeks: Option[]
  groups: KpiGroup[]
  metrics: Section
  daily: DailySummary
  computed_at: string | null
  live: boolean
  note: string | null
  legacy: LabeledValue[]
  submitted_at: string | null
  review: Review | null
  history: HistoryItem[]
}

export type WeeklyRow = {
  content: string | null
  detail: string | null
  deadline: string | null
  progress: number | null
  link: string | null
  report: string | null
  support: string | null
  note: string | null
  source: string | null
}
export type AcademicWeeklyForm = {
  tabs: Tab[]
  week: string
  weeks: Option[]
  sections: Option[]
  columns: Option[]
  progress_options: number[]
  rows: Record<string, WeeklyRow[]>
  summary: Section
  suggestions: Record<string, WeeklyRow[]>
  computed_at: string
  submitted_at: string | null
  review: Review | null
  history: HistoryItem[]
  legacy: LegacyReport[]
}

export type WeekOfMonth = {
  week: string
  label: string
  range: string
  pic: string | null
  title: string | null
  content: string | null
}
export type Meeting = { id: string; week_start: string; teacher: string; status_label: string; status_color: string }
export type AcademicMonthlyForm = {
  tabs: Tab[]
  month: string
  months: Option[]
  fields: Option[]
  values: Record<string, string | null>
  submitted_at: string | null
  review: Review | null
  weekly_reports: WeekOfMonth[]
  meetings: Meeting[]
  meetings_note?: string
  history: HistoryItem[]
  max_field_length: number
}
export type FieldGroup = { title: string; fields: Option[] }
export type AcademicQuarterlyForm = {
  tabs: Tab[]
  quarter: string
  quarter_label: string
  quarters: Option[]
  sections: FieldGroup[]
  values: Record<string, string | null>
  submitted_at: string | null
  review: Review | null
  monthly_reports: { month: string; label: string; submitted: boolean; updated_at: string | null }[]
  history: HistoryItem[]
  max_field_length: number
}

export type Check = { key: string; label: string; note?: string }
export type Choice = { key: string; label: string; options: Option[] }
export type TeacherClass = {
  id: string
  name: string
  attendance: number | null
  homework: number | null
  sessions_done: number
}
export type ClassValue = {
  sessions?: number | string | null
  checks?: Record<string, boolean>
  notes?: Record<string, string | null>
  need_support?: boolean
  [field: string]: unknown
}
export type TeacherMonthlyForm = {
  tabs: Tab[]
  month: string
  month_label: string
  months: Option[]
  due_label: string
  general_fields: Option[]
  academic_checks: Check[]
  operation_checks: Check[]
  quality_checks: Check[]
  class_texts: Option[]
  choices: Choice[]
  general: Record<string, string | null>
  class_values: Record<string, ClassValue>
  classes: TeacherClass[]
  classes_note?: string
  status: 'draft' | 'submitted' | 'returned' | null
  submitted_at: string | null
  review: Review | null
  attendance: { note: string | null; submitted_at: string | null }
  propose_url: string | null
  history: HistoryItem[]
  legacy: LegacyReport[]
}

export type Page = { page: number; per_page: number; total: number; last_page: number }
export type FreeReport = { id: string; title: string; content: string | null; report_date: string }
export type MyReports = {
  primary_kind?: string
  type: string
  label: string
  reports: FreeReport[]
  page: Page
  today: string
  monthly_due: { due_label: string; month_label: string; submitted: boolean } | null
  tabs: Tab[]
  kinds: string[]
  classes: Option[]
}
export type FreeReportInput = { title: string; content: string; report_date: string; class_id: string | null }

export type Followup = { id: string; user: string | null; content: string; created_at: string }
export type JournalEntry = {
  id: string
  title: string
  content: string | null
  severity: string | null
  severity_label: string
  kpi_code: string | null
  serious: boolean
  serious_by: string | null
  status: 'open' | 'following' | 'resolved'
  report_date: string
  created_at: string
  class: { id: string; code: string } | null
  class_id: string | null
  user: string
  logged_by: string | null
  user_role: string | null
  followups: Followup[]
}
export type JournalPage = {
  journals: JournalEntry[]
  page: Page
  is_priv: boolean
  classes: Option[]
  kpi_options: Option[]
  can_confirm_serious: boolean
  subjects: Option[]
  statuses: Option[]
  today: string
}
export type JournalInput = {
  content: string
  kpi_code: string | null
  report_date: string
  class_id: string | null
  subject_user_id: string | null
  severity?: string | null
}

export type AllRow = {
  id: string
  type: string
  type_label: string
  for: string | null
  severity_label: string | null
  title: string
  preview: string | null
  report_date: string
  submitted_at: string
  updated_at: string | null
  late: boolean
  user: string
  branch: string | null
  followups_count: number
  reviewable: boolean
  status: string
  review_note: string | null
  reviewed_at: string | null
  reviewed_by: string | null
}
export type MissingRow = { user_id: string; user: string; branch: string | null; for: string; days: DayLink[] }
export type AllStats = {
  journal: number
  daily: number
  weekly: number
  monthly: number
  urgent_open: number
  pending_review: number
  returned: number
  missing_yesterday: number
}
export type AllFilters = {
  view?: string
  type?: string
  review?: string
  user_id?: string
  branch_id?: string
  from?: string
  to?: string
  page?: number
}
export type AllPage = {
  view: 'list' | 'missing'
  reports: AllRow[]
  page: Page
  missing: { from: string; to: string; rows: MissingRow[] } | null
  stats: AllStats
  filters: Record<string, string | null>
  can_review: boolean
  types: Option[]
  users: Option[]
  branches: Option[]
  open_id: string | null
}
export type ReportDetail = {
  report: {
    id: string
    title: string
    type_label: string
    for: string | null
    user: string
    report_date: string
    submitted_at: string
    updated_at: string
    late: boolean
    deadline: string | null
    status: string
    review_note: string | null
    reviewable: boolean
    reviewed_at: string | null
    reviewed_by: string | null
    content: string | null
    severity_label: string | null
    kpi_code: string | null
    class: string | null
    followups: { user: string | null; content: string; created_at: string }[]
  }
  view: 'journal' | 'daily' | 'daily_legacy' | 'weekly_kpi' | 'weekly_legacy' | 'academic_weekly' | 'text'
  auto: { sections?: Section[]; metrics?: Section; groups?: KpiGroup[]; daily?: DailySummary; computed_at?: string } | null
  notes: Record<string, string>
  note_labels: Record<string, string>
  note: string | null
  tasks: TaskSummary[]
  can_review: boolean
  next_id: string | null
  legacy: { fields?: LabeledValue[]; groups?: KpiGroup[]; metrics?: LabeledValue[] } | null
  rows: Record<string, WeeklyRow[]>
  sections: Option[]
  columns: Option[]
}

function qs(params: Record<string, string | number | boolean | undefined | null>) {
  const q = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '' && v !== false) q.set(k, String(v))
  const s = q.toString()
  return s ? `?${s}` : ''
}

export const reportsApi = {
  my: (page?: number) => request<MyReports>('GET', `/reports/my${qs({ page })}`),
  saveMy: (body: FreeReportInput) => request<Message>('POST', '/reports/my', body),

  daily: (date?: string, refresh?: boolean) => request<DailyForm>('GET', `/reports/daily${qs({ date, refresh: refresh ? 1 : undefined })}`),
  saveDaily: (body: DailyInput) => request<Message>('POST', '/reports/daily', body),
  weeklyKpi: (week?: string, refresh?: boolean) =>
    request<WeeklyKpiForm>('GET', `/reports/weekly-kpi${qs({ week, refresh: refresh ? 1 : undefined })}`),
  saveWeeklyKpi: (body: { week: string; note: string | null; refresh: boolean }) =>
    request<Message>('POST', '/reports/weekly-kpi', body),
  academicWeekly: (week?: string) => request<AcademicWeeklyForm>('GET', `/reports/academic-weekly${qs({ week })}`),
  saveAcademicWeekly: (body: { week: string; rows: Record<string, WeeklyRow[]> }) =>
    request<Message>('POST', '/reports/academic-weekly', body),
  academicMonthly: (month?: string) => request<AcademicMonthlyForm>('GET', `/reports/academic-monthly${qs({ month })}`),
  saveAcademicMonthly: (body: { month: string; narrative: Record<string, string | null> }) =>
    request<Message>('POST', '/reports/academic-monthly', body),
  academicQuarterly: (quarter?: string) => request<AcademicQuarterlyForm>('GET', `/reports/academic-quarterly${qs({ quarter })}`),
  saveAcademicQuarterly: (body: { quarter: string; narrative: Record<string, string | null> }) =>
    request<Message>('POST', '/reports/academic-quarterly', body),
  teacherMonthly: (month?: string) => request<TeacherMonthlyForm>('GET', `/reports/teacher-monthly${qs({ month })}`),
  saveTeacherMonthly: (body: {
    month: string
    intent: 'draft' | 'submit'
    general: Record<string, string | null>
    classes: Record<string, ClassValue>
  }) => request<Message>('POST', '/reports/teacher-monthly', body),
  saveTeacherAttendance: (body: { month: string; note: string | null }) =>
    request<Message>('POST', '/reports/teacher-attendance', body),

  journal: (f: { severity?: string; status?: string; class_id?: string; page?: number }) =>
    request<JournalPage>('GET', `/reports/journal${qs(f)}`),
  addJournal: (body: JournalInput) => request<Message & { id: string }>('POST', '/reports/journal', body),
  addFollowup: (id: string, content: string) => request<Message>('POST', `/reports/journal/${id}/followup`, { content }),
  setJournalStatus: (id: string, status: string) => request<Message>('POST', `/reports/journal/${id}/status`, { status }),
  setJournalSerious: (id: string, serious: boolean) => request<Message>('POST', `/reports/journal/${id}/serious`, { serious }),

  all: (f: AllFilters & { open?: string }) => request<AllPage>('GET', `/reports/all${qs(f)}`),
  detail: (id: string) => request<ReportDetail>('GET', `/reports/${id}/detail`),
  review: (id: string, reviewed: boolean) => request<Message>('POST', `/reports/${id}/review`, { reviewed }),
  requestChanges: (id: string, note: string) => request<Message>('POST', `/reports/${id}/request-changes`, { note }),
  reviewBulk: (ids: string[]) => request<Message>('POST', '/reports/review-bulk', { ids }),
}
