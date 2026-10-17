import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import type { Me } from '../../api'
import { AttendanceReviewPage } from './AttendanceReviewPage'
import { CriteriaPage } from './CriteriaPage'
import { KpiBoardPage } from './KpiBoardPage'
import { MinePage } from './MinePage'
import { MonthlyPage } from './MonthlyPage'

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
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}>{node}</QueryClientProvider>)
}

const me = {
  user: { id: 'u1', kind: 'staff', code: 'TT01', email: null, phone: null, full_name: 'Trưởng học thuật', must_change_password: false, totp_enabled: true },
  csrf_token: 'c',
  totp_required: true,
  roles: [],
  permissions: ['kpi.view', 'kpi.confirm'],
  scopes: {},
  branches: [],
} as Me

const rule = {
  measure: 'count',
  lower_better: true,
  tiers: [
    [0, 100],
    [1, 50],
  ],
  linear: false,
  full_at: 0,
  has_rule: true,
  knockout: false,
}
const baseLine = {
  code: null,
  description: null,
  amount: null,
  threshold_full: '0 lần',
  max_full: 0,
  rule_label: '',
  rule,
  measure: 'count',
  unit: 'lần',
  per_month: false,
  knockout: false,
  count_based: true,
  pending: null,
  na: false,
  cap: false,
  suggestion: null,
}

const evaluate = {
  staff: { id: 's1', name: 'Học vụ Hà Nội', role_label: 'Học vụ', branch: 'Cầu Giấy' },
  track: 'main',
  month: 9,
  year: 2026,
  period: '2026-09',
  period_months: 1,
  period_label: 'Kỳ lương tháng 09/2026',
  status: 'draft',
  status_label: 'Chờ duyệt',
  status_color: 'warning',
  reject_reason: null,
  decided_by: null,
  decided_at: null,
  fund: 2_000_000,
  weight_total: 100,
  grades: [],
  simple_rules: true,
  bonus_fund: null,
  grade_cap: null,
  is_self: false,
  locked: false,
  can_close: true,
  close_on: '30/09/2026',
  can_decide: true,
  total: 30,
  missing: 1,
  groups: [
    {
      name: '1. Vận hành',
      items: [
        {
          ...baseLine,
          id: 'c1',
          name: 'Báo cáo ngày trễ',
          weight: 60,
          weight_label: '60',
          max_amount: 1_200_000,
          threshold_half: '≤ 2 lần',
          max_half: 2,
          auto: true,
          value: 1,
          level: 50,
          allow_na: false,
          evidence: [{ date: '05/09', month: '2026-09', text: 'Báo cáo ngày 04/09 nộp trễ', counted: true, note: null }],
        },
        {
          ...baseLine,
          id: 'c2',
          name: 'Phụ huynh phàn nàn',
          weight: 40,
          weight_label: '40',
          max_amount: 800_000,
          threshold_half: '≤ 1 lần',
          max_half: 1,
          auto: false,
          value: null,
          level: null,
          allow_na: true,
          evidence: [],
          pending: 'Chờ phân hệ SLA / phạt CRM chuyển sang hệ thống mới: người chấm điền tay số liệu.',
        },
      ],
    },
  ],
}

const monthly = {
  staff: [
    {
      id: 's1',
      name: 'Học vụ Hà Nội',
      branch: 'Cầu Giấy',
      sheets: [
        {
          track: 'main',
          role: 'Học vụ',
          period_label: null,
          rate_label: '30%',
          amount_label: '600.000 đ',
          status: 'draft',
          status_label: 'Chờ duyệt',
          status_color: 'warning',
          period: '2026-09',
        },
      ],
    },
  ],
  total: 1,
  page: 1,
  per_page: 20,
  filters: { period: '2026-09', role: '', branch_id: '', page: 1 },
  current_period: '2026-10',
  period_options: [{ value: '2026-09', label: 'Kỳ lương tháng 09/2026' }],
  role_options: [{ value: 'academic_staff', label: 'Học vụ' }],
  branch_options: [],
}

test('Phiếu KPI tháng: mở phiếu, điền số liệu, tổng tính lại, Duyệt gửi số điền tay', async () => {
  const calls = mockApi({
    'GET /kpi/monthly': () => [200, monthly],
    'GET /kpi/evaluate/s1?period=2026-09': () => [200, evaluate],
    'POST /kpi/evaluate/s1': () => [200, { message: 'Đã duyệt KPI tháng 9/2026 của Học vụ Hà Nội (70%).' }],
  })
  wrap(<MonthlyPage />)
  await userEvent.click(await screen.findByText('Học vụ Hà Nội'))
  const dialog = await screen.findByRole('dialog')
  expect(within(dialog).getByText(/Chờ phân hệ SLA/)).toBeTruthy()
  expect(within(dialog).getByTestId('kpi-total').textContent).toBe('600.000 đ')
  // Duyệt khi còn thiếu số liệu → báo ngay, không gửi
  await userEvent.click(within(dialog).getByRole('button', { name: 'Duyệt' }))
  expect(within(dialog).getByText('Còn 1 tiêu chí chưa có số liệu.')).toBeTruthy()
  await userEvent.type(within(dialog).getByLabelText('Số liệu: Phụ huynh phàn nàn'), '0')
  expect(within(dialog).getByTestId('kpi-total').textContent).toBe('1.400.000 đ')
  await userEvent.click(within(dialog).getByRole('button', { name: 'Xem 1 bản ghi' }))
  expect(within(dialog).getByText('05/09 · Báo cáo ngày 04/09 nộp trễ')).toBeTruthy()
  await userEvent.click(within(dialog).getByRole('button', { name: 'Duyệt' }))
  expect(await screen.findByText('Đã duyệt KPI tháng 9/2026 của Học vụ Hà Nội (70%).')).toBeTruthy()
  const post = calls.find((c) => c.key === 'POST /kpi/evaluate/s1')
  expect(post?.body).toEqual({ month: 9, year: 2026, track: 'main', action: 'approve', actual: { c2: '0' }, na: { c2: false }, reject_reason: null })
})

