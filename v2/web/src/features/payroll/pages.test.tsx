import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import type { Me } from '../../api'
import { PeriodsRoute } from './PeriodsPage'
import { MySalaryPage } from './PortalPages'

afterEach(() => {
  vi.restoreAllMocks()
  window.history.pushState(null, '', '/')
})

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

const me = (permissions: string[]) =>
  ({
    user: {
      id: 'u1',
      kind: 'staff',
      code: 'KT01',
      email: null,
      phone: null,
      full_name: 'Kế toán',
      must_change_password: false,
      totp_enabled: true,
    },
    csrf_token: 'c',
    totp_required: false,
    roles: [],
    permissions,
    scopes: {},
    branches: [],
  }) as Me

const paging = { current_page: 1, per_page: 12, total: 1, last_page: 1 }

test('danh sách kỳ lương: khởi tạo kỳ mới báo lỗi của server', async () => {
  window.history.pushState(null, '', '/payroll/periods')
  const calls = mockApi({
    'GET /payroll/periods': () => [
      200,
      {
        periods: [
          {
            id: 'p1',
            code: 'PR-2026-09',
            title: 'Bảng lương Tháng 9/2026',
            start_date: '2026-09-01',
            end_date: '2026-09-30',
            staff: 3,
            hours: 3,
            amount: 17120000,
            status: 'approved',
            status_label: 'Đã duyệt',
            status_color: 'success',
          },
        ],
        paging,
        all_periods: [],
        status_options: [{ value: 'draft', label: 'Đang tính' }],
        default_month: 10,
        default_year: 2026,
      },
    ],
    'POST /payroll/periods': () => [
      422,
      {
        error: 'invalid',
        message: 'Đã tồn tại bảng lương cho tháng 10/2026.',
      },
    ],
  })
  wrap(<PeriodsRoute me={me(['payroll.view', 'payroll.create'])} />)
  expect(await screen.findByText('Bảng lương Tháng 9/2026')).toBeTruthy()
  expect(screen.getByText('17.120.000 đ')).toBeTruthy()
  await userEvent.click(screen.getByRole('button', { name: /Khởi tạo kỳ lương/ }))
  await userEvent.click(screen.getByRole('button', { name: 'Khởi tạo & tính lương' }))
  expect(await screen.findByText('Đã tồn tại bảng lương cho tháng 10/2026.')).toBeTruthy()
  expect(calls.find((c) => c.key === 'POST /payroll/periods')?.body).toEqual({
    month: 10,
    year: 2026,
  })
})

test('danh sách kỳ lương: không có quyền tạo thì ẩn nút', async () => {
  window.history.pushState(null, '', '/payroll/periods')
  mockApi({
    'GET /payroll/periods': () => [
      200,
      {
        periods: [],
        paging,
        all_periods: [],
        status_options: [],
        default_month: 10,
        default_year: 2026,
      },
    ],
  })
  wrap(<PeriodsRoute me={me(['payroll.view'])} />)
  expect(await screen.findByText('Chưa có kỳ lương nào')).toBeTruthy()
  expect(screen.queryByRole('button', { name: /Khởi tạo kỳ lương/ })).toBeNull()
})

test('Lương của tôi: chưa có kỳ đã duyệt', async () => {
  mockApi({
    'GET /portal/my-salary': () => [
      200,
      {
        record: null,
        period_options: [],
        penalties: [],
        timesheets: [],
        attendance_pending: true,
      },
    ],
  })
  wrap(<MySalaryPage />)
  expect(await screen.findByText('Chưa có kỳ lương đã duyệt')).toBeTruthy()
})

test('Lương của tôi: phiếu đã duyệt, ẩn dòng phạt khi đã liệt kê biên bản', async () => {
  mockApi({
    'GET /portal/my-salary': () => [
      200,
      {
        record: {
          id: 'r1',
          payroll_period_id: 'p1',
          period_status: 'approved',
          period_status_label: 'Đã duyệt',
          period_title: 'Bảng lương Tháng 9/2026',
          gross_income: 10500000,
          total_deductions: 1300000,
          net_salary: 9200000,
          change: null,
          is_full_time: true,
          adjustment_notes: null,
          variant: { key: 'fulltime', title: 'Chi tiết', type: 'GV' },
          earning_lines: [
            {
              key: 'base_salary',
              label: 'Lương cơ bản',
              amount: 10000000,
              hint: null,
              quantity: '',
            },
          ],
          deduction_lines: [
            {
              key: 'insurance_deduction',
              label: 'BHXH',
              amount: 1050000,
              hint: null,
              quantity: '',
            },
            {
              key: 'penalty_deduction',
              label: 'Phạt',
              amount: 200000,
              hint: null,
              quantity: '',
            },
            {
              key: 'tax_deduction',
              label: 'Thuế TNCN',
              amount: 0,
              hint: null,
              quantity: '',
            },
          ],
        },
        period_options: [{ value: 'p1', label: 'Tháng 09/2026' }],
        penalties: [
          {
            id: 'x',
            code: 'BB-2026-901',
            violation_type: 'Nghỉ dạy không phép',
            violation_date: '15/09',
            amount: 200000,
          },
        ],
        timesheets: [],
        attendance_pending: false,
      },
    ],
  })
  wrap(<MySalaryPage />)
  expect(await screen.findByText('Kỳ lương hiện tại: Đã duyệt — Bảng lương Tháng 9/2026', { exact: false })).toBeTruthy()
  expect(screen.getByText('BHXH')).toBeTruthy()
  expect(screen.queryByText('Phạt')).toBeNull()
  expect(screen.queryByText('Thuế TNCN')).toBeNull()
  expect(screen.getByText(/Nghỉ dạy không phép/)).toBeTruthy()
})
