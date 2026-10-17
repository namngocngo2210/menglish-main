import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import type { Me } from '../../api'
import { ChecklistPage } from './ChecklistPage'
import { Redirect } from './components'
import { OperationsPage } from './OperationsPage'
import { ProjectsPage } from './ProjectsPage'

afterEach(() => {
  vi.restoreAllMocks()
  window.history.pushState(null, '', '/')
})

type Route = (body: unknown) => [number, unknown?]

function mockApi(routes: Record<string, Route>) {
  const calls: { key: string; body: unknown }[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init = {}) => {
    const key = `${init.method ?? 'GET'} ${String(input).replace('/api/v1', '').split('?')[0]}`
    const body = init.body ? JSON.parse(String(init.body)) : undefined
    calls.push({ key, body })
    const route = routes[key]
    if (!route) return new Response(JSON.stringify({ error: 'not_found', message: key }), { status: 404 })
    const [status, data] = route(body)
    return new Response(data === undefined ? null : JSON.stringify(data), { status })
  })
  return calls
}

function wrap(node: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}>{node}</QueryClientProvider>)
}

const me = {
  user: { id: 'u1', kind: 'staff', code: 'QA01', email: null, phone: null, full_name: 'QA Hà', must_change_password: false, totp_enabled: true },
  csrf_token: 'c',
  totp_required: true,
  roles: [],
  permissions: ['class_quality.view', 'class_quality.observe_operations'],
  scopes: {},
  branches: [],
} as Me

const operations = {
  observations: [
    {
      id: 'o1',
      observed_on: '2026-10-10',
      teacher_id: 't1',
      teacher: 'Cô Lan',
      teacher_code: 'GV01',
      class_id: 'c1',
      class: 'IELTS 1',
      branch: 'Cầu Giấy',
      observer: 'QA Hà',
      rating: 'good',
      rating_label: 'Tốt',
      rating_color: 'success',
      notes: { lesson_content: 'Bài 5', attitude: null, preparation: null, technique: null, suggestions: null },
    },
  ],
  meta: { page: 1, per_page: 20, total: 1 },
  classes: [
    { value: 'c1', label: 'IELTS 1', teacher_ids: ['t1'] },
    { value: 'c2', label: 'KIDS 2', teacher_ids: ['t2'] },
  ],
  teachers: [
    { value: 't1', label: 'Cô Lan' },
    { value: 't2', label: 'Thầy Minh' },
  ],
  filter_teachers: [{ value: 't1', label: 'Cô Lan' }],
  ratings: [
    { value: 'good', label: 'Tốt' },
    { value: 'average', label: 'Trung bình' },
  ],
  note_fields: [
    { key: 'lesson_content', label: 'Nội dung bài giảng' },
    { key: 'attitude', label: 'Thái độ' },
  ],
  can_record: true,
  today: '2026-10-17',
}

test('dự giờ vận hành: xem chi tiết, ghi nhận mới — lớp lọc theo giáo viên', async () => {
  const calls = mockApi({
    'GET /class-quality/operations': () => [200, operations],
    'POST /class-quality/operations': () => [201, { message: 'Đã ghi nhận lượt dự giờ.' }],
  })
  wrap(<OperationsPage me={me} />)
  await userEvent.click(await screen.findByRole('button', { name: 'Xem' }))
  const view = screen.getByRole('dialog', { name: 'Chi tiết lượt dự giờ' })
  expect(within(view).getByText('Bài 5')).toBeTruthy()
  expect(within(view).queryByText('Thái độ')).toBeNull()
  await userEvent.click(within(view).getAllByRole('button', { name: 'Đóng' })[1])
  expect(screen.queryByRole('dialog')).toBeNull()

  await userEvent.click(screen.getByRole('button', { name: 'Ghi nhận dự giờ mới' }))
  const form = screen.getByRole('dialog', { name: 'Ghi nhận lượt dự giờ mới' })
  expect(within(form).getByRole('option', { name: 'Chọn giáo viên trước' })).toBeTruthy()
  await userEvent.selectOptions(within(form).getByLabelText('Giáo viên'), 't2')
  // Chỉ một lớp của giáo viên → tự chọn.
  expect((within(form).getByLabelText('Lớp') as HTMLSelectElement).value).toBe('c2')
  expect(within(form).queryByRole('option', { name: 'IELTS 1' })).toBeNull()
  await userEvent.type(within(form).getByLabelText('Thái độ'), 'Nhiệt tình')
  await userEvent.selectOptions(within(form).getByLabelText('Xếp loại'), 'average')
  await userEvent.click(within(form).getByRole('button', { name: 'Lưu' }))
  expect(await screen.findByText('Đã ghi nhận lượt dự giờ.')).toBeTruthy()
  expect(calls.find((c) => c.key === 'POST /class-quality/operations')?.body).toEqual({
    observed_on: '2026-10-17',
    teacher_id: 't2',
    class_id: 'c2',
    rating: 'average',
    lesson_content: null,
    attitude: 'Nhiệt tình',
    preparation: null,
    technique: null,
    suggestions: null,
  })
})

