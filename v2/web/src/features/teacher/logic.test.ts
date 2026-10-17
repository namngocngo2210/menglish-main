import { describe, expect, it } from 'vitest'
import type { Shift } from './api'
import {
  attLabel,
  checkinState,
  classRoute,
  countdown,
  editHomeworkForm,
  emptyHomeworkForm,
  homeworkInput,
  initials,
  overMax,
  reviewedLabel,
  SUBMISSION_TAB_ICONS,
  scorePreview,
  selectable,
  submissionsHref,
  toggleCategory,
} from './logic'

describe('màn chấm bài nộp', () => {
  it('đường dẫn lớp + tab', () => {
    expect(submissionsHref(undefined, '')).toBe('/teacher/submissions')
    expect(submissionsHref('c1', 'pronunciation')).toBe('/teacher/submissions?class_id=c1&type=pronunciation')
    expect(submissionsHref(undefined, 'quiz')).toBe('/teacher/submissions?type=quiz')
  })
  it('chữ đầu tên, nhãn đã chấm, biểu tượng tab', () => {
    expect(initials('Bé An')).toBe('Bé')
    expect(initials('')).toBe('HV')
    expect(reviewedLabel('9/10')).toBe('Đã chấm: 9/10')
    expect(reviewedLabel(' ')).toBe('Đã xem')
    expect(SUBMISSION_TAB_ICONS.pronunciation).toBe('mic')
  })
})

const shift = (over: Partial<Shift> = {}): Shift => ({
  session_id: 's1',
  class_id: 'c1',
  class_name: 'Lớp Một',
  class_code: 'L1',
  branch_name: 'CN A',
  room_label: 'Phòng P101',
  type_label: '',
  is_support: false,
  scheduled_time: '18:00 - 19:30',
  shift_name: 'Ca 1',
  checked_in: false,
  checkin_time: '',
  checkin: { deadline: '18:00 20/10', deadline_iso: '', remaining_seconds: 3600, expired: false, warning: false },
  is_today: true,
  date_label: '19/10',
  attendance_done: false,
  student_count: 10,
  trial_count: 0,
  ...over,
})

describe('check-in', () => {
  it('đếm ngược', () => {
    expect(countdown(2 * 3600 + 5 * 60 + 30)).toBe('2 giờ 5 phút')
    expect(countdown(12 * 60)).toBe('12 phút')
  })
  it('hạn check-in theo thời gian đã trôi', () => {
    expect(checkinState(shift(), 0)).toEqual({ expired: false, warning: false, left: 3600 })
    expect(checkinState(shift(), (3600 - 600) * 1000)).toEqual({ expired: false, warning: true, left: 600 })
    expect(checkinState(shift(), 3600 * 1000)?.expired).toBe(true)
    expect(checkinState(shift({ checkin: { ...shift().checkin, expired: true } }), 0)?.expired).toBe(true)
    expect(checkinState(shift({ checked_in: true }), 0)).toBeNull()
  })
  it('chỉ chọn được ca chưa check-in, còn hạn, khi đã có phân hệ chấm công', () => {
    expect(selectable(shift(), 0, true)).toBe(true)
    expect(selectable(shift(), 0, false)).toBe(false)
    expect(selectable(shift({ checked_in: true }), 0, true)).toBe(false)
    expect(selectable(shift(), 4000 * 1000, true)).toBe(false)
  })
})

describe('bài tập', () => {
  it('bật / tắt hạng mục, hạng mục khóa không tắt được', () => {
    let f = emptyHomeworkForm('s1', '2026-10-22T20:00')
    f = toggleCategory(f, 'video')
    f = toggleCategory(f, 'quiz')
    expect(f.categories).toEqual(['video', 'quiz'])
    expect(toggleCategory(f, 'video', ['video']).categories).toEqual(['video', 'quiz'])
    expect(toggleCategory(f, 'quiz').categories).toEqual(['video'])
  })
  it('chỉ gửi yêu cầu của hạng mục đang chọn', () => {
    const f = { ...emptyHomeworkForm(null, ''), categories: ['video'], items: { video: '  Quay bài 3 ', quiz: 'bỏ' }, youtube_url: ' https://youtu.be/x ' }
    expect(homeworkInput(f)).toEqual({
      session_id: null,
      due_at: '',
      class_note: '',
      youtube_url: 'https://youtu.be/x',
      quizizz_url: '',
      audio_file_id: null,
      categories: ['video'],
      items: { video: 'Quay bài 3' },
    })
  })
  it('mở bài đang sửa', () => {
    const f = editHomeworkForm({
      id: 'h1',
      session_id: 's1',
      due_at: '2026-10-22T20:00',
      class_note: 'n',
      youtube_url: '',
      quizizz_url: '',
      has_audio: true,
      items: { workbook: 'Trang 12' },
      locked: [],
    })
    expect(f.categories).toEqual(['workbook'])
    expect(f.session_id).toBe('s1')
    expect(f.audio_file_id).toBeNull()
  })
})

describe('mini test', () => {
  const keys = ['listening', 'speaking', 'reading', 'writing']
  it('điểm tổng = trung bình 4 kỹ năng', () => {
    expect(scorePreview({ listening: '6', speaking: '7', reading: '5', writing: '6' }, keys)).toBe('6')
    expect(scorePreview({ listening: '8', speaking: '8,5', reading: '9', writing: '8.5' }, keys)).toBe('8.5')
    expect(scorePreview({ listening: '6', speaking: '', reading: '5', writing: '6' }, keys)).toBe('')
    expect(scorePreview({ listening: 'a', speaking: '1', reading: '5', writing: '6' }, keys)).toBe('')
  })
  it('vượt điểm tối đa', () => {
    expect(overMax({ listening: '11' }, '10')).toBe(true)
    expect(overMax({ listening: '10' }, '')).toBe(false)
    expect(overMax({ listening: '15' }, '20')).toBe(false)
  })
})

describe('khác', () => {
  it('đường dẫn trang lớp', () => {
    const id = '01a14927-575e-7521-bacc-953d164a3c02'
    expect(classRoute(`/teacher/classes/${id}/homework`)).toEqual({ id, tab: 'homework' })
    expect(classRoute(`/teacher/classes/${id}/remarks/`)).toEqual({ id, tab: 'remarks' })
    expect(classRoute(`/teacher/classes/${id}/order-test`)).toBeNull()
    expect(classRoute('/teacher/classes/abc/scores')).toBeNull()
  })
  it('nhãn điểm danh', () => {
    expect(attLabel('excused')).toBe('Vắng có phép')
    expect(attLabel('none')).toBe('Chưa điểm danh')
  })
})
