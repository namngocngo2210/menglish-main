import { expect, test } from 'vitest'
import type { Me } from '../../api'
import { bannerOf, barWidth, formatCount, formatDate, formatHourDay, formatPercent, formatScore, kindClass, reportSummary, seesDashboard, welcomeOf } from './logic'

const me = (extra: Partial<Me> = {}): Me => ({
  user: { id: 'u1', kind: 'staff', code: 'GV01', email: null, phone: null, full_name: 'Cô Lan', must_change_password: false, totp_enabled: true },
  csrf_token: 'c',
  totp_required: false,
  roles: [],
  permissions: [],
  scopes: {},
  branches: [],
  ...extra,
})

test('chỉ nhân sự (portal.staff) hoặc người có bảng điều hành thấy Tổng quan', () => {
  expect(seesDashboard(me({ permissions: ['portal.staff'] }))).toBe(true)
  expect(seesDashboard(me({ permissions: ['dashboard.operations'] }))).toBe(true)
  expect(seesDashboard(me({ permissions: ['portal.student'] }))).toBe(false)
})

test('định dạng điểm, phần trăm, số đếm, ngày', () => {
  expect(formatScore(null)).toBe('—')
  expect(formatScore(8.25)).toBe('8,3')
  expect(formatPercent(92.5)).toBe('92,5%')
  expect(formatPercent(undefined)).toBe('—')
  expect(formatCount(1234567)).toBe('1,234,567')
  expect(formatDate('2026-10-05')).toBe('05/10/2026')
  expect(formatDate('2026-10-05T02:30:00Z', true)).toBe('09:30 05/10/2026')
  expect(formatDate(null)).toBe('')
  expect(formatHourDay('2026-10-05T02:30:00Z')).toBe('09:30 05/10')
  expect(formatHourDay(null)).toBe('')
})

test('màu nhãn lịch hẹn theo loại, loại lạ dùng màu trung tính', () => {
  expect(kindClass('call')).toContain('warning')
  expect(kindClass('khac')).toContain('surface-container-high')
})

test('lời chào: vai trò rút gọn, chi nhánh chính, nút nhiệm vụ hôm nay theo quyền', () => {
  const w = welcomeOf(
    me({
      roles: [{ name: 'teacher_fulltime', label: 'Giáo viên full-time', short_label: 'GV FT' }],
      branches: [
        { id: 'b2', code: 'TX', name: 'Thanh Xuân', primary: false },
        { id: 'b1', code: 'CG', name: 'Cầu Giấy', primary: true },
      ],
      permissions: ['work_task.view'],
    }),
  )
  expect(w).toEqual({ name: 'Cô Lan', roles: 'GV FT', branch: 'Cầu Giấy', salaryUrl: null, tasksUrl: '/portal/ta-tasks' })
  expect(welcomeOf(me())).toMatchObject({ roles: 'Nhân viên', branch: 'Trung tâm', salaryUrl: null, tasksUrl: null })
  expect(welcomeOf(me({ permissions: ['payroll.view_own'] })).salaryUrl).toBe('/portal/my-salary')
  expect(welcomeOf(me({ permissions: ['payroll.view'] })).salaryUrl).toBe('/portal/my-salary')
})

test('băng báo cáo ngày: tối đa 5 ngày thiếu, hạn ngày gần nhất, nút nộp', () => {
  const days = Array.from({ length: 7 }, (_, i) => ({
    label: `0${9 - i}/10`,
    href: `/reports/daily?date=2026-10-0${9 - i}`,
    deadline: `09:00 ${10 - i}/10`,
    overdue: i > 0,
  }))
  const b = bannerOf({ missing: days, returned: [], today_submitted: false, today_href: '/reports/daily?date=2026-10-10' })
  expect(b.attention).toBe(true)
  expect(b.missing).toHaveLength(5)
  expect(b.more).toBe(2)
  expect(b.deadline).toBe('09:00 10/10')
  expect(b.action).toEqual({ label: 'Nộp báo cáo 09/10', href: '/reports/daily?date=2026-10-09' })
  expect(b.today).toBe('chưa nộp (hạn 09:00 sáng mai)')

  const done = bannerOf({ missing: [], returned: [], today_submitted: true, today_href: '/reports/daily?date=2026-10-10' })
  expect(done).toMatchObject({ attention: false, allDone: true, today: 'đã nộp', deadline: null })
  expect(done.action).toEqual({ label: 'Viết báo cáo hôm nay', href: '/reports/daily?date=2026-10-10' })
})

test('độ rộng thanh bị chặn trong 0–100%', () => {
  expect(barWidth(5, 10)).toBe('50%')
  expect(barWidth(120, 100)).toBe('100%')
  expect(barWidth(-1, 100)).toBe('0%')
})

test('tóm tắt báo cáo tháng giáo viên', () => {
  const base = { id: 't', teacher: 'A', updated_at: null, progress: null, difficulties: null, proposals: null, classes: [] }
  expect(reportSummary({ ...base, submitted: false })).toBeNull()
  expect(reportSummary({ ...base, submitted: true })).toBe('Chỉ báo cáo theo lớp')
  expect(reportSummary({ ...base, submitted: true, progress: 'Đúng tiến độ', difficulties: 'Thiếu phòng' })).toBe('Thiếu phòng')
})
