import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import type { Me } from '../../api'
import type { Overview, Teaching } from './api'
import { AcademicPage } from './AcademicPage'
import { DashboardPage } from './DashboardPage'

afterEach(() => {
  vi.restoreAllMocks()
  window.history.pushState(null, '', '/')
})

type Route = () => [number, unknown?]

function mockApi(routes: Record<string, Route>) {
  const calls: string[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init = {}) => {
    const key = `${init.method ?? 'GET'} ${String(input).replace('/api/v1', '')}`
    calls.push(key)
    const route = routes[key]
    if (!route) return new Response(JSON.stringify({ error: 'not_found', message: key }), { status: 404 })
    const [status, data] = route()
    return new Response(data === undefined ? null : JSON.stringify(data), { status })
  })
  return calls
}

function wrap(node: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}>{node}</QueryClientProvider>)
}

const me = {
  user: { id: 'u1', kind: 'staff', code: 'GV01', email: null, phone: null, full_name: 'Cô Lan', must_change_password: false, totp_enabled: true },
  csrf_token: 'c',
  totp_required: false,
  roles: [{ name: 'teacher_fulltime', label: 'Giáo viên full-time', short_label: 'GV FT' }],
  permissions: ['portal.staff', 'work_task.view'],
  scopes: {},
  branches: [{ id: 'b1', code: 'CG', name: 'Cầu Giấy', primary: true }],
} as Me

const teaching = (month: string): Teaching => ({
  month,
  month_label: month,
  months: [
    { value: '2026-10', label: 'Tháng 10/2026' },
    { value: '2026-09', label: 'Tháng 9/2026' },
  ],
  mine: {
    work: null,
    totals: { classes: 1, students: 12, new: 2, dropped: 1, attendance: 87.5, homework: null, score: null },
    classes: [{ id: 'c1', code: 'KID1', name: 'Kids 1', teacher_id: 'u1', students: 12, new: 2, dropped: 1, attendance: 87.5, homework: null, score: null }],
  },
  report_url: `/reports/teacher-monthly?month=${month}`,
  team: null,
  teacher_reports: null,
  academic_orders: null,
  orders_url: null,
  pending: ['Bài về nhà, điểm trung bình: Chờ cổng Giáo viên (bài tập, mini test)'],
})

const personal = (month: string): Overview => ({
  is_operations: false,
  daily_report: {
    missing: [{ label: 'Thứ 5 · 15/10', href: '/reports/daily?date=2026-10-15', deadline: '09:00 16/10', overdue: true }],
    returned: [{ label: '14/10', href: '/reports/daily?date=2026-10-14', note: 'Thiếu số liệu tuyển sinh' }],
    today_submitted: false,
    today_href: '/reports/daily?date=2026-10-17',
  },
  teaching: teaching(month),
  role_dashboard: {
    type: 'personal',
    title: 'Việc của bạn',
    scope: 'Khách và việc bạn phụ trách',
    updated_at: '08:00 17/10/2026',
    stats: [{ label: 'Việc của tôi đến hạn tuần này', value: '3', icon: 'task', tone: 'warning', hint: '1 việc quá hạn', href: '/tasks?tab=mine' }],
    queues: [
      { label: 'Khách quá hạn chăm sóc', value: 2, icon: 'schedule', href: '/crm/customers?chip=sla', hint: null },
      { label: 'Học phí quá hạn', value: null, icon: 'payments', href: null, hint: null, pending: 'Chờ phân hệ Học phí' },
    ],
    agenda: {
      days: [
        {
          date: '2026-10-17',
          label: 'Hôm nay · 17/10',
          items: [{ time: '09:30', past: false, kind: 'call', kind_label: 'Gọi lại', title: 'Phụ huynh An', subtitle: '0901', href: '/crm/customers/l1' }],
        },
      ],
      total: 1,
      more: 0,
      range: '17/10 – 23/10',
      pending: [],
    },
    my_tasks: { items: [{ id: 't1', title: 'Gọi khách cũ', status: 'new', status_label: 'Mới', due: '18/10', overdue: false }], total: 1, url: '/tasks?tab=mine' },
  },
})

