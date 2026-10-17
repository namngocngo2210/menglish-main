import { expect, test } from 'vitest'
import type { SLA } from './api'
import {
  canDrag,
  formatDate,
  formatDateTime,
  formatMoney,
  needsReason,
  normalizePhone,
  ownersForBranch,
  remainingLabel,
  slaState,
  stageEditOptions,
  toDateTimeLocal,
  transitionType,
  validPhone,
  type StageRules,
} from './logic'

test('SĐT Việt Nam: chuẩn hóa và kiểm tra như Go', () => {
  for (const p of ['0912345678', '0912 345 678', '0912.345.678', '+84 912 345 678', '02412345678']) expect(validPhone(p)).toBe(true)
  for (const p of ['', '012345678', '0112345678', '0912a45678', '09123456789']) expect(validPhone(p)).toBe(false)
  expect(normalizePhone('+84 912.345.678')).toBe('0912345678')
})

test('nhãn thời gian còn lại / quá hạn', () => {
  const now = new Date('2026-10-16T03:00:00Z')
  const at = (ms: number) => new Date(now.getTime() + ms)
  expect(remainingLabel(at(30_000), now)).toBe('Còn dưới 1 phút')
  expect(remainingLabel(at((2 * 60 + 14) * 60_000), now)).toBe('Còn 2 giờ 14 phút')
  expect(remainingLabel(at(-(27 * 60 + 5) * 60_000), now)).toBe('Quá hạn 1 ngày 3 giờ')
  expect(remainingLabel(at(48 * 3_600_000), now)).toBe('Còn 2 ngày')
})

test('trạng thái SLA tính lại theo giờ hiện tại', () => {
  const sla: SLA = {
    kind: 'first',
    label: '',
    deadline: '2026-10-16T10:00:00Z',
    deadline_label: '',
    warn_seconds: 6 * 3600,
    state: 'on_time',
    remaining: '',
  }
  expect(slaState(sla, new Date('2026-10-16T01:00:00Z'))).toBe('on_time')
  expect(slaState(sla, new Date('2026-10-16T05:00:00Z'))).toBe('due_soon')
  expect(slaState(sla, new Date('2026-10-16T10:00:01Z'))).toBe('overdue')
})

test('định dạng tiền, ngày giờ Việt Nam', () => {
  expect(formatMoney(1500000)).toBe('1.500.000 đ')
  expect(formatMoney(null)).toBe('0 đ')
  expect(formatDateTime('2026-10-16T02:05:00Z')).toBe('09:05 16/10/2026')
  expect(formatDate('2015-08-05')).toBe('05/08/2015')
  expect(formatDate('2026-10-16T18:00:00Z')).toBe('17/10/2026')
  expect(toDateTimeLocal('2026-10-16T02:05:00Z')).toBe('2026-10-16T09:05')
})

const order = ['new', 'consulting', 'test_scheduled', 'testing', 'tested', 'result_sent', 'waiting_class', 'won']
const closed = ['waiting_class', 'won']
const staff: StageRules = { order, closed, permissions: { can_forward: true, can_backward: false, can_convert: true, can_mark_lost: true } }
const admin: StageRules = { order, closed, permissions: { can_forward: true, can_backward: true, can_convert: true, can_mark_lost: true } }
const sales: StageRules = { order, closed, permissions: { can_forward: false, can_backward: false, can_convert: true, can_mark_lost: true } }

test('kéo thả Kanban: tiến 1 bước, lùi cần quyền, không kéo vào / ra khỏi bước đã chốt', () => {
  expect(transitionType(staff, 'new', 'consulting')).toBe('forward')
  expect(transitionType(staff, 'new', 'test_scheduled')).toBeNull()
  expect(transitionType(staff, 'consulting', 'new')).toBeNull()
  expect(transitionType(admin, 'consulting', 'new')).toBe('backward')
  expect(transitionType(admin, 'result_sent', 'waiting_class')).toBeNull()
  expect(transitionType(admin, 'won', 'new')).toBeNull()
  expect(canDrag(staff, 'result_sent')).toBe(false)
  expect(canDrag(admin, 'result_sent')).toBe(true)
  expect(canDrag(sales, 'new')).toBe(false)
})

test('hộp sửa giai đoạn và lý do bắt buộc', () => {
  const labels = Object.fromEntries(order.map((s) => [s, s]))
  expect(stageEditOptions(sales, 'consulting', labels).map((o) => o.value)).toEqual(['lost'])
  expect(stageEditOptions(admin, 'test_scheduled', labels).map((o) => o.value)).toEqual(['testing', 'consulting', 'new', 'lost'])
  expect(stageEditOptions(admin, 'won', labels)).toEqual([])
  expect(needsReason(admin, 'tested', 'consulting')).toBe(true)
  expect(needsReason(admin, 'tested', 'result_sent')).toBe(false)
  expect(needsReason(admin, 'tested', 'lost')).toBe(true)
})

test('người phụ trách theo cơ sở', () => {
  const owners = [
    { value: 'a', label: 'Admin', branch_ids: [], all_branches: true },
    { value: 'b', label: 'HV A', branch_ids: ['A'], all_branches: false },
    { value: 'c', label: 'HV B', branch_ids: ['B'], all_branches: false },
  ]
  expect(ownersForBranch(owners, 'A').map((o) => o.value)).toEqual(['a', 'b'])
  expect(ownersForBranch(owners, null)).toHaveLength(3)
})
