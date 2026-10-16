// Gọi Go API. Phiên nằm trong cookie HttpOnly; mọi request ghi gửi kèm X-CSRF-Token lấy từ /auth/me hoặc lúc đăng nhập.

export type User = {
  id: string
  kind: 'staff' | 'student' | 'guardian'
  code: string | null
  email: string | null
  phone: string | null
  full_name: string
  must_change_password: boolean
  totp_enabled: boolean
}

export type Me = { user: User; csrf_token: string; recovery_codes_left?: number }
export type Meta = { version: string; env: string }
export type LoginResponse =
  | { status: 'ok'; user: User; csrf_token: string }
  | { status: 'totp_required'; challenge: string }
export type SessionInfo = {
  id: string
  current: boolean
  created_at: string
  last_seen: string
  ip: string
  user_agent: string
}

export class ApiError extends Error {
  constructor(
    public status: number,
    public code: string,
    message: string,
  ) {
    super(message)
  }
}

let csrfToken = ''

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = {}
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (method !== 'GET' && csrfToken) headers['X-CSRF-Token'] = csrfToken
  const res = await fetch(`/api/v1${path}`, {
    method,
    headers,
    credentials: 'same-origin',
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  if (res.status === 204) return undefined as T
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new ApiError(res.status, data.error ?? 'http_error', data.message ?? `Lỗi máy chủ (HTTP ${res.status}).`)
  }
  if (typeof data.csrf_token === 'string') csrfToken = data.csrf_token
  return data as T
}

export const api = {
  meta: () => request<Meta>('GET', '/meta'),
  // Chưa đăng nhập thì trả null thay vì lỗi
  me: async () => {
    try {
      return await request<Me>('GET', '/auth/me')
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) return null
      throw e
    }
  },
  login: (identifier: string, password: string, remember: boolean) =>
    request<LoginResponse>('POST', '/auth/login', { identifier, password, remember }),
  loginTotp: (challenge: string, code: string) =>
    request<LoginResponse>('POST', '/auth/login/totp', { challenge, code }),
  logout: () => request<void>('POST', '/auth/logout'),
  changePassword: (current_password: string, new_password: string) =>
    request<void>('POST', '/auth/password', { current_password, new_password }),
  totpSetup: () => request<{ secret: string; otpauth_url: string }>('POST', '/auth/totp/setup'),
  totpEnable: (code: string) => request<{ recovery_codes: string[] }>('POST', '/auth/totp/enable', { code }),
  totpDisable: (password: string) => request<void>('POST', '/auth/totp/disable', { password }),
  sessions: () => request<{ sessions: SessionInfo[] }>('GET', '/auth/sessions'),
  revokeSession: (id: string) => request<void>('DELETE', `/auth/sessions/${encodeURIComponent(id)}`),
}

export function errorMessage(e: unknown): string {
  if (e instanceof ApiError) return e.message
  return 'Không kết nối được máy chủ, vui lòng thử lại.'
}
