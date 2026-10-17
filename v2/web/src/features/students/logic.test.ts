import { describe, expect, it } from 'vitest'
import { listQuery, type StudentProfile } from './api'
import { formatDate, formatDateTime, genderOptions, pageCount, parseStudentPath, statusClass, toInput, toggle } from './logic'

describe('listQuery', () => {
  it('bỏ tham số trống, lặp lại statuses', () => {
    expect(listQuery({})).toBe('')
    expect(listQuery({ q: '  ', statuses: [], page: 1 })).toBe('')
    expect(listQuery({ q: ' An ', statuses: ['studying', 'deferred'], tenure: '3', sort: 'tenure', page: 2, per_page: 50 })).toBe(
      '?q=An&statuses=studying&statuses=deferred&tenure=3&sort=tenure&page=2&per_page=50',
    )
    expect(listQuery({ without_class: true, branch_id: 'b1' })).toBe('?branch_id=b1&without_class=1')
  })
})

describe('parseStudentPath', () => {
  it('phân biệt danh sách, xếp lớp, hồ sơ, hồ sơ theo phân quyền', () => {
    expect(parseStudentPath('/students')).toEqual({ page: 'list' })
    expect(parseStudentPath('/students/')).toEqual({ page: 'list' })
    expect(parseStudentPath('/students/enrollments')).toEqual({ page: 'enrollments' })
    expect(parseStudentPath('/students/HV-00012')).toEqual({ page: 'profile', id: 'HV-00012', scoped: false })
    expect(parseStudentPath('/students/abc/scoped')).toEqual({ page: 'profile', id: 'abc', scoped: true })
  })
})

describe('định dạng', () => {
  it('ngày dd/mm/yyyy', () => {
    expect(formatDate('2015-03-02')).toBe('02/03/2015')
    expect(formatDate(null)).toBeNull()
    expect(formatDate('sai')).toBeNull()
  })
  it('thời điểm hôm nay / ngày khác', () => {
    const now = new Date(2026, 9, 16, 15, 0)
    expect(formatDateTime(new Date(2026, 9, 16, 9, 5).toISOString(), now)).toBe('Hôm nay, 09:05')
    expect(formatDateTime(new Date(2026, 0, 2, 18, 30).toISOString(), now)).toBe('02/01/2026 18:30')
  })
  it('màu trạng thái và số trang', () => {
    expect(statusClass('warning')).toContain('warning')
    expect(statusClass('lạ')).toContain('surface-container-high')
    expect(pageCount(0, 20)).toBe(1)
    expect(pageCount(41, 20)).toBe(3)
  })
})

describe('form', () => {
  it('chip trạng thái bật / tắt', () => {
    expect(toggle(['a'], 'b')).toEqual(['a', 'b'])
    expect(toggle(['a', 'b'], 'a')).toEqual(['b'])
  })
  it('giới tính cũ ngoài danh sách vẫn giữ', () => {
    expect(genderOptions('Nữ')).toEqual(['Nam', 'Nữ', 'Khác'])
    expect(genderOptions('nam giới')).toEqual(['Nam', 'Nữ', 'Khác', 'nam giới'])
  })
  it('hồ sơ → form, null thành chuỗi trống, giữ phiên bản', () => {
    const p = { full_name: 'An', phone: null, email: 'a@b.vn', notes: null, version: 4 } as unknown as StudentProfile
    const f = toInput(p)
    expect(f.full_name).toBe('An')
    expect(f.phone).toBe('')
    expect(f.email).toBe('a@b.vn')
    expect(f.notes).toBe('')
    expect(f.version).toBe(4)
  })
})
