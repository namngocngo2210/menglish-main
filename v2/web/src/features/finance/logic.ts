// Hàm thuần của màn Thu - Chi (có test).
import type { BranchRef, Expense, ExpenseInput, MarginStatus, Option } from './api'

// 1500000 → "1.500.000" (number_format($v, 0, ',', '.')).
export function vnd(n: number) {
  const s = Math.round(Math.abs(n))
    .toString()
    .replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  return `${n < 0 ? '-' : ''}${s}`
}

export const money = (n: number) => `${vnd(n)} đ`

// Triệu đồng, dấu chấm thập phân (number_format($v / 1e6, n)): 12500000 → "12.5".
export function millions(n: number, decimals: number) {
  return (n / 1_000_000).toLocaleString('en-US', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  })
}

// "2026-10" → "10/2026".
export const monthLabel = (month: string) => month.split('-').reverse().join('/')

// "2026-10-05" → "05/10/2026".
export const dateLabel = (iso: string) => iso.split('-').reverse().join('/')

// Ô chọn chi nhánh của bộ lọc: người bị giới hạn chi nhánh không có "Tất cả chi nhánh".
export function branchFilterOptions(branches: BranchRef[], scoped: boolean): Option[] {
  return [...(scoped ? [] : [{ value: 'all', label: 'Tất cả chi nhánh' }]), ...branches.map((b) => ({ value: b.id, label: b.name }))]
}

// Giá trị ban đầu của form Thêm / Sửa khoản chi.
export function expenseForm(exp: Expense | null, today: string): Record<keyof ExpenseInput, string> {
  if (!exp)
    return {
      expense_date: today,
      title: '',
      amount: '',
      payment_method: 'chuyen_khoan',
      branch_id: '',
      category: '',
      notes: '',
    }
  return {
    expense_date: exp.expense_date,
    title: exp.title,
    amount: exp.amount ? String(Math.trunc(exp.amount)) : '',
    payment_method: exp.payment_method || 'chuyen_khoan',
    branch_id: exp.branch_id ?? '',
    category: exp.category || '',
    notes: exp.notes ?? '',
  }
}

// Form → body gửi API (số tiền rỗng = null để máy chủ báo "Vui lòng nhập số tiền chi.").
export function toExpenseInput(f: Record<keyof ExpenseInput, string>): ExpenseInput {
  const amount = f.amount.trim() === '' ? null : Number(f.amount)
  return {
    ...f,
    amount: amount === null || Number.isNaN(amount) ? null : amount,
  }
}

// Màu của tình trạng chi nhánh theo tỷ suất gộp.
export function marginTone(s: MarginStatus): 'success' | 'info' | 'error' {
  if (s.key === 'good' || s.key === 'target') return 'success'
  return s.key === 'stable' ? 'info' : 'error'
}

// So với tháng trước của sổ chi: "↑ 12.5% so với tháng 09/2026" / "Tương đương tháng trước".
export function expenseDiffText(percent: number, decreased: boolean, prev: string) {
  if (percent === 0) return 'Tương đương tháng trước'
  return `${decreased ? '↓' : '↑'} ${Math.abs(percent)}% so với tháng ${prev}`
}
