import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { api, errorMessage, type Catalog, type Me, type RoleView, type ScopeLevel } from '../../api'
import { Alert, Button, Card, Checkbox, Field } from '../../components/ui'
import { can } from '../../nav'
import { modulesByGroup, moduleRows, scopeLevel, toggle, withScope } from './permissions'

export function RolesPage({ me }: { me: Me }) {
  const roles = useQuery({ queryKey: ['roles'], queryFn: api.roles })
  const catalog = useQuery({ queryKey: ['catalog'], queryFn: api.catalog, staleTime: Infinity })
  const [selected, setSelected] = useState<string | null>(null)

  if (roles.isError || catalog.isError) return <Alert>{errorMessage(roles.error ?? catalog.error)}</Alert>
  if (!roles.data || !catalog.data) return <p className="text-sm text-on-surface-variant">Đang tải…</p>
  const role = roles.data.roles.find((r) => r.name === selected) ?? roles.data.roles[0]

  return (
    <div className="grid gap-6 lg:grid-cols-[16rem_1fr]">
      <Card title="Vai trò">
        <ul className="space-y-1">
          {roles.data.roles.map((r) => (
            <li key={r.name}>
              <button
                type="button"
                onClick={() => setSelected(r.name)}
                aria-current={r.name === role.name ? 'true' : undefined}
                className="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm hover:bg-surface-container-low aria-[current=true]:bg-surface-container-low aria-[current=true]:font-medium aria-[current=true]:text-primary"
              >
                <span>{r.short_label}</span>
                <span className="text-xs text-on-surface-variant">{r.user_count}</span>
              </button>
            </li>
          ))}
        </ul>
      </Card>
      <RoleEditor key={role.name} me={me} role={role} catalog={catalog.data} />
    </div>
  )
}

function RoleEditor({ me, role, catalog }: { me: Me; role: RoleView; catalog: Catalog }) {
  const qc = useQueryClient()
  const [perms, setPerms] = useState(() => new Set(role.permissions))
  const [label, setLabel] = useState(role.label)
  const [short, setShort] = useState(role.short_label)
  const editable = can(me, 'role.assign_permission') && !role.super_admin
  const dirty = perms.size !== role.permissions.length || role.permissions.some((p) => !perms.has(p))
  const done = () => qc.invalidateQueries({ queryKey: ['roles'] })
  const savePerms = useMutation({ mutationFn: () => api.setRolePermissions(role.name, [...perms]), onSuccess: done })
  const saveLabels = useMutation({ mutationFn: () => api.updateRole(role.name, label, short), onSuccess: done })

  return (
    <div className="space-y-6">
      <Card title={role.label}>
        {can(me, 'role.update') ? (
          <form
            className="grid gap-4 sm:grid-cols-[1fr_12rem_auto] sm:items-end"
            onSubmit={(e) => {
              e.preventDefault()
              saveLabels.mutate()
            }}
          >
            <Field label="Tên vai trò" required maxLength={100} value={label} onChange={(e) => setLabel(e.target.value)} />
            <Field label="Tên ngắn" required maxLength={50} value={short} onChange={(e) => setShort(e.target.value)} />
            <Button type="submit" variant="secondary" disabled={saveLabels.isPending}>
              Lưu tên
            </Button>
            {saveLabels.isError && <Alert>{errorMessage(saveLabels.error)}</Alert>}
          </form>
        ) : (
          <p className="text-sm text-on-surface-variant">Mã vai trò: {role.name}</p>
        )}
        {role.super_admin && (
          <div className="mt-4">
            <Alert tone="info">Admin luôn có mọi quyền thao tác và phạm vi toàn hệ thống, không sửa được.</Alert>
          </div>
        )}
      </Card>

      {modulesByGroup(catalog).map(({ group, modules }) => (
        <Card key={group.key} title={group.label}>
          <div className="divide-y divide-surface-container-highest">
            {modules.map((m) => {
              const rows = moduleRows(catalog, m).filter((r) => r.kind !== 'scope')
              const level = scopeLevel(perms, m)
              return (
                <div key={m.key} className="grid gap-3 py-3 md:grid-cols-[14rem_1fr]">
                  <div>
                    <p className="text-sm font-medium">{m.label}</p>
                    {m.scope && level && (
                      <select
                        aria-label={`Phạm vi dữ liệu: ${m.label}`}
                        disabled={!editable}
                        value={level}
                        onChange={(e) => setPerms(withScope(perms, m, e.target.value as ScopeLevel))}
                        className="mt-1 h-8 rounded-md border border-outline-variant bg-surface px-2 text-xs"
                      >
                        {m.scope.levels.map((l) => (
                          <option key={l} value={l}>
                            Phạm vi: {catalog.scope_levels.find((s) => s.key === l)?.label}
                          </option>
                        ))}
                      </select>
                    )}
                  </div>
                  <div className="flex flex-wrap gap-x-5 gap-y-2">
                    {rows.map((r) => (
                      <Checkbox
                        key={r.name}
                        label={
                          <>
                            {r.label}
                            {catalog.admin_only.includes(r.name) && <span className="text-on-surface-variant"> (chỉ Admin)</span>}
                            {r.kind === 'audience' && <span className="text-on-surface-variant"> (đối tượng)</span>}
                          </>
                        }
                        hint={r.description}
                        disabled={!editable}
                        checked={perms.has(r.name)}
                        onChange={(e) => setPerms(toggle(perms, r.name, e.target.checked))}
                      />
                    ))}
                  </div>
                </div>
              )
            })}
          </div>
        </Card>
      ))}

      {editable && (
        <div className="sticky bottom-0 flex items-center gap-3 border-t border-surface-container-highest bg-background py-3">
          <Button onClick={() => savePerms.mutate()} disabled={!dirty || savePerms.isPending}>
            Lưu quyền
          </Button>
          <Button variant="secondary" disabled={!dirty} onClick={() => setPerms(new Set(role.permissions))}>
            Hoàn tác
          </Button>
          {savePerms.isError && <Alert>{errorMessage(savePerms.error)}</Alert>}
          {savePerms.isSuccess && !dirty && <Alert tone="success">Đã lưu. Quyền mới có hiệu lực ngay.</Alert>}
        </div>
      )}
    </div>
  )
}
