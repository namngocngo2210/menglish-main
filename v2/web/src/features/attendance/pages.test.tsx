import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import type { Me } from '../../api'
import type { DailyPage as DailyData, HomePage, TeacherTimesheetsPage as TsData, TimesheetRow } from './api'
import { ApprovalsPage } from './ApprovalsPage'
import { DailyPage } from './DailyPage'
import { MyAttendancePage } from './MyAttendancePage'
import { RequestsPage } from './RequestsPage'
import { TeacherTimesheetsPage } from './TeacherTimesheetsPage'

afterEach(() => {
  vi.restoreAllMocks()
  window.history.replaceState(null, '', '/')
})

type Route = (body: unknown) => [number, unknown?]

function mockApi(routes: Record<string, Route>) {
  const calls: { key: string; body: unknown }[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init = {}) => {
    const key = `${init.method ?? 'GET'} ${String(input).replace('/api/v1', '')}`
    const body = typeof init.body === 'string' ? JSON.parse(init.body) : undefined
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
  user: { id: 'u1', kind: 'staff', code: 'NV01', email: null, phone: null, full_name: 'Lê Hà', must_change_password: false, totp_enabled: true },
  csrf_token: 'c',
  totp_required: false,
  roles: [],
  permissions: ['portal.staff', 'work_task.request'],
  scopes: {},
  branches: [],
} as Me

const home: HomePage = {
  branch: { id: 'b1', name: 'Cầu Giấy', address: '1 Xuân Thủy', latitude: 21.03, longitude: 105.85, radius: 100, grace: 5, has_location: true },
  expected: { start: '08:00', end: '17:00', basis: 'Giờ hành chính', sessions: [] },
  today: null,
  server_time: '2026-10-17T08:02:03+07:00',
  locked: null,
}

test('chấm công của tôi: đồng hồ máy chủ, cơ sở, nút chấm vào', async () => {
  mockApi({ 'GET /attendance/me': () => [200, home] })
  wrap(<MyAttendancePage />)
  expect(await screen.findByText('Cầu Giấy')).toBeInTheDocument()
  expect(screen.getByText('Giờ vào 08:00 · giờ ra 17:00 (giờ hành chính)')).toBeInTheDocument()
  expect(screen.getByText(/Thứ Bảy, 17\/10\/2026/)).toBeInTheDocument()
  expect(screen.getByRole('button', { name: /Chấm công vào/ })).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Quên chấm công?' })).toHaveAttribute('href', '/cham-cong/don?type=correction')
})

test('chấm công của tôi: cơ sở chưa cài toạ độ thì không có nút chấm', async () => {
  mockApi({ 'GET /attendance/me': () => [200, { ...home, branch: { ...home.branch!, has_location: false } }] })
  wrap(<MyAttendancePage />)
  expect(await screen.findByText(/Cơ sở Cầu Giấy chưa cài toạ độ chấm công/)).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Chấm công vào/ })).not.toBeInTheDocument()
})

test('đơn chấm công: ?type=correction mở form, gửi đơn', async () => {
  window.history.replaceState(null, '', '/cham-cong/don?type=correction')
  const calls = mockApi({
    'GET /attendance/me/requests': () => [
      200,
      {
        types: [
          { value: 'correction', label: 'Bổ sung công' },
          { value: 'late_early', label: 'Xin đi muộn / về sớm' },
          { value: 'leave', label: 'Xin nghỉ phép' },
        ],
        today: '2026-10-17',
        requests: [],
      },
    ],
    'POST /attendance/me/requests': () => [200, { message: 'Đã gửi đơn Bổ sung công, chờ Admin duyệt.' }],
  })
  wrap(<RequestsPage me={me} />)
  const dialog = await screen.findByRole('dialog', { name: 'Bổ sung công' })
  await userEvent.type(within(dialog).getByLabelText('Giờ vào'), '08:00')
  await userEvent.type(within(dialog).getByLabelText('Lý do'), 'Quên chấm công vào')
  await userEvent.click(within(dialog).getByRole('button', { name: 'Gửi đơn' }))
  expect(await screen.findByText('Đã gửi đơn Bổ sung công, chờ Admin duyệt.')).toBeInTheDocument()
  expect(calls.find((c) => c.key === 'POST /attendance/me/requests')?.body).toEqual({
    type: 'correction',
    date_from: '2026-10-17',
    date_to: '',
    check_in_time: '08:00',
    check_out_time: '',
    reason: 'Quên chấm công vào',
  })
  expect(screen.getByRole('link', { name: /Nhờ hỗ trợ \/ giao việc/ })).toHaveAttribute('href', '/tasks/new')
})