test('Phiếu KPI: Không duyệt cần lý do, hiện lỗi máy chủ', async () => {
  const calls = mockApi({
    'GET /kpi/monthly': () => [200, monthly],
    'GET /kpi/evaluate/s1?period=2026-09': () => [200, evaluate],
    'POST /kpi/evaluate/s1': () => [422, { error: 'invalid', message: 'Cần ghi lý do không duyệt.' }],
  })
  wrap(<MonthlyPage />)
  await userEvent.click(await screen.findByText('Học vụ Hà Nội'))
  const dialog = await screen.findByRole('dialog')
  await userEvent.click(within(dialog).getByRole('button', { name: 'Không duyệt' }))
  expect(calls.some((c) => c.key.startsWith('POST'))).toBe(false)
  await userEvent.click(within(dialog).getByRole('button', { name: 'Xác nhận không duyệt' }))
  expect(await within(dialog).findByText('Cần ghi lý do không duyệt.')).toBeTruthy()
})

test('KPI của tôi: vai trò chưa có tiêu chí', async () => {
  mockApi({ 'GET /kpi/mine': () => [403, { error: 'no_kpi', message: 'Vai trò của bạn chưa có bộ tiêu chí KPI.' }] })
  wrap(<MinePage />)
  expect(await screen.findByText('Bạn chưa có tiêu chí KPI')).toBeTruthy()
})

test('KPI của tôi: phiếu đã duyệt', async () => {
  mockApi({
    'GET /kpi/mine': () => [
      200,
      {
        ...evaluate,
        name: 'Học vụ Hà Nội',
        role_label: 'Học vụ',
        track_options: [],
        period_options: [{ value: '2026-09', label: 'Kỳ lương tháng 09/2026' }],
        status: 'confirmed',
        status_label: 'Đã duyệt',
        status_color: 'success',
        decided_by: 'Trưởng học thuật',
        amount: 1_400_000,
        rate_label: '70%',
        grade: null,
        bonus: null,
        knockout: null,
      },
    ],
  })
  wrap(<MinePage />)
  expect(await screen.findByText('1.400.000 đ')).toBeTruthy()
  expect(screen.getByText('Đã duyệt')).toBeTruthy()
  expect(screen.getByText('Duyệt bởi Trưởng học thuật')).toBeTruthy()
})

const criteria = {
  role: 'academic_staff',
  role_options: [
    { value: 'academic_staff', label: 'Học vụ' },
    { value: 'teacher_parttime', label: 'GV part-time' },
  ],
  unit_options: [{ value: 'lan', label: 'Lần' }],
  source_options: [
    { value: '', label: 'Người chấm điền tay' },
    { value: 'daily_report_late', label: 'Tự động: Báo cáo ngày nộp sau 9h sáng hôm sau' },
  ],
  measure_options: [
    { value: 'count', label: 'Đếm số lần (càng ít càng tốt)' },
    { value: 'rate', label: 'Tỉ lệ % (càng cao càng tốt)' },
  ],
  cycle_options: [
    { value: '1', label: 'Theo tháng' },
    { value: '3', label: 'Theo quý' },
  ],
  groups: ['1. Vận hành'],
  fund: 2_000_000,
  period_months: 1,
  grades: [],
  grade_fund: null,
  total_weight: 60,
  total_weight_label: '60',
  criteria_groups: [
    {
      name: '1. Vận hành',
      count: 1,
      active_weight: '60',
      active_fund: 1_200_000,
      items: [
        {
          id: 'c1',
          code: null,
          name: 'Báo cáo ngày trễ',
          group_name: '1. Vận hành',
          weight: '60',
          fund_amount: 1_200_000,
          unit: 'lan',
          auto_source: 'daily_report_late',
          source_pending: null,
          max_full: 0,
          max_half: 2,
          threshold_full: '0 lần',
          threshold_half: '≤ 2 lần',
          measure: 'count',
          tiers: [
            [0, 100],
            [2, 50],
          ],
          linear: false,
          full_at: null,
          per_month: false,
          knockout: false,
          allow_na: false,
          rule_label: '0 lần: 100% · 1–2: 50% · từ 3: 0%',
          description: null,
          is_active: true,
        },
      ],
    },
  ],
  can_manage: true,
}

