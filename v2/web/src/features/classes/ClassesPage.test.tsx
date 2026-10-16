import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
import type { Me } from '../../api'
import { ClassesPage } from './ClassesPage'
import { ScheduleConfigPage } from './ScheduleConfigPage'

afterEach(() => {
  vi.restoreAllMocks()
  window.history.pushState(null, '', '/')
})

const me: Me = {
  user: { id: 'u1', kind: 'staff', code: 'HV001', email: null, phone: null, full_name: 'Học vụ', must_change_password: false, totp_enabled: true },
  csrf_token: 'c',
  totp_required: false,
  roles: [{ name: 'academic_staff', label: 'Học vụ', short_label: 'Học vụ' }],
  permissions: ['class.view', 'class.create', 'class.update', 'work_task.assign', 'attendance_student.record_any'],
  scopes: { class: 'all' },
  branches: [],
}

const seat = { occupied: 3, capacity: 10, left: 7, min: 6, needed: 3, not_started: true }
const list = {
  classes: [
    {
      id: 'c1', code: 'IELTS-01', name: 'IELTS tối', status: { key: 'pending_schedule', label: 'Chờ lịch', color: 'warning' },
      meta: 'IELTS · B1 · Chi nhánh A', schedule_text: null, teacher: 'Cô Lan', foreign_teacher: null, seat, total_sessions: 0,
      done_sessions: 0, next: { label: 'Cấu hình lịch học', tone: 'warning', tab: 'schedule' },
    },
  ],
  total: 1, page: 1, per_page: 20, branches: [{ value: 'b1', label: 'Chi nhánh A' }],
  statuses: [
    { key: 'pending_schedule', label: 'Chờ lịch', count: 1 },
    { key: 'active', label: 'Đang học', count: 0 },
  ],
  open_count: 1, program_counts: [{ label: 'IELTS', total: 1 }], level_counts: [],
  stats: { active: 0, pending_schedule: 1, short: 1, missing_attendance: 0 }, can_create: true,
}

const sheet = {
  classroom: { id: 'c1', name: 'IELTS tối', branch_name: 'Chi nhánh A' },
  window_hours: 24,
  session: { id: 's1', date: '19/10/2026', time: '18:00 - 19:30', room_label: 'P101', type: 'regular', is_past: false },
  students: [
    { id: 'st1', name: 'Nguyễn An', code: 'HV-1', linked: false, recorder_name: null, status: '', note: null },
    { id: 'st2', name: 'Trần Bình', code: 'HV-2', linked: true, recorder_name: null, status: '', note: null },
  ],
  block_reason: '', window_reason: '', on_behalf: false, on_behalf_name: null, recent_sessions: [], roster_size: 2,
  statuses: [
    { value: 'present', label: 'Có mặt' },
    { value: 'absent', label: 'Vắng' },
  ],
}

function mockApi(extra: (key: string) => { status: number; data: unknown } | null = () => null) {
  const calls: { key: string; body: unknown }[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init = {}) => {
    const key = `${init.method ?? 'GET'} ${String(input).replace('/api/v1', '')}`
    const body = init.body ? JSON.parse(String(init.body)) : undefined
    calls.push({ key, body })
    const hit = extra(key)
    if (hit) return new Response(JSON.stringify(hit.data), { status: hit.status })
    if (key.startsWith('GET /classes?') || key === 'GET /classes') return new Response(JSON.stringify(list))
    if (key === 'GET /class-attendance/c1') return new Response(JSON.stringify(sheet))
    if (key === 'POST /class-attendance/c1') return new Response(JSON.stringify({ message: 'Đã lưu điểm danh 2 học viên lớp IELTS tối buổi hôm nay.' }))
    return new Response(JSON.stringify({ error: 'not_found', message: 'Không tìm thấy.' }), { status: 404 })
  })
  return calls
}

function renderAt(path: string, node = <ClassesPage me={me} />) {
  window.history.pushState(null, '', path)
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}>{node}</QueryClientProvider>)
}

test('danh sách lớp: chip trạng thái gửi bộ lọc', async () => {
  const calls = mockApi()
  renderAt('/classes')
  expect(await screen.findByText('IELTS tối')).toBeInTheDocument()
  expect(screen.getByText('Cấu hình lịch học')).toBeInTheDocument()
  expect(screen.getByText('3/10')).toBeInTheDocument()
  await userEvent.click(screen.getByRole('button', { name: 'Đang học (0)' }))
  expect(calls.some((c) => c.key.includes('status=active'))).toBe(true)
  expect(screen.getByRole('button', { name: 'Tạo lớp mới' })).toBeInTheDocument()
})

