import { expect, test } from 'vitest'
import type { Catalog, CatalogModule } from '../../api'
import { moduleRows, scopeLevel, withScope } from './permissions'

const lead: CatalogModule = {
  key: 'lead',
  label: 'CRM',
  group: 'crm',
  icon: '',
  actions: [{ key: 'view', label: 'Xem khách', description: '' }],
  audience: [{ key: 'be_assigned', label: 'Được nhận phụ trách', description: '' }],
  scope: { levels: ['own', 'branch', 'all'], descriptions: { own: 'Của tôi', branch: 'Chi nhánh', all: 'Tất cả' } },
}

test('mức phạm vi: mức cao nhất được cấp, mặc định mức thấp nhất, chỉ lưu một mức', () => {
  expect(scopeLevel(new Set(), lead)).toBe('own')
  expect(scopeLevel(new Set(['lead.scope_branch', 'lead.scope_all']), lead)).toBe('all')
  expect([...withScope(new Set(['lead.view', 'lead.scope_all']), lead, 'branch')].sort()).toEqual(['lead.scope_branch', 'lead.view'])
  expect([...withScope(new Set(['lead.scope_all']), lead, 'own')]).toEqual([])
})

test('quyền gán vai trò sinh theo từng vai trò trừ Admin', () => {
  const user: CatalogModule = {
    key: 'user',
    label: 'Người dùng',
    group: 'system',
    icon: '',
    actions: [],
    audience: [],
    dynamic: [{ prefix: 'assign_role.', label: 'Gán vai trò: :role', description: '' }],
  }
  const c = {
    scope_levels: [],
    roles: [
      { name: 'admin', label: 'Admin', short_label: 'Admin' },
      { name: 'manager', label: 'Quản lý', short_label: 'Quản lý cơ sở' },
    ],
  } as unknown as Catalog
  expect(moduleRows(c, user).map((r) => [r.name, r.label])).toEqual([['user.assign_role.manager', 'Gán vai trò: Quản lý cơ sở']])
})
