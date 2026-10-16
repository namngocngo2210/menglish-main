import type { Me } from './api'

// Menu theo quyền: mục chỉ hiện khi người dùng có ít nhất một quyền trong perms (giống SidebarMenu của bản Laravel).
// Chỉ gồm các màn đã có ở v2; module mới thêm mục của mình vào đây.
// group: tên nhóm menu như SidebarMenu của Laravel (vd. 'Khách hàng (CRM)'); không có thì nằm nhóm đầu.
// anchor: điều kiện hiện thêm (cần thêm ít nhất một quyền trong anchor), như mục có hai điều kiện @can của Laravel.
export type NavItem = { path: string; label: string; perms?: string[]; anchor?: string[]; group?: string; hidden?: boolean }

export const NAV: NavItem[] = [
  { path: '/', label: 'Tài khoản của tôi' },
  { path: '/users', label: 'Người dùng', perms: ['user.view'] },
  { path: '/roles', label: 'Vai trò & quyền', perms: ['role.view'] },
  { path: '/branches', label: 'Chi nhánh', perms: ['branch.view'] },
  { path: '/reports', label: 'Báo cáo định kỳ', perms: ['staff_report.submit'], group: 'Báo cáo của tôi' },
  { path: '/reports/all', label: 'Tổng hợp báo cáo & nhật ký', perms: ['staff_report.view_all'], group: 'Nhân sự & KPI' },
  {
    path: '/reports/journal',
    label: 'Nhật ký Học vụ',
    perms: ['staff_report.submit'],
    anchor: ['user.view', 'kpi.view'],
    group: 'Nhân sự & KPI',
  },
]

export function can(me: Me, ...perms: string[]) {
  return perms.some((p) => me.permissions.includes(p))
}

export function visibleNav(me: Me) {
  return NAV.filter(
    (item) => !item.hidden && (!item.perms || can(me, ...item.perms)) && (!item.anchor || can(me, ...item.anchor)),
  )
}

// Mục menu ứng với đường dẫn (khớp tiền tố dài nhất), null nếu không có.
export function navItemFor(path: string) {
  return (
    NAV.filter((item) => path === item.path || (item.path !== '/' && path.startsWith(item.path + '/'))).sort(
      (a, b) => b.path.length - a.path.length,
    )[0] ?? null
  )
}
