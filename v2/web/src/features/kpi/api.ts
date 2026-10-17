// API KPI nhân sự: tiêu chí theo vai trò, phiếu KPI tháng / quý, KPI của tôi, rà soát điểm danh, bảng KPI tự động
// (KpiController, KpiBoardService của Laravel).
import { request } from '../../api'

export type Option = { value: string; label: string }
export type Tier = [number, number]
type Msg = { message: string }

export type GradeView = { grade: string; from: number; label: string; pay: number }
export type Grade = GradeView & { pass: boolean }

export type CriterionView = {
  id: string
  code: string | null
  name: string
  group_name: string | null
  weight: string
  fund_amount: number | null
  unit: string | null
  auto_source: string | null
  source_pending: string | null
  max_full: number | null
  max_half: number | null
  threshold_full: string | null
  threshold_half: string | null
  measure: string
  tiers: Tier[]
  linear: boolean
  full_at: number | null
  per_month: boolean
  knockout: boolean
  allow_na: boolean
  rule_label: string | null
  description: string | null
  is_active: boolean
}

export type CriteriaGroup = { name: string; count: number; active_weight: string; active_fund: number | null; items: CriterionView[] }

export type CriteriaPage = {
  role: string
  role_options: Option[]
  unit_options: Option[]
  source_options: Option[]
  measure_options: Option[]
  cycle_options: Option[]
  groups: string[]
  fund: number | null
  period_months: number
  grades: GradeView[]
  grade_fund: number | null
  total_weight: number
  total_weight_label: string
  criteria_groups: CriteriaGroup[]
  can_manage: boolean
}

export type TierInput = { at: number | null; percent: number | null }
export type CriterionInput = {
  role?: string
  group_name: string | null
  new_group: string | null
  name: string
  weight: number | null
  measure: string
  unit: string | null
  tiers?: TierInput[]
  linear: boolean
  full_at: number | null
  per_month: boolean
  knockout: boolean
  allow_na: boolean
  description: string | null
  auto_source: string | null
  is_active?: boolean
}

export type Evidence = { date: string; month: string; text: string; counted: boolean; note: string | null; cap?: boolean }

export type Rule = {
  measure: string
  lower_better: boolean
  tiers: Tier[]
  linear: boolean
  full_at: number
  has_rule: boolean
  knockout: boolean
}

export type Line = {
  id: string
  code: string | null
  name: string
  description: string | null
  weight: number
  weight_label: string
  max_amount: number | null
  amount: number | null
  threshold_full: string
  threshold_half: string
  max_full: number | null
  max_half: number | null
  rule_label: string
  rule: Rule
  measure: string
  unit: string
  per_month: boolean
  knockout: boolean
  count_based: boolean
  auto: boolean
  pending: string | null
  value: number | null
  level: number | null
  allow_na: boolean
  na: boolean
  cap: boolean
  evidence: Evidence[]
  suggestion: { count: number; evidence: { date: string; text: string }[] } | null
}

export type LineGroup = { name: string; items: Line[] }

export type SheetRow = {
  track: string
  role: string
  period_label: string | null
  rate_label: string
  amount_label: string
  status: string
  status_label: string
  status_color: string
  period: string
}

export type MonthlyStaff = { id: string; name: string; branch: string | null; sheets: SheetRow[] }
export type MonthlyFilter = { period: string; role: string; branch_id: string; page: number }

export type MonthlyPage = {
  staff: MonthlyStaff[]
  total: number
  page: number
  per_page: number
  filters: MonthlyFilter
  current_period: string
  period_options: Option[]
  role_options: Option[]
  branch_options: Option[]
}

export type EvaluatePage = {
  staff: { id: string; name: string; role_label: string; branch: string | null }
  track: string
  month: number
  year: number
  period: string
  period_months: number
  period_label: string
  status: string
  status_label: string
  status_color: string
  reject_reason: string | null
  decided_by: string | null
  decided_at: string | null
  fund: number | null
  weight_total: number
  grades: GradeView[]
  simple_rules: boolean
  bonus_fund: number | null
  grade_cap: string | null
  is_self: boolean
  locked: boolean
  can_close: boolean
  close_on: string
  can_decide: boolean
  total: number
  missing: number
  groups: LineGroup[]
}

