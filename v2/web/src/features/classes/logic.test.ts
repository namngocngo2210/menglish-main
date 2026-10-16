import { expect, test } from 'vitest'
import { addMinutes, dayType, emptyForm, formFromValues, missingNotes, parseClassPath, seatText, shiftsFor, splitTransfer, toInput } from './logic'

test('đường dẫn trang lớp', () => {
  expect(parseClassPath('/classes')).toEqual({ page: 'list' })
  expect(parseClassPath('/classes/')).toEqual({ page: 'list' })
  expect(parseClassPath('/classes/new')).toEqual({ page: 'new' })
  expect(parseClassPath('/classes/abc')).toEqual({ page: 'show', id: 'abc' })
  expect(parseClassPath('/classes/abc/edit')).toEqual({ page: 'edit', id: 'abc' })
  expect(parseClassPath('/classes/abc/attendance')).toEqual({ page: 'attendance', id: 'abc' })
})

test('sĩ số và giờ ca', () => {
  expect(seatText(12, 20)).toBe('12/20')
  expect(seatText(12, 0)).toBe('12')
  expect(addMinutes('18:00', 90)).toBe('19:30')
  expect(addMinutes('23:30', 90)).toBe('01:00')
  expect(addMinutes('x', 90)).toBe('')
  expect(dayType('Thứ 3')).toBe('weekday')
  expect(dayType('Thứ 7')).toBe('saturday')
  expect(dayType('Chủ nhật')).toBe('sunday')
  const opt = { value: '18:00-19:30', label: 'Ca 1', name: 'Ca 1', start: '18:00', end: '19:30', variant: 'standard' }
  expect(shiftsFor({ weekday: [opt] }, 'Thứ 2')).toEqual([opt])
  expect(shiftsFor({ weekday: [opt] }, '')).toEqual([])
  expect(shiftsFor({ weekday: [opt] }, 'Chủ nhật')).toEqual([])
})

test('ghi chú bắt buộc khi nghỉ', () => {
  expect(
    missingNotes([
      { student_id: 'a', status: 'absent', note: ' ' },
      { student_id: 'b', status: 'excused', note: 'Ốm' },
      { student_id: 'c', status: 'present', note: '' },
      { student_id: 'd', status: 'excused', note: '' },
    ]),
  ).toBe(2)
})

test('dữ liệu form lớp', () => {
  const create = toInput({ ...emptyForm, name: ' Lớp A ', branch_id: 'b1', program: 'IELTS', level: 'B1', tuition_fee: '1.500.000' }, false)
  expect(create).toMatchObject({ name: 'Lớp A', max_capacity: 20, min_students: 6, tuition_fee: 1500000, room_id: '' })
  expect(create).not.toHaveProperty('status')
  const f = formFromValues({
    name: 'Lớp A', code: 'LA', branch_id: 'b1', program: 'IELTS', level: 'B1', max_capacity: 12, min_students: 5, room_id: null,
    teacher_id: 't1', foreign_teacher_id: null, assistant_id: null, tuition_fee: null, notes: '', status: 'active',
    start_date: null, end_date: null, schedule_text: '',
  })
  expect(toInput(f, true)).toMatchObject({ max_capacity: 12, teacher_id: 't1', status: 'active', assistant_id: '', tuition_fee: null })
})

test('tách câu hỏi chuyển phòng', () => {
  expect(splitTransfer('P1 đang được gán cho lớp X. Chuyển phòng?\nX — dự kiến Thứ 2: sẽ bị hủy gán phòng')).toEqual({
    question: 'P1 đang được gán cho lớp X. Chuyển phòng?',
    lines: ['X — dự kiến Thứ 2: sẽ bị hủy gán phòng'],
  })
})
