import { describe, expect, test } from 'vitest'
import type { AcademicClass, FormClass, Milestone } from './api'
import { projectIdOf } from './ProjectsPage'
import {
  academicForm,
  badgeTone,
  checklistGaps,
  classesForTeacher,
  daysLeft,
  deadlineHint,
  filledNotes,
  filterStaff,
  formatDate,
  formatDateTime,
  linkHost,
  meetingForm,
  milestoneForm,
  mondayOf,
  operationForm,
  pickClass,
  progressTone,
  query,
  rate,
  shortDateTime,
  targetOf,
  toAcademicInput,
  toMeetingInput,
  toMilestoneInput,
  toOperationInput,
  toProjectInput,
  toggleMember,
  updatableMilestones,
  weekLabel,
  weekRange,
  withCurrent,
  projectForm,
} from './logic'

describe('định dạng', () => {
  test('ngày, giờ Việt Nam, tỉ lệ', () => {
    expect(formatDate('2026-10-17')).toBe('17/10/2026')
    expect(formatDate(null)).toBe('—')
    expect(formatDateTime('2026-10-17T02:05:00Z')).toBe('09:05 17/10/2026')
    expect(shortDateTime('2026-10-17T17:30:00Z')).toBe('18/10 00:30')
    expect(rate(80)).toBe('80%')
    expect(rate(80.55)).toBe('80,6%')
    expect(rate(null)).toBe('—')
    expect(weekLabel('2026-10-12', '2026-10-18')).toBe('12/10 – 18/10/2026')
  })

  test('tuần chứa một ngày', () => {
    expect(mondayOf('2026-10-17')).toBe('2026-10-12')
    expect(mondayOf('2026-10-12')).toBe('2026-10-12')
    expect(mondayOf('2026-10-18')).toBe('2026-10-12')
    expect(mondayOf('sai')).toBe('')
    expect(weekRange('2026-10-14')).toBe('12/10/2026 - 18/10/2026')
    expect(weekRange('')).toBeNull()
  })

  test('màu Laravel → tông Badge', () => {
    expect(badgeTone('primary')).toBe('info')
    expect(badgeTone('secondary')).toBe('neutral')
    expect(badgeTone('error')).toBe('error')
    expect(badgeTone(undefined)).toBe('neutral')
  })

  test('query bỏ giá trị rỗng', () => {
    expect(query({ teacher_id: '', rating: 'good', page: 1 })).toBe('?rating=good&page=1')
    expect(query({ month: '', page: 0 })).toBe('')
  })
})

const classes: FormClass[] = [
  { value: 'c1', label: 'IELTS 1', teacher_ids: ['t1'] },
  { value: 'c2', label: 'IELTS 2', teacher_ids: ['t1', 't2'] },
  { value: 'c3', label: 'KIDS 1', teacher_ids: ['t3'] },
]

describe('dự giờ vận hành', () => {
  test('lớp lọc theo giáo viên, tự chọn khi chỉ còn một lớp', () => {
    expect(classesForTeacher(classes, 't1').map((c) => c.value)).toEqual(['c1', 'c2'])
    expect(classesForTeacher(classes, '')).toEqual([])
    expect(pickClass(classesForTeacher(classes, 't3'), 'c1')).toBe('c3')
    expect(pickClass(classesForTeacher(classes, 't1'), 'c2')).toBe('c2')
    expect(pickClass(classesForTeacher(classes, 't1'), 'c3')).toBe('')
  })

  test('giữ lựa chọn cũ không còn hoạt động', () => {
    const old = { value: 'c9', label: 'Lớp cũ', teacher_ids: ['t1'] }
    expect(withCurrent(classes, old)).toHaveLength(4)
    expect(withCurrent(classes, classes[0])).toHaveLength(3)
    expect(withCurrent(classes, null)).toBe(classes)
  })

  test('form ↔ API: mục nội dung rỗng gửi null', () => {
    const f = operationForm(null, '2026-10-17')
    expect(f.observed_on).toBe('2026-10-17')
    const input = toOperationInput({ ...f, teacher_id: 't1', class_id: 'c1', rating: 'good', notes: { attitude: '  ', technique: 'Tốt' } })
    expect(input).toMatchObject({ teacher_id: 't1', class_id: 'c1', rating: 'good', attitude: null, technique: 'Tốt', lesson_content: null })
  })

  test('mục đã ghi theo thứ tự hiển thị', () => {
    const fields = [
      { key: 'a', label: 'A' },
      { key: 'b', label: 'B' },
    ]
    expect(filledNotes({ a: '', b: 'x' }, fields).map((f) => f.key)).toEqual(['b'])
  })
})

describe('đánh giá dự giờ', () => {
  const c: AcademicClass = { id: 'c1', code: 'L1', name: 'Lớp 1', teacher: null, course: null, suggested_attendance: 92.5, evaluation: null }

  test('lớp chưa đánh giá: gợi ý chuyên cần, mặc định đã dự giờ, người dự giờ là mình', () => {
    const f = academicForm(c, 'u1')
    expect(f).toMatchObject({ attendance_rate: '92.5', observed: true, observer_id: 'u1' })
  })

  test('bỏ "Đã dự giờ" thì không gửi tiêu chí', () => {
    const f = { ...academicForm(c, 'u1'), observed: false, observed_on: '2026-10-10', criteria: { overall: 'Tốt' }, pass_rate: '75,5' }
    const input = toAcademicInput('c1', '2026-10', f)
    expect(input).toMatchObject({ observed: false, observed_on: null, observer_id: null, overall: null, pass_rate: 75.5, attendance_rate: 92.5 })
  })

  test('đã dự giờ gửi đủ tiêu chí', () => {
    const f = { ...academicForm(c, 'u1'), observed_on: '2026-10-10', criteria: { overall: 'Tốt', attitude: '' } }
    expect(toAcademicInput('c1', '2026-10', f)).toMatchObject({ observed: true, observed_on: '2026-10-10', overall: 'Tốt', attitude: null })
  })
})