test('điểm danh: bắt ghi lý do khi vắng rồi lưu', async () => {
  const calls = mockApi()
  renderAt('/classes/c1/attendance')
  expect(await screen.findByText('Nguyễn An')).toBeInTheDocument()
  const absent = screen.getAllByRole('radio', { name: 'Vắng' })[0]
  await userEvent.click(absent)
  expect(screen.getByText('Còn 1 học viên nghỉ chưa ghi lý do.')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Lưu điểm danh' })).toBeDisabled()
  await userEvent.type(screen.getByLabelText('Ghi chú Nguyễn An'), 'Ốm')
  await userEvent.click(screen.getByRole('button', { name: 'Lưu điểm danh' }))
  expect(await screen.findByText('Đã lưu điểm danh 2 học viên lớp IELTS tối buổi hôm nay.')).toBeInTheDocument()
  const post = calls.find((c) => c.key === 'POST /class-attendance/c1')
  expect(post?.body).toEqual({
    session_id: 's1',
    records: [
      { student_id: 'st1', status: 'absent', note: 'Ốm' },
      { student_id: 'st2', status: 'present', note: '' },
    ],
  })
})

test('Lịch & TKB: hỏi chuyển phòng rồi gửi xác nhận', async () => {
  const config = {
    can_schedule: true, can_toggle: true,
    rooms: [{ value: 'r1', label: 'P101 • Phòng học', name: 'P101', branch_id: 'b1', capacity: 20, classes: [] }],
    new_classes: [{ value: 'c1', label: 'IELTS tối (IELTS-01)' }], scheduled_classes: [],
    academic_years: [{ value: '2026 - 2027', label: 'Năm học 2026 - 2027' }],
    schedule_data: { c1: { academic_year: '', start_date: '', end_date: '', slot1_day: '', slot1_start: '', slot1_end: '', slot2_day: '', slot2_start: '', slot2_end: '', status: 'pending_schedule', branch_id: 'b1', room_id: '' } },
    initial: { class_id: 'c1', academic_year: '2026 - 2027', start_date: '2026-10-19', end_date: '2027-01-19', slot1_day: 'Thứ 2', slot1_start: '18:00', slot1_end: '19:30', slot2_day: '', slot2_start: '18:00', slot2_end: '19:30', room_id: '' },
    holiday_sessions: [], class_search: '', list_classes: [], weekdays: ['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ nhật'],
    shift_options: { weekday: [{ value: '18:00-19:30', label: 'Ca 1 (18:00–19:30)', name: 'Ca 1', start: '18:00', end: '19:30', variant: 'standard' }] },
    weekday_rule: 'Ca 1 (18:00–19:30)', shift_duration: 90, off_schedule_classes: [],
  }
  let asked = false
  const calls = mockApi((key) => {
    if (key.startsWith('GET /schedule-config')) return { status: 200, data: config }
    if (key === 'POST /schedule-config') {
      if (!asked) {
        asked = true
        return { status: 409, data: { error: 'room_transfer', message: 'P101 đang được gán cho lớp Lớp 4 (chưa bắt đầu học). Chuyển phòng sang lớp này?\nLớp 4 — dự kiến Thứ 2 18:00–19:30: sẽ bị hủy gán phòng' } }
      }
      return { status: 200, data: { message: 'Đã lưu lịch và tạo 13 buổi học thực tế.' } }
    }
    return null
  })
  renderAt('/schedule-config?class_id=c1', <ScheduleConfigPage />)
  await userEvent.selectOptions(await screen.findByLabelText('Phòng học'), 'r1')
  await userEvent.click(screen.getByRole('button', { name: 'Lưu lịch & tạo buổi học' }))
  expect(await screen.findByText('Lớp 4 — dự kiến Thứ 2 18:00–19:30: sẽ bị hủy gán phòng')).toBeInTheDocument()
  await userEvent.click(screen.getByRole('button', { name: 'Chuyển phòng' }))
  expect(await screen.findByText('Đã lưu lịch và tạo 13 buổi học thực tế.')).toBeInTheDocument()
  const posts = calls.filter((c) => c.key === 'POST /schedule-config').map((c) => c.body as { transfer_room: boolean; room_id: string; class_id: string })
  expect(posts.map((b) => b.transfer_room)).toEqual([false, true])
  expect(posts[1]).toMatchObject({ class_id: 'c1', room_id: 'r1', slot1_day: 'Thứ 2' })
})
