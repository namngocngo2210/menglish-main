import { expect, test } from 'vitest'
import type { Me } from './api'
import { buildSidebar, normalize, searchScreens, settingsNav, workspaceTabs, type NavItem } from './nav'

function meWith(...permissions: string[]): Me {
  return {
    user: { id: 'u', kind: 'staff', code: 'MES001', email: null, phone: null, full_name: 'A', must_change_password: false, totp_enabled: true },
    csrf_token: 'c',
    totp_required: false,
    roles: [],
    permissions,
    scopes: {},
    branches: [],
  }
}

const nav: NavItem[] = [
  { path: '/', label: 'Tổng quan' },
  { path: '/students', label: 'Danh sách học viên', perms: ['student.view'], group: 'Học viên' },
  { path: '/students/archive', label: 'Lưu trữ', perms: ['student.view'], group: 'Học viên' },
  { path: '/crm', label: 'Kanban', perms: ['lead.view'], group: 'Khách hàng (CRM)' },
  { path: '/crm/list', label: 'Danh sách', perms: ['lead.view'], group: 'Khách hàng (CRM)' },
  { path: '/x', label: 'Màn lạ', group: 'Nhóm chưa khai báo' },
  { path: '/notifications', label: 'Thông báo', group: 'Của tôi' },
  { path: '/activity-logs', label: 'Nhật ký vận hành', perms: ['activity_log.view'], group: 'Hệ thống' },
  { path: '/courses', label: 'Khóa học & Bảng giá', perms: ['course.view'], group: 'Tổ chức & Đào tạo' },
  { path: '/bank', label: 'Tài khoản ngân hàng', perms: ['course.view'], group: 'Cài đặt › Học phí' },
  { path: '/tuition', label: 'Công nợ học viên', perms: ['tuition.view'], group: 'Học phí' },
  { path: '/hidden', label: 'Ẩn', group: 'Học viên', hidden: true },
]

test('sidebar theo khu và thứ tự của SidebarMenu, nhóm lạ ở cuối, Cài đặt riêng', () => {
  const me = meWith('student.view', 'lead.view', 'activity_log.view', 'course.view', 'tuition.view')
  const s = buildSidebar(me, '/students/archive', nav)
  expect(s.top.map((l) => l.label)).toEqual(['Tổng quan'])
  expect(s.sections.map((x) => x.label)).toEqual(['Tuyển sinh', 'Đào tạo', 'Tài chính', 'Cá nhân', 'Khác'])
  expect(s.sections[0].links[0]).toMatchObject({ label: 'Khách hàng (CRM)', path: '/crm', icon: 'person_search', active: false })
  expect(s.sections[1].links[0]).toMatchObject({ label: 'Học viên', path: '/students', active: true })
  expect(s.sections[2].links[0]).toMatchObject({ label: 'Học phí', path: '/tuition' })
  expect(s.settings).toEqual({ path: '/courses', active: false })

  // Không quyền thì mất cả workspace; không còn mục Cài đặt nào thì không có "Cài đặt"
  const teacher = buildSidebar(meWith(), '/notifications', nav)
  expect(teacher.sections.map((x) => x.label)).toEqual(['Cá nhân', 'Khác'])
  expect(teacher.settings).toBeNull()
})

test('tab của workspace và menu Cài đặt', () => {
  const me = meWith('student.view', 'activity_log.view', 'course.view')
  expect(workspaceTabs(me, '/students/123', nav)).toEqual({
    label: 'Học viên',
    tabs: [
      { path: '/students', label: 'Danh sách học viên', active: true },
      { path: '/students/archive', label: 'Lưu trữ', active: false },
    ],
  })
  expect(workspaceTabs(me, '/activity-logs', nav)).toBeNull()
  expect(settingsNav(me, '/activity-logs', nav)).toEqual([
    { label: 'Tổ chức & Đào tạo', items: [{ path: '/courses', label: 'Khóa học & Bảng giá', active: false }] },
    { label: 'Học phí', items: [{ path: '/bank', label: 'Tài khoản ngân hàng', active: false }] },
    { label: 'Hệ thống', items: [{ path: '/activity-logs', label: 'Nhật ký vận hành', active: true }] },
  ])
  expect(buildSidebar(me, '/activity-logs', nav).settings).toEqual({ path: '/courses', active: true })
})

test('tìm màn hình không phân biệt dấu, chỉ màn được mở', () => {
  expect(normalize('  Đào TẠO ')).toBe('dao tao')
  const me = meWith('student.view', 'activity_log.view')
  expect(searchScreens(me, 'hoc vien', nav)).toEqual([
    { title: 'Học viên › Danh sách học viên', path: '/students' },
    { title: 'Học viên › Lưu trữ', path: '/students/archive' },
  ])
  expect(searchScreens(me, 'nhật ký', nav)).toEqual([{ title: 'Cài đặt › Nhật ký vận hành', path: '/activity-logs' }])
  expect(searchScreens(me, 'kanban', nav)).toEqual([])
  expect(searchScreens(me, 'h', nav)).toEqual([])
})