export type DecideInput = {
  month: number
  year: number
  track: string
  action: 'approve' | 'reject'
  actual: Record<string, string>
  na: Record<string, boolean>
  reject_reason: string | null
}

export type MinePage = {
  name: string
  role_label: string
  track: string
  track_options: Option[]
  period: string
  period_months: number
  period_label: string
  period_options: Option[]
  status: string
  status_label: string
  status_color: string
  reject_reason: string | null
  decided_by: string | null
  decided_at: string | null
  fund: number | null
  amount: number | null
  rate_label: string
  grade: Grade | null
  bonus: number | null
  bonus_fund: number | null
  missing: number
  knockout: string | null
  groups: LineGroup[]
}

export type AttendanceRecord = {
  id: string
  student: string
  class: string
  teacher: string
  note: string | null
  status: string
  status_label: string
  review_status: string
  review_label: string
}

export type AttendancePage = {
  records: AttendanceRecord[]
  summary: { present: number; late: number; absent: number; excused: number }
  classes: Option[]
  date: string
  class_id: string | null
  pending: string | null
}

export type BoardRow = {
  id: string
  name: string
  code: string
  classes: number
  retention: number | null
  retention_detail: string
  attendance: number | null
  attendance_detail: string
  homework: number | null
  homework_detail: string
  tasks: number | null
  tasks_detail: string
}

export type BoardFilter = { month: string; month_to: string; user_id: string; page: number }

export type BoardPage = {
  staff: BoardRow[]
  total: number
  page: number
  per_page: number
  staff_options: Option[]
  user_id: string | null
  month: string
  month_to: string
  period_label: string
  can_see_staff: boolean
  homework_pending: string
  pending: string | null
}

export function query(params: Record<string, string | number | undefined>) {
  const q = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    // Trang 1 là mặc định, không gửi.
    if (v === undefined || v === '' || (k === 'page' && Number(v) <= 1)) continue
    q.set(k, String(v))
  }
  const s = q.toString()
  return s ? `?${s}` : ''
}

export const kpiApi = {
  criteria: (role: string) => request<CriteriaPage>('GET', `/kpi/criteria${query({ role })}`),
  createCriterion: (in_: CriterionInput) => request<Msg & { role: string }>('POST', '/kpi/criteria', in_),
  updateCriterion: (id: string, in_: CriterionInput) => request<Msg & { role: string }>('PUT', `/kpi/criteria/${id}`, in_),
  deleteCriterion: (id: string) => request<Msg & { role: string }>('DELETE', `/kpi/criteria/${id}`),
  setCycle: (role: string, period_months: number, grade_fund?: number | null) =>
    request<Msg>('POST', '/kpi/criteria/cycle', grade_fund === undefined ? { role, period_months } : { role, period_months, grade_fund }),
  monthly: (f: MonthlyFilter) => request<MonthlyPage>('GET', `/kpi/monthly${query(f)}`),
  evaluate: (userId: string, period: string, track: string) =>
    request<EvaluatePage>('GET', `/kpi/evaluate/${userId}${query({ period, track: track === 'main' ? '' : track })}`),
  decide: (userId: string, in_: DecideInput) => request<Msg>('POST', `/kpi/evaluate/${userId}`, in_),
  mine: (period: string, track: string) => request<MinePage>('GET', `/kpi/mine${query({ period, track: track === 'main' ? '' : track })}`),
  attendance: (date: string, classId: string) => request<AttendancePage>('GET', `/kpi/attendance-review${query({ date, class_id: classId })}`),
  review: (id: string, decision: 'approved' | 'rejected') => request<Msg>('POST', `/kpi/attendance-review/${id}`, { decision }),
  board: (f: BoardFilter) => request<BoardPage>('GET', `/kpi/board${query(f)}`),
  boardExportUrl: (f: BoardFilter) => `/api/v1/kpi/board/export${query({ month: f.month, month_to: f.month_to, user_id: f.user_id })}`,
}
