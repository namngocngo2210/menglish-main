import type { Catalog, CatalogModule, ScopeLevel } from '../../api'

// Tiện ích đọc danh mục quyền cho ma trận quyền vai trò và phân quyền cá nhân.

export type PermRow = { name: string; label: string; description: string; kind: 'action' | 'scope' | 'audience' | 'dynamic' }

export const ASSIGN_ROLE_PREFIX = 'user.assign_role.'

// Mọi quyền của một module theo thứ tự hiển thị: thao tác, phạm vi, đối tượng, gán vai trò.
export function moduleRows(c: Catalog, m: CatalogModule): PermRow[] {
  const levelLabel = Object.fromEntries(c.scope_levels.map((l) => [l.key, l.label]))
  const rows: PermRow[] = (m.actions ?? []).map((a) => ({ name: `${m.key}.${a.key}`, label: a.label, description: a.description, kind: 'action' }))
  for (const level of m.scope?.levels ?? []) {
    rows.push({
      name: `${m.key}.scope_${level}`,
      label: `Phạm vi: ${levelLabel[level] ?? level}`,
      description: m.scope?.descriptions[level] ?? '',
      kind: 'scope',
    })
  }
  for (const a of m.audience ?? []) {
    rows.push({ name: `${m.key}.${a.key}`, label: a.label, description: a.description, kind: 'audience' })
  }
  for (const d of m.dynamic ?? []) {
    for (const r of c.roles) {
      if (r.name === 'admin') continue
      rows.push({
        name: `${m.key}.${d.prefix}${r.name}`,
        label: d.label.replace(':role', r.short_label),
        description: d.description.replace(':role', r.short_label),
        kind: 'dynamic',
      })
    }
  }
  return rows
}

// Mức phạm vi của tập quyền trên module: mức cao nhất có trong tập, không có thì mức thấp nhất.
export function scopeLevel(perms: Set<string>, m: CatalogModule): ScopeLevel | null {
  const levels = m.scope?.levels
  if (!levels) return null
  for (let i = levels.length - 1; i > 0; i--) if (perms.has(`${m.key}.scope_${levels[i]}`)) return levels[i]
  return levels[0]
}

// Đặt mức phạm vi: bỏ mọi mức cũ, chỉ lưu mức được chọn nếu cao hơn mức thấp nhất.
export function withScope(perms: Set<string>, m: CatalogModule, level: ScopeLevel): Set<string> {
  const next = new Set([...perms].filter((p) => !p.startsWith(`${m.key}.scope_`)))
  if (m.scope && level !== m.scope.levels[0]) next.add(`${m.key}.scope_${level}`)
  return next
}

export function toggle(perms: Set<string>, name: string, on: boolean): Set<string> {
  const next = new Set(perms)
  if (on) next.add(name)
  else next.delete(name)
  return next
}

export function modulesByGroup(c: Catalog) {
  return c.groups
    .map((g) => ({ group: g, modules: c.modules.filter((m) => m.group === g.key) }))
    .filter((g) => g.modules.length > 0)
}
