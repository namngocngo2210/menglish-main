import { describe, expect, test } from 'vitest'
import type { Me } from '../../api'
import { buildSidebar, visibleNav } from '../../nav'
import type { Section } from './api'
import { allSelected, badgeText, canAct, failedOf, groupPath, keepPending, reasonError, refOf, toggle, toggleAll, visibleSections } from './logic'

const item = (source: string, id: string) => ({ source, id, title: id, subtitle: null, url: '/x', created_at: null, amount: null, flag: null })
const sec = (key: string, approve: boolean, reject: boolean, ids: string[]): Section => ({
  key,
  label: key,
  group: 'Học phí',
  index_url: '/' + key,
  count: ids.length,
  approve,
  reject,
  items: ids.map((id) => item(key, id)),
})

describe('chọn nhiều và nút thao tác', () => {
  const receipt = sec('receipt', true, true, ['a', 'b'])
  const refund = sec('refund', false, true, ['c'])
  const enrollment = sec('enrollment', false, false, ['d'])
  const all = [receipt, refund, enrollment, sec('empty', true, true, [])]

  test('mã mục và nguồn hiện', () => {
    expect(refOf({ source: 'receipt', id: 'a' })).toBe('receipt:a')
    expect(visibleSections(all).map((s) => s.key)).toEqual(['receipt', 'refund', 'enrollment'])
  })

  test('duyệt / từ chối chỉ bấm được khi mọi mục hỗ trợ', () => {
    expect(canAct([], all, 'approve')).toBe(false)
    expect(canAct(['receipt:a', 'receipt:b'], all, 'approve')).toBe(true)
    expect(canAct(['receipt:a', 'refund:c'], all, 'approve')).toBe(false)
    expect(canAct(['receipt:a', 'refund:c'], all, 'reject')).toBe(true)
    expect(canAct(['enrollment:d'], all, 'reject')).toBe(false)
    expect(canAct(['receipt:zz'], all, 'reject')).toBe(false)
  })

  test('chọn tất cả một nguồn, bỏ chọn, giữ mục còn chờ', () => {
    let s = toggleAll(['refund:c'], receipt, true)
    expect(s).toEqual(['refund:c', 'receipt:a', 'receipt:b'])
    expect(allSelected(s, receipt)).toBe(true)
    s = toggle(s, 'receipt:a', false)
    expect(allSelected(s, receipt)).toBe(false)
    expect(toggle(s, 'refund:c', true)).toEqual(s)
    expect(toggleAll(s, receipt, false)).toEqual(['refund:c'])
    expect(keepPending(['receipt:a', 'gone:x'], all)).toEqual(['receipt:a'])
  })
})

test('badge, đường dẫn nhóm, lý do từ chối, kết quả lỗi', () => {
  expect(badgeText(null)).toBeNull()
  expect(badgeText(0)).toBeNull()
  expect(badgeText(7)).toBe('7')
  expect(badgeText(100)).toBe('99+')
  expect(groupPath(null)).toBe('/approvals')
  expect(groupPath('hoc-phi')).toBe('/approvals?group=hoc-phi')
  expect(reasonError('   ')).toBe('Vui lòng nhập lý do từ chối.')
  expect(reasonError('x'.repeat(1001))).toBe('Lý do từ chối không được vượt quá 1000 ký tự.')
  expect(reasonError('Sai số tiền')).toBeNull()
  expect(failedOf([{ ref: 'a', title: 'A', ok: true, message: '' }, { ref: 'b', title: 'B', ok: false, message: 'Lỗi' }]).map((r) => r.ref)).toEqual(['b'])
})

const me = (role: string, permissions: string[]) =>
  ({ user: {}, roles: [{ name: role, label: role, short_label: role }], permissions, scopes: {}, branches: [] }) as unknown as Me

test('menu: "Việc cần duyệt" là tab đầu của Cần duyệt, chỉ Admin', () => {
  const admin = me('admin', ['tuition.view', 'tuition.approve', 'work_task.view', 'work_task.create'])
  const tabs = visibleNav(admin).filter((i) => i.group === 'Cần duyệt')
  expect(tabs[0].path).toBe('/approvals')
  const link = buildSidebar(admin, '/approvals').sections.flatMap((s) => s.links).find((l) => l.key === 'Cần duyệt')
  expect(link?.path).toBe('/approvals')
  expect(link?.active).toBe(true)
  const manager = me('manager', ['tuition.view', 'tuition.approve', 'lead.approve_transfer'])
  expect(visibleNav(manager).some((i) => i.path === '/approvals')).toBe(false)
})
