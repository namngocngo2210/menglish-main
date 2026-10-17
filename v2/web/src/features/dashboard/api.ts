// Gọi API Tổng quan (/api/v1/dashboard) và Báo cáo & sự vụ lớp (/api/v1/academic/dashboards/*). Kiểu dữ liệu khớp JSON
// của internal/dashboard (Go). Trường pending: lý do mục chưa có số liệu ("Chờ phân hệ …").
import { request } from '../../api'

export type Option = { value: string; label: string }

export type Stat = {
  label: string
  value: string | null
  icon: string
  tone: string
  hint: string | null
  href: string | null
  pending?: string
}

export type Queue = {
  label: string
  value: number | null
  icon: string
  href: string | null
  hint: string | null
  pending?: string
}

export type AgendaEntry = {
  time: string | null
  past: boolean
  kind: string
  kind_label: string
  title: string
  subtitle: string
  href: string | null
}
export type AgendaDay = { date: string; label: string; items: AgendaEntry[] }
export type Agenda = { days: AgendaDay[]; total: number; more: number; range: string; pending: string[] }

export type TaskItem = { id: string; title: string; status: string; status_label: string; due: string | null; overdue: boolean }
export type MyTasks = { items: TaskItem[]; total: number; url: string | null }
export type OverdueTask = { id: string; title: string; assignee: string | null; due_date: string | null }

export type RoleDashboard = {
  type: 'admin' | 'manager' | 'academic' | 'personal'
  title: string
  scope: string
  updated_at: string
  stats: Stat[]
  queues: Queue[]
  overdue_tasks?: OverdueTask[]
  pending_adjustments?: unknown[]
  upcoming_big_tests?: unknown[]
  academic_pending?: string
  agenda?: Agenda
  my_tasks?: MyTasks
}

export type MissingDay = { label: string; href: string; deadline: string; overdue: boolean }
export type ReturnedDay = { label: string; href: string; note: string | null }
export type DailyReport = { missing: MissingDay[]; returned: ReturnedDay[]; today_submitted: boolean; today_href: string }

export type WorkStats = { work_days: number; late: number; leave_days: number; violations: number }
export type Totals = {
  classes: number
  students: number
  new: number
  dropped: number
  attendance: number | null
  homework: number | null
  score: number | null
}
export type ClassMetric = {
  id: string
  code: string
  name: string
  teacher_id: string | null
  students: number
  new: number
  dropped: number
  attendance: number | null
  homework: number | null
  score: number | null
}
export type TeamRow = Totals & { id: string; name: string; work: WorkStats | null }
export type TeacherReportClass = {
  class: string
  situation: string | null
  attention: string | null
  solution: string | null
  need_support: boolean
  support_note: string | null
}
export type TeacherReport = {
  id: string
  teacher: string
  submitted: boolean
  updated_at: string | null
  progress: string | null
  difficulties: string | null
  proposals: string | null
  classes: TeacherReportClass[]
}
export type AcademicOrder = {
  id: string
  code: string
  title: string
  teacher: string | null
  class: string | null
  use_date: string
  status_label: string
  open: boolean
}
export type Teaching = {
  month: string
  month_label: string
  months: Option[]
  mine: { work: WorkStats | null; totals: Totals; classes: ClassMetric[] } | null
  report_url: string | null
  team: { rows: TeamRow[] } | null
  teacher_reports: TeacherReport[] | null
  academic_orders: AcademicOrder[] | null
  orders_url: string | null
  pending: string[]
}

export type Overview = {
  is_operations: boolean
  role_dashboard: RoleDashboard | null
  daily_report: DailyReport | null
  teaching: Teaching | null
}

export type RecordRow = {
  id: string
  title: string
  record_code: string | null
  user: string | null
  status_label: string
  content: string | null
  created_at: string
}
export type DailyRow = {
  id: string
  date: string
  user: string | null
  title: string
  content: string | null
  structured: boolean
  classes: string | null
  staff: string | null
  enrollment: string | null
  has_task: boolean
  updated_at: string
}
export type ReportsTab = 'daily' | 'weekly' | 'monthly'
export type AcademicReports = {
  tab: ReportsTab
  today: string
  class_reports: unknown[]
  class_reports_pending: string
  staff_daily_reports: DailyRow[]
  weekly_reports: RecordRow[]
  weekly_kpi_reports: RecordRow[]
  monthly_reports: RecordRow[]
  total_classes: number
  active_classes: number
  total_daily_reports_today: number
  attendance_rate_today: number | null
  weekly_stats: { pending: string }
}

export type Journal = {
  id: string
  branch: string | null
  class_id: string | null
  class_code: string | null
  title: string
  content: string | null
  severity: string | null
  priority: string | null
  severity_label: string
  user: string | null
  followup: string | null
  status: string
}
export type IncidentFilter = { severity: string; status: string; branch_id: string; class_id: string }
export type Incidents = IncidentFilter & {
  branches: Option[]
  classes: Option[]
  journals: Journal[]
  tickets: unknown[]
  tickets_pending: string
  total_incidents: number
  resolved_count: number
  open_count: number
  urgent_count: number
  can_submit_journal: boolean
}

function query(params: Record<string, string>) {
  const q = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v !== 'all')).toString()
  return q ? `?${q}` : ''
}

export const dashboardApi = {
  overview: (month: string) => request<Overview>('GET', `/dashboard${query({ month })}`),
  reports: (tab: string) => request<AcademicReports>('GET', `/academic/dashboards/reports${query({ tab })}`),
  incidents: (f: IncidentFilter) => request<Incidents>('GET', `/academic/dashboards/incidents${query(f)}`),
}
