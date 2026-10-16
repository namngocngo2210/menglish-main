import { expect, test } from 'vitest'
import type { Me } from '../../api'
import { navItemFor, visibleNav } from '../../nav'
import { firstSafe, formatDate, formatDateTime, isLateAssign, percent, reasonLabel, reasonRequired, statusTone, taskIdFromPath } from './logic'

test('bị chặn / hủy bắt buộc lý do, nhãn ghi chú theo bước', () => {
  expect(reasonRequired('blocked')).toBe(true)
  expect(reasonRequired('canceled')).toBe(true)
  expect(reasonRequired('completed')).toBe(false)
  expect(reasonLabel('blocked')).toBe('Ghi chú lý do')
  expect(reasonLabel('hand_back')).toBe('Ghi chú cho người giao (tùy chọn)')
  expect(reasonLabel('in_progress')).toBe('Ghi chú / kết quả (tùy chọn)')
})

test('bước chọn sẵn bỏ qua "Hủy công việc"', () => {
  expect(firstSafe(['canceled', 'in_progress'])).toBe('in_progress')
  expect(firstSafe(['canceled'])).toBe('')
  expect(firstSafe([])).toBe('')
})

test('màu nhãn trạng thái', () => {
  expect(statusTone('overdue')).toContain('error')
  expect(statusTone('completed')).toContain('success')
  expect(statusTone('new')).toContain('surface')
})

test('định dạng ngày và giờ Việt Nam', () => {
  expect(formatDate('2026-10-16')).toBe('16/10/2026')
  expect(formatDate(null)).toBe('—')
  expect(formatDateTime('2026-10-16T09:05:00Z')).toBe('16:05 16/10/2026')
  expect(formatDateTime('2026-10-16T18:30:00Z')).toBe('01:30 17/10/2026')
})

test('giao việc trợ giảng trễ: ngày đã qua hoặc quá 15:30 hôm nay', () => {
  expect(isLateAssign('2026-10-15', '2026-10-16', '09:00', '15:30')).toBe(true)
  expect(isLateAssign('2026-10-16', '2026-10-16', '15:30', '15:30')).toBe(false)
  expect(isLateAssign('2026-10-16', '2026-10-16', '15:31', '15:30')).toBe(true)
  expect(isLateAssign('2026-10-16', '2026-10-16', '15:20', '15:30', 11)).toBe(true)
  expect(isLateAssign('2026-10-17', '2026-10-16', '20:00', '15:30')).toBe(false)
  expect(isLateAssign('', '2026-10-16', '20:00', '15:30')).toBe(false)
})

test('phần trăm tiến độ và id chi tiết từ đường dẫn', () => {
  expect(percent(1, 3)).toBe(33)
  expect(percent(0, 0)).toBe(0)
  const id = '01928c4e-7b1a-7cde-8f00-123456789abc'
  expect(taskIdFromPath(`/tasks/${id}`)).toBe(id)
  expect(taskIdFromPath('/tasks/new')).toBeNull()
  expect(taskIdFromPath('/tasks')).toBeNull()
})

const me = (roles: string[], permissions: string[]): Me =>
  ({
    user: {} as Me['user'],
    csrf_token: '',
    totp_required: false,
    roles: roles.map((name) => ({ name })) as Me['roles'],
    permissions,
    scopes: {},
    branches: [],
  }) as Me

test('menu công việc theo quyền: Cần duyệt chỉ Admin, cổng TA neo quyền cổng', () => {
  const paths = (m: Me) => visibleNav(m).map((i) => i.path)
  const admin = me(['admin'], ['work_task.view', 'work_task.create', 'work_task.assign'])
  expect(paths(admin)).toEqual(expect.arrayContaining(['/tasks/approvals', '/tasks/new', '/tasks']))
  expect(paths(admin)).not.toContain('/tasks/ta-assign')
  expect(paths(admin)).not.toContain('/portal/ta-tasks')

  const manager = me(['manager'], ['work_task.view', 'work_task.create'])
  expect(paths(manager)).not.toContain('/tasks/approvals')

  const ta = me(['assistant'], ['work_task.view', 'work_task.request', 'portal.assistant'])
  expect(paths(ta)).toEqual(expect.arrayContaining(['/tasks/new', '/tasks', '/portal/ta-tasks']))

  const id = '01928c4e-7b1a-7cde-8f00-123456789abc'
  expect(navItemFor(`/tasks/${id}`)?.path).toBe('/tasks')
  expect(navItemFor('/tasks/ta-assign')?.path).toBe('/tasks/ta-assign')
})
