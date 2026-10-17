// API Học phí: công nợ học viên, lịch sử thanh toán, lập / sửa / duyệt / trả về phiếu thu, xác nhận nộp tiền mặt,
// lịch sử thu (Go: internal/tuition).
import { request, upload } from '../../api'

export type Option = { value: string; label: string }
export type Tone = 'neutral' | 'success' | 'error' | 'info' | 'warning'
export type Page = {
  page: number
  per_page: number
  total: number
  last_page: number
}
export type StudentBrief = {
  id: string
  name: string
  code: string
  phone: string
}

export type TuitionRow = {
  id: string
  student: StudentBrief
  class_name: string | null
  branch_name: string | null
  fee_label: string
  final_amount: number
  paid_amount: number
  debt_amount: number
  due_date: string | null
  days_overdue: number
  status: string
  status_color: Tone
  status_label: string
}

export type DueRow = {
  id: string
  student: StudentBrief
  class_label: string
  class_name: string | null
  branch_name: string | null
  fee_label: string
  debt_amount: number
  due_date: string | null
  days_overdue: number
  session_lines: { label: string; value: number }[]
  last_contact: {
    at: string | null
    user_name: string | null
    note: string | null
    note_short: string | null
  } | null
  last_report_date: string | null
  reminder_paused_until: string | null
  deferred_until: string | null
}

export type DueGroups = {
  serious_overdue: DueRow[]
  new_overdue: DueRow[]
  upcoming: DueRow[]
  upcoming_page: Page
  paused: DueRow[]
  overdue_count: number
  type: string
  serious_days: number
  upcoming_days: number
}

export type StudentsPage = {
  tuitions: TuitionRow[]
  page: Page
  stats: { final: number; paid: number; debt: number; overdue: number }
  branches: Option[]
  classes: Option[]
  statuses: Option[]
  due: DueGroups
}

export type PaymentRow = {
  id: string
  kind: string
  title: string
  installment: number | null
  number: string
  date: string | null
  method: string
  method_icon: string
  amount: number
  surcharge: number
  discount: number
  debt_after: number | null
  invoice_number: string | null
  surcharge_detail: string | null
  creator_name: string | null
  approver_name: string | null
  notes: string | null
  tuition_label: string | null
}

export type StudentPayments = {
  student: StudentBrief
  summary: {
    final_amount: number
    paid_amount: number
    discount_amount: number
    debt_amount: number
    other_fees: number
    surcharge_amount: number
    installments: number
    next_due_date: string | null
    is_settled: boolean
  }
  tuitions: {
    id: string
    label: string
    course_label: string
    class_name: string | null
    final_amount: number
    other_fees: number
    paid_amount: number
    debt_amount: number
    due_date: string | null
    status_label: string
    status_color: Tone
  }[]
  payments: PaymentRow[]
  others: {
    id: string
    number: string
    date: string | null
    amount: number
    method: string
    status_label: string
    status_color: Tone
    rejection_reason: string | null
  }[]
}

// Sổ buổi (SessionLedger): contract = thu tiếp khoản còn nợ, new = khóa kế tiếp (tạo khoản khi duyệt), none = chưa xếp lớp.
export type Quote = {
  paid: number
  used: number
  balance: number
  class_name: string | null
  course_sessions: number
  held: number
  remaining: number
  needed: number
  carry_over: number
  mode: 'contract' | 'new' | 'none'
  tuition_id: string | null
  unit_price: number
  max_sessions: number
  contract_remaining_sessions: number | null
  tuition_remaining: number | null
  fee_due: number
  suggested: number
}

export type Bank = {
  id: string
  bank_code: string
  bank_name: string
  account_number: string
  account_holder: string
  scope: string
}
export type ItemLine = {
  id: string
  code?: string
  name: string
  unit?: string
  quantity: number
  unit_price?: number
  amount: number
  source?: string
}

