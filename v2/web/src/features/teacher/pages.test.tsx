import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import type { Home, RemarksPage, SubmissionsPage } from './api'
import { TeacherClassPage } from './ClassPage'
import { HomePage } from './HomePage'
import { SubmissionsPage as Submissions } from './SubmissionsPage'

afterEach(() => {
  vi.restoreAllMocks()
  window.history.pushState(null, '', '/')
})

const CID = '01a14927-575e-7521-bacc-953d164a3c02'

const shift = {
  session_id: 's0',
  class_id: CID,
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
  checkin: { deadline: '18:00 20/10', deadline_iso: '', remaining_seconds: 30000, expired: false, warning: false },
  is_today: true,
  date_label: '19/10',
  attendance_done: false,
  student_count: 12,
  trial_count: 1,
}

const home = (canCheckin: boolean): Home => ({
  teacher_name: 'Cô Hạnh',
  today_label: 'Thứ Hai, 19/10',
  stats: { total: 1, checked_in: 0, attendance_done: 0, pending: 1 },
  next_shift: { class_id: CID, session_id: 's0', start_time: '18:00', upcoming: true },
  shifts: [shift],
  pending_sessions: [{ id: 'sp', class_id: CID, class_name: 'Lớp Một', date: '16/10/2026', time: '18:00-19:30', type_label: '' }],
  week: { label: '19/10 – 25/10/2026', start: '2026-10-19', prev: '2026-10-12', next: '2026-10-26' },
  week_days: [],
  widgets: {
    attention: [{ id: 'm1', student_name: 'Bé Bình', class_name: 'Lớp Một', name: 'Mini Test', score: '5/10' }],
    work_pending: canCheckin ? '' : 'Chờ phân hệ Lương & chấm công chuyển sang hệ thống mới',
    estimate: canCheckin ? 1500000 : null,
    hours_label: '3',
    month: 10,
    today_dm: '19/10',
    period: '10/2026',
    month_end_dm: '31/10',
    timesheets_total: 2,
    timesheets_pending: 1,
    violations_count: 0,
    latest_violation: null,
  },
  warning_minutes: 15,
  can_checkin: canCheckin,
})

const remarks: RemarksPage = {
  classroom: { id: CID, name: 'Lớp Một', code: 'L1', branch_name: 'CN A' },
  session: { id: 's0', label: 'Buổi 3: 19/10/2026 · 18:00-19:30' },
  record_state: null,
  block_reason: '',
  students: [
    { id: 'st1', name: 'Bé An', att_status: 'present', absent: false, remark: { monsters_group: '', monsters_bonus: '', grammar: '', attitude: '', result: '', comment: '' } },
  ],
  recent_sessions: [{ id: 's0', label: '19/10/2026 · 18:00-19:30', disabled: false }],
  trial_guests: { scope: 'upcoming', items: [{ id: 'g1', name: 'Khách Minh', test_score: '6.5', status_label: 'Đã hẹn', notes: '' }] },
}

const subs: SubmissionsPage = {
  current_class: { id: CID, name: 'Lớp Một', code: 'L1' },
  classes: [{ id: CID, name: 'Lớp Một', code: 'L1' }],
  active_tab: 'video',
  tabs: [
    { value: 'video', label: 'Quay video' },
    { value: 'pronunciation', label: 'Phát âm' },
  ],
  submissions: [
    {
      id: 'sb1',
      status: 'submitted',
      student_name: 'Bé An',
      submitted_at: '10:00, 19/10/2026',
      notes: 'Con đã quay',
      audio_url: '',
      unit_title: '',
      attachment_url: '/api/v1/files/f1',
      attachment_name: 'video.mp4',
      homework_label: 'Quay video',
      score: '',
      feedback: '',
    },
  ],
  pronunciation_pending: true,
}

function mockApi(routes: Record<string, { status?: number; data: unknown }>) {
  const calls: { key: string; body: unknown }[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init = {}) => {
    const key = `${init.method ?? 'GET'} ${String(input).replace('/api/v1', '')}`
    calls.push({ key, body: init.body ? JSON.parse(String(init.body)) : undefined })
    const hit = routes[key]
    if (hit) return new Response(JSON.stringify(hit.data), { status: hit.status ?? 200 })
    return new Response(JSON.stringify({ error: 'not_found', message: 'Không tìm thấy.' }), { status: 404 })
  })
  return calls
}

function renderAt(path: string, node: ReactNode) {
  window.history.pushState(null, '', path)
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}>{node}</QueryClientProvider>)
}

test('trang chủ: chọn ca rồi check-in', async () => {
  const calls = mockApi({
    'GET /teacher/home': { data: home(true) },
    'POST /teacher/checkin': { data: { message: 'Đã check-in thành công 1 ca dạy hôm nay!', count: 1 } },
  })
  renderAt('/teacher/home', <HomePage />)
  expect(await screen.findByText('Lịch dạy hôm nay — Thứ Hai, 19/10')).toBeInTheDocument()
  expect(screen.getByText('Ca dạy lúc 18:00 sắp bắt đầu!')).toBeInTheDocument()
  expect(screen.getByText(/\+1 khách học thử/)).toBeInTheDocument()
  expect(screen.getByText('Bé Bình')).toBeInTheDocument()
  expect(screen.getByText('1.500.000 đ')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: /Điểm danh bù/ })).toHaveAttribute('href', `/classes/${CID}/attendance?session=sp`)
  const submit = screen.getByRole('button', { name: 'Check-in các ca đã chọn' })
  expect(submit).toBeDisabled()
  await userEvent.click(screen.getByLabelText('Chọn ca Lớp Một để check-in'))
  await userEvent.click(submit)
  expect(await screen.findByText('Đã check-in thành công 1 ca dạy hôm nay!')).toBeInTheDocument()
  expect(calls.find((c) => c.key === 'POST /teacher/checkin')?.body).toEqual({ session_ids: ['s0'] })
})

