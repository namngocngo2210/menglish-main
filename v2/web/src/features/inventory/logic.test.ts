import { expect, test } from 'vitest'
import { qs } from './api'
import { dueRule, formatCount, formatDate, formatDateTime, formatTimeDate, formatVnd, pageCount, previewDueAt, signed, toInt } from './logic'

test('định dạng tiền, số, ngày theo giờ Việt Nam', () => {
  expect(formatVnd(200000)).toBe('200.000 đ')
  expect(formatCount(-1234567)).toBe('-1.234.567')
  expect(formatCount(0)).toBe('0')
  expect(formatDate('2026-10-12')).toBe('12/10/2026')
  // 2026-10-11T08:00Z = 15:00 giờ Việt Nam
  expect(formatTimeDate('2026-10-11T08:00:00Z')).toBe('15:00 11/10/2026')
  expect(formatDateTime('2026-10-05T16:59:00Z')).toBe('05/10/2026 23:59')
  expect(signed(3)).toBe('+3')
  expect(signed(-3)).toBe('-3')
})

test('hạn xử lý dự kiến theo loại order (khớp server)', () => {
  const iso = (c: string, d: string, n = 5) => previewDueAt(c, d, n, '15:00')?.toISOString()
  expect(iso('props', '2026-10-12')).toBe('2026-10-11T08:00:00.000Z') // 15:00 11/10 giờ VN
  expect(iso('printing', '2026-10-01')).toBe('2026-09-30T08:00:00.000Z') // sang tháng trước
  expect(iso('foreign_teacher', '2026-10-20')).toBe('2026-10-05T16:59:00.000Z') // 23:59 05/10
  expect(iso('academic', '2026-02-10', 31)).toBe('2026-02-28T16:59:00.000Z') // N vượt số ngày tháng
  expect(previewDueAt('props', '12/10/2026', 5, '15:00')).toBeNull()
  expect(dueRule('academic', 5, '15:00')).toContain('Trưởng Học thuật')
  expect(dueRule('props', 5, '15:00')).toContain('trước 15:00')
})

test('ô số, phân trang, chuỗi truy vấn', () => {
  expect(toInt('')).toBeNull()
  expect(toInt('12')).toBe(12)
  expect(toInt('abc')).toBeNull()
  expect(pageCount(0, 15)).toBe(1)
  expect(pageCount(31, 15)).toBe(3)
  expect(qs({ q: '', page: 2, level: null, category: 'book' })).toBe('?page=2&category=book')
  expect(qs({})).toBe('')
})