export type FormTuition = {
  id: string
  student_id: string
  branch_id: string | null
  fee_label: string
  class_name: string | null
  course_id: string | null
  total_amount: number
  discount_amount: number
  other_fees: number
  paid_amount: number
  debt_amount: number
  final_amount: number
  due_date: string | null
  fee_items: ItemLine[]
  class_changed: boolean
  current_class_name: string | null
  transfer_memo: string
  bank: Bank | null
  status: string
  status_label: string
  quote: Quote
}

export type FormStudent = {
  id: string
  branch_id: string | null
  name: string
  code: string
  phone: string
  parent_name: string
  parent_phone: string
  class_name: string
  branch_name: string
  status: string
  is_deferred: boolean
  transfer_memo: string
  course_id: string | null
  quote: Quote
}

export type Promotion = {
  id: string
  code: string
  name: string
  type: 'percent' | 'fixed' | string
  value: number
  max_discount_amount: number | null
  branch_id: string | null
  course_id: string | null
  is_default: boolean
}

export type FormItem = {
  id: string
  code: string
  name: string
  unit: string
  price: number
  stock: number
}

export type SessionBreakdown = {
  session_count: number
  unit_price: number
  session_value: number
  fee_due: number
  material_fee: number
  exam_fee: number
  other_fee: number
  other_fee_reason: string | null
  subtotal: number
  discount: number
  total_due: number
  extra: number
  amount: number
}

export type StockLine = {
  item_id: string
  name: string
  unit: string
  quantity: number
  source: string
  stock: number
  stock_after: number
}

export type ReceiptDetail = {
  id: string
  code: string
  source: string
  status: string
  status_label: string
  status_color: Tone
  invoice_number: string | null
  issued_paper_invoice: string | null
  created_at: string
  creator_name: string | null
  approver_name: string | null
  branch_name: string
  student_id: string | null
  student_name: string | null
  student_code: string | null
  class_name: string
  payer_name: string | null
  payer_phone: string | null
  student_tuition_id: string | null
  tuition_due_date: string | null
  fee_label: string | null
  payment_method: string
  method_label: string
  transaction_code: string | null
  paper_invoice_number: string | null
  amount: number
  tuition_portion: number
  discount_amount: number
  promotion_id: string | null
  promotion_name: string | null
  contract_promotion_name: string | null
  contract_promotion_reason: string | null
  discount_reason: string | null
  surcharge_amount: number
  surcharge_reason: string | null
  surcharge_items: ItemLine[]
  session: SessionBreakdown | null
  is_vat_invoice: boolean
  notes: string | null
  rejection_reason: string | null
  proof_url: string | null
  reference: string
  payment_date: string | null
  deposit_state: 'pending' | 'on_time' | 'late' | null
  deposited_at: string | null
  deposited_by_name: string | null
  can_confirm_deposit: boolean
  can_resubmit: boolean
  beneficiary: string | null
  stock_out: StockLine[]
  reconciliation: { tone: Tone; label: string; detail: string } | null
  sepay_warnings: {
    sepay_id: string
    amount: number
    date: string | null
    receipt_number: string | null
  }[]
}

export type ReceiptForm = {
  student: FormStudent | null
  tuitions: FormTuition[]
  selected_tuition_id: string | null
  promotions: Promotion[]
  items: FormItem[]
  paper_invoice_next: string | null
  default_bank: Bank | null
  editing: ReceiptDetail | null
  recent_rejection: {
    id: string
    code: string
    rejection_reason: string | null
    can_edit: boolean
  } | null
  can_request_cancel: boolean
  methods: Option[]
}

export type StudentOption = {
  id: string
  code: string
  name: string
  phone: string
  class_name: string | null
  branch_name: string | null
}

export type ReceiptRow = {
  id: string
  code: string
  invoice_number: string | null
  has_proof: boolean
  paper_invoice_number: string | null
  student_id: string | null
  student_name: string
  student_code: string | null
  class_name: string
  branch_name: string
  fee_label: string | null
  payment_method: string
  method_label: string
  transaction_code: string | null
  amount: number
  surcharge_amount: number
  has_surcharge: boolean
  creator_name: string | null
  approver_name: string | null
  created_at: string
  payment_date: string | null
  status: string
  status_label: string
  status_color: Tone
  deposit_state: 'pending' | 'on_time' | 'late' | null
  deposited_at: string | null
  deposited_by_name: string | null
  can_confirm_deposit: boolean
}

