// Gọi API CRM (/api/v1/crm). Kiểu dữ liệu theo JSON của Go (internal/crm).
import { ApiError, request } from '../../api'

export type Option = { value: string; label: string }
export type OwnerOption = { value: string; label: string; branch_ids: string[]; all_branches: boolean }

export type SLA = {
  kind: 'first' | 'follow_up' | 'appointment'
  label: string
  deadline: string
  deadline_label: string
  warn_seconds: number
  state: 'on_time' | 'due_soon' | 'overdue'
  remaining: string
}

export type ChipCounts = {
  sla: number
  test_today: number
  follow_up: number
  waiting_class: number
  won: number
  lost: number
  deleted: number
}

export type Meta = { page: number; per_page: number; total: number }
export type Page<T> = { data: T[]; meta: Meta }

export type LeadRow = {
  id: string
  code: string
  short_code: string
  name: string
  phone: string
  parent_name: string | null
  parent_phone: string | null
  email: string | null
  source: string | null
  stage: string
  stage_label: string
  course_interest: string | null
  deal_value: number
  branch_id: string | null
  branch: string | null
  assigned_user_id: string | null
  assigned_user: string | null
  appointment_at: string | null
  appointment_type: string | null
  next_follow_up_at: string | null
  last_care_at: string | null
  lost_reason: string | null
  lost_at: string | null
  converted_at: string | null
  converted_student_id: string | null
  class_name: string | null
  enrollment_confirmed: boolean | null
  notes: string | null
  created_at: string
  updated_at: string
  sla: SLA | null
  test_today_at: string | null
}

export type LeadList = Page<LeadRow> & { counts: ChipCounts }
export type WonList = LeadList & {
  total_count: number
  total_contract_amount: number
  total_collected_amount: number
  total_debt_amount: number
}

export type StagePerms = { can_forward: boolean; can_backward: boolean; can_convert: boolean; can_mark_lost: boolean }

export type Options = {
  stages: Option[]
  filter_branches: Option[]
  filter_owners: Option[]
  filter_sources: string[]
  form_branches: Option[]
  form_owners: OwnerOption[]
  all_owners: OwnerOption[]
  sources: string[]
  default_branch_id: string | null
  default_assignee_id: string
  event_types: Option[]
  care_items: Option[]
  scope: 'own' | 'branch' | 'all'
  permissions: StagePerms
}

export type Card = {
  id: string
  code: string
  short_code: string
  name: string
  parent_name: string | null
  phone: string
  source: string
  course: string
  deal_value: number
  agent: string
  created_at: string
  score: string
  has_test_result: boolean
  confirmed: boolean
  next_blocked: string | null
  has_student: boolean
  sla: SLA | null
  converted_at: string | null
}
export type Column = { id: string; name: string; next: string | null; count: number; hidden: number; amount: number; leads: Card[] }
export type Pipeline = {
  stages: Column[]
  permissions: StagePerms
  order: string[]
  closed: string[]
  closable: string[]
  counts: ChipCounts
}

export type Change = { label: string; old: string | null; new: string | null }
export type LeadEvent = {
  id: string
  type: string
  type_label: string
  failed: boolean
  is_lost: boolean
  user: string | null
  content: string
  reason: string | null
  from_stage: string | null
  to_stage: string | null
  changes?: Record<string, Change>
  created_at: string
}

