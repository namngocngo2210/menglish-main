import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
import { App } from './App'
import { describeAgent } from './pages/SessionsCard'

afterEach(() => vi.restoreAllMocks())

const staff = {
  id: 'u1',
  kind: 'staff',
  code: 'MES001',
  email: 'admin@meducation.vn',
  phone: null,
  full_name: 'Quản trị',
  must_change_password: false,
  totp_enabled: false,
}

type Route = (body: unknown, init: RequestInit) => [number, unknown?]

// Giả lập API theo "METHOD /path"; ghi lại header của từng request để kiểm CSRF.
function mockApi(routes: Record<string, Route>) {
  const calls: { key: string; headers: Record<string, string>; body: unknown }[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init = {}) => {
    const key = `${init.method ?? 'GET'} ${String(input).replace('/api/v1', '')}`
    const body = init.body ? JSON.parse(String(init.body)) : undefined
    calls.push({ key, headers: (init.headers ?? {}) as Record<string, string>, body })
    const route = routes[key]
    if (!route) return new Response(JSON.stringify({ error: 'not_found' }), { status: 404 })
    const [status, data] = route(body, init)
    return new Response(data === undefined ? null : JSON.stringify(data), { status })
  })
  return calls
}

function renderApp() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <App />
    </QueryClientProvider>,
  )
}

test('đăng nhập sai báo lỗi, đúng thì vào trang chính', async () => {
  let loggedIn = false
  const calls = mockApi({
    'GET /auth/me': () => (loggedIn ? [200, { user: staff, csrf_token: 'csrf-1' }] : [401, { error: 'unauthenticated' }]),
    'POST /auth/login': (body) => {
      const { password } = body as { password: string }
      if (password !== 'dung-mat-khau') return [401, { error: 'invalid_credentials', message: 'Sai thông tin đăng nhập.' }]
      loggedIn = true
      return [200, { status: 'ok', user: staff, csrf_token: 'csrf-1' }]
    },
    'GET /meta': () => [200, { version: 'abc123def4567890', env: 'beta' }],
    'GET /auth/sessions': () => [200, { sessions: [] }],
    'POST /auth/logout': () => [204],
  })
  const user = userEvent.setup()
  renderApp()

  await user.type(await screen.findByLabelText('Tài khoản'), 'MES001')
  await user.type(screen.getByLabelText('Mật khẩu'), 'sai')
  await user.click(screen.getByRole('button', { name: 'Đăng nhập' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('Sai thông tin đăng nhập.')

  await user.clear(screen.getByLabelText('Mật khẩu'))
  await user.type(screen.getByLabelText('Mật khẩu'), 'dung-mat-khau')
  await user.click(screen.getByRole('button', { name: 'Đăng nhập' }))
  expect(await screen.findByText('Tài khoản', { selector: 'h2' })).toBeInTheDocument()
  expect(screen.getByText('MES001')).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: 'Đăng xuất' }))
  const logout = calls.find((c) => c.key === 'POST /auth/logout')
  expect(logout?.headers['X-CSRF-Token']).toBe('csrf-1')
})

test('tài khoản bật 2FA phải nhập mã', async () => {
  let loggedIn = false
  mockApi({
    'GET /auth/me': () =>
      loggedIn ? [200, { user: { ...staff, totp_enabled: true }, csrf_token: 'c', recovery_codes_left: 10 }] : [401, {}],
    'POST /auth/login': () => [200, { status: 'totp_required', challenge: 'ch-1' }],
    'POST /auth/login/totp': (body) => {
      const { challenge, code } = body as { challenge: string; code: string }
      if (challenge !== 'ch-1' || code !== '123456') return [401, { error: 'invalid_code', message: 'Mã xác thực không đúng.' }]
      loggedIn = true
      return [200, { status: 'ok', user: staff, csrf_token: 'c' }]
    },
    'GET /meta': () => [200, { version: 'v', env: 'beta' }],
    'GET /auth/sessions': () => [200, { sessions: [] }],
  })
  const user = userEvent.setup()
  renderApp()
  await user.type(await screen.findByLabelText('Tài khoản'), 'MES001')
  await user.type(screen.getByLabelText('Mật khẩu'), 'dung-mat-khau')
  await user.click(screen.getByRole('button', { name: 'Đăng nhập' }))

  const codeInput = await screen.findByLabelText('Mã xác thực')
  await user.type(codeInput, '000000')
  await user.click(screen.getByRole('button', { name: 'Xác nhận' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('Mã xác thực không đúng.')
  await user.clear(codeInput)
  await user.type(codeInput, '123456')
  await user.click(screen.getByRole('button', { name: 'Xác nhận' }))
  expect(await screen.findByText(/Còn 10 mã dự phòng/)).toBeInTheDocument()
})

test('mật khẩu tạm phải đổi trước khi vào hệ thống', async () => {
  let changed = false
  mockApi({
    'GET /auth/me': () => [200, { user: { ...staff, must_change_password: !changed }, csrf_token: 'c' }],
    'POST /auth/password': () => {
      changed = true
      return [204]
    },
    'GET /meta': () => [200, { version: 'v', env: 'beta' }],
    'GET /auth/sessions': () => [200, { sessions: [] }],
  })
  const user = userEvent.setup()
  renderApp()
  expect(await screen.findByText('Đổi mật khẩu lần đầu')).toBeInTheDocument()
  await user.type(screen.getByLabelText('Mật khẩu hiện tại'), 'mat-khau-tam-123')
  await user.type(screen.getByLabelText('Mật khẩu mới'), 'Trung-tam-tieng-anh-2026')
  await user.type(screen.getByLabelText('Nhập lại mật khẩu mới'), 'khac-hoan-toan-2026')
  await user.click(screen.getByRole('button', { name: 'Đổi mật khẩu' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('không khớp')

  await user.clear(screen.getByLabelText('Nhập lại mật khẩu mới'))
  await user.type(screen.getByLabelText('Nhập lại mật khẩu mới'), 'Trung-tam-tieng-anh-2026')
  await user.click(screen.getByRole('button', { name: 'Đổi mật khẩu' }))
  expect(await screen.findByText('Tài khoản', { selector: 'h2' })).toBeInTheDocument()
})

test('mô tả thiết bị từ User-Agent', () => {
  expect(
    describeAgent(
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36',
    ),
  ).toBe('Chrome trên Windows')
  expect(
    describeAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Safari/604.1'),
  ).toBe('Safari trên iOS')
  expect(describeAgent('curl/8.0')).toBe('Trình duyệt')
})
