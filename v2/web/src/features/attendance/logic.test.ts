import { describe, expect, it } from 'vitest'
import type { Me } from '../../api'
import { workspaceTabs } from '../../nav'
import type { BranchInfo, Day, ManualForm, TimesheetRow } from './api'
import {
  actionLabel,
  blocker,
  classesOfBranch,
  clockText,
  dateLabel,
  dateText,
  expectedText,
  filterTeachers,
  inRange,
  lateNote,
  lockedRangeFor,
  manualDefaults,
  manualUrl,
  nextKind,
  num,
  roleRequests,
  selectableIds,
  shiftText,
  toManualInput,
  weekday,
} from './logic'
import { legacyMobileTarget } from './parts'

const branch: BranchInfo = { id: 'b1', name: 'Cầu Giấy', address: null, latitude: 21.03, longitude: 105.85, radius: 100, grace: 5, has_location: true }

const day = (over: Partial<Day> = {}): Day => ({
  id: 'a1',
  date: '2026-10-17',
  check_in: '08:12',
  check_out: null,
  check_in_photo: null,
  check_out_photo: null,
  check_in_distance: 20,
  expected_start: '08:00',
  expected_end: '17:00',
  late_minutes: 12,
  early_minutes: 0,
  late_excused: false,
  status_label: 'Đi muộn 12 phút',
  status_tone: 'warning',
  source: 'mobile',
  penalty: null,
  ...over,
})

const me = (permissions: string[], roles: string[] = []): Me =>
  ({
    user: { id: 'u1', kind: 'staff', code: 'NV01', email: null, phone: null, full_name: 'A', must_change_password: false, totp_enabled: true },
    csrf_token: 'c',
    totp_required: false,
    roles: roles.map((name) => ({ name, label: name, short_label: name })),
    permissions,
    scopes: {},
    branches: [],
  }) as Me

describe('chấm công của tôi', () => {
  it('đồng hồ và ngày theo giờ Việt Nam', () => {
    const ms = Date.UTC(2026, 9, 17, 1, 2, 3) // 08:02:03 giờ VN
    expect(clockText(ms)).toBe('08:02:03')
    expect(dateText(ms)).toBe('Thứ Bảy, 17/10/2026')
    expect(dateLabel('2026-10-05')).toBe('05/10/2026')
    expect(weekday('2026-10-18')).toBe('Chủ nhật')
    expect(num(12345)).toBe('12.345')
  })

  it('lý do không chấm được', () => {
    expect(blocker(null, null)).toContain('chưa được gán cơ sở')
    expect(blocker({ ...branch, has_location: false }, null)).toContain('Cơ sở Cầu Giấy chưa cài toạ độ')
    expect(blocker(branch, 'Kỳ lương đã khóa')).toBe('Kỳ lương đã khóa')
    expect(blocker(branch, null)).toBeNull()
  })

  it('nút chấm vào / ra', () => {
    expect(nextKind(null)).toBe('in')
    expect(actionLabel(null)).toBe('Chấm công vào')
    expect(actionLabel(day())).toBe('Chấm công ra')
    expect(actionLabel(day({ check_out: '17:05' }))).toBe('Chấm công ra lại')
  })

  it('giờ phải có mặt và bán kính', () => {
    expect(expectedText({ start: '08:00', end: '17:00', basis: 'Giờ hành chính', sessions: [] })).toBe('Giờ vào 08:00 · giờ ra 17:00 (giờ hành chính)')
    expect(expectedText({ start: null, end: null, basis: 'Không có ca dạy', sessions: [] })).toBe('Không có ca dạy hôm nay, không tính đi muộn')
    expect(inRange(100, branch)).toBe(true)
    expect(inRange(101, branch)).toBe(false)
    expect(inRange(null, branch)).toBe(false)
  })

  it('nhắc đi muộn', () => {
    expect(lateNote(day({ late_minutes: 0 }))).toBeNull()
    expect(lateNote(day({ late_excused: true }))).toBeNull()
    expect(lateNote(day())).toBe('Đi muộn 12 phút. Có lý do thì gửi đơn "Xin đi muộn / về sớm".')
    expect(lateNote(day({ penalty: { code: 'BB-01', status: 'pending' } }))).toContain('đã lập biên bản BB-01')
  })

  it('yêu cầu khác theo vai trò', () => {
    expect(roleRequests(me(['portal.staff']))).toEqual([])
    expect(roleRequests(me(['work_task.request'])).map((r) => r.path)).toEqual(['/tasks/new'])
  })

  it('URL cũ /m', () => {
    expect(legacyMobileTarget('/m')).toBe('/cham-cong')
    expect(legacyMobileTarget('/m/lich-su/')).toBe('/cham-cong/lich-su')
    expect(legacyMobileTarget('/m/xin-duyet')).toBe('/cham-cong/don')
  })
})