export type LeadDetail = {
  id: string
  code: string
  short_code: string
  name: string
  phone: string
  parent_name: string | null
  parent_phone: string | null
  email: string | null
  dob: string | null
  gender: string | null
  address: string | null
  school: string | null
  course_interest: string | null
  source: string | null
  deal_value: number
  notes: string | null
  stage: string
  stage_label: string
  branch_id: string | null
  branch: string | null
  assigned_user_id: string | null
  assigned_user: string | null
  converted_student_id: string | null
  converted_at: string | null
  lost_at: string | null
  lost_reason: string | null
  waiting_since: string | null
  waiting_branch: string | null
  fee_paid_at_closing: boolean
  next_follow_up_at: string | null
  appointment_at: string | null
  appointment_type: string | null
  test_score: string | null
  test_decision: string | null
  examiner: string | null
  created_at: string
  updated_at: string
  contract_locked: boolean
  status_card: {
    stage_since: string
    days_in_stage: number
    last_contact: string | null
    sla: SLA | null
    neglected: boolean
    neglect_days: number
  }
  stage_controls: {
    next: string | null
    next_label: string | null
    next_blocked: string | null
    backward: Option[]
    can_lose: boolean
    can_reopen: boolean
    can_cancel_closing: boolean
  }
  actions: { can_close: boolean; can_place: boolean; can_update: boolean; can_reassign: boolean; primary: string | null }
  pending_transfer: {
    id: string
    requester: string | null
    to_user: string | null
    from_branch: string | null
    to_branch: string | null
    created_at: string
  } | null
  care_checklist: { key: string; label: string; done: boolean; done_at: string | null; by: string | null }[] | null
  events: LeadEvent[]
  event_counts: Record<string, number>
  reassign_owners: OwnerOption[] | null
  reassign_branches: Option[] | null
  edit_form: { branches: Option[]; owners: OwnerOption[]; sources: Option[]; locked_fields: string } | null
}

export type LeadInput = {
  name: string
  phone: string
  parent_name?: string
  parent_phone?: string
  email?: string
  dob?: string
  gender?: string
  address?: string
  school?: string
  branch_id?: string | null
  course_interest?: string
  source?: string
  assigned_user_id?: string | null
  deal_value?: number | null
  next_follow_up_at?: string
  notes?: string
}

export type Saved = { id: string; message: string }
export type StageResult = { stage: string; stage_label: string; message: string }

export type ClassChoice = { id: string; label: string; course_id: string | null }
export type ClosingChoices = {
  classes: ClassChoice[]
  courses: { id: string; label: string; fee: number }[]
  promotions: Option[]
}

export type WaitingRow = {
  id: string
  short_code: string
  name: string
  phone: string
  student_id: string | null
  course: string | null
  branch: string
  assigned_user: string | null
  converted_at: string | null
  wait_days: number | null
  fee_paid_at_closing: boolean
}

export type DeletedRow = {
  id: string
  short_code: string
  name: string
  phone: string
  branch: string | null
  stage: string
  stage_label: string
  assigned_user: string | null
  deleted_at: string | null
}

export type TransferRow = {
  id: string
  lead_id: string
  lead_name: string
  lead_code: string
  lead_short_code: string
  has_student: boolean
  from_branch: string | null
  to_branch: string | null
  from_user: string | null
  to_user: string | null
  requester: string | null
  reason: string | null
  status: string
  status_label: string
  decider: string | null
  decided_at: string | null
  decision_note: string | null
  created_at: string
}

export type ConfirmationRow = {
  id: string
  lead_id: string
  student_id: string
  student_code: string
  student_name: string
  phone: string
  class_id: string
  class_name: string
  class_code: string | null
  branch: string | null
  start_label: string
  closed_at: string
  confirmed_at: string | null
  confirmed_by: string | null
  checklist: Record<string, boolean>
}
export type ConfirmationList = Page<ConfirmationRow> & {
  status: string
  pending_count: number
  total_count: number
  filter_classes: Option[]
  filter_branches: Option[]
  checklist_items: Option[]
}

export type PromotionRow = {
  id: string
  code: string
  name: string
  type: 'fixed' | 'percent'
  value: number
  value_label: string
  max_discount_amount: number | null
  branch_id: string | null
  course_id: string | null
  scope_label: string
  period_label: string
  usage_label: string
  starts_at: string | null
  ends_at: string | null
  usage_limit: number | null
  used_count: number
  is_active: boolean
  is_default: boolean
  is_special: boolean
  is_available: boolean
  description: string | null
  reason: string | null
  creator_name: string | null
  created_at: string
}
export type PromotionList = Page<PromotionRow> & { tab: string; counts: Record<string, number>; branches: Option[] }
export type PromotionInput = {
  name: string
  type: 'fixed' | 'percent'
  value: number
  max_discount_amount?: number | null
  description?: string
  reason?: string
  branch_id?: string | null
  starts_at?: string
  ends_at?: string
  usage_limit?: number | null
  is_active?: boolean
  is_default?: boolean
  is_special?: boolean
}

