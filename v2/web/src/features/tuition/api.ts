// API Học phí: công nợ học viên, lịch sử thanh toán, lập / sửa / duyệt / trả về phiếu thu, xác nhận nộp tiền mặt,
// lịch sử thu (Go: internal/tuition).
import { request } from '../../api'

export type Option = { value: string; label: string }
export type Tone = 'neutral' | 'success' | 'error' | 'info' | 'warning'
export type Page = { page: number; per_page: number; total: number; last_page: number }
export type StudentBrief = { id: string; name: string; code: string; phone: string }

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
  last_contact: { at: string | null; user_name: string | null; note: string | null; note_short: string | null } | null
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

export type Bank = { id: string; bank_code: string; bank_name: string; account_number: string; account_holder: string; scope: string }
export type ItemLine = { id: string; code?: string; name: string; unit?: string; quantity: number; unit_price?: number; amount: number; source?: string }

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

export type FormItem = { id: string; code: string; name: string; unit: string; price: number; stock: number }

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

export type StockLine = { item_id: string; name: string; unit: string; quantity: number; source: string; stock: number; stock_after: number }

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
  sepay_warnings: { sepay_id: string; amount: number; date: string | null; receipt_number: string | null }[]
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
  recent_rejection: { id: string; code: string; rejection_reason: string | null; can_edit: boolean } | null
  can_request_cancel: boolean
  methods: Option[]
}

export type StudentOption = { id: string; code: string; name: string; phone: string; class_name: string | null; branch_name: string | null }

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
  filters: { status: string; branch_id: string | null; payment_method: string; q: string }
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

export type Saved = { id: string; code: string; status: string; message: string }

export function query(params: Record<string, string | number | null | undefined>) {
  const qs = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) if (v !== '' && v !== null && v !== undefined) qs.set(k, String(v))
  const s = qs.toString()
  return s ? `?${s}` : ''
}

export const tuitionApi = {
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
  approve: (id: string, confirm_not_duplicate: boolean) => request<Saved>('POST', `/tuition/receipts/${id}/approve`, { confirm_not_duplicate }),
  reject: (id: string, rejection_reason: string) => request<Saved>('POST', `/tuition/receipts/${id}/reject`, { rejection_reason }),
  confirmDeposit: (id: string) => request<Saved>('POST', `/tuition/receipts/${id}/confirm-deposit`),
  history: (p: { search?: string; student_id?: string; status?: string; method?: string; from?: string; to?: string; kind?: string; page?: number }) =>
    request<HistoryPage>('GET', `/tuition/history${query(p)}`),
  exportUrl: (p: Record<string, string>) => `/api/v1/tuition/history/export${query(p)}`,
}