export type ApproveScreen = {
  filters: {
    status: string
    branch_id: string | null
    payment_method: string
    q: string
  }
  branches: Option[]
  statuses: Option[]
  pending_count: number
  pending_total: number
  approved_today_count: number
  rejected_today_count: number
  receipts: ReceiptRow[]
  selected: ReceiptDetail | null
  can_approve: boolean
  can_reject: boolean
}

export type HistoryPage = {
  receipts: ReceiptRow[]
  page: Page
  amount_total: number
  statuses: Option[]
  methods: Option[]
}

export type ReceiptInput = {
  student_id?: string | null
  student_tuition_id?: string | null
  tuition_amount?: number | null
  session_count?: number | null
  exam_fee?: number
  other_fee?: number
  other_fee_reason?: string
  discount_amount?: number
  promotion_id?: string | null
  discount_reason?: string
  surcharge_amount?: number
  surcharge_reason?: string
  amount: number
  payment_method: string
  transaction_code?: string
  paper_invoice_number?: string
  expected_paper_invoice_number?: string | null
  payer_name?: string
  payer_phone?: string
  is_vat_invoice?: boolean
  notes?: string
  collected_items?: { id: string; quantity: number }[]
  proof_url?: string | null
  remove_proof?: boolean
  submit_action: 'draft' | 'submit'
}

export type Saved = {
  id: string
  code: string
  status: string
  message: string
}

export function query(params: Record<string, string | number | null | undefined>) {
  const qs = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) if (v !== '' && v !== null && v !== undefined) qs.set(k, String(v))
  const s = qs.toString()
  return s ? `?${s}` : ''
}

// Bill "Thông báo nộp học phí" (crm.tuition-bill).
export type Bill = {
  tuition: {
    id: string
    total_amount: number
    discount_amount: number
    other_fees: number
    prepaid_amount: number
    final_amount: number
    paid_amount: number
    debt_amount: number
    notes: string | null
  }
  student: {
    id: string
    code: string
    name: string
    phone: string
    parent_name: string | null
    parent_phone: string | null
  }
  class_name: string | null
  course_name: string | null
  branch_name: string | null
  customer: { id: string; code: string; name: string } | null
  promotion_name: string | null
  bank_account: {
    bank_name: string
    account_number: string
    account_holder: string
  } | null
  transfer_memo: string
  amount_to_pay: number
  pending_amount: number
  vietqr_url: string | null
  qr_warning: string | null
  total_sessions: number
  schedule: {
    text: string | null
    start_date: string | null
    end_date: string | null
  } | null
  receipts: {
    installment: number
    code: string
    invoice_number: string | null
    paper_invoice_number: string | null
    status: string
    method: string
    method_label: string
    date: string
    content: string
    amount: number
  }[]
  center: { name: string; branches: string[]; phone: string | null }
  today: string
}

