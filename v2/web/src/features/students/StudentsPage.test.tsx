import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
import type { Me } from '../../api'
import { StudentsPage } from './StudentsPage'

afterEach(() => {
  vi.restoreAllMocks()
  window.history.pushState(null, '', '/')
})

const me: Me = {
  user: { id: 'u1', kind: 'staff', code: 'HV001', email: null, phone: null, full_name: 'Học vụ', must_change_password: false, totp_enabled: true },
  csrf_token: 'c',
  totp_required: false,
  roles: [{ name: 'academic_staff', label: 'Học vụ', short_label: 'Học vụ' }],
  permissions: ['student.view', 'student.update', 'student.change_status', 'student.assign_class'],
  scopes: { student: 'branch' },
  branches: [],
}

const options = {
  branches: [{ id: 'b1', code: 'CN-A', name: 'Chi nhánh A' }],
  statuses: [
    { value: 'waiting_start', label: 'Chờ khai giảng', color: 'info' },
    { value: 'studying', label: 'Đang học', color: 'success' },
    { value: 'dropped', label: 'Thôi học', color: 'error' },
  ],
  tenures: [{ value: '3', label: 'Từ 3 năm trở lên' }],
  sorts: [{ value: 'tenure', label: 'Học lâu nhất' }],
}

const row = {
  id: 's1', code: 'HV-00001', full_name: 'Nguyễn An', dob: '2015-03-02', phone: '0912345678', email: null,
  branch_id: 'b1', branch_name: 'Chi nhánh A', current_class_id: null, status: 'studying', status_label: 'Đang học',
  study_started_on: '2022-01-10', tenure_label: '4 năm 9 tháng', long_term: true, created_at: '2026-10-01T00:00:00Z',
}

const profile = {
  ...row, age: 11, gender: 'Nam', address: null, parent_name: 'Nguyễn Hoa', parent_phone: '0987654321', school: null,
  target: null, entrance_score: 'Chưa test', notes: null, lead_id: null, deferred_until: null, updated_at: '2026-10-16T08:00:00Z',
  version: 3, account: { login: 'HV-00001', status: 'active', must_change_password: true, last_login_at: null },
  guardians: [], history: [{ id: 'e1', kind: 'created', message: 'Tạo hồ sơ học viên HV-00001.', from_status: null, to_status: 'waiting_start', actor_name: 'Học vụ', created_at: '2026-10-01T00:00:00Z' }],
  view: { scoped: false, can_edit: true, can_change_status: true, can_assign_class: true, can_delete: false, can_view_academic: true, can_view_contact: true, can_view_tuition: false },
}

function mockApi() {
  const calls: { key: string; body: unknown }[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init = {}) => {
    const key = `${init.method ?? 'GET'} ${String(input).replace('/api/v1', '')}`
    const body = init.body ? JSON.parse(String(init.body)) : undefined
    calls.push({ key, body })
    let data: unknown = { error: 'not_found' }
    let status = 200
    if (key === 'GET /students/options') data = options
    else if (key.startsWith('GET /students?')) data = { students: key.includes('statuses=dropped') ? [] : [row], total: 1, page: 1, per_page: 20, total_visible: 7, has_filters: key.includes('statuses') }
    else if (key === 'GET /students/s1') data = profile
    else if (key === 'GET /class-enrollments?student_id=s1') data = { enrollments: [], total: 0, page: 1, per_page: 15, classes: [], students: [], can_assign: true, crm_waiting_count: 0 }
    else if (key === 'PATCH /students/s1') data = { student: { ...profile, version: 4 }, message: 'Cập nhật hồ sơ học viên Nguyễn An thành công!' }
    else status = 404
    return new Response(JSON.stringify(data), { status })
  })
  return calls
}

function renderPage(path: string) {
  window.history.pushState(null, '', path)
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <StudentsPage me={me} />
    </QueryClientProvider>,
  )
}

test('danh sách: hiện học viên, lọc chip trạng thái, mở hồ sơ', async () => {
  const calls = mockApi()
  renderPage('/students')
  expect(await screen.findByText('Nguyễn An')).toBeInTheDocument()
  expect(screen.getByTestId('student-total')).toHaveTextContent('7')
  expect(screen.getByText('02/03/2015 ·', { exact: false })).toBeInTheDocument()
  expect(screen.getByText(/4 năm 9 tháng/)).toBeInTheDocument()

  await userEvent.click(screen.getByRole('button', { name: 'Thôi học' }))
  expect(await screen.findByText('Không tìm thấy học viên')).toBeInTheDocument()
  expect(calls.some((c) => c.key.includes('statuses=dropped'))).toBe(true)
})

test('hồ sơ: sửa gửi kèm phiên bản, tab lớp học lấy lượt xếp lớp', async () => {
  const calls = mockApi()
  renderPage('/students/s1')
  const name = await screen.findByLabelText('Họ và tên')
  await userEvent.clear(name)
  await userEvent.type(name, 'Nguyễn Văn An')
  await userEvent.click(screen.getByRole('button', { name: 'Lưu thay đổi' }))
  expect(await screen.findByText('Cập nhật hồ sơ học viên Nguyễn An thành công!')).toBeInTheDocument()
  const patch = calls.find((c) => c.key === 'PATCH /students/s1')
  expect(patch?.body).toMatchObject({ full_name: 'Nguyễn Văn An', parent_phone: '0987654321', version: 3 })

  await userEvent.click(screen.getByRole('tab', { name: 'Lớp học & lộ trình' }))
  expect(await screen.findByText('Học viên chưa được xếp lớp.')).toBeInTheDocument()
  await userEvent.click(screen.getByRole('tab', { name: 'Lịch sử' }))
  expect(screen.getByText('Tạo hồ sơ học viên HV-00001.')).toBeInTheDocument()
  // Không có tuition.view → không có tab Học phí
  expect(screen.queryByRole('tab', { name: 'Học phí' })).toBeNull()
})