test('Tổng quan cá nhân: lời chào, băng báo cáo ngày, việc của bạn, chất lượng giảng dạy; đổi tháng gọi lại API', async () => {
  const calls = mockApi({
    'GET /dashboard': () => [200, personal('2026-10')],
    'GET /dashboard?month=2026-09': () => [200, personal('2026-09')],
  })
  const user = userEvent.setup()
  wrap(<DashboardPage me={me} />)

  expect(await screen.findByText('Xin chào, Cô Lan!')).toBeInTheDocument()
  expect(screen.getByText('GV FT')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: /Nhiệm vụ hôm nay/ })).toBeInTheDocument()

  const banner = document.querySelector('[data-dashboard-daily-report]') as HTMLElement
  expect(within(banner).getByRole('link', { name: 'Thứ 5 · 15/10' })).toHaveAttribute('href', '/reports/daily?date=2026-10-15')
  expect(within(banner).getByText(/Thiếu số liệu tuyển sinh/)).toBeInTheDocument()
  expect(within(banner).getByRole('button', { name: /Nộp báo cáo Thứ 5 · 15\/10/ })).toBeInTheDocument()

  const queues = document.querySelector('[data-role-queues]') as HTMLElement
  expect(within(queues).getByRole('link', { name: /Khách quá hạn chăm sóc/ })).toHaveAttribute('href', '/crm/customers?chip=sla')
  expect(within(queues).getByText('Chờ phân hệ Học phí')).toBeInTheDocument()

  const agenda = document.querySelector('[data-agenda]') as HTMLElement
  expect(within(agenda).getByRole('link', { name: /Phụ huynh An/ })).toHaveAttribute('href', '/crm/customers/l1')
  expect(within(document.querySelector('[data-my-tasks]') as HTMLElement).getByRole('link', { name: /Xem tất cả \(1\)/ })).toBeInTheDocument()

  const mine = document.querySelector('[data-teaching-mine]') as HTMLElement
  expect(within(mine).getAllByText('Chờ phân hệ Chấm công & KPI')).toHaveLength(4)
  expect(screen.getByLabelText(/KID1: 87,5% · Kids 1 · 12 học sinh/)).toBeInTheDocument()

  await user.selectOptions(screen.getByLabelText('Chọn tháng'), '2026-09')
  expect(await screen.findByRole('button', { name: /Viết báo cáo tháng/ })).toBeInTheDocument()
  expect(calls).toContain('GET /dashboard?month=2026-09')
  expect(window.location.search).toBe('?month=2026-09')
})

test('Bảng điều hành Admin: không lời chào, hàng chờ chờ phân hệ không bấm được, việc quá hạn mở danh sách lọc', async () => {
  mockApi({
    'GET /dashboard': () => [
      200,
      {
        is_operations: true,
        daily_report: null,
        teaching: null,
        role_dashboard: {
          type: 'admin',
          title: 'Bảng điều hành',
          scope: 'Toàn hệ thống',
          updated_at: '08:00 17/10/2026',
          stats: [{ label: 'Doanh thu tháng', value: null, icon: 'payments', tone: 'default', hint: null, href: null, pending: 'Chờ phân hệ Học phí' }],
          queues: [{ label: 'Phiếu thu chờ duyệt', value: null, icon: 'receipt', href: null, hint: null, pending: 'Chờ phân hệ Học phí' }],
          overdue_tasks: [{ id: 't9', title: 'Nộp hồ sơ', assignee: null, due_date: '2026-10-10' }],
        },
      } satisfies Overview,
    ],
  })
  wrap(<DashboardPage me={{ ...me, permissions: ['dashboard.operations'] }} />)
  expect(await screen.findByText('Bảng điều hành toàn trung tâm')).toBeInTheDocument()
  expect(screen.queryByText(/Xin chào/)).not.toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Xem danh sách' })).toHaveAttribute('href', '/tasks?tab=assigned&status=overdue')
  expect(screen.getByText('Hạn 10/10/2026')).toBeInTheDocument()
  expect(screen.getByText('Chưa phân công')).toBeInTheDocument()
  const queues = document.querySelector('[data-role-queues]') as HTMLElement
  expect(within(queues).queryByRole('link')).not.toBeInTheDocument()
})