export const tuitionApi = {
  bill: (id: string) => request<Bill>('GET', `/tuition/bill/${id}`),
  students: (p: { search?: string; status?: string; branch_id?: string; class_id?: string; page?: number; type?: string; upcoming_page?: number }) =>
    request<StudentsPage>('GET', `/tuition/students${query(p)}`),
  payments: (studentId: string) => request<StudentPayments>('GET', `/tuition/students/${studentId}/payments`),
  studentOptions: (q: string) => request<{ students: StudentOption[] }>('GET', `/tuition/student-options${query({ q })}`),
  form: (p: { student_id?: string; tuition_id?: string; receipt_id?: string }) => request<ReceiptForm>('GET', `/tuition/receipts/form${query(p)}`),
  create: (body: ReceiptInput) => request<Saved>('POST', '/tuition/receipts', body),
  update: (id: string, body: ReceiptInput) => request<Saved>('PUT', `/tuition/receipts/${id}`, body),
  receipt: (id: string) => request<ReceiptDetail>('GET', `/tuition/receipts/${id}`),
  approveScreen: (p: { status?: string; branch_id?: string; payment_method?: string; q?: string; selected_id?: string }) =>
    request<ApproveScreen>('GET', `/tuition/receipts/approve${query(p)}`),
  approve: (id: string, confirm_not_duplicate: boolean) =>
    request<Saved>('POST', `/tuition/receipts/${id}/approve`, {
      confirm_not_duplicate,
    }),
  reject: (id: string, rejection_reason: string) =>
    request<Saved>('POST', `/tuition/receipts/${id}/reject`, {
      rejection_reason,
    }),
  confirmDeposit: (id: string) => request<Saved>('POST', `/tuition/receipts/${id}/confirm-deposit`),
  history: (p: {
    search?: string
    student_id?: string
    status?: string
    method?: string
    from?: string
    to?: string
    kind?: string
    page?: number
  }) => request<HistoryPage>('GET', `/tuition/history${query(p)}`),
  exportUrl: (p: Record<string, string>) => `/api/v1/tuition/history/export${query(p)}`,
  overdue: (p: { search?: string; branch_id?: string; class_id?: string; type?: string; upcoming_page?: number }) =>
    request<OverduePage>('GET', `/tuition/overdue${query(p)}`),
  remind: (id: string, upcoming: boolean) =>
    request<{ message: string }>('POST', `/tuition/overdue/${id}/${upcoming ? 'upcoming-remind' : 'remind'}`),
  contacted: (id: string, body: { note?: string; contacted_at?: string }) =>
    request<{ message: string }>('POST', `/tuition/overdue/${id}/contacted`, body),
  reportAdmin: (id: string, note: string) => request<{ message: string }>('POST', `/tuition/overdue/${id}/report-admin`, { note }),
  refunds: (p: { status?: string; type?: string; search?: string }) => request<RefundsPage>('GET', `/tuition/refunds${query(p)}`),
  createRefund: (body: RefundInput) => request<Saved>('POST', '/tuition/refunds', body),
  approveRefund: (id: string, d: { clawback_commission?: boolean; clawback_amount?: number }, proof?: File | null) => {
    if (!proof) return request<Saved>('POST', `/tuition/refunds/${id}/approve`, d)
    const f = new FormData()
    f.append('proof_image', proof)
    if (d.clawback_commission !== undefined) f.append('clawback_commission', d.clawback_commission ? '1' : '0')
    if (d.clawback_amount !== undefined) f.append('clawback_amount', String(d.clawback_amount))
    return upload<Saved>(`/tuition/refunds/${id}/approve`, f)
  },
  rejectRefund: (id: string, rejection_reason: string) => request<Saved>('POST', `/tuition/refunds/${id}/reject`, { rejection_reason }),
  cancellations: (p: CancellationFilter) => request<CancellationsPage>('GET', `/tuition/invoices/cancellations${query(p)}`),
  cancellationsExportUrl: (p: CancellationFilter) =>
    `/api/v1/tuition/invoices/cancellations/export${query({ ...p, selected_id: undefined, cancel_invoice: undefined })}`,
  createCancellation: (d: { invoice_number: string; amount: number | null; reason: string }, proof?: File | null) => {
    if (!proof) return request<Saved>('POST', '/tuition/invoices/cancellations', d)
    const f = new FormData()
    f.append('invoice_number', d.invoice_number)
    if (d.amount !== null) f.append('amount', String(d.amount))
    f.append('reason', d.reason)
    f.append('proof_image', proof)
    return upload<Saved>('/tuition/invoices/cancellations', f)
  },
  approveCancellation: (id: string) => request<Saved>('POST', `/tuition/invoices/cancellations/${id}/approve`),
  rejectCancellation: (id: string, rejection_reason: string) =>
    request<Saved>('POST', `/tuition/invoices/cancellations/${id}/reject`, { rejection_reason }),
}

