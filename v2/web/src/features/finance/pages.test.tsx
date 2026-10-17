import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import type { Me } from '../../api'
import { ExpensesPage } from './ExpensesPage'
import { RevenuePage } from './RevenuePage'

afterEach(() => vi.restoreAllMocks())

type Route = (body: unknown) => [number, unknown?]

function mockApi(routes: Record<string, Route>) {
  const calls: { key: string; body: unknown }[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init = {}) => {
    const key = `${init.method ?? 'GET'} ${String(input).replace('/api/v1', '')}`
    const body = init.body ? JSON.parse(String(init.body)) : undefined
    calls.push({ key, body })
    const route = routes[key]
    if (!route) return new Response(JSON.stringify({ error: 'not_found', message: key }), { status: 404 })
    const [status, data] = route(body)
    return new Response(data === undefined ? null : JSON.stringify(data), { status })
  })
  return calls
}

function wrap(node: ReactNode) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })
  return render(<QueryClientProvider client={client}>{node}</QueryClientProvider>)
}

const me = {
  user: {
    id: 'u1',
    kind: 'staff',
    code: 'QL01',
    email: null,
    phone: null,
    full_name: 'Quản lý HN',
    must_change_password: false,
    totp_enabled: true,
  },
  csrf_token: 'c',
  totp_required: true,
  roles: [],
  permissions: ['finance.view', 'tuition.create'],
  scopes: {},
  branches: [],
} as Me

const page = {
  branch_scoped: true,
  month: '2026-10',
  branch_id: 'b1',
  search: '',
  branches: [{ id: 'b1', name: 'Cầu Giấy' }],
  expenses: [
    {
      id: 'e1',
      expense_date: '2026-10-05',
      title: 'Tiền điện tháng 10',
      amount: 1500000,
      payment_method: 'tien_mat',
      branch_id: 'b1',
      branch_name: 'Cầu Giấy',
      category: 'mat_bang_tien_ich',
      category_label: 'Mặt bằng & Tiện ích',
      notes: null,
      creator_name: 'Quản lý HN',
      created_at: '05/10 09:00',
    },
  ],
  auto_salary_row: {
    expense_date: '31/10/2026',
    date_sub: 'Cuối kỳ lương',
    title: 'Chi lương tháng 10/2026',
    description: 'Tổng hợp từ bảng lương',
    amount: 3000000,
    staff_count: 3,
    payment_method: 'chuyen_khoan',
    branch_name: 'Cầu Giấy',
    notes: 'Bảng lương mã PR-10.',
  },
  grand_total_expense: 4500000,
  auto_salary_amount: 3000000,
  auto_salary_staff_count: 3,
  total_manual_expense: 1500000,
  manual_expenses_count: 1,
  total_items_count: 2,
  prev_month_label: '09/2026',
  percent_diff: 200,
  is_decreased: false,
  total_transfer: 3000000,
  total_cash: 1500000,
  transfer_percent: 67,
  cash_percent: 33,
  month_options: [{ value: '2026-10', label: 'Tháng 10/2026 (Hiện tại)' }],
  categories: [{ value: 'khac', label: 'Chi phí khác' }],
  today: '2026-10-17',
  can_manage: true,
}

