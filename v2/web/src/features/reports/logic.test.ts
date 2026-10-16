import { expect, test } from 'vitest'
import type { AllRow, HistoryItem, WeeklyRow } from './api'
import {
  blankRow,
  formatDate,
  historyBadge,
  initialLines,
  kindPath,
  missingSuggestions,
  missingSummary,
  periodParam,
  quarterPeriod,
  reviewBadge,
  reviewNotice,
  rowsPayload,
  sameNotes,
  selectableIds,
  suggestLevels,
  toLine,
} from './logic'

const row = (r: Partial<WeeklyRow>): WeeklyRow => {
  const { uid: _uid, ...blank } = blankRow()
  return { ...blank, ...r }
}

test('đường dẫn và tham số kỳ theo loại báo cáo', () => {
  expect(kindPath('daily_ops')).toBe('/reports/daily')
  expect(kindPath('teacher_attendance')).toBe('/reports/teacher-monthly')
  expect(kindPath('khac')).toBe('/reports')
  expect(periodParam('/reports/daily')).toBe('date')
  expect(periodParam('/reports/weekly-kpi')).toBe('week')
  expect(periodParam('/reports/academic-weekly')).toBe('week')
  expect(periodParam('/reports/academic-quarterly')).toBe('quarter')
  expect(periodParam('/reports/teacher-monthly')).toBe('month')
})

test('định dạng ngày giờ theo giờ Việt Nam', () => {
  expect(formatDate('2026-10-16')).toBe('16/10/2026')
  expect(formatDate('2026-10-15T20:30:00Z', 'H:i d/m/Y')).toBe('03:30 16/10/2026')
  expect(formatDate('2026-10-16T01:05:00+07:00', 'd/m H:i')).toBe('16/10 01:05')
  expect(formatDate(null)).toBe('—')
  expect(formatDate('không phải ngày')).toBe('không phải ngày')
})

test('nhãn trạng thái kiểm tra và lịch sử', () => {
  const base = { type: 'daily', status: 'submitted', reviewed_at: null }
  expect(reviewBadge({ ...base, type: 'journal' })).toBeNull()
  expect(reviewBadge(base)?.label).toBe('Chờ kiểm tra')
  expect(reviewBadge({ ...base, reviewed_at: '2026-10-16T02:00:00Z' })?.label).toBe('Đã kiểm tra')
  expect(reviewBadge({ ...base, status: 'returned', reviewed_at: '2026-10-16T02:00:00Z' })?.tone).toBe('error')

  const h: HistoryItem = { period: '2026-10-15', label: '15/10', updated_at: null, draft: false, returned: false, reviewed: false, missing: false }
  expect(historyBadge(h)).toBeNull()
  expect(historyBadge({ ...h, draft: true, missing: true })?.label).toBe('Nháp')
  expect(historyBadge({ ...h, missing: true })?.label).toBe('Chưa nộp')
  expect(historyBadge({ ...h, returned: true, reviewed: true })?.label).toBe('Cần bổ sung')
  expect(historyBadge({ ...h, reviewed: true })?.tone).toBe('success')

  expect(reviewNotice(null)).toBeNull()
  expect(reviewNotice({ status: 'returned' } as never)?.tone).toBe('error')
})

test('ngày còn thiếu: bỏ ngày đang xem, tối đa 6 ngày', () => {
  const days = Array.from({ length: 9 }, (_, i) => ({ date: `2026-10-0${i + 1}` }))
  const s = missingSummary(days, '2026-10-01')
  expect(s.shown).toHaveLength(6)
  expect(s.shown[0].date).toBe('2026-10-02')
  expect(s.extra).toBe(2)
  expect(s.total).toBe(8)
  expect(missingSummary(days.slice(0, 1), '2026-10-01')).toEqual({ shown: [], extra: 0, total: 0 })
})

test('bảng báo cáo tuần Học thuật: dòng đã lưu, gợi ý, dữ liệu gửi lên', () => {
  const sug = { tasks: [row({ content: 'Việc A', source: 'task:1' }), row({ content: 'Việc B', source: 'task:2' })] }
  // Chưa nộp: lấy gợi ý; mục không có gợi ý thì một dòng trắng.
  const fresh = initialLines(['tasks', 'issues'], {}, sug)
  expect(fresh.tasks.map((r) => r.content)).toEqual(['Việc A', 'Việc B'])
  expect(fresh.issues).toHaveLength(1)
  expect(fresh.issues[0].content).toBeNull()
  // Đã nộp: lấy dòng đã lưu, bỏ qua gợi ý.
  const saved = initialLines(['tasks'], { tasks: [row({ content: 'Đã lưu', source: 'task:1' })] }, sug)
  expect(saved.tasks.map((r) => r.content)).toEqual(['Đã lưu'])
  expect(missingSuggestions(saved.tasks, sug.tasks).map((r) => r.source)).toEqual(['task:2'])
  expect(new Set([...fresh.tasks, ...saved.tasks].map((r) => r.uid)).size).toBe(3)

  const payload = rowsPayload({ tasks: [toLine({ content: '  ', detail: 'Chi tiết', progress: 50 })] })
  expect(payload.tasks[0]).not.toHaveProperty('uid')
  expect(payload.tasks[0].content).toBeNull()
  expect(payload.tasks[0].detail).toBe('Chi tiết')
  expect(payload.tasks[0].progress).toBe(50)
})

test('gợi ý mức chuyên cần / bài tập của giáo viên', () => {
  const c = { id: 'c1', name: 'A1', attendance: 95, homework: 80, sessions_done: 8 }
  expect(suggestLevels(c)).toEqual({ program_progress: null, attendance_level: 'high', homework_level: 'low' })
  expect(suggestLevels({ ...c, attendance: 94.9, homework: 81 })).toMatchObject({ attendance_level: 'low', homework_level: 'high' })
  expect(suggestLevels({ ...c, attendance: null, homework: null })).toMatchObject({ attendance_level: null, homework_level: null })
  expect(suggestLevels(c, { attendance_level: 'low', program_progress: 'on_track' })).toMatchObject({
    attendance_level: 'low',
    program_progress: 'on_track',
  })
})

test('kỳ quý', () => {
  expect(quarterPeriod('2026-Q4')).toEqual({ year: '2026', start: 10, end: 12 })
  expect(quarterPeriod('2026-Q1')).toEqual({ year: '2026', start: 1, end: 3 })
  expect(quarterPeriod('sai')).toEqual({ year: '', start: 1, end: 3 })
})

test('chọn hàng loạt chỉ báo cáo đang chờ kiểm tra', () => {
  const r = (id: string, over: Partial<AllRow>) => ({ id, reviewable: true, status: 'submitted', reviewed_at: null, ...over }) as AllRow
  const rows = [
    r('1', {}),
    r('2', { reviewed_at: '2026-10-16T00:00:00Z' }),
    r('3', { status: 'returned' }),
    r('4', { reviewable: false }),
    r('5', {}),
  ]
  expect(selectableIds(rows)).toEqual(['1', '5'])
})

test('so sánh ghi chú bỏ khoảng trắng, ô thiếu coi như rỗng', () => {
  expect(sameNotes({ a: ' x ' }, { a: 'x', b: '' })).toBe(true)
  expect(sameNotes({ a: 'x' }, { a: 'y' })).toBe(false)
  expect(sameNotes({}, { b: 'z' })).toBe(false)
})