test('Tiêu chí KPI: cảnh báo tổng trọng số, thêm tiêu chí, đổi chu kỳ', async () => {
  const calls = mockApi({
    'GET /kpi/criteria': () => [200, criteria],
    'POST /kpi/criteria': () => [201, { message: 'Đã thêm tiêu chí KPI.', role: 'academic_staff' }],
    'POST /kpi/criteria/cycle': () => [200, { message: 'Đã đổi chu kỳ chấm KPI: theo quý.' }],
  })
  wrap(<CriteriaPage />)
  expect(await screen.findByText(/chưa bằng 100%/)).toBeTruthy()
  expect(screen.getByText('1.200.000 đ', { selector: 'td' })).toBeTruthy()
  await userEvent.click(screen.getByRole('button', { name: 'Thêm tiêu chí' }))
  const dialog = await screen.findByRole('dialog')
  await userEvent.type(within(dialog).getByLabelText('Tên tiêu chí'), 'Phụ huynh phàn nàn')
  await userEvent.type(within(dialog).getByLabelText('Trọng số (% quỹ)'), '40')
  expect(within(dialog).getByText('= 800.000 đ / tháng')).toBeTruthy()
  await userEvent.selectOptions(within(dialog).getByLabelText('Đơn vị đếm'), 'lan')
  await userEvent.click(within(dialog).getByRole('button', { name: 'Thêm tiêu chí' }))
  expect(await screen.findByText('Đã thêm tiêu chí KPI.')).toBeTruthy()
  expect(calls.find((c) => c.key === 'POST /kpi/criteria')?.body).toMatchObject({
    role: 'academic_staff',
    name: 'Phụ huynh phàn nàn',
    weight: 40,
    unit: 'lan',
    measure: 'count',
    tiers: [
      { at: 0, percent: 100 },
      { at: 2, percent: 50 },
    ],
    auto_source: null,
  })
  await userEvent.selectOptions(screen.getByLabelText('Chu kỳ chấm'), '3')
  expect(await screen.findByText('Đã đổi chu kỳ chấm KPI: theo quý.')).toBeTruthy()
  expect(calls.find((c) => c.key === 'POST /kpi/criteria/cycle')?.body).toEqual({ role: 'academic_staff', period_months: 3 })
})

test('Rà soát điểm danh: thống kê và Duyệt', async () => {
  const calls = mockApi({
    'GET /kpi/attendance-review': () => [
      200,
      {
        records: [
          {
            id: 'a1',
            student: 'Bé An',
            class: 'Lớp 1',
            teacher: 'Cô Lan',
            note: null,
            status: 'present',
            status_label: 'Có mặt',
            review_status: 'pending_review',
            review_label: 'Chờ duyệt',
          },
        ],
        summary: { present: 1, late: 0, absent: 0, excused: 0 },
        classes: [],
        date: '2026-10-17',
        class_id: null,
        pending: null,
      },
    ],
    'POST /kpi/attendance-review/a1': () => [200, { message: 'Đã duyệt điểm danh.' }],
  })
  wrap(<AttendanceReviewPage me={me} />)
  await userEvent.click(await screen.findByRole('button', { name: 'Duyệt' }))
  expect(await screen.findByText('Đã duyệt điểm danh.')).toBeTruthy()
  expect(calls.find((c) => c.key === 'POST /kpi/attendance-review/a1')?.body).toEqual({ decision: 'approved' })
})

test('Bảng KPI tự động: tỉ lệ dưới ngưỡng tô đỏ, chưa có dữ liệu, link xuất', async () => {
  mockApi({
    'GET /kpi/board': () => [
      200,
      {
        staff: [
          {
            id: 'g1',
            name: 'An Giáo viên',
            code: 'GV01',
            classes: 2,
            retention: 90,
            retention_detail: '18/20 học viên',
            attendance: 80,
            attendance_detail: '32/40 lượt',
            homework: null,
            homework_detail: '0/0 bài',
            tasks: 75,
            tasks_detail: '3/4 việc',
          },
        ],
        total: 1,
        page: 1,
        per_page: 20,
        staff_options: [],
        user_id: null,
        month: '2026-10',
        month_to: '2026-10',
        period_label: 'Tháng 10/2026 (01/10/2026 - 31/10/2026)',
        can_see_staff: false,
        homework_pending: 'Chờ phân hệ Bài tập',
        pending: null,
      },
    ],
  })
  wrap(<KpiBoardPage />)
  expect(await screen.findByText('90,0%')).toBeTruthy()
  expect(screen.getByText('80,0%').className).toContain('text-error')
  expect(screen.getByText('Chưa có dữ liệu')).toBeTruthy()
  expect(screen.getByText(/Bạn đang xem KPI của chính mình/)).toBeTruthy()
  expect(screen.getByRole('link', { name: /Xuất Excel KPI/ }).getAttribute('href')).toBe('/api/v1/kpi/board/export?month=2026-10&month_to=2026-10')
})