test('sổ chi: dòng lương khóa, thêm khoản chi gửi đúng body', async () => {
  const calls = mockApi({
    'GET /finance/expenses': () => [200, page],
    'POST /finance/expenses': () => [
      201,
      {
        message: 'Khoản chi đã được lưu thành công vào sổ chi vận hành!',
      },
    ],
  })
  wrap(<ExpensesPage me={me} />)
  expect(await screen.findByText('Chi lương tháng 10/2026')).toBeTruthy()
  expect(screen.getByText('Cố định')).toBeTruthy()
  expect(screen.getByText('↑ 200% so với tháng 09/2026')).toBeTruthy()
  // Người bị giới hạn chi nhánh không có "Tất cả chi nhánh"
  expect(screen.queryByRole('option', { name: 'Tất cả chi nhánh' })).toBeNull()

  await userEvent.click(screen.getByRole('button', { name: 'Thêm khoản chi mới' }))
  await userEvent.type(screen.getByLabelText('Nội dung khoản chi'), 'Mua mực in')
  await userEvent.type(screen.getByLabelText('Số tiền (VNĐ)'), '250000')
  await userEvent.selectOptions(screen.getByLabelText('Chi nhánh áp dụng'), 'b1')
  await userEvent.click(screen.getByRole('button', { name: 'Lưu khoản chi' }))
  expect(await screen.findByText('Khoản chi đã được lưu thành công vào sổ chi vận hành!')).toBeTruthy()
  const post = calls.find((c) => c.key === 'POST /finance/expenses')
  expect(post?.body).toEqual({
    expense_date: '2026-10-17',
    title: 'Mua mực in',
    amount: 250000,
    payment_method: 'chuyen_khoan',
    branch_id: 'b1',
    category: '',
    notes: '',
  })
})

test('sổ chi: không có quyền ghi khoản chi thì ẩn nút thêm / sửa / xóa', async () => {
  mockApi({
    'GET /finance/expenses': () => [200, { ...page, can_manage: false }],
  })
  wrap(<ExpensesPage me={{ ...me, permissions: ['finance.view'] }} />)
  expect(await screen.findByText('Tiền điện tháng 10')).toBeTruthy()
  expect(screen.queryByRole('button', { name: 'Thêm khoản chi mới' })).toBeNull()
  expect(screen.queryByRole('button', { name: /Xóa khoản chi/ })).toBeNull()
})

test('doanh thu tạm tính: báo chờ phân hệ và dòng tổng', async () => {
  const empty = {
    branch_scoped: false,
    month: '2026-10',
    branch_id: 'all',
    branches: [{ id: 'b1', name: 'Cầu Giấy' }],
    prev_month_label: '09/2026',
    month_options: [{ value: '2026-10', label: 'Tháng 10/2026 (Hiện tại)' }],
    revenue_pending: 'Chờ phân hệ Học phí chuyển sang hệ thống mới: chưa có phiếu thu để tính doanh thu.',
    total_revenue: 0,
    valid_receipts_count: 0,
    tuition_revenue: 0,
    surcharge_revenue: 0,
    tuition_percent: 0,
    surcharge_percent: 0,
    revenue_transfer_percent: 0,
    revenue_cash_percent: 0,
    revenue_diff_percent: 0,
    revenue_diff_is_up: true,
    total_expense: 2000000,
    expense_percentage_of_revenue: 0,
    total_expense_items_count: 1,
    salary_total: 0,
    rent_utilities_expense: 2000000,
    curriculum_operations_expense: 0,
    other_expense: 0,
    salary_percent_of_expense: 0,
    rent_percent_of_expense: 100,
    curriculum_percent_of_expense: 0,
    other_percent_of_expense: 0,
    provisional_revenue: -2000000,
    is_profit_positive: false,
    gross_profit_margin: 0,
    branch_matrix: [
      {
        branch: { id: 'b1', name: 'Cầu Giấy' },
        revenue: 0,
        expense: 2000000,
        profit: -2000000,
        margin: 0,
        status: { key: 'stable', label: 'Ổn định' },
      },
    ],
    total_matrix_revenue: 0,
    total_matrix_expense: 2000000,
    total_matrix_profit: -2000000,
    total_matrix_margin: 0,
    total_matrix_status: { key: 'stable', label: 'Ổn định' },
  }
  mockApi({ 'GET /finance/reports/provisional-revenue': () => [200, empty] })
  wrap(<RevenuePage />)
  expect(await screen.findByText(/Chờ phân hệ Học phí/)).toBeTruthy()
  expect(screen.getByText('Tổng cộng toàn hệ thống')).toBeTruthy()
  expect(screen.getAllByText('-2.000.000').length).toBeGreaterThan(0)
  expect(screen.getByRole('link', { name: /Xuất báo cáo/ }).getAttribute('href')).toBe(
    '/api/v1/finance/reports/provisional-revenue/export?month=2026-10&branch_id=all',
  )
})