test('checklist: mục đến hạn = Có mà việc cần làm = Không là thiếu sót', () => {
  const followUps = { tuition_due: ['tuition_reminded', 'tuition_collected'], big_test_due: ['feedback_on_time'] }
  expect(checklistGaps({ tuition_due: 'yes', tuition_reminded: 'no', tuition_collected: 'yes', big_test_due: 'no', feedback_on_time: 'no' }, followUps)).toEqual([
    'tuition_reminded',
  ])
  expect(checklistGaps({ tuition_due: 'na', tuition_reminded: 'no' }, followUps)).toEqual([])
})

test('họp giáo viên: mặc định tuần hiện tại, tình trạng đang xử lý; lớp trống gửi null', () => {
  const f = meetingForm(null, '2026-10-12')
  expect(f).toMatchObject({ week_start: '2026-10-12', status: 'in_progress', class_id: '' })
  expect(toMeetingInput({ ...f, teacher_id: 't1', class_note: 'Ổn' })).toMatchObject({ class_id: null, syllabus_note: null, class_note: 'Ổn' })
})

describe('dự án học thuật', () => {
  test('đường dẫn chi tiết', () => {
    expect(projectIdOf('/academic-projects/abc')).toBe('abc')
    expect(projectIdOf('/academic-projects/report')).toBeNull()
    expect(projectIdOf('/academic-projects')).toBeNull()
  })

  test('form dự án và thành viên', () => {
    const f = projectForm(null)
    expect(f.type).toBe('book')
    expect(toProjectInput({ ...f, name: 'Sách 6', owner_id: 'u1', kickoff_link: ' ' })).toMatchObject({ start_date: null, kickoff_link: null, member_ids: [] })
    expect(toggleMember(['a', 'b'], 'a')).toEqual(['b'])
    expect(toggleMember(['b'], 'a')).toEqual(['b', 'a'])
  })

  test('tìm nhân sự không phân biệt dấu', () => {
    const staff = [
      { value: '1', label: 'Nguyễn Thị Hằng' },
      { value: '2', label: 'Đỗ Minh' },
    ]
    expect(filterStaff(staff, 'hang').map((s) => s.value)).toEqual(['1'])
    expect(filterStaff(staff, 'do m').map((s) => s.value)).toEqual(['2'])
    expect(filterStaff(staff, '')).toHaveLength(2)
  })

  const m = (over: Partial<Milestone>): Milestone => ({
    id: 'm1',
    title: 'Unit 1',
    description: null,
    assignee_id: 'u1',
    assignee: 'A',
    start_date: null,
    due_date: '2026-10-20',
    target_quantity: 12,
    done_quantity: 3,
    quantity_label: '3 / 12 unit',
    unit: 'unit',
    status: 'in_progress',
    status_label: 'Đang làm',
    completed_at: null,
    progress: 25,
    deadline_state: null,
    mine: true,
    can_update: true,
    ...over,
  })

  test('form mốc: thêm mới không gửi trạng thái', () => {
    const f = milestoneForm(null)
    expect(f).toMatchObject({ target_quantity: '1', unit: 'unit', status: 'todo' })
    expect(toMilestoneInput({ ...f, title: 'X', due_date: '2026-11-01', target_quantity: '2,5' }, false)).toMatchObject({ status: '', target_quantity: 2.5, start_date: null })
    expect(toMilestoneInput(milestoneForm(m({})), true)).toMatchObject({ status: 'in_progress', target_quantity: 12 })
  })

  test('mốc cập nhật được và khối lượng cần làm', () => {
    const list = [m({}), m({ id: 'm2', status: 'done' }), m({ id: 'm3', can_update: false })]
    expect(updatableMilestones(list).map((x) => x.id)).toEqual(['m1'])
    expect(targetOf('3 / 12 unit')).toBe('12 unit')
  })

  test('hạn dự án và màu tiến độ', () => {
    const now = new Date('2026-10-17T03:00:00Z')
    expect(daysLeft('2026-10-20', now)).toBe(4)
    expect(daysLeft('2026-10-15', now)).toBe(-1)
    expect(daysLeft(null, now)).toBeNull()
    expect(deadlineHint(4, true)).toBe('Còn 4 ngày')
    expect(deadlineHint(-2, true)).toBe('Quá 2 ngày')
    expect(deadlineHint(-2, false)).toBeNull()
    expect(progressTone(100, true)).toBe('done')
    expect(progressTone(40, true)).toBe('overdue')
    expect(progressTone(40, false)).toBe('normal')
    expect(linkHost('https://www.drive.google.com/x')).toBe('drive.google.com')
    expect(linkHost('sai')).toBe('sai')
  })
})
