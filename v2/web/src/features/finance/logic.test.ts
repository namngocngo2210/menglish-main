import { describe, expect, it } from 'vitest'
import type { Expense } from './api'
import { branchFilterOptions, dateLabel, expenseDiffText, expenseForm, marginTone, millions, monthLabel, toExpenseInput, vnd } from './logic'

describe('định dạng', () => {
  it('vnd như number_format', () => {
    expect(vnd(0)).toBe('0')
    expect(vnd(1500000)).toBe('1.500.000')
    expect(vnd(-2000000)).toBe('-2.000.000')
  })
  it('triệu đồng và nhãn tháng / ngày', () => {
    expect(millions(12500000, 1)).toBe('12.5')
    expect(millions(7500000, 2)).toBe('7.50')
    expect(monthLabel('2026-10')).toBe('10/2026')
    expect(dateLabel('2026-10-05')).toBe('05/10/2026')
  })
  it('so với tháng trước', () => {
    expect(expenseDiffText(0, false, '09/2026')).toBe('Tương đương tháng trước')
    expect(expenseDiffText(-12.5, true, '09/2026')).toBe('↓ 12.5% so với tháng 09/2026')
    expect(expenseDiffText(200, false, '09/2026')).toBe('↑ 200% so với tháng 09/2026')
  })
})

describe('bộ lọc và form', () => {
  const branches = [{ id: 'a', name: 'HN' }]
  it('người bị giới hạn chi nhánh không có "Tất cả"', () => {
    expect(branchFilterOptions(branches, false).map((o) => o.value)).toEqual(['all', 'a'])
    expect(branchFilterOptions(branches, true).map((o) => o.value)).toEqual(['a'])
  })
  it('form thêm mới và sửa', () => {
    expect(expenseForm(null, '2026-10-17')).toMatchObject({
      expense_date: '2026-10-17',
      payment_method: 'chuyen_khoan',
      amount: '',
    })
    const exp = {
      id: 'x',
      expense_date: '2026-10-05',
      title: 'Tiền điện',
      amount: 1500000,
      payment_method: 'tien_mat',
      branch_id: 'a',
      category: 'khac',
      notes: null,
    } as Expense
    expect(expenseForm(exp, '2026-10-17')).toMatchObject({
      amount: '1500000',
      payment_method: 'tien_mat',
      branch_id: 'a',
      notes: '',
    })
  })
  it('số tiền rỗng gửi null', () => {
    const f = expenseForm(null, '2026-10-17')
    expect(toExpenseInput(f).amount).toBeNull()
    expect(toExpenseInput({ ...f, amount: '250000' }).amount).toBe(250000)
    expect(toExpenseInput({ ...f, amount: 'abc' }).amount).toBeNull()
  })
  it('màu tình trạng', () => {
    expect(marginTone({ key: 'good', label: '' })).toBe('success')
    expect(marginTone({ key: 'stable', label: '' })).toBe('info')
    expect(marginTone({ key: 'optimize', label: '' })).toBe('error')
  })
})