test('trang chủ: chưa có phân hệ chấm công thì không chọn ca check-in', async () => {
  mockApi({ 'GET /teacher/home': { data: home(false) } })
  renderAt('/teacher/home', <HomePage />)
  expect(await screen.findByText(/Vui lòng check-in trên hệ thống cũ/)).toBeInTheDocument()
  expect(screen.queryByLabelText('Chọn ca Lớp Một để check-in')).toBeNull()
  expect(screen.queryByRole('button', { name: 'Check-in các ca đã chọn' })).toBeNull()
})

test('nhận xét: lưu nháp gửi đúng buổi và học sinh', async () => {
  const calls = mockApi({
    [`GET /teacher/classes/${CID}/remarks?session=s0`]: { data: remarks },
    [`POST /teacher/classes/${CID}/remarks`]: { data: { session_id: 's0', message: 'Đã lưu nháp nhận xét (chưa hiển thị cho học viên).' } },
  })
  renderAt(`/teacher/classes/${CID}/remarks?session=s0`, <TeacherClassPage />)
  expect(await screen.findByText('Khách Minh')).toBeInTheDocument()
  await userEvent.type(screen.getByLabelText('Nhận xét chung Bé An'), 'Con phát biểu tốt')
  await userEvent.click(screen.getByRole('button', { name: 'Lưu nháp' }))
  expect(await screen.findByText('Đã lưu nháp nhận xét (chưa hiển thị cho học viên).')).toBeInTheDocument()
  const post = calls.find((c) => c.key.startsWith('POST'))?.body as { action: string; session_id: string; remarks: Record<string, Record<string, string>> }
  expect(post.action).toBe('draft')
  expect(post.session_id).toBe('s0')
  expect(post.remarks.st1.comment).toBe('Con phát biểu tốt')
})

test('chấm bài: lỗi máy chủ hiện trên màn, chấm xong báo thành công', async () => {
  let fail = true
  const bodies: unknown[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init = {}) => {
    const key = `${init.method ?? 'GET'} ${String(input).replace('/api/v1', '')}`
    if (key === 'GET /teacher/submissions') return new Response(JSON.stringify(subs))
    if (key === 'POST /teacher/submissions/sb1/mark') {
      bodies.push(JSON.parse(String(init.body)))
      if (fail) {
        fail = false
        return new Response(JSON.stringify({ error: 'invalid', message: 'Điểm không được vượt quá 20 ký tự.' }), { status: 422 })
      }
      return new Response(JSON.stringify({ message: 'Đã chấm điểm và gửi phản hồi bài nộp thành công!' }))
    }
    return new Response('{}', { status: 404 })
  })
  renderAt('/teacher/submissions', <Submissions />)
  expect(await screen.findByText('"Con đã quay"')).toBeInTheDocument()
  expect(screen.getByText('Danh sách đã nộp bài (1)')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'video.mp4' })).toHaveAttribute('href', '/api/v1/files/f1')
  expect(screen.queryByText(/Bài phát âm: chờ phân hệ/)).toBeNull()
  await userEvent.type(screen.getByLabelText('Điểm'), '9/10')
  await userEvent.type(screen.getByLabelText('Nhận xét'), 'Tốt')
  await userEvent.click(screen.getByRole('button', { name: /Đánh dấu đã xem/ }))
  expect(await screen.findByText('Điểm không được vượt quá 20 ký tự.')).toBeInTheDocument()
  await userEvent.click(screen.getByRole('button', { name: /Đánh dấu đã xem/ }))
  expect(await screen.findByText('Đã chấm điểm và gửi phản hồi bài nộp thành công!')).toBeInTheDocument()
  expect(bodies[1]).toEqual({ score: '9/10', feedback: 'Tốt' })
})

test('chấm bài: bài đã chấm chỉ hiện nhãn, tab phát âm bắt buộc điểm', async () => {
  const reviewed = { ...subs.submissions[0], id: 'sb2', status: 'reviewed' as const, score: '8' }
  const pron = {
    ...subs,
    active_tab: 'pronunciation',
    pronunciation_pending: false,
    submissions: [{ ...subs.submissions[0], id: 'pr1', audio_url: '/api/v1/files/a1', unit_title: 'Unit 1', attachment_url: '', homework_label: '' }],
  }
  mockApi({
    'GET /teacher/submissions?type=video': { data: { ...subs, submissions: [reviewed] } },
    'GET /teacher/submissions?type=pronunciation': { data: pron },
  })
  const first = renderAt('/teacher/submissions?type=video', <Submissions />)
  expect(await screen.findByText(/Đã chấm: 8/)).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Đánh dấu đã xem/ })).toBeNull()
  first.unmount()
  renderAt('/teacher/submissions?type=pronunciation', <Submissions />)
  expect(await screen.findByText('Unit 1')).toBeInTheDocument()
  expect(screen.getByLabelText('Điểm phát âm')).toBeRequired()
  expect(screen.getByRole('button', { name: 'Chấm' })).toBeInTheDocument()
})
