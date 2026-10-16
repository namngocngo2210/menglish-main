import type { Me } from './api'

// Menu theo quyền: mục chỉ hiện khi người dùng có ít nhất một quyền trong perms (giống SidebarMenu của bản Laravel).
// Chỉ gồm các màn đã có ở v2; module mới thêm mục của mình vào đây.
// group: tên nhóm menu như SidebarMenu của Laravel (vd. 'Khách hàng (CRM)'); không có thì nằm nhóm đầu.
export type NavItem = { path: string; label: string; perms?: string[]; group?: string; hidden?: boolean }

export const NAV: NavItem[] = [
  { path: '/', label: 'Tài khoản của tôi' },
  { path: '/users', label: 'Người dùng', perms: ['user.view'] },
  { path: '/roles', label: 'Vai trò & quyền', perms: ['role.view'] },
  { path: '/branches', label: 'Chi nhánh', perms: ['branch.view'] },
  {
    path: '/material-orders',
    label: 'Order học liệu',
    perms: ['material_order.create', 'material_order.view_all', 'material_order.process_ops', 'material_order.process_academic'],
    group: 'Danh sách đầu việc',
  },
  { path: '/inventory/items', label: 'Hàng hóa & Vật phẩm', perms: ['system_category.manage'], group: 'Kho vật phẩm' },
  { path: '/inventory/stock', label: 'Tồn kho theo chi nhánh', perms: ['merchandise_stock.view'], group: 'Kho vật phẩm' },
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