export type CancellationFilter = { status?: string; branch_id?: string; q?: string; selected_id?: string; cancel_invoice?: string }

export type CancellationStatus = 'pending' | 'approved' | 'rejected'

export type CancellationRow = {
  id: string
  invoice_number: string
  receipt_number: string | null
  student_name: string | null
  student_code: string | null
  amount: number
  reason: string
  requester_name: string | null
  created_at: string
  created_ago: string
  status: CancellationStatus
}

export type CancellationDetail = {
  id: string
  invoice_number: string
  status: CancellationStatus
  amount: number
  reason: string
  rejection_reason: string | null
  requester_name: string | null
  approver_name: string | null
  created_date: string
  created_at: string
  updated_at: string
  proof_url: string | null
  student_name: string | null
  student_code: string | null
  class_name: string
  branch_name: string
  receipt: {
    receipt_number: string
    status_color: string
    status_label: string
    payer_name: string | null
    payer_phone: string | null
    payment_method: string
    method_label: string
    is_vat_invoice: boolean
    paper_invoice_number: string | null
    tuition_amount: number
    discount_amount: number
    surcharge_amount: number
    surcharge_reason: string | null
  } | null
  payer_name: string | null
  payer_phone: string | null
}

export type CancellationsPage = {
  filters: { status: string; branch_id: string; q: string }
  branches: Option[]
  pending_count: number
  approved_month_count: number
  rejected_count: number
  can_approve_cancel: boolean
  prefill: { invoice_number: string; amount: number } | null
  cancellations: CancellationRow[]
  selected: CancellationDetail | null
}

export type RefundType = 'refund' | 'transfer' | 'extension' | 'deferral'

export type RefundPerson = { name: string; code: string; class_name: string | null }

export type RefundRow = {
  id: string
  ref: string
  type: RefundType
  type_label: string
  status: 'pending' | 'approved' | 'rejected'
  student: RefundPerson | null
  target: RefundPerson | null
  refund_amount: number
  defer_from: string | null
  defer_to: string | null
  extended_due_date: string | null
  processing_deadline: string | null
  overdue: boolean
  reason: string
  no_transfer_reason: string | null
  rejection_reason: string | null
  created_date: string
  created_at: string
  requester_name: string | null
  approver_name: string | null
  clawback_commission: boolean | null
  clawback_amount: number
  clawback_user_name: string | null
  has_proof: boolean
  proof_url: string | null
  overdue_days?: number
  approve_permission?: string
}

export type RefundBasis = {
  has_tuition: boolean
  paid: number
  contract: number
  debt: number
  due_date: string | null
  total_sessions: number | null
  attended_sessions: number
  remaining_sessions: number | null
  unit_price: number
  remaining_value: number
  admin_fee: number
  suggested_refund: number
  status_label: string
}

export type RefundsPage = {
  pending_requests: RefundRow[]
  overdue_count: number
  history_requests: RefundRow[]
  total_count: number
  filters: { status: string; type: string; search: string }
  type_options: Option[]
  approvable_types: RefundType[]
  can_reject_any: boolean
  clawback_hints: Record<string, { suggest: boolean; amount: number; owner: string | null; start: string | null }>
  student_finance: Record<string, RefundBasis>
  admin_fee_percent: number
  targets: { id: string; name: string; code: string; class: string | null; debt: number }[]
  source_options: Option[]
  debtor_options: Option[]
  deadline: string
  current_month: string
  today: string
  tomorrow: string
}

export type RefundInput = {
  student_id: string
  type: RefundType
  refund_amount?: number
  target_student_id?: string
  extended_due_date?: string
  defer_from?: string
  defer_to?: string
  reason: string
  no_transfer_reason?: string
}

export type DebtStat = { total_debt: number; count: number; overdue_count: number }

export type OverduePage = {
  due: DueGroups
  stats_by_branch: (DebtStat & { branch_name: string })[]
  stats_by_class: (DebtStat & { class_name: string; class_code: string })[]
  branches: Option[]
  classes: Option[]
}
