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

const notif = (id: string, extra: Record<string, unknown> = {}) => ({
  id,
  type: 'task_assigned',
  type_label: 'Được giao việc',
  icon: 'assignment',
  tone: 'success',
  title: `Việc ${id}`,
  body: 'Chuẩn bị tài liệu',
  link: null,
  data: {},
  is_read: false,
  read_at: null,
  created_at: new Date().toISOString(),
  ...extra,
})

test('chuông: số chưa đọc, mở thông báo thì đánh dấu đã đọc và chuyển trang', async () => {
  let unread = 12
  const calls = renderAt('/', ['notification.view'], {
    'GET /auth/sessions': () => [200, { sessions: [] }],
    'GET /notifications/summary': () => [200, { unread, items: [notif('n1', { link: '/notifications' }), notif('n2', { is_read: true })] }],
    'POST /notifications/n1/read': () => {
      unread = 11
      return [200, { unread }]
    },
    'POST /notifications/read-all': () => {
      unread = 0
      return [200, { unread: 0, updated: 11 }]
    },
    'GET /notifications': () => [200, { notifications: [], total: 0, page: 1, per_page: 15, stats: { total: 0, unread: 0, alerts: 0 }, types: [] }],
  })
  const user = userEvent.setup()
  const bell = await screen.findByRole('button', { name: 'Thông báo (12 chưa đọc)' })
  expect(bell).toHaveTextContent('9+')
  await user.click(bell)
  expect(screen.getByText('Việc n1')).toBeInTheDocument()
  await user.click(screen.getByRole('link', { name: /Xử lý ngay/ }))
  expect(calls.some((c) => c.key === 'POST /notifications/n1/read')).toBe(true)
  expect(window.location.pathname).toBe('/notifications')
  expect(await screen.findByRole('button', { name: 'Thông báo (11 chưa đọc)' })).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: 'Thông báo (11 chưa đọc)' }))
  await user.click(screen.getByRole('button', { name: 'Đã đọc tất cả' }))
  expect(await screen.findByRole('button', { name: 'Thông báo' })).toBeInTheDocument()
})

test('không có quyền thông báo thì không hiện chuông', async () => {
  renderAt('/users', ['user.view'], { 'GET /users': () => [200, { users: [], total: 0, page_size: 50 }] })
  expect(await screen.findByRole('navigation', { name: 'Menu chính' })).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /^Thông báo/ })).not.toBeInTheDocument()
})

test('trang thông báo: lọc nhanh, cảnh báo lead, sao chép nội dung, đánh dấu đã đọc', async () => {
  const lead = notif('l1', {
    type: 'stale_lead_24h',
    type_label: 'Lead sót >24h',
    tone: 'error',
    icon: 'person_alert',
    link: '/crm/customers/c1',
    data: { customer_id: 'c1', customer_name: 'Phạm Minh', customer_phone: '0912345678', assigned_user: 'Sales A', hours_elapsed: 30 },
  })
  const copy = notif('c1', { type: 'payment_confirm_manual', type_label: 'Báo đã nhận học phí qua Zalo', data: { copy_text: 'Kính gửi phụ huynh' } })
  const calls = renderAt('/notifications', ['notification.view', 'lead.view'], {
    'GET /notifications/summary': () => [200, { unread: 2, items: [] }],
    'GET /notifications': (_b, url) => [
      200,
      {
        notifications: url.searchParams.get('type') === 'stale_lead_24h' ? [lead] : [lead, copy],
        total: 2,
        page: 1,
        per_page: 15,
        stats: { total: 2, unread: 2, alerts: 1 },
        types: [{ value: 'stale_lead_24h', label: 'Lead sót >24h' }],
      },
    ],
    'POST /notifications/c1/read': () => [200, { unread: 1 }],
  })
  const user = userEvent.setup()
  expect(await screen.findByText('Phạm Minh')).toBeInTheDocument()
  expect(screen.getByText('Lead bị sót >24h (Chưa xử lý)')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Xử lý Lead ngay' })).toBeInTheDocument()

  const writeText = vi.fn().mockResolvedValue(undefined)
  Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
  await user.click(screen.getByRole('button', { name: 'Sao chép nội dung' }))
  expect(writeText).toHaveBeenCalledWith('Kính gửi phụ huynh')
  expect(await screen.findByRole('button', { name: 'Đã sao chép' })).toBeInTheDocument()

  const items = within(screen.getByRole('main')).getAllByRole('listitem')
  await user.click(within(items[1]).getByRole('button', { name: 'Đánh dấu đã đọc' }))
  expect(calls.some((c) => c.key === 'POST /notifications/c1/read')).toBe(true)

  await user.click(screen.getByRole('button', { name: /Lead tồn đọng/ }))
  await vi.waitFor(() => expect(screen.queryByText('Kính gửi phụ huynh')).not.toBeInTheDocument())
  expect(calls.some((c) => c.key === 'GET /notifications' && c.url.searchParams.get('type') === 'stale_lead_24h')).toBe(true)
})