test('duyệt đơn: từ chối bắt buộc lý do', async () => {
  const calls = mockApi({
    'GET /attendance/approvals': () => [
      200,
      {
        items: [
          {
            id: 'r1',
            user_name: 'Lê Hà',
            user_code: 'NV01',
            branch: 'Cầu Giấy',
            type: 'leave',
            type_label: 'Xin nghỉ phép',
            period: '20/10/2026 – 21/10/2026',
            date: '2026-10-20',
            reason: 'Việc gia đình',
            current_punch: null,
            created_at: '2026-10-17T01:00:00Z',
          },
        ],
      },
    ],
    'POST /attendance/approvals/r1/reject': () => [200, { message: 'Đã từ chối đơn.' }],
  })
  wrap(<ApprovalsPage />)
  await userEvent.click(await screen.findByRole('button', { name: 'Từ chối' }))
  const dialog = screen.getByRole('dialog', { name: 'Từ chối đơn' })
  await userEvent.type(within(dialog).getByLabelText('Lý do từ chối'), 'Trùng lịch')
  await userEvent.click(within(dialog).getByRole('button', { name: 'Từ chối đơn' }))
  expect(await screen.findByText('Đã từ chối đơn.')).toBeInTheDocument()
  expect(calls.find((c) => c.key === 'POST /attendance/approvals/r1/reject')?.body).toEqual({ reason: 'Trùng lịch' })
})

const daily: DailyData = {
  view: 'day',
  date: '2026-10-17',
  month: '2026-10',
  rows: [
    {
      user: { id: 'u1', name: 'Lê Hà', code: 'NV01', branch: 'Cầu Giấy' },
      attendance: {
        id: 'a1',
        check_in: '08:12',
        check_out: null,
        check_in_photo: null,
        check_in_distance: 1234,
        expected: '08:00 – 17:00',
        status_label: 'Đi muộn 12 phút',
        status_tone: 'warning',
        source: 'mobile',
        penalty: 'BB-01',
      },
    },
    { user: { id: 'u2', name: 'Phạm Minh', code: 'NV02', branch: 'Cầu Giấy' }, leave: 'Việc riêng' },
  ],
  meta: { page: 1, per_page: 30, total: 2 },
  branches: [],
  statuses: [{ value: 'late', label: 'Đi muộn' }],
  stats: { staff: 2, checked_in: 1, late: 1, leave: 1 },
  unconfigured: [],
}

test('chấm công hằng ngày: bảng theo ngày, mở chi tiết', async () => {
  mockApi({
    'GET /attendance/daily?view=day': () => [200, daily],
    'GET /attendance/daily/a1': () => [
      200,
      {
        id: 'a1',
        user: 'Lê Hà',
        code: 'NV01',
        branch: 'Cầu Giấy',
        radius: 100,
        date: '2026-10-17',
        source: 'mobile',
        expected_start: '08:00',
        expected_end: '17:00',
        in: { time: '08:12:00', photo: '/api/v1/files/f1', distance: 20, accuracy: 8, map: 'https://www.google.com/maps/search/?api=1&query=21,105' },
        out: null,
        status_label: 'Đi muộn 12 phút',
        status_tone: 'warning',
        note: null,
        penalty: { code: 'BB-01', url: '/penalties?search=BB-01' },
      },
    ],
  })
  wrap(<DailyPage />)
  expect(await screen.findByText('1.234 m')).toBeInTheDocument()
  expect(screen.getByText('Nghỉ phép · Việc riêng')).toBeInTheDocument()
  await userEvent.click(screen.getByText('Lê Hà'))
  const dialog = await screen.findByRole('dialog', { name: 'Chi tiết chấm công' })
  expect(await within(dialog).findByText('Lúc 08:12:00')).toBeInTheDocument()
  expect(within(dialog).getByRole('link', { name: 'BB-01' })).toHaveAttribute('href', '/penalties?search=BB-01')
  expect(within(dialog).getByText('Chưa chấm.')).toBeInTheDocument()
})

