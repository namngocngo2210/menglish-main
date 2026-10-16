import type { Me } from './api'

// Menu theo quyền: mục chỉ hiện khi người dùng có ít nhất một quyền trong perms (giống SidebarMenu của bản Laravel).
// Chỉ gồm các màn đã có ở v2; module mới thêm mục của mình vào đây.
// group: tên nhóm menu như SidebarMenu của Laravel (vd. 'Khách hàng (CRM)'); không có thì nằm nhóm đầu.
export type NavItem = { path: string; label: string; perms?: string[]; group?: string; hidden?: boolean }

const CRM = 'Khách hàng (CRM)'

export const NAV: NavItem[] = [
  { path: '/', label: 'Tài khoản của tôi' },
  { path: '/users', label: 'Người dùng', perms: ['user.view'] },
  { path: '/roles', label: 'Vai trò & quyền', perms: ['role.view'] },
  { path: '/branches', label: 'Chi nhánh', perms: ['branch.view'] },
  // Khách hàng (CRM) — features/crm. Danh sách phụ (chốt, thất bại, chờ xếp lớp, đã xóa, duyệt chuyển cơ sở) mở từ chip.
  { path: '/crm/pipeline', label: 'Kanban', perms: ['lead.view'], group: CRM },
  { path: '/crm/customers', label: 'Danh sách khách', perms: ['lead.view'], group: CRM },
  { path: '/crm/reports', label: 'Báo cáo', perms: ['report.view'], group: CRM },
  { path: '/crm/promotions', label: 'Ưu đãi', perms: ['promotion.manage'], group: CRM },
  { path: '/crm/import', label: 'Nhập Excel', perms: ['lead.create'], group: CRM },
  { path: '/crm/customers/new', label: 'Thêm khách mới', perms: ['lead.create'], group: CRM },
  { path: '/crm/confirmations', label: 'Xác nhận chính thức', perms: ['student.assign_class'], group: CRM },
  { path: '/crm/won', label: 'Khách chốt thành công', perms: ['lead.view'], group: CRM, hidden: true },
  { path: '/crm/lost', label: 'Khách thất bại', perms: ['lead.view'], group: CRM, hidden: true },
  { path: '/crm/waiting', label: 'Chờ xếp lớp', perms: ['lead.view'], group: CRM, hidden: true },
  { path: '/crm/deleted', label: 'Khách đã xóa', perms: ['lead.delete'], group: CRM, hidden: true },
  { path: '/crm/transfers', label: 'Duyệt chuyển cơ sở', perms: ['lead.approve_transfer'], group: CRM, hidden: true },
]

export function can(me: Me, ...perms: string[]) {
  return perms.some((p) => me.permissions.includes(p))
}

export function visibleNav(me: Me) {
  return NAV.filter((item) => !item.hidden && (!item.perms || can(me, ...item.perms)))
}

// Mục menu ứng với đường dẫn (khớp tiền tố dài nhất), null nếu không có.
export function navItemFor(path: string) {
  return (
    NAV.filter((item) => path === item.path || (item.path !== '/' && path.startsWith(item.path + '/'))).sort(
      (a, b) => b.path.length - a.path.length,
    )[0] ?? null
  )
}
