import { expect, test } from 'vitest'
import type { Survey } from './api'
import { deleteMessage, emptyForm, formatAverage, formError, formOf, payloadOf, stars, surveyRoute } from './logic'

const sv: Survey = {
  id: '01900000-0000-7000-8000-000000000001',
  title: 'Cơ sở vật chất',
  description: null,
  deadline: '2026-10-20',
  deadline_label: '20/10/2026',
  deadline_today: false,
  is_active: false,
  creator_name: null,
  response_count: 0,
}

test('form tạo / sửa và dữ liệu gửi', () => {
  expect(formOf(sv)).toEqual({ title: 'Cơ sở vật chất', description: '', deadline: '2026-10-20', is_active: false })
  expect(payloadOf({ title: ' A ', description: '  ', deadline: '', is_active: true }, false)).toEqual({ title: 'A', description: null, deadline: null })
  expect(payloadOf({ ...formOf(sv), description: ' Mô tả ' }, true)).toEqual({
    title: 'Cơ sở vật chất',
    description: 'Mô tả',
    deadline: '2026-10-20',
    is_active: false,
  })
})

test('kiểm dữ liệu cùng câu chữ máy chủ', () => {
  const today = '2026-10-17'
  expect(formError(emptyForm(), today, false)).toBe('Vui lòng nhập Tiêu đề.')
  expect(formError({ ...emptyForm(), title: 'a'.repeat(256) }, today, false)).toBe('Trường Tiêu đề không được vượt quá 255 ký tự.')
  expect(formError({ ...emptyForm(), title: 'A', description: 'ạ'.repeat(2001) }, today, false)).toBe('Trường Mô tả chi tiết không được vượt quá 2000 ký tự.')
  expect(formError({ ...emptyForm(), title: 'A', deadline: '2026-10-16' }, today, false)).toBe(
    'Trường Hạn hoàn thành phải là thời gian bắt đầu sau hoặc đúng bằng hôm nay.',
  )
  // Sửa được đặt hạn đã qua
  expect(formError({ ...emptyForm(), title: 'A', deadline: '2026-10-16' }, today, true)).toBeNull()
  expect(formError({ ...emptyForm(), title: 'A', deadline: today }, today, false)).toBeNull()
})

test('đường dẫn, sao, điểm trung bình, câu xác nhận xóa', () => {
  expect(surveyRoute('/surveys')).toEqual({ kind: 'list' })
  expect(surveyRoute('/surveys/' + sv.id)).toEqual({ kind: 'responses', id: sv.id })
  expect(surveyRoute('/surveys/abc')).toEqual({ kind: 'list' })
  expect(stars(4)).toBe('★★★★☆')
  expect(stars(9)).toBe('★★★★★')
  expect(formatAverage(null)).toBe('—')
  expect(formatAverage(4.25)).toBe('4,3 / 5')
  expect(deleteMessage(sv)).toBe('Xóa khảo sát "Cơ sở vật chất"? Lịch sử đã nộp của học viên vẫn được giữ.')
})
