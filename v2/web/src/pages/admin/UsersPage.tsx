import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type ChangeEvent } from 'react'
import { api, errorMessage, type Me, type UserDetail, type UserInput, type UserSummary } from '../../api'
import { Alert, Badge, Button, Card, Checkbox, Dialog, Field, Select } from '../../components/ui'
import { can } from '../../nav'
import { useQueryParam } from '../../router'
import { UserPermissionsDialog } from './UserPermissionsDialog'

export function UsersPage({ me }: { me: Me }) {
  // ?q=<từ khóa>&open=<id>: mở từ tìm kiếm chung (tìm sẵn và mở hộp chi tiết tài khoản).
  const urlQ = useQueryParam('q')
  const urlOpen = useQueryParam('open')
  const [fromUrl, setFromUrl] = useState({ q: urlQ, open: urlOpen })
  const [q, setQ] = useState(urlQ)
  const [search, setSearch] = useState(urlQ)
  const [role, setRole] = useState('')
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState<string | 'new' | null>(urlOpen || null)
  if (fromUrl.q !== urlQ || fromUrl.open !== urlOpen) {
    setFromUrl({ q: urlQ, open: urlOpen })
    setQ(urlQ)
    setSearch(urlQ)
    setPage(1)
    setOpen(urlOpen || null)
  }
  const users = useQuery({
    queryKey: ['users', search, role, page],
    queryFn: () => api.users({ q: search, role, page }),
    placeholderData: keepPreviousData,
  })
  const roles = useQuery({ queryKey: ['roles'], queryFn: api.roles, enabled: can(me, 'role.view') })
  const roleLabel = (name: string) => roles.data?.roles.find((r) => r.name === name)?.short_label ?? name
  const pages = users.data ? Math.max(1, Math.ceil(users.data.total / users.data.page_size)) : 1

  return (
    <Card
      title={
        <span className="flex items-center justify-between gap-4">
          Người dùng
          {can(me, 'user.create') && <Button onClick={() => setOpen('new')}>Thêm tài khoản</Button>}
        </span>
      }
    >
      <form
        className="mb-4 grid gap-3 sm:grid-cols-[1fr_14rem_auto] sm:items-end"
        onSubmit={(e) => {
          e.preventDefault()
          setSearch(q)
          setPage(1)
        }}
      >
        <Field label="Tìm theo tên, mã, email, SĐT" value={q} onChange={(e) => setQ(e.target.value)} />
        {roles.data && (
          <Select
            label="Vai trò"
            value={role}
            onChange={(e) => {
              setRole(e.target.value)
              setPage(1)
            }}
          >
            <option value="">Tất cả</option>
            {roles.data.roles.map((r) => (
              <option key={r.name} value={r.name}>
                {r.short_label}
              </option>
            ))}
          </Select>
        )}
        <Button type="submit" variant="secondary">
          Tìm
        </Button>
      </form>

      {users.isError && <Alert>{errorMessage(users.error)}</Alert>}
      {users.data && users.data.users.length === 0 && (
        <p className="text-sm text-on-surface-variant">Không có tài khoản nào trong phạm vi bạn quản lý.</p>
      )}
      {users.data && users.data.users.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="text-xs text-on-surface-variant">
              <tr>
                <th className="py-2 pr-4 font-medium">Họ tên</th>
                <th className="py-2 pr-4 font-medium">Đăng nhập</th>
                <th className="py-2 pr-4 font-medium">Vai trò</th>
                <th className="py-2 pr-4 font-medium">Trạng thái</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-surface-container-highest">
              {users.data.users.map((u) => (
                <tr key={u.id} className="cursor-pointer hover:bg-surface-container-low" onClick={() => setOpen(u.id)}>
                  <td className="py-2 pr-4">
                    <button type="button" className="text-left font-medium hover:text-primary" onClick={() => setOpen(u.id)}>
                      {u.full_name}
                    </button>
                  </td>
                  <td className="py-2 pr-4 text-on-surface-variant">{u.code ?? u.email ?? u.phone}</td>
                  <td className="py-2 pr-4">{u.roles.map(roleLabel).join(', ') || '—'}</td>
                  <td className="space-x-1 py-2 pr-4 whitespace-nowrap">
                    <StatusBadges u={u} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {pages > 1 && (
        <div className="mt-4 flex items-center gap-3 text-sm">
          <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            Trước
          </Button>
          <span>
            Trang {page}/{pages}
          </span>
          <Button variant="secondary" disabled={page >= pages} onClick={() => setPage(page + 1)}>
            Sau
          </Button>
        </div>
      )}

      {open === 'new' && <CreateUserDialog onClose={() => setOpen(null)} />}
      {open && open !== 'new' && <UserDialog me={me} id={open} roleLabel={roleLabel} onClose={() => setOpen(null)} />}
    </Card>
  )
}

function StatusBadges({ u }: { u: UserSummary }) {
  return (
    <>
      {u.status === 'disabled' ? <Badge tone="error">Đã khóa</Badge> : <Badge tone="success">Hoạt động</Badge>}
      {u.locked && <Badge tone="error">Tạm khóa do sai mật khẩu</Badge>}
      {u.must_change_password && <Badge tone="info">Chưa đổi mật khẩu</Badge>}
      {u.totp_enabled && <Badge>2FA</Badge>}
    </>
  )
}

const emptyForm: UserInput = { code: '', email: '', phone: '', full_name: '', roles: [], primary_branch_id: null, branch_ids: [] }

function UserFields({
  form,
  setForm,
  withRoles,
}: {
  form: UserInput
  setForm: (f: UserInput) => void
  withRoles: boolean
}) {
  const options = useQuery({ queryKey: ['user-options'], queryFn: api.userOptions })
  const set = (k: 'code' | 'email' | 'phone' | 'full_name') => (e: ChangeEvent<HTMLInputElement>) =>
    setForm({ ...form, [k]: e.target.value })
  return (
    <>
      <Field label="Họ tên" required maxLength={200} value={form.full_name} onChange={set('full_name')} />
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Mã đăng nhập" maxLength={30} value={form.code} onChange={set('code')} hint="Ví dụ MES012" />
        <Field label="Số điện thoại" inputMode="tel" value={form.phone} onChange={set('phone')} />
      </div>
      <Field label="Email" type="email" value={form.email} onChange={set('email')} />
      {options.isError && <Alert>{errorMessage(options.error)}</Alert>}
      {options.data && withRoles && (
        <fieldset className="space-y-2">
          <legend className="text-sm font-medium">Vai trò</legend>
          {options.data.roles.map((r) => (
            <Checkbox
              key={r.name}
              label={r.label}
              checked={form.roles?.includes(r.name) ?? false}
              onChange={(e) =>
                setForm({
                  ...form,
                  roles: e.target.checked ? [...(form.roles ?? []), r.name] : (form.roles ?? []).filter((x) => x !== r.name),
                })
              }
            />
          ))}
        </fieldset>
      )}
      {options.data && (
        <>
          <Select
            label="Chi nhánh chính"
            value={form.primary_branch_id ?? ''}
            onChange={(e) =>
              setForm({
                ...form,
                primary_branch_id: e.target.value || null,
                branch_ids: form.branch_ids.filter((b) => b !== e.target.value),
              })
            }
          >
            <option value="">Chưa gán</option>
            {options.data.branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </Select>
          {form.primary_branch_id && options.data.branches.length > 1 && (
            <fieldset className="space-y-2">
              <legend className="text-sm font-medium">Chi nhánh cấp thêm</legend>
              {options.data.branches
                .filter((b) => b.id !== form.primary_branch_id)
                .map((b) => (
                  <Checkbox
                    key={b.id}
                    label={b.name}
                    checked={form.branch_ids.includes(b.id)}
                    onChange={(e) =>
                      setForm({
                        ...form,
                        branch_ids: e.target.checked ? [...form.branch_ids, b.id] : form.branch_ids.filter((x) => x !== b.id),
                      })
                    }
                  />
                ))}
            </fieldset>
          )}
        </>
      )}
    </>
  )
}

function TempPassword({ name, login, password }: { name: string; login: string; password: string }) {
  return (
    <div className="space-y-3">
      <Alert tone="success">
        Đã cấp mật khẩu tạm cho {name}. Mật khẩu chỉ hiện một lần, người dùng phải đổi ở lần đăng nhập đầu.
      </Alert>
      <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
        <dt className="text-on-surface-variant">Đăng nhập</dt>
        <dd className="font-mono">{login}</dd>
        <dt className="text-on-surface-variant">Mật khẩu tạm</dt>
        <dd className="font-mono">{password}</dd>
      </dl>
    </div>
  )
}

function CreateUserDialog({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient()
  const [form, setForm] = useState<UserInput>(emptyForm)
  const create = useMutation({
    mutationFn: () => api.createUser(form),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['users'] }),
  })
  return (
    <Dialog title="Thêm tài khoản" onClose={onClose}>
      {create.data ? (
        <TempPassword
          name={create.data.user.full_name}
          login={create.data.user.code ?? create.data.user.email ?? create.data.user.phone ?? ''}
          password={create.data.temporary_password}
        />
      ) : (
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault()
            create.mutate()
          }}
        >
          {create.isError && <Alert>{errorMessage(create.error)}</Alert>}
          <UserFields form={form} setForm={setForm} withRoles />
          <Button type="submit" disabled={create.isPending}>
            Tạo tài khoản
          </Button>
        </form>
      )}
    </Dialog>
  )
}

function UserDialog({
  me,
  id,
  roleLabel,
  onClose,
}: {
  me: Me
  id: string
  roleLabel: (name: string) => string
  onClose: () => void
}) {
  const qc = useQueryClient()
  const user = useQuery({ queryKey: ['user', id], queryFn: () => api.user(id) })
  const [mode, setMode] = useState<'view' | 'edit' | 'roles' | 'permissions'>('view')
  const [temp, setTemp] = useState<string | null>(null)
  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['users'] })
    qc.invalidateQueries({ queryKey: ['user', id] })
  }
  const action = useMutation({
    mutationFn: async (kind: 'lock' | 'unlock' | 'reset-password' | 'reset-totp') => {
      if (kind === 'reset-password') setTemp((await api.resetUserPassword(id)).temporary_password)
      else if (kind === 'reset-totp') await api.resetUserTotp(id)
      else await api.setUserLocked(id, kind === 'lock')
    },
    onSuccess: refresh,
  })

  if (mode === 'permissions' && user.data) {
    return <UserPermissionsDialog user={user.data} onClose={() => setMode('view')} />
  }
  return (
    <Dialog title={user.data?.full_name ?? 'Tài khoản'} onClose={onClose}>
      {user.isError && <Alert>{errorMessage(user.error)}</Alert>}
      {user.data && mode === 'edit' && <EditUserForm user={user.data} onDone={() => (refresh(), setMode('view'))} />}
      {user.data && mode === 'roles' && <RolesForm user={user.data} onDone={() => (refresh(), setMode('view'))} />}
      {user.data && mode === 'view' && (
        <div className="space-y-4">
          {action.isError && <Alert>{errorMessage(action.error)}</Alert>}
          {temp && (
            <TempPassword name={user.data.full_name} login={user.data.code ?? user.data.email ?? user.data.phone ?? ''} password={temp} />
          )}
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            <dt className="text-on-surface-variant">Mã</dt>
            <dd className="font-mono">{user.data.code ?? '—'}</dd>
            <dt className="text-on-surface-variant">Email</dt>
            <dd>{user.data.email ?? '—'}</dd>
            <dt className="text-on-surface-variant">Điện thoại</dt>
            <dd>{user.data.phone ?? '—'}</dd>
            <dt className="text-on-surface-variant">Vai trò</dt>
            <dd>{user.data.roles.map(roleLabel).join(', ') || '—'}</dd>
            <dt className="text-on-surface-variant">Chi nhánh</dt>
            <dd>{user.data.branches.map((b) => (b.primary ? `${b.name} (chính)` : b.name)).join(', ') || '—'}</dd>
            <dt className="text-on-surface-variant">Trạng thái</dt>
            <dd className="space-x-1">
              <StatusBadges u={user.data} />
            </dd>
          </dl>
          <div className="flex flex-wrap gap-2">
            {can(me, 'user.update') && (
              <Button variant="secondary" onClick={() => setMode('edit')}>
                Sửa
              </Button>
            )}
            {can(me, 'user.assign_role') && (
              <Button variant="secondary" onClick={() => setMode('roles')}>
                Vai trò
              </Button>
            )}
            {can(me, 'permission.override') && (
              <Button variant="secondary" onClick={() => setMode('permissions')}>
                Phân quyền cá nhân
              </Button>
            )}
            {can(me, 'user.reset_password') && (
              <Button variant="secondary" disabled={action.isPending} onClick={() => action.mutate('reset-password')}>
                Cấp lại mật khẩu
              </Button>
            )}
            {can(me, 'user.reset_password') && user.data.totp_enabled && (
              <Button variant="secondary" disabled={action.isPending} onClick={() => action.mutate('reset-totp')}>
                Tắt 2FA (mất điện thoại)
              </Button>
            )}
            {can(me, 'user.lock') &&
              (user.data.status === 'active' ? (
                <Button variant="danger" disabled={action.isPending} onClick={() => action.mutate('lock')}>
                  Khóa tài khoản
                </Button>
              ) : (
                <Button variant="secondary" disabled={action.isPending} onClick={() => action.mutate('unlock')}>
                  Mở khóa
                </Button>
              ))}
          </div>
        </div>
      )}
    </Dialog>
  )
}

