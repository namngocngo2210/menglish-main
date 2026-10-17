// API Thu - Chi: sổ khoản chi vận hành và báo cáo doanh thu tạm tính (FinanceController của Laravel).
import { request } from '../../api'

export type Option = { value: string; label: string }
export type BranchRef = { id: string; name: string }

export type Expense = {
  id: string
  expense_date: string
  title: string
  amount: number
  payment_method: 'chuyen_khoan' | 'tien_mat'
  branch_id: string | null
  branch_name: string | null
  category: string
  category_label: string
  notes: string | null
  creator_name: string | null
  created_at: string
}

export type SalaryRow = {
  expense_date: string
  date_sub: string
  title: string
  description: string
  amount: number
  staff_count: number
  payment_method: string
  branch_name: string
  notes: string
}

export type ExpensesPageData = {
  branch_scoped: boolean
  month: string
  branch_id: string
  search: string
  branches: BranchRef[]
  expenses: Expense[]
  auto_salary_row: SalaryRow | null
  grand_total_expense: number
  auto_salary_amount: number
  auto_salary_staff_count: number
  total_manual_expense: number
  manual_expenses_count: number
  total_items_count: number
  prev_month_label: string
  percent_diff: number
  is_decreased: boolean
  total_transfer: number
  total_cash: number
  transfer_percent: number
  cash_percent: number
  month_options: Option[]
  categories: Option[]
  today: string
  can_manage: boolean
  payroll_pending?: string
}

export type ExpenseInput = {
  expense_date: string
  title: string
  amount: number | null
  payment_method: string
  branch_id: string
  category: string
  notes: string
}

export type MarginStatus = {
  key: 'good' | 'target' | 'stable' | 'optimize'
  label: string
}
export type MatrixRow = {
  branch: BranchRef
  revenue: number
  expense: number
  profit: number
  margin: number
  status: MarginStatus
}

export type RevenueReport = {
  branch_scoped: boolean
  month: string
  branch_id: string
  branches: BranchRef[]
  prev_month_label: string
  month_options: Option[]
  revenue_pending?: string
  payroll_pending?: string
  total_revenue: number
  valid_receipts_count: number
  tuition_revenue: number
  surcharge_revenue: number
  tuition_percent: number
  surcharge_percent: number
  revenue_transfer_percent: number
  revenue_cash_percent: number
  revenue_diff_percent: number
  revenue_diff_is_up: boolean
  total_expense: number
  expense_percentage_of_revenue: number
  total_expense_items_count: number
  salary_total: number
  rent_utilities_expense: number
  curriculum_operations_expense: number
  other_expense: number
  salary_percent_of_expense: number
  rent_percent_of_expense: number
  curriculum_percent_of_expense: number
  other_percent_of_expense: number
  provisional_revenue: number
  is_profit_positive: boolean
  gross_profit_margin: number
  branch_matrix: MatrixRow[]
  total_matrix_revenue: number
  total_matrix_expense: number
  total_matrix_profit: number
  total_matrix_margin: number
  total_matrix_status: MarginStatus
}

export type ExpenseFilter = {
  month: string
  branch_id: string
  search: string
}
type Msg = { message: string }

export function query(params: Record<string, string>) {
  const q = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) if (v) q.set(k, v)
  const s = q.toString()
  return s ? `?${s}` : ''
}

export const financeApi = {
  expenses: (f: ExpenseFilter) => request<ExpensesPageData>('GET', `/finance/expenses${query(f)}`),
  createExpense: (in_: ExpenseInput) => request<Msg>('POST', '/finance/expenses', in_),
  updateExpense: (id: string, in_: ExpenseInput) => request<Msg>('PUT', `/finance/expenses/${id}`, in_),
  deleteExpense: (id: string) => request<Msg>('DELETE', `/finance/expenses/${id}`),
  expensesExportUrl: (f: ExpenseFilter) => `/api/v1/finance/expenses/export${query(f)}`,
  revenue: (month: string, branchId: string) => request<RevenueReport>('GET', `/finance/reports/provisional-revenue${query({ month, branch_id: branchId })}`),
  revenueExportUrl: (month: string, branchId: string) => `/api/v1/finance/reports/provisional-revenue/export${query({ month, branch_id: branchId })}`,
}
