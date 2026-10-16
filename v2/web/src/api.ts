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

export type RoleRef = { name: string; label: string; short_label: string }
export type UserBranch = { id: string; code: string; name: string; primary: boolean }
export type ScopeLevel = 'own' | 'branch' | 'all'

export type Me = {
  user: User
  csrf_token: string
  recovery_codes_left?: number
  totp_required: boolean
  roles: RoleRef[]
  permissions: string[]
  scopes: Record<string, ScopeLevel>
  branches: UserBranch[]
}

export type Branch = {
  id: string
  code: string
  name: string
  address: string | null
  phone: string | null
  is_active: boolean
  created_at: string
  checkin: BranchCheckin
}
export type BranchInput = { code: string; name: string; address: string; phone: string }
// Cài đặt chấm công: configured = đã có tọa độ; work_start / work_end dạng "HH:MM".
export type BranchCheckin = {
  latitude: number | null
  longitude: number | null
  radius: number
  work_start: string | null
  work_end: string | null
  grace: number
  configured: boolean
}
export type BranchCheckinInput = {
  latitude: number | null
  longitude: number | null
  checkin_radius: number | null
  work_start_time: string
  work_end_time: string
  late_grace_minutes: number | null
}
export type AddressPlace = { id: string; label: string; lat: number; lng: number }

export type RoleView = RoleRef & { super_admin: boolean; user_count: number; permissions: string[] }

type Labeled = { key: string; label: string }
export type CatalogAction = { key: string; label: string; description: string }
export type CatalogModule = {
  key: string
  label: string
  group: string
  icon: string
  actions: CatalogAction[] | null
  audience: CatalogAction[] | null
  scope?: { levels: ScopeLevel[]; descriptions: Record<string, string> }
  dynamic?: { prefix: string; label: string; description: string }[]
}
export type Catalog = {
  groups: Labeled[]
  scope_levels: Labeled[]
  matrix_columns: Labeled[]
  modules: CatalogModule[]
  roles: { name: string; label: string; short_label: string }[]
  admin_only: string[]
}

export type UserSummary = {
  id: string
  kind: User['kind']
  code: string | null
  email: string | null
  phone: string | null
  full_name: string
  status: 'active' | 'disabled'
  must_change_password: boolean
  totp_enabled: boolean
  locked: boolean
  last_login_at: string | null
  created_at: string
  roles: string[]
  primary_branch_id: string | null
}
export type UserDetail = UserSummary & { branches: UserBranch[] }
export type UserInput = {
  kind?: User['kind']
  code: string
  email: string
  phone: string
  full_name: string
  roles?: string[]
  primary_branch_id: string | null
  branch_ids: string[]
}
export type UserOptions = { roles: RoleRef[]; branches: Branch[] }
export type Override = { permission: string; allow: boolean }
export type UserPermissions = {
  user: UserDetail
  role_permissions: string[]
  overrides: Override[]
  effective: string[]
}
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

export async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
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

  branches: () => request<{ branches: Branch[] }>('GET', '/branches'),
  createBranch: (b: BranchInput) => request<Branch>('POST', '/branches', b),
  updateBranch: (id: string, b: BranchInput) => request<Branch>('PATCH', `/branches/${id}`, b),
  updateBranchCheckin: (id: string, c: BranchCheckinInput) => request<Branch>('PUT', `/branches/${id}/checkin`, c),
  addressSearch: (q: string) =>
    request<{ results: AddressPlace[]; matched: string | null }>('GET', `/branches/address-search?${new URLSearchParams({ q })}`),
  setBranchActive: (id: string, active: boolean) =>
    request<Branch>('POST', `/branches/${id}/${active ? 'activate' : 'deactivate'}`),

  catalog: () => request<Catalog>('GET', '/permissions/catalog'),
  roles: () => request<{ roles: RoleView[] }>('GET', '/roles'),
  updateRole: (name: string, label: string, short_label: string) =>
    request<void>('PATCH', `/roles/${name}`, { label, short_label }),
  setRolePermissions: (name: string, permissions: string[]) =>
    request<{ permissions: string[] }>('PUT', `/roles/${name}/permissions`, { permissions }),

  users: (params: { q?: string; role?: string; status?: string; page?: number }) => {
    const qs = new URLSearchParams()
    for (const [k, v] of Object.entries(params)) if (v) qs.set(k, String(v))
    return request<{ users: UserSummary[]; total: number; page_size: number }>('GET', `/users?${qs}`)
  },
  userOptions: () => request<UserOptions>('GET', '/users/options'),
  user: (id: string) => request<UserDetail>('GET', `/users/${id}`),
  createUser: (u: UserInput) => request<{ user: UserDetail; temporary_password: string }>('POST', '/users', u),
  updateUser: (id: string, u: UserInput) => request<UserDetail>('PATCH', `/users/${id}`, u),
  setUserRoles: (id: string, roles: string[]) => request<UserDetail>('PUT', `/users/${id}/roles`, { roles }),
  setUserLocked: (id: string, locked: boolean) => request<void>('POST', `/users/${id}/${locked ? 'lock' : 'unlock'}`),
  resetUserPassword: (id: string) => request<{ temporary_password: string }>('POST', `/users/${id}/reset-password`),
  resetUserTotp: (id: string) => request<void>('POST', `/users/${id}/reset-totp`),
  userPermissions: (id: string) => request<UserPermissions>('GET', `/users/${id}/permissions`),
  setUserPermissions: (id: string, overrides: Override[]) =>
    request<UserPermissions>('PUT', `/users/${id}/permissions`, { overrides }),
}

export function errorMessage(e: unknown): string {
  if (e instanceof ApiError) return e.message
  return 'Không kết nối được máy chủ, vui lòng thử lại.'
}