export type Report = {
  preset: string
  preset_label: string
  presets: Option[]
  start_date: string
  end_date: string
  branch_id: string | null
  branches: Option[]
  metric_total_leads: number
  metric_won_deals: number
  metric_conversion_rate: number
  metric_lost_deals: number
  lead_delta_percent: number
  lead_diff: number
  won_delta_percent: number
  won_diff: number
  conversion_delta_percent: number
  lost_delta_percent: number
  lost_diff: number
  funnel_stages: { stage: string; name: string; count: number; percent: number; desc: string }[]
  lost_reasons: { id: string; name: string; at: string; reason: string | null; user: string | null }[]
  reps: {
    user_id: string
    name: string
    role: string
    avatar_letter: string
    leads: number
    won: number
    rate: number
    revenue: number
    commission_amount: number
    tier_name: string
    delta: string
    rating: string
  }[]
  updated_at: string
}

export type ImportOptions = {
  branches: Option[]
  owners: OwnerOption[]
  can_assign: boolean
  default_branch_id: string | null
  max_rows: number
}
export type ImportRow = { line: number; data: Record<string, string>; errors: string[] }
export type ImportPreview = {
  id: string
  file_name: string
  branch_name: string
  assigned_user_name: string
  status: string
  valid_count: number
  error_count: number
  rows: ImportRow[]
  created_count: number
  skipped: string[] | null
}
export type ImportResult = { created: number; skipped: string[]; message: string }

export type ListParams = {
  search?: string
  branch_id?: string
  assigned_user_id?: string
  source?: string
  from?: string
  to?: string
  stage?: string
  sla?: boolean
  follow_up?: boolean
  test_today?: boolean
  page?: number
  per_page?: number
}

export function query(params: Record<string, string | number | boolean | undefined | null>) {
  const qs = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null || v === '' || v === false) continue
    qs.set(k, v === true ? '1' : String(v))
  }
  const s = qs.toString()
  return s ? `?${s}` : ''
}

const lead = (id: string) => `/crm/leads/${encodeURIComponent(id)}`

