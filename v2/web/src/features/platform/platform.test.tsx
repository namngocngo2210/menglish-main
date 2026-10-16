import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
import { App } from '../../App'
import { timeAgo, toQuery } from './format'

afterEach(() => {
  vi.restoreAllMocks()
  window.history.pushState(null, '', '/')
})

const staff = {
  id: 'u1',
  kind: 'staff',
  code: 'MES001',
  email: 'admin@meducation.vn',
  phone: null,
  full_name: 'Quản trị',
  must_change_password: false,
  totp_enabled: true,
}

type Route = (body: unknown, url: URL) => [number, unknown?]

// Giả lập API theo "METHOD /path" (bỏ query); trả 404 cho đường dẫn chưa khai báo.
function mockApi(routes: Record<string, Route>) {
  const calls: { key: string; url: URL; body: unknown }[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init = {}) => {
    const url = new URL(String(input), 'http://localhost')
    const key = `${init.method ?? 'GET'} ${url.pathname.replace('/api/v1', '')}`
    const body = init.body ? JSON.parse(String(init.body)) : undefined
    calls.push({ key, url, body })
    const route = routes[key]
    if (!route) return new Response(JSON.stringify({ error: 'not_found' }), { status: 404 })
    const [status, data] = route(body, url)
    return new Response(data === undefined ? null : JSON.stringify(data), { status })
  })
  return calls
}

function renderAt(path: string, permissions: string[], routes: Record<string, Route>) {
  window.history.pushState(null, '', path)
  const calls = mockApi({
    'GET /auth/me': () => [200, { user: staff, csrf_token: 'c', totp_required: false, roles: [], permissions, scopes: {}, branches: [] }],
    'GET /meta': () => [200, { version: 'v', env: 'beta' }],
    ...routes,
  })
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <App />
    </QueryClientProvider>,
  )
  return calls
}

test('timeAgo và toQuery', () => {
  const now = Date.parse('2026-05-01T10:00:00Z')
  expect(timeAgo('2026-05-01T09:59:30Z', now)).toBe('vừa xong')
  expect(timeAgo('2026-05-01T09:55:00Z', now)).toBe('5 phút trước')
  expect(timeAgo('2026-05-01T07:00:00Z', now)).toBe('3 giờ trước')
  expect(timeAgo('2026-04-29T10:00:00Z', now)).toBe('2 ngày trước')
  expect(timeAgo('2026-01-01T10:00:00Z', now)).toBe('01/01/2026')
  expect(toQuery({ q: 'Hà', page: 2, empty: '', no: undefined, off: false, on: true })).toBe('q=H%C3%A0&page=2&on=true')
})

const log = {
  id: 'l1',
  created_at: '2026-05-01T03:04:05Z',
  actor_id: 'u1',
  actor_name: 'Nguyễn Văn A',
  actor_code: 'MES002',
  actor_role: 'Quản lý',
  module: 'Người dùng & Phân quyền',
  event: 'updated',
  event_label: 'Cập nhật',
  action: 'user.update',
  description: 'Cập nhật tài khoản nhân viên',
  subject: 'Tài khoản Trần Thị B (MES003)',
  subject_id: 'u3',
  diff: [{ field: 'full_name', old: 'Trần B', new: 'Trần Thị B' }],
  properties: {},
  ip: '10.0.0.1',
  user_agent: 'Firefox',
  request_id: 'r1',
  url: 'PATCH /api/v1/admin/users/u3',
}

test('nhật ký vận hành: lọc, xem chi tiết đối chiếu', async () => {
  const calls = renderAt('/activity-logs', ['activity_log.view'], {
    'GET /activity-logs': (_b, url) => [200, { logs: url.searchParams.get('group') === 'crm' ? [] : [log], total: url.searchParams.get('group') === 'crm' ? 0 : 1, page: 1, per_page: 20 }],
    'GET /activity-logs/options': () => [
      200,
      {
        groups: [{ value: 'crm', label: 'Tuyển sinh' }],
        modules: ['Người dùng & Phân quyền'],
        events: [{ value: 'updated', label: 'Cập nhật' }],
        actors: [{ value: 'u1', label: 'Nguyễn Văn A (MES002)' }],
        stats: { total: 12, today: 3, active_users: 2 },
      },
    ],
  })
  const user = userEvent.setup()
  expect(await screen.findByText('Cập nhật tài khoản nhân viên')).toBeInTheDocument()
  expect(screen.getByText('2 người')).toBeInTheDocument()
  // Giờ Việt Nam: 03:04:05Z → 10:04:05.
  expect(screen.getByText('10:04:05')).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: 'Xem chi tiết đối chiếu' }))
  const dialog = screen.getByRole('dialog', { name: 'Chi tiết đối chiếu' })
  expect(within(dialog).getByText('full_name: "Trần Thị B"')).toBeInTheDocument()
  expect(within(dialog).getByText('IP Address: 10.0.0.1')).toBeInTheDocument()
  await user.click(within(dialog).getByRole('button', { name: 'Đóng chi tiết' }))

  await user.click(screen.getByRole('button', { name: 'Tuyển sinh' }))
  expect(await screen.findByText('Không tìm thấy nhật ký vận hành nào phù hợp với bộ lọc')).toBeInTheDocument()
  expect(calls.some((c) => c.key === 'GET /activity-logs' && c.url.searchParams.get('group') === 'crm')).toBe(true)
  expect(screen.getByRole('link', { name: /Xuất Excel/ })).toHaveAttribute('href', '/api/v1/activity-logs/export?group=crm')
})

test('không có quyền nhật ký thì không thấy trang', async () => {
  renderAt('/activity-logs', ['user.view'], { 'GET /users': () => [200, { users: [], total: 0, page_size: 50 }] })
  expect(await screen.findByText('Bạn không có quyền xem trang này.')).toBeInTheDocument()
})