test('Báo cáo & sự vụ lớp: tab báo cáo theo URL, xem nội dung; tab sự vụ lọc theo mức độ', async () => {
  window.history.pushState(null, '', '/academic/dashboards/reports?tab=monthly')
  const base = {
    today: '17/10/2026',
    class_reports: [],
    class_reports_pending: 'Chờ phân hệ Báo cáo trực lớp',
    staff_daily_reports: [],
    weekly_reports: [],
    weekly_kpi_reports: [],
    total_classes: 5,
    active_classes: 3,
    total_daily_reports_today: 2,
    attendance_rate_today: 90,
    weekly_stats: { pending: 'Chờ phân hệ Giáo trình & Big Test' },
  }
  const incidents = (severity: string) => ({
    severity,
    status: 'all',
    branch_id: '',
    class_id: '',
    branches: [{ value: 'b1', label: 'Cầu Giấy' }],
    classes: [],
    journals:
      severity === 'urgent'
        ? [
            {
              id: 'aaaaaaaa-0000-0000-0000-000000000001',
              branch: 'Cầu Giấy',
              class_id: 'c1',
              class_code: 'KID1',
              title: 'Học viên ngất',
              content: null,
              severity: 'urgent',
              priority: 'urgent',
              severity_label: 'Khẩn',
              user: 'Học vụ A',
              followup: 'Đã gọi phụ huynh',
              status: 'following',
            },
          ]
        : [],
    tickets: [],
    tickets_pending: 'Chờ phân hệ Ticket hỗ trợ',
    total_incidents: severity === 'urgent' ? 1 : 0,
    resolved_count: 0,
    open_count: severity === 'urgent' ? 1 : 0,
    urgent_count: severity === 'urgent' ? 1 : 0,
    can_submit_journal: true,
  })
  const calls = mockApi({
    'GET /academic/dashboards/reports?tab=monthly': () => [
      200,
      {
        ...base,
        tab: 'monthly',
        monthly_reports: [
          { id: 'r1', title: 'Báo cáo tháng 9', record_code: '2026-09', user: 'Cô Lan', status_label: 'Tháng giáo viên', content: 'Lớp ổn định', created_at: '10:00 01/10/2026' },
        ],
      },
    ],
    'GET /academic/dashboards/incidents': () => [200, incidents('all')],
    'GET /academic/dashboards/incidents?severity=urgent': () => [200, incidents('urgent')],
  })
  const user = userEvent.setup()
  wrap(<AcademicPage />)
  await user.click(await screen.findByText('Báo cáo tháng 9'))
  expect(screen.getByRole('dialog')).toHaveTextContent('Lớp ổn định')
  await user.click(within(screen.getByRole('dialog')).getAllByRole('button', { name: 'Đóng' })[0])
  expect(screen.getByRole('link', { name: '3. Báo cáo tháng Giáo viên' })).toHaveClass('text-primary')

  await user.click(screen.getByRole('link', { name: /Nhật ký sự vụ/ }))
  expect(await screen.findByText('Chưa có sự vụ nào phù hợp bộ lọc.')).toBeInTheDocument()
  await user.selectOptions(screen.getByLabelText('Mức độ'), 'urgent')
  expect(await screen.findByText('Học viên ngất')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Lớp KID1' })).toHaveAttribute('href', '/classes/c1')
  expect(calls).toContain('GET /academic/dashboards/incidents?severity=urgent')
  expect(window.location.search).toBe('?severity=urgent')
})
