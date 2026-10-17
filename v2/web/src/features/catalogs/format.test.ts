import { expect, test } from 'vitest'
import { qs } from './api'
import {
  addMinutes,
  formatDate,
  formatDateRange,
  formatMoney,
  minutesToTime,
  moveItem,
  offsetLabel,
  slaValueLabel,
  splitEmails,
} from './format'

test('tiền theo kiểu Việt Nam', () => {
  expect(formatMoney(12500000)).toBe('12.500.000 đ')
  expect(formatMoney(0)).toBe('0 đ')
  expect(formatMoney(999)).toBe('999 đ')
  expect(formatMoney(-30000)).toBe('-30.000 đ')
})

test('ngày và khoảng ngày', () => {
  expect(formatDate('2026-04-30')).toBe('30/04/2026')
  expect(formatDateRange('2026-09-02', '2026-09-02')).toBe('02/09/2026')
  expect(formatDateRange('2026-04-30', '2026-05-01')).toBe('30/04/2026 – 01/05/2026')
})

test('gợi ý giờ kết thúc ca 90 phút', () => {
  expect(addMinutes('18:00', 90)).toBe('19:30')
  expect(addMinutes('7:45', 90)).toBe('09:15')
  expect(addMinutes('23:00', 90)).toBe('')
  expect(addMinutes('25:00', 90)).toBe('')
  expect(addMinutes('', 90)).toBe('')
})

test('giá trị SLA giống FormatSlaValue của Go', () => {
  expect(slaValueLabel(24, 'hours')).toBe('24 giờ')
  expect(slaValueLabel(3, 'days')).toBe('3 ngày')
  expect(slaValueLabel(3, 'count')).toBe('3 lần')
  expect(slaValueLabel(1140, 'time')).toBe('19:00')
  expect(slaValueLabel(5, 'day_of_month')).toBe('ngày 5')
  expect(minutesToTime(0)).toBe('00:00')
  expect(minutesToTime(2000)).toBe('23:59')
})

test('mốc nhắc nợ giống offsetLabel của Go', () => {
  expect(offsetLabel('before', 3)).toBe('3 ngày trước hạn')
  expect(offsetLabel('due', null)).toBe('Đúng ngày đến hạn')
  expect(offsetLabel('after', 5)).toBe('Quá hạn 5 ngày')
})

test('tách email, bỏ trùng không phân biệt hoa thường', () => {
  expect(splitEmails('a@x.vn, b@x.vn;\nA@x.vn\n\n c@x.vn ')).toEqual(['a@x.vn', 'b@x.vn', 'c@x.vn'])
  expect(splitEmails('')).toEqual([])
})

test('đổi chỗ khi sắp xếp, ngoài biên giữ nguyên', () => {
  expect(moveItem(['a', 'b', 'c'], 1, -1)).toEqual(['b', 'a', 'c'])
  expect(moveItem(['a', 'b', 'c'], 1, 1)).toEqual(['a', 'c', 'b'])
  const list = ['a', 'b']
  expect(moveItem(list, 0, -1)).toBe(list)
})

test('chuỗi truy vấn bỏ tham số rỗng', () => {
  expect(qs({ search: 'IELTS 6.5', status: '', page: 2, x: undefined })).toBe('?search=IELTS+6.5&page=2')
  expect(qs({ q: '' })).toBe('')
})
