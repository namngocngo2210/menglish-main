// API Lương & Phạt (PayrollController, PenaltyController của Laravel): kỳ lương, phiếu lương, Lương của tôi, xếp hạng KPI &
// Thưởng, cấu hình lương (tham số, đơn giá giáo viên, mốc hoa hồng), biên bản vi phạm.
import { request, upload } from '../../api'

export type Option = { value: string; label: string }
export type Paging = {
  current_page: number
  per_page: number
  total: number
  last_page: number
}
type Msg = { message: string }

// ───────────── Kỳ lương ─────────────

export type PeriodListRow = {
  id: string
  code: string
  title: string
  start_date: string
  end_date: string
  staff: number
  hours: number
  amount: number
  status: string
  status_label: string
  status_color: string
}

export type PeriodsPage = {
  periods: PeriodListRow[]
  paging: Paging
  all_periods: Option[]
  status_options: Option[]
  default_month: number
  default_year: number
}

export type PeriodData = {
  id: string
  code: string
  title: string
  month: number
  year: number
  start_date: string
  end_date: string
  status: string
  status_label: string
  status_color: string
  locked: boolean
}

export type Calendar = {
  kpi_close_on: string
  attendance_close_on: string
  violation_close_on: string
  approve_from: string
  close_after_days: number
  pay_from: string
  pay_to: string
  can_approve: boolean
  in_pay_window: boolean
}

export type PeriodView = PeriodData & {
  next_step: string
  next_color: string
  calendar: Calendar
}

// kpi_state: [khóa, nhãn] — khóa done | pending | na | self.
export type RecordRow = {
  id: string
  user_id: string
  name: string
  email: string
  employee_code: string
  branch: string
  department: string
  salary_role: string
  is_part_time: boolean
  employee_type_label: string
  salary_role_label: string
  kpi_state: [string, string]
  kpi_source: string | null
  kpi_score: number | null
  kpi_manual_amount: number | null
  kpi_bonus: number
  retention_students: number
  retention_base_students: number
  retention_tier: number | null
  base_salary: number
  teaching_salary: number
  teaching_sessions: number
  actual_hours: number
  teaching_concurrent: boolean
  teaching_kpi_bonus: number
  foreign_session_pay: number
  foreign_teacher_sessions_count: number
  adjustment_notes: string | null
  commission_bonus: number
  commission_deferred: number
  renew_bonus: number
  free_allowance: number
  insurance_deduction: number
  union_deduction: number
  tax_deduction: number
  other_deductions: number
  gross_income: number
  total_deductions: number
  net_salary: number
}

export type PeriodStats = {
  total_amount: number
  records: number
  parttime_sessions: number
  hours: number
  kpi_bonus: number
  deductions: number
}

export type MissingStaff = {
  count: number
  items: { id: string; name: string; code: string; reason: string }[]
}

export type PeriodPage = {
  period: PeriodView
  stats: PeriodStats
  kpi_pending: { count: number; names: string }
  records: RecordRow[]
  paging: Paging
  period_options: Option[]
  type_options: Option[]
  filtered: boolean
  missing_staff: MissingStaff | null
  attendance_pending: string | null
}

export type PeriodFilter = {
  search: string
  type: string
  kpi: string
  page: number
}

export type DepartmentPage = {
  department: string
  label: string
  period: PeriodData
  records: RecordRow[]
  totals: Record<string, number>
}

// ───────────── Phiếu lương ─────────────

export type Line = {
  key: string
  label: string
  amount: number
  hint: string | null
  quantity: string
}
export type ManualLine = {
  kind: 'earning' | 'deduction'
  label: string
  amount: number
}
export type Variant = { key: string; title: string; type: string }

export type TimesheetRow = {
  id: string
  date: string
  scheduled_time: string
  class: string
  type: string
  type_label: string
  hours: number
  rate: number
  unit: string
  amount: number
}

export type CommissionItemRow = {
  id: string
  student: string | null
  receipt_number: string
  earned: string
  closing_rank: number
  closed_month: string | null
  deferred_reason: string | null
  base_amount: number
  percent: number
  amount: number
}

export type RecordDetail = RecordRow & {
  uses_q3: boolean
  is_full_time: boolean
  retention_lost: number
  commission_closed_count: number
  commission_percent: number | null
  kpi_fund: number
  rate_insurance: number
  rate_union: number
  notes: string | null
  manual_lines: ManualLine[]
  earning_lines: Line[]
  deduction_lines: Line[]
}