export const crmApi = {
  options: () => request<Options>('GET', '/crm/options'),
  counts: () => request<ChipCounts>('GET', '/crm/counts'),
  leads: (p: ListParams) => request<LeadList>('GET', `/crm/leads${query(p)}`),
  won: (p: ListParams) => request<WonList>('GET', `/crm/won${query(p)}`),
  lost: (p: ListParams) => request<LeadList>('GET', `/crm/lost${query(p)}`),
  waiting: () => request<{ data: WaitingRow[]; counts: ChipCounts }>('GET', '/crm/waiting'),
  deleted: (search: string, page: number) => request<Page<DeletedRow>>('GET', `/crm/deleted${query({ search, page })}`),
  pipeline: (p: ListParams) => request<Pipeline>('GET', `/crm/pipeline${query(p)}`),
  lead: (id: string) => request<LeadDetail>('GET', lead(id)),
  create: (b: LeadInput) => request<Saved>('POST', '/crm/leads', b),
  update: (id: string, b: LeadInput) => request<Saved>('PUT', lead(id), b),
  stage: (id: string, stage: string, reason?: string) =>
    request<StageResult>('POST', `${lead(id)}/stage`, stage === 'lost' ? { stage, lost_reason: reason } : { stage, reason }),
  nextStage: (id: string) => request<StageResult>('POST', `${lead(id)}/next-stage`, {}),
  cancelClosing: (id: string, reason: string) => request<Saved>('POST', `${lead(id)}/cancel-closing`, { reason }),
  note: (id: string, b: { type: string; content?: string; outcome?: string; sent_at?: string; next_follow_up_at?: string }) =>
    request<Saved>('POST', `${lead(id)}/notes`, b),
  care: (id: string, items: string[], note?: string) => request<Saved>('POST', `${lead(id)}/care-checklist`, { items, note }),
  reassign: (id: string, b: { assigned_user_id: string; branch_id?: string | null; reason: string }) =>
    request<Saved>('POST', `${lead(id)}/reassign`, b),
  bulkReassign: (b: { ids: string[]; assigned_user_id: string; reason: string }) =>
    request<Saved>('POST', '/crm/leads/bulk-reassign', b),
  restore: (id: string) => request<Saved>('POST', `${lead(id)}/restore`),
  close: (id: string, b: Record<string, unknown>) => request<Saved>('POST', `${lead(id)}/close`, b),
  assignClass: (id: string, class_id: string) => request<Saved>('POST', `${lead(id)}/assign-class`, { class_id }),
  closingChoices: (id: string) => request<ClosingChoices>('GET', `${lead(id)}/closing-options`),
  classChoices: (id: string) => request<{ classes: ClassChoice[] }>('GET', `${lead(id)}/class-options`),

  transfers: (status: string, page: number) => request<Page<TransferRow>>('GET', `/crm/transfers${query({ status, page })}`),
  approveTransfer: (id: string, note: string) => request<Saved>('POST', `/crm/transfers/${id}/approve`, { note }),
  rejectTransfer: (id: string, reason: string) => request<Saved>('POST', `/crm/transfers/${id}/reject`, { reason }),

  confirmations: (p: { status?: string; search?: string; branch_id?: string; class_id?: string; page?: number }) =>
    request<ConfirmationList>('GET', `/crm/confirmations${query(p)}`),
  confirm: (id: string, b: { account_sent: boolean; zalo_group_added: boolean; curriculum_delivered: boolean; action: string }) =>
    request<Saved>('POST', `/crm/confirmations/${id}`, b),

  promotions: (p: { tab?: string; status?: string; q?: string; page?: number }) =>
    request<PromotionList>('GET', `/crm/promotions${query(p)}`),
  createPromotion: (b: PromotionInput) => request<Saved>('POST', '/crm/promotions', b),
  updatePromotion: (id: string, b: PromotionInput) => request<Saved>('PUT', `/crm/promotions/${id}`, b),
  togglePromotion: (id: string) => request<Saved>('POST', `/crm/promotions/${id}/toggle`),

  report: (p: { preset?: string; start_date?: string; end_date?: string; branch_id?: string }) =>
    request<Report>('GET', `/crm/reports${query(p)}`),

  importOptions: () => request<ImportOptions>('GET', '/crm/import/options'),
  importBatch: (id: string) => request<ImportPreview>('GET', `/crm/import/${id}`),
  commitImport: (id: string) => request<ImportResult>('POST', `/crm/import/${id}/commit`),
  cancelImport: (id: string) => request<Saved>('POST', `/crm/import/${id}/cancel`),
}

// previewImport gửi multipart (file + chi nhánh + người phụ trách); CSRF lấy từ /auth/me.
export async function previewImport(csrf: string, form: FormData): Promise<ImportPreview> {
  const res = await fetch('/api/v1/crm/import/preview', {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrf },
    credentials: 'same-origin',
    body: form,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new ApiError(res.status, data.error ?? 'http_error', data.message ?? `Lỗi máy chủ (HTTP ${res.status}).`)
  }
  return data as ImportPreview
}

// Đường dẫn tải file (trình duyệt tự gửi cookie phiên).
export const downloads = {
  leads: (p: ListParams, format: 'xlsx' | 'csv') => `/api/v1/crm/leads/export${query({ ...p, format })}`,
  won: (p: ListParams, format: 'xlsx' | 'csv') => `/api/v1/crm/won${query({ ...p, export: format })}`,
  lost: (p: ListParams, format: 'xlsx' | 'csv') => `/api/v1/crm/lost${query({ ...p, export: format })}`,
  report: (p: Record<string, string | undefined>, format: 'xlsx' | 'csv') => `/api/v1/crm/reports/export${query({ ...p, format })}`,
  importTemplate: '/api/v1/crm/import/template',
  importErrors: (id: string) => `/api/v1/crm/import/${id}/errors`,
}
