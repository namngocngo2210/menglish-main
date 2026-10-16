import type { Me } from './api'

// Menu theo quyền: mục chỉ hiện khi người dùng có ít nhất một quyền trong perms (giống SidebarMenu của bản Laravel).
// Chỉ gồm các màn đã có ở v2; module mới thêm mục của mình vào đây.
// group: tên nhóm menu như SidebarMenu của Laravel (vd. 'Khách hàng (CRM)'); không có thì nằm nhóm đầu.
// menu: điều kiện thêm để hiện trên menu (vd. cả nhóm "Cần duyệt" chỉ Admin thấy, quyền neo của nhóm), không chặn mở trang.
export type NavItem = {
  path: string
  label: string
  perms?: string[]
  group?: string
  hidden?: boolean
  menu?: (me: Me) => boolean
}

export const isAdmin = (me: Me) => me.roles.some((r) => r.name === 'admin')

export const NAV: NavItem[] = [
  { path: '/', label: 'Tài khoản của tôi' },
  { path: '/users', label: 'Người dùng', perms: ['user.view'] },
  { path: '/roles', label: 'Vai trò & quyền', perms: ['role.view'] },
  { path: '/branches', label: 'Chi nhánh', perms: ['branch.view'] },
  // Công việc (WorkTaskController). Cần duyệt → "Hoàn thành công việc": chỉ Admin, neo work_task.create.
  {
    path: '/tasks/approvals',
    label: 'Hoàn thành công việc',
    group: 'Cần duyệt',
    perms: ['work_task.view'],
    menu: (me) => isAdmin(me) && can(me, 'work_task.create'),
  },
  { path: '/tasks/new', label: 'Tạo đầu việc', group: 'Tạo đầu việc', perms: ['work_task.create', 'work_task.request'] },
  { path: '/tasks', label: 'Danh sách đầu việc', group: 'Danh sách đầu việc', perms: ['work_task.view'] },
  // Nút "Giao việc cho Trợ giảng" trên danh sách đầu việc (không phải mục menu riêng như Laravel)
  { path: '/tasks/ta-assign', label: 'Giao việc cho Trợ giảng', group: 'Danh sách đầu việc', perms: ['work_task.assign'], hidden: true },
  // Cổng Giáo viên: neo quyền đối tượng cổng giáo viên / trợ giảng; người giao / duyệt việc mở từ danh sách đầu việc.
  {
    path: '/portal/ta-tasks',
    label: 'Nhiệm vụ hôm nay của TA',
    group: 'Cổng Giáo viên',
    perms: ['work_task.view'],
    menu: (me) => can(me, 'portal.teacher', 'portal.assistant'),
  },
]

export function can(me: Me, ...perms: string[]) {
  return perms.some((p) => me.permissions.includes(p))
}

export function visibleNav(me: Me) {
  return NAV.filter((item) => !item.hidden && (!item.perms || can(me, ...item.perms)) && (!item.menu || item.menu(me)))
}

// Mục menu ứng với đường dẫn (khớp tiền tố dài nhất), null nếu không có.
export function navItemFor(path: string) {
  return (
    NAV.filter((item) => path === item.path || (item.path !== '/' && path.startsWith(item.path + '/'))).sort(
      (a, b) => b.path.length - a.path.length,
    )[0] ?? null
  )
}