export type KpiItem = {
  code: string
  group: string
  name: string
  weight: number
  score: number
  amount: number
}
export type KpiGroup = {
  group: string
  weight: number
  amount: number
  items: KpiItem[]
}

export type RecordPage = {
  record: RecordDetail
  period: PeriodData
  variant: Variant
  can_edit: boolean
  lines: { kind: string; label: string; amount: number | '' }[]
  retention_tiers: number[]
  current_rate: {
    rate: number
    unit_label: string
    effective_from: string
    is_tuition_share: boolean
  } | null
  lost_students: string
  teaching_share: {
    classes: unknown[]
    default_percent: number
    skipped: number
    rate_percent: number | null
  } | null
  timesheets: TimesheetRow[]
  attendance_pending: boolean
  kpi_groups: KpiGroup[]
  renewal_classes: {
    name?: string
    quits?: number
    percent?: number
    revenue?: number
    amount?: number
  }[]
  late_lines: {
    date: string
    class: string
    minutes: number
    rule: string
    deduction: number
  }[]
  late_info: { threshold: number; per_minute: number; total: number }
  daily_attendance: Record<string, unknown> | null
  commission_tiers: {
    id: string
    name: string
    range: string
    percent: number
    applied: boolean
  }[]
  penalties: {
    id: string
    code: string
    violation_type: string
    violation_date: string
    due_date: string | null
    amount: number
  }[]
  clawbacks: { id: string; reason: string; amount: number }[]
  paid_commission: CommissionItemRow[]
  deferred_commission: CommissionItemRow[]
}

export type AdjustInput = {
  retention_tier?: number | null
  kpi_manual_amount?: number | null
  foreign_session_pay?: number | null
  tax_deduction?: number | null
  lines: ManualLine[]
  adjustment_notes?: string | null
}

// ───────────── Lương của tôi / Xếp hạng ─────────────

export type MySalaryPage = {
  record: {
    id: string
    payroll_period_id: string
    period_status: string
    period_status_label: string
    period_title: string
    gross_income: number
    total_deductions: number
    net_salary: number
    change: number | null
    is_full_time: boolean
    adjustment_notes: string | null
    variant: Variant
    earning_lines: Line[]
    deduction_lines: Line[]
  } | null
  period_options: Option[]
  penalties: {
    id: string
    code: string
    violation_type: string
    violation_date: string
    amount: number
  }[]
  timesheets: TimesheetRow[]
  attendance_pending: boolean
}

export type RetentionRow = {
  id: string
  user_id: string
  name: string
  branch: string
  retention_students: number
  retention_base_students: number
  retention_tier: number | null
  // null khi người xem không có payroll.view (ẩn số tiền)
  kpi_bonus: number | null
}

export type SalesRow = {
  id: string
  name: string
  tier_name: string
  deals: number
  branch: string
  closed: number
  percent: number
  next_percent: number | null
  to_next: number | null
  // null khi người xem không có payroll.view (ẩn số tiền)
  revenue: number | null
  base: number | null
  commission: number | null
}

export type LeaderboardPage = {
  retention: RetentionRow[]
  retention_paging: Paging
  sales: SalesRow[]
  sales_paging: Paging
  period: { locked?: boolean } | null
  month: number
  year: number
  selected_period: string
  branch_id: string | null
  period_options: Option[]
  branches: Option[]
  show_money: boolean
}

export type LeaderboardFilter = {
  period: string
  branch_id: string
  kpi_page: number
  sales_page: number
}

// ───────────── Cấu hình ─────────────

export type RenewalRow = { quits: number; percent: number; pending: boolean }

export type Settings = {
  insurance_rate_percent: number
  union_rate_percent: number
  academic_kpi_fund: number
  retention_tiers: number[]
  renewal: RenewalRow[]
  renewal_beyond_percent: number
  late_threshold_minutes: number
  late_deduction_per_minute: number
  teaching_share_percent: number
}

export type SettingsPage = { settings: Settings; defaults: Settings }

export type SettingsInput = {
  insurance_rate_percent: number | null
  union_rate_percent: number | null
  academic_kpi_fund: number | null
  renewal: {
    quits: number | null
    percent: number | null
    pending: boolean
  }[]
  renewal_beyond_percent: number | null
  late_threshold_minutes: number | null
  late_deduction_per_minute: number | null
  teaching_share_percent: number | null
}