test('dự giờ vận hành: không có quyền ghi nhận thì ẩn nút thêm / sửa / xóa', async () => {
  mockApi({ 'GET /class-quality/operations': () => [200, { ...operations, can_record: false }] })
  wrap(<OperationsPage me={{ ...me, permissions: ['class_quality.view'] }} />)
  expect(await screen.findByText('Cô Lan', { selector: 'span' })).toBeTruthy()
  expect(screen.queryByRole('button', { name: 'Ghi nhận dự giờ mới' })).toBeNull()
  expect(screen.queryByRole('button', { name: /Xóa lượt dự giờ/ })).toBeNull()
})

test('checklist: đánh dấu thiếu sót và lưu cả trang', async () => {
  const page = {
    month: '2026-10',
    months: [{ value: '2026-10', label: 'Tháng 10/2026' }],
    classes: [
      {
        id: 'c1',
        code: 'IELTS1',
        name: 'IELTS 1',
        teacher: 'Cô Lan',
        values: {
          tuition_due: 'yes',
          tuition_reminded: 'yes',
          tuition_collected: 'yes',
          big_test_due: null,
          feedback_on_time: null,
          negative_feedback_handled: null,
        },
        tuition_note: null,
        gaps: [],
      },
    ],
    meta: { page: 1, per_page: 20, total: 1 },
    items: [
      { key: 'tuition_due', label: 'Đến hạn học phí' },
      { key: 'tuition_reminded', label: 'Đã nhắc học phí' },
      { key: 'tuition_collected', label: 'Đã thu học phí' },
      { key: 'big_test_due', label: 'Đến hạn Big Test' },
      { key: 'feedback_on_time', label: 'Feedback đúng hạn' },
      { key: 'negative_feedback_handled', label: 'Xử lý feedback tiêu cực' },
    ],
    follow_ups: { tuition_due: ['tuition_reminded', 'tuition_collected'], big_test_due: ['feedback_on_time'] },
    answers: [
      { key: 'yes', label: 'Có' },
      { key: 'no', label: 'Không' },
      { key: 'na', label: 'N-A' },
    ],
    can_edit: true,
  }
  const calls = mockApi({
    'GET /class-quality/checklist': () => [200, page],
    'PUT /class-quality/checklist': () => [200, { message: 'Đã lưu checklist 1 lớp.' }],
  })
  const { container } = wrap(<ChecklistPage me={me} />)
  const group = await screen.findByRole('radiogroup', { name: 'Đã thu học phí — IELTS1' })
  expect(container.querySelector('tr.bg-error\\/5')).toBeNull()
  await userEvent.click(within(group).getByLabelText('Không'))
  expect(container.querySelector('tr.bg-error\\/5')).toBeTruthy()
  await userEvent.click(screen.getByRole('button', { name: 'Lưu checklist' }))
  expect(await screen.findByText('Đã lưu checklist 1 lớp.')).toBeTruthy()
  const body = calls.find((c) => c.key === 'PUT /class-quality/checklist')?.body as { month: string; rows: Record<string, unknown>[] }
  expect(body.month).toBe('2026-10')
  expect(body.rows[0]).toMatchObject({ class_id: 'c1', tuition_collected: 'no', tuition_note: null })
})