describe('menu Chấm công', () => {
  it('tab theo quyền như SidebarMenu', () => {
    const teacher = me(['portal.staff', 'portal.teacher', 'payroll.view_own'])
    expect(workspaceTabs(teacher, '/cham-cong')?.tabs.map((t) => t.label)).toEqual(['Chấm công của tôi', 'Lịch sử công của tôi', 'Giờ dạy giáo viên'])
    const sales = me(['portal.staff', 'payroll.view_own'])
    expect(workspaceTabs(sales, '/cham-cong')?.tabs.map((t) => t.label)).toEqual(['Chấm công của tôi', 'Lịch sử công của tôi'])
    const academic = me(['portal.staff', 'attendance_staff.view', 'attendance_staff.manual_record'])
    expect(workspaceTabs(academic, '/payroll/timesheets/manual')?.tabs.map((t) => t.label)).toEqual([
      'Chấm công của tôi',
      'Lịch sử công của tôi',
      'Giờ dạy giáo viên',
      'Chấm công đơn lẻ',
      'AppSheet',
    ])
    expect(workspaceTabs(sales, '/cham-cong/don')?.label).toBe('Xin duyệt')
  })
})

describe('giờ dạy giáo viên', () => {
  const form: ManualForm = {
    teachers: [
      { id: 't1', label: 'Nguyễn Văn An (GV01)', search: 'Nguyễn Văn An GV01 an@x.vn' },
      { id: 't2', label: 'Trần Bình (GV02)', search: 'Trần Bình GV02' },
    ],
    classes: [
      { id: 'c1', label: 'IELTS 1', branch: 'b1' },
      { id: 'c2', label: 'IELTS 2', branch: 'b2' },
    ],
    branches: [],
    locked_ranges: [{ from: '2026-09-01', to: '2026-09-30', title: 'Kỳ lương 09/2026' }],
    default_branch: 'b1',
    today: '2026-10-17',
    types: [],
  }

  it('tìm nhân sự không dấu, giữ người đang chọn', () => {
    expect(filterTeachers(form.teachers, 'nguyen an', '').map((t) => t.id)).toEqual([])
    expect(filterTeachers(form.teachers, 'van an', '').map((t) => t.id)).toEqual(['t1'])
    expect(filterTeachers(form.teachers, 'gv01', 't2').map((t) => t.id)).toEqual(['t1', 't2'])
    expect(filterTeachers(form.teachers, '', '')).toHaveLength(2)
    expect(classesOfBranch(form.classes, 'b2').map((c) => c.id)).toEqual(['c2'])
    expect(classesOfBranch(form.classes, '')).toHaveLength(2)
  })

  it('kỳ lương đã khóa', () => {
    expect(lockedRangeFor(form.locked_ranges, '2026-09-15')?.title).toBe('Kỳ lương 09/2026')
    expect(lockedRangeFor(form.locked_ranges, '2026-10-01')).toBeNull()
  })

  it('form chấm tay điền sẵn từ buổi thiếu chấm công', () => {
    const url = manualUrl('t1', { class_id: 'c1', branch_id: 'b1', date: '2026-10-10', start_time: '18:00', end_time: '19:30' })
    expect(url).toBe('/payroll/timesheets/manual?user_id=t1&class_id=c1&branch_id=b1&teaching_date=2026-10-10&time_in=18%3A00&time_out=19%3A30')
    const s = manualDefaults(form, new URL(url, 'http://x').searchParams)
    expect(s).toMatchObject({ user_id: 't1', class_id: 'c1', teaching_date: '2026-10-10', time_in: '18:00', time_out: '19:30' })
    expect(manualDefaults(form, new URLSearchParams())).toMatchObject({ branch_id: 'b1', teaching_date: '2026-10-17', type: 'regular' })
    expect(toManualInput({ ...s, hourly_rate: '', late_minutes: '5' })).toMatchObject({ hourly_rate: null, late_minutes: 5, early_leave_minutes: 0 })
  })

  it('ca chọn được để chốt, nhãn ca học', () => {
    const rows = [
      { id: 'x1', status: 'pending_review', locked: false },
      { id: 'x2', status: 'pending_review', locked: true },
      { id: 'x3', status: 'valid', locked: false },
    ] as TimesheetRow[]
    expect(selectableIds(rows)).toEqual(['x1'])
    expect(shiftText({ scheduled_time: null, shift_name: null })).toBe('Ngoài lịch')
    expect(shiftText({ scheduled_time: '18:00 - 19:30', shift_name: 'Ca 3' })).toBe('Ca 3 · 18:00 - 19:30')
    expect(shiftText({ scheduled_time: '18:00 - 19:30', shift_name: null })).toBe('Ca 18:00 - 19:30')
  })
})