export type RankRate = {
  id: string
  rank_title: string
  criteria: string | null
  communication_rate: number
  ielts_rate: number
}

export type RateHistoryRow = {
  id: string
  user_id: string
  user_name: string
  teacher_type_label: string
  hourly_rate: number
  unit: string
  unit_label: string
  rate_unit: string
  effective_from: string
  end: string | null
  state_label: string
  state_color: string
  note: string | null
  creator: string
  created_at: string
}

export type TeacherRow = {
  id: string
  name: string
  employee_code: string
  search: string
  current: {
    rate: number
    unit_label: string
    effective_from: string
  } | null
  profile_rate: number
}

export type RatesPage = {
  rates: RankRate[]
  teachers: TeacherRow[]
  history: RateHistoryRow[]
  paging: Paging
  selected_teacher: {
    id: string
    name: string
    employee_code: string
    type_label: string
    type: string
    current: Record<string, string> | null
    profile_rate: string | null
    history: RateHistoryRow[]
  } | null
  teacher_types: Option[]
  default_rate: string
  today: string
  today_date: string
}

export type PersonalRateInput = {
  user_id: string
  hourly_rate: number | null
  rate_unit: string
  teacher_type: string
  effective_from: string
  note: string
}

export type RankRateInput = {
  rank_title: string
  criteria: string | null
  communication_rate: number | null
  ielts_rate: number | null
}

export type TierRow = {
  id: string
  tier_name: string
  min_students: number
  max_students: number | null
  new_sale_percent: number
  percent_label: string
  range: string
  effective_from: string
  effective_date: string | null
  effective_to: string
  current: boolean
  creator: string
}

export type TiersPage = {
  as_of: Record<string, string>
  tiers: TierRow[]
  history: TierRow[]
  paging: Paging
  gate_days: number
  gate_milestones: number
  today: string
  tomorrow: string
  renewal_rows: RenewalRow[]
  renewal_beyond: number
}

export type TierInput = {
  tier_name: string
  min_students: number | null
  max_students: number | null
  new_sale_percent: number | null
  effective_from: string
}

// ───────────── Biên bản vi phạm ─────────────

export type PenaltyRow = {
  id: string
  code: string
  user_name: string
  employee_code: string
  violation_date: string
  violation_time: string | null
  evidence_url: string | null
  source_label: string
  violation_type: string
  category: string | null
  category_label: string
  confirmer_label: string
  step: string
  step_label: string
  status: string
  status_label: string
  overdue: boolean
  amount: number
  due_date: string | null
  paid_at: string | null
  employee_state: string
  employee_color: string
  remedied: boolean
  class_name: string | null
  reporter: string | null
  notes: string | null
  explanation: string | null
  decision_note: string | null
  decider: string | null
  remedy: string | null
  can_decide: boolean
  can_explain: boolean
  can_mark_paid: boolean
  can_resolve: boolean
  can_remedy: boolean
  can_cancel: boolean
}

export type ViolationOption = { value: string; category: string }
export type StaffOption = { value: string; label: string; roles: string[] }

export type PenaltyFilter = {
  search: string
  status: string
  error_category: string
  step: string
  from: string
  to: string
  page: number
}

export type PenaltiesPage = {
  penalties: PenaltyRow[]
  paging: Paging
  filters: PenaltyFilter
  users: StaffOption[]
  classes: Option[]
  can_view_all: boolean
  can_create: boolean
  counts: {
    pending: number
    deciding: number
    fined: number
    overdue: number
  }
  steps: Option[]
  category_options: Option[]
  category_confirmer_options: Option[]
  status_options: Option[]
  role_violations: Record<string, { label: string; violations: ViolationOption[] }>
  all_violations: ViolationOption[]
  payment_due_days: number
  violation_window: { hours: number; min: string; max: string }
}

export type PenaltyInput = {
  user_id: string
  error_category: string
  violation_type: string
  violation_at: string
  amount: string
  class_id: string
  notes: string
}

export type DecisionInput = {
  decision: 'fine' | 'error'
  amount: number | null
  decision_note: string
}

