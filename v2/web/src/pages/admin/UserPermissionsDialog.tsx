import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { api, errorMessage, type UserDetail } from '../../api'
import { Alert, Button, Dialog } from '../../components/ui'
import { modulesByGroup, moduleRows } from './permissions'

type Choice = 'role' | 'allow' | 'deny'

// Phân quyền cá nhân: mỗi quyền chọn "theo vai trò", "cho phép" hoặc "chặn"; chặn thắng quyền vai trò.
export function UserPermissionsDialog({ user, onClose }: { user: UserDetail; onClose: () => void }) {
  const qc = useQueryClient()
  const catalog = useQuery({ queryKey: ['catalog'], queryFn: api.catalog, staleTime: Infinity })
  const data = useQuery({ queryKey: ['user-permissions', user.id], queryFn: () => api.userPermissions(user.id) })
  const [choices, setChoices] = useState<Record<string, Choice> | null>(null)
  const save = useMutation({
    mutationFn: (c: Record<string, Choice>) =>
      api.setUserPermissions(
        user.id,
        Object.entries(c)
          .filter(([, v]) => v !== 'role')
          .map(([permission, v]) => ({ permission, allow: v === 'allow' })),
      ),
    onSuccess: (res) => {
      qc.setQueryData(['user-permissions', user.id], res)
      setChoices(null)
    },
  })

  const current: Record<string, Choice> =
    choices ?? Object.fromEntries((data.data?.overrides ?? []).map((o) => [o.permission, o.allow ? 'allow' : 'deny']))
  const role = new Set(data.data?.role_permissions)
  const effective = new Set(data.data?.effective)

  return (
    <Dialog title={`Phân quyền cá nhân: ${user.full_name}`} onClose={onClose}>
      {(data.isError || catalog.isError) && <Alert>{errorMessage(data.error ?? catalog.error)}</Alert>}
      {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
      {data.data && catalog.data && (
        <div className="space-y-3">
          <p className="text-sm text-on-surface-variant">
            Chỉ chỉnh khi người này cần khác vai trò của họ. Quyền duyệt tiền chỉ Admin dùng được dù cấp ở đây.
          </p>
          <div className="max-h-[60vh] space-y-2 overflow-y-auto pr-1">
            {modulesByGroup(catalog.data).flatMap(({ modules }) =>
              modules.map((m) => {
                const rows = moduleRows(catalog.data, m)
                const changed = rows.filter((r) => current[r.name] && current[r.name] !== 'role').length
                return (
                  <details key={m.key} className="rounded-lg border border-surface-container-highest">
                    <summary className="cursor-pointer px-3 py-2 text-sm font-medium">
                      {m.label}
                      {changed > 0 && <span className="ml-2 text-xs text-primary">{changed} điều chỉnh</span>}
                    </summary>
                    <table className="w-full text-left text-xs">
                      <thead className="text-on-surface-variant">
                        <tr>
                          <th className="px-3 py-1 font-medium">Quyền</th>
                          <th className="px-2 py-1 font-medium">Vai trò</th>
                          <th className="px-2 py-1 font-medium">Cá nhân</th>
                          <th className="px-2 py-1 font-medium">Kết quả</th>
                        </tr>
                      </thead>
                      <tbody>
                        {rows.map((r) => (
                          <tr key={r.name} title={r.description}>
                            <td className="px-3 py-1">{r.label}</td>
                            <td className="px-2 py-1">{role.has(r.name) ? 'Có' : '—'}</td>
                            <td className="px-2 py-1">
                              <select
                                aria-label={`Phân quyền cá nhân: ${r.label}`}
                                value={current[r.name] ?? 'role'}
                                onChange={(e) => setChoices({ ...current, [r.name]: e.target.value as Choice })}
                                className="h-7 rounded border border-outline-variant bg-surface px-1"
                              >
                                <option value="role">Theo vai trò</option>
                                <option value="allow">Cho phép</option>
                                <option value="deny">Chặn</option>
                              </select>
                            </td>
                            <td className="px-2 py-1">{effective.has(r.name) ? 'Có' : '—'}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </details>
                )
              }),
            )}
          </div>
          <div className="flex gap-2">
            <Button disabled={!choices || save.isPending} onClick={() => choices && save.mutate(choices)}>
              Lưu phân quyền
            </Button>
            <Button variant="secondary" onClick={onClose}>
              Quay lại
            </Button>
          </div>
          {save.isSuccess && !choices && <Alert tone="success">Đã lưu. Quyền mới có hiệu lực ngay.</Alert>}
        </div>
      )}
    </Dialog>
  )
}