const row = (over: Partial<TimesheetRow>): TimesheetRow => ({
  id: 't1',
  user_id: 'g1',
  teaching_date: '2026-10-10',
  scheduled_time: '18:00 - 19:30',
  shift_name: 'Ca 3',
  type: 'regular',
  type_label: 'Ca thường',
  source: 'checkin',
  source_label: 'Check-in',
  class_id: 'c1',
  class_label: 'IELTS1',
  teacher_name: 'Nguyễn An',
  checkin_time: '18:00',
  checkout_time: null,
  display_checkout: '19:30',
  adjusted: false,
  hours: '1,5',
  punch_state: 'full',
  punch_state_label: 'Đầy đủ',
  adjustment_reason: null,
  adjustment_short: null,
  adjuster_name: null,
  notes_short: null,
  status: 'pending_review',
  status_label: 'Chờ duyệt',
  rejection_short: null,
  reviewer_name: null,
  locked: false,
  reject_label: 'Nguyễn An — 10/10/2026',
  late_minutes: 0,
  early_leave_minutes: 0,
  late_notified: false,
  late_label: null,
  edit_label: 'Nguyễn An — IELTS1 — 10/10/2026',
  ...over,
})

const ts: TsData = {
  can_view_all: true,
  can_review: true,
  can_adjust: true,
  month: '2026-10',
  month_label: '10/2026',
  period: null,
  period_locked: false,
  summary: { pending_review: 2, valid: 1, invalid: 0 },
  timesheets: [row({}), row({ id: 't2', teaching_date: '2026-10-11', reject_label: 'Nguyễn An — 11/10/2026' }), row({ id: 't3', status: 'valid', status_label: 'Hợp lệ' })],
  meta: { page: 1, per_page: 30, total: 3 },
  teacher: null,
  missing_sessions: [],
  schedule_day: { date: '2026-10-17', weekday: 'Thứ Bảy' },
  schedule_sessions: [
    {
      id: 's1',
      class_name: 'IELTS Foundation',
      class_code: 'IELTS1',
      start_time: '18:00',
      end_time: '19:30',
      teacher_name: 'Nguyễn An',
      state: 'none',
      state_note: null,
      source_label: null,
      can_confirm: true,
    },
  ],
  sub_pending_count: 0,
  filter_branches: [],
  filter_classes: [],
  filter_teachers: [],
  types: [],
  statuses: [],
}

test('giờ dạy giáo viên: chốt bảng công các ca đã chọn, xác nhận buổi theo lịch', async () => {
  const calls = mockApi({
    'GET /timesheets/teachers': () => [200, ts],
    'POST /timesheets/teachers/bulk-review': () => [200, { message: 'Đã chốt 2 ca dạy.' }],
    'POST /timesheets/sessions/s1/confirm': () => [200, { message: 'Đã xác nhận chấm công buổi học.' }],
  })
  wrap(<TeacherTimesheetsPage />)
  expect(await screen.findByText('Lịch dạy Thứ Bảy, 17/10/2026')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Chốt bảng công (0)' })).toBeDisabled()
  await userEvent.click(screen.getByRole('checkbox', { name: 'Chọn tất cả ca chờ đối soát' }))
  await userEvent.click(screen.getByRole('button', { name: 'Chốt bảng công (2)' }))
  expect(await screen.findByText('Đã chốt 2 ca dạy.')).toBeInTheDocument()
  expect(calls.find((c) => c.key === 'POST /timesheets/teachers/bulk-review')?.body).toEqual({ ids: ['t1', 't2'] })
  await userEvent.click(screen.getByRole('button', { name: 'Xác nhận chấm công' }))
  expect(await screen.findByText('Đã xác nhận chấm công buổi học.')).toBeInTheDocument()
})