const project = {
  id: 'p1',
  code: 'DA-2026-001',
  name: 'Sách Tiếng Anh 6',
  type: 'book',
  type_label: 'Soạn sách / giáo trình',
  status: 'active',
  status_label: 'Đang thực hiện',
  status_color: 'primary',
  owner: 'Trưởng học thuật',
  start_date: '2026-10-01',
  deadline: '2026-12-31',
  progress: 25,
  milestones_total: 1,
  milestones_done: 0,
  milestones_overdue: 0,
  next_milestone: { title: 'Unit 1', due_date: '2026-10-20' },
  members_count: 2,
  description: null,
  owner_id: 'lead',
  member_ids: ['u1'],
  members: [{ id: 'u1', name: 'QA Hà' }],
  kickoff_notes: null,
  kickoff_link: null,
  plan_locked_at: '2026-10-02T02:00:00Z',
  locker: 'Trưởng học thuật',
  creator: 'Trưởng học thuật',
  completed_at: null,
}
const milestone = {
  id: 'm1',
  title: 'Unit 1',
  description: null,
  assignee_id: 'u1',
  assignee: 'QA Hà',
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
  deadline_state: 'soon',
  mine: true,
  can_update: true,
}
const detail = {
  project,
  milestones: [milestone],
  updates: [],
  penalties: [],
  penalties_pending: true,
  staff: [{ value: 'u1', label: 'QA Hà' }],
  types: [{ value: 'book', label: 'Soạn sách / giáo trình' }],
  milestone_statuses: [{ value: 'todo', label: 'Chưa bắt đầu' }],
  can_manage: false,
  can_update: true,
  can_respond: false,
  grace_hours: 0,
  sla_enabled: true,
}

test('dự án: thành viên cập nhật tiến độ mốc của mình, không thấy nút quản lý', async () => {
  window.history.pushState(null, '', '/academic-projects/p1')
  const calls = mockApi({
    'GET /academic-projects/p1': () => [200, detail],
    'POST /academic-projects/p1/updates': () => [201, { message: 'Đã cập nhật và đánh dấu hoàn thành mốc.' }],
  })
  wrap(<ProjectsPage me={{ ...me, permissions: ['academic_project.view'] }} />)
  expect(await screen.findByRole('heading', { name: 'Sách Tiếng Anh 6' })).toBeTruthy()
  expect(screen.getByText('Việc của bạn')).toBeTruthy()
  expect(screen.getByText('Sắp đến hạn')).toBeTruthy()
  expect(screen.queryByRole('button', { name: /Sửa dự án/ })).toBeNull()
  expect(screen.queryByRole('button', { name: /Hủy dự án/ })).toBeNull()

  await userEvent.click(screen.getByRole('button', { name: 'Cập nhật' }))
  const form = screen.getByRole('dialog', { name: 'Cập nhật tiến độ' })
  const qty = within(form).getByLabelText('Khối lượng đã xong đến nay') as HTMLInputElement
  expect(qty.value).toBe('3')
  expect(within(form).getByText('Cần làm 12 unit')).toBeTruthy()
  await userEvent.clear(qty)
  await userEvent.type(qty, '12')
  await userEvent.click(within(form).getByLabelText('Đã hoàn thành mốc này'))
  await userEvent.type(within(form).getByLabelText('Đã làm được gì'), 'Xong Unit 1')
  await userEvent.click(within(form).getByRole('button', { name: 'Gửi cập nhật' }))
  expect(await screen.findByText('Đã cập nhật và đánh dấu hoàn thành mốc.')).toBeTruthy()
  expect(calls.find((c) => c.key === 'POST /academic-projects/p1/updates')?.body).toEqual({
    milestone_id: 'm1',
    quantity_done: '12',
    content: 'Xong Unit 1',
    difficulties: '',
    links_text: '',
    marks_complete: true,
  })
})

test('dự án: người quản lý tạm dừng có xác nhận', async () => {
  window.history.pushState(null, '', '/academic-projects/p1')
  const calls = mockApi({
    'GET /academic-projects/p1': () => [200, { ...detail, can_manage: true, can_respond: true }],
    'POST /academic-projects/p1/status': () => [200, { message: 'Đã tạm dừng dự án (mốc không bị tính trễ khi tạm dừng).' }],
  })
  wrap(<ProjectsPage me={{ ...me, permissions: ['academic_project.view', 'academic_project.manage'] }} />)
  await userEvent.click(await screen.findByRole('button', { name: 'Tạm dừng' }))
  const dialog = screen.getByRole('dialog', { name: 'Tạm dừng dự án' })
  await userEvent.click(within(dialog).getByRole('button', { name: 'Tạm dừng' }))
  expect(await screen.findByText('Đã tạm dừng dự án (mốc không bị tính trễ khi tạm dừng).')).toBeTruthy()
  expect(calls.find((c) => c.key === 'POST /academic-projects/p1/status')?.body).toEqual({ action: 'pause' })
})

test('URL cũ /classes/qa-observation chuyển sang màn mới, không thêm lịch sử', () => {
  window.history.pushState(null, '', '/classes/qa-observation')
  const before = window.history.length
  render(<Redirect to="/class-quality/operations" />)
  expect(window.location.pathname).toBe('/class-quality/operations')
  expect(window.history.length).toBe(before)
})