// query: chuỗi tham số bỏ giá trị rỗng / trang 1.
export function query(params: Record<string, string | number | null | undefined>) {
  const q = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v === null || v === undefined || v === '' || (k.endsWith('page') && v === 1)) continue
    q.set(k, String(v))
  }
  const s = q.toString()
  return s ? `?${s}` : ''
}

export const payrollApi = {
  periods: (f: { search: string; status: string; page: number }) => request<PeriodsPage>('GET', `/payroll/periods${query(f)}`),
  createPeriod: (month: number | null, year: number | null) =>
    request<Msg & { id: string }>('POST', '/payroll/periods', {
      month,
      year,
    }),
  period: (id: string, f: PeriodFilter) => request<PeriodPage>('GET', `/payroll/periods/${id}${query(f)}`),
  calculate: (id: string) => request<Msg>('POST', `/payroll/periods/${id}/calculate`),
  approve: (id: string) => request<Msg>('POST', `/payroll/periods/${id}/approve`),
  markPaid: (id: string) => request<Msg & { warning: string | null }>('POST', `/payroll/periods/${id}/mark-paid`),
  exportUrl: (id: string, department = '') => `/api/v1/payroll/periods/${id}/export${query({ department })}`,
  department: (id: string, dep: string) => request<DepartmentPage>('GET', `/payroll/periods/${id}/departments/${dep}`),
  record: (id: string) => request<RecordPage>('GET', `/payroll/records/${id}`),
  adjust: (id: string, input: AdjustInput) => request<Msg>('POST', `/payroll/records/${id}/adjust`, input),
  foreignPay: (id: string, amount: number | null, notes: string) =>
    request<Msg>('POST', `/payroll/records/${id}/update`, {
      foreign_session_pay: amount,
      notes,
    }),
  mySalary: (periodId: string) => request<MySalaryPage>('GET', `/portal/my-salary${query({ period_id: periodId })}`),
  leaderboard: (f: LeaderboardFilter) => request<LeaderboardPage>('GET', `/payroll/kpi-leaderboard${query(f)}`),

  settings: () => request<SettingsPage>('GET', '/payroll/config/settings'),
  saveSettings: (input: SettingsInput) => request<Msg>('POST', '/payroll/config/settings', input),
  rates: (teacherId: string, page: number) => request<RatesPage>('GET', `/payroll/config/teacher-rates${query({ teacher_id: teacherId, page })}`),
  saveRankRates: (rates: RankRateInput[]) => request<Msg>('POST', '/payroll/config/teacher-rates', { rates }),
  addPersonalRate: (input: PersonalRateInput) => request<Msg>('POST', '/payroll/config/teacher-rates/personal', input),
  tiers: (asOf: string, page: number) => request<TiersPage>('GET', `/payroll/config/commission-tiers${query({ as_of: asOf, page })}`),
  createTier: (input: TierInput) => request<Msg>('POST', '/payroll/config/commission-tiers', input),
  updateTier: (id: string, input: TierInput) => request<Msg>('PUT', `/payroll/config/commission-tiers/${id}`, input),
  retireTier: (id: string) => request<Msg>('DELETE', `/payroll/config/commission-tiers/${id}`),
  saveRenewal: (renewal: SettingsInput['renewal'], beyond: number | null) =>
    request<Msg>('POST', '/payroll/config/renewal-bonus', {
      renewal,
      renewal_beyond_percent: beyond,
    }),

  penalties: (f: PenaltyFilter) => request<PenaltiesPage>('GET', `/penalties${query(f)}`),
  createPenalty: (input: PenaltyInput, evidence: File | null) => {
    const form = new FormData()
    for (const [k, v] of Object.entries(input)) form.append(k, v)
    if (evidence) form.append('evidence', evidence)
    return upload<Msg>('/penalties', form)
  },
  explain: (id: string, explanation: string) => request<Msg>('POST', `/penalties/${id}/explain`, { explanation }),
  decide: (id: string, input: DecisionInput) => request<Msg>('POST', `/penalties/${id}/confirm`, input),
  penaltyPaid: (id: string) => request<Msg>('POST', `/penalties/${id}/mark-paid`),
  resolve: (id: string) => request<Msg>('POST', `/penalties/${id}/resolve`),
  cancel: (id: string) => request<Msg>('POST', `/penalties/${id}/cancel`),
  remedy: (id: string, note: string) => request<Msg>('POST', `/penalties/${id}/remedy`, { remedy_note: note }),
}