function EditUserForm({ user, onDone }: { user: UserDetail; onDone: () => void }) {
  const [form, setForm] = useState<UserInput>({
    code: user.code ?? '',
    email: user.email ?? '',
    phone: user.phone ?? '',
    full_name: user.full_name,
    primary_branch_id: user.primary_branch_id,
    branch_ids: user.branches.filter((b) => !b.primary).map((b) => b.id),
  })
  const save = useMutation({ mutationFn: () => api.updateUser(user.id, form), onSuccess: onDone })
  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        save.mutate()
      }}
    >
      {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
      <UserFields form={form} setForm={setForm} withRoles={false} />
      <Button type="submit" disabled={save.isPending}>
        Lưu
      </Button>
    </form>
  )
}

function RolesForm({ user, onDone }: { user: UserDetail; onDone: () => void }) {
  const options = useQuery({ queryKey: ['user-options'], queryFn: api.userOptions })
  const [roles, setRoles] = useState(user.roles)
  const save = useMutation({ mutationFn: () => api.setUserRoles(user.id, roles), onSuccess: onDone })
  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        save.mutate()
      }}
    >
      {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
      <p className="text-sm text-on-surface-variant">Bạn chỉ thêm, bớt được các vai trò mình được phép gán.</p>
      {options.data?.roles.map((r) => (
        <Checkbox
          key={r.name}
          label={r.label}
          checked={roles.includes(r.name)}
          onChange={(e) => setRoles(e.target.checked ? [...roles, r.name] : roles.filter((x) => x !== r.name))}
        />
      ))}
      <Button type="submit" disabled={save.isPending}>
        Lưu vai trò
      </Button>
    </form>
  )
}
