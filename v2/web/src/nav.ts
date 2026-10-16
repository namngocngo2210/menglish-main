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
  // Trang con /students/<id>, /students/enrollments do StudentsPage tự đọc đường dẫn
  { path: '/students', label: 'Danh sách học viên', perms: ['student.view'], group: 'Học viên' },
  {
    path: '/material-orders',
    label: 'Order học liệu',
    perms: ['material_order.create', 'material_order.view_all', 'material_order.process_ops', 'material_order.process_academic'],
    group: 'Danh sách đầu việc',
  },
  { path: '/inventory/items', label: 'Hàng hóa & Vật phẩm', perms: ['system_category.manage'], group: 'Kho vật phẩm' },
  { path: '/inventory/stock', label: 'Tồn kho theo chi nhánh', perms: ['merchandise_stock.view'], group: 'Kho vật phẩm' },
  // Danh mục & tổ chức (features/catalogs)
  { path: '/courses', label: 'Khóa học & Bảng giá', perms: ['course.view'], group: 'Tổ chức & Đào tạo' },
  { path: '/course-levels', label: 'Trình độ (CEFR)', perms: ['level.view'], group: 'Tổ chức & Đào tạo' },
  { path: '/holidays', label: 'Ngày nghỉ lễ', perms: ['holiday.manage'], group: 'Tổ chức & Đào tạo' },
  { path: '/system-categories', label: 'Danh mục hệ thống', perms: ['system_category.manage'], group: 'Tổ chức & Đào tạo' },
  { path: '/teaching-shifts', label: 'Khung giờ ca dạy', perms: ['class.update'], group: 'Tổ chức & Đào tạo' },
  { path: '/rooms', label: 'Phòng học', perms: ['room.view'], group: 'Tổ chức & Đào tạo' },
  { path: '/system-config/debt-reminders', label: 'Nhắc nợ học phí', perms: ['fee_reminder_config.manage'], group: 'Học phí' },
  { path: '/system-config/bank-accounts', label: 'Tài khoản ngân hàng', perms: ['bank_account.manage'], group: 'Học phí' },
  { path: '/system-config/ticket-emails', label: 'Email nhận Ticket', perms: ['support_ticket.update'], group: 'Hệ thống' },
  { path: '/system-config/sla', label: 'Cấu hình SLA', perms: ['sla.configure'], group: 'Hệ thống' },
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
  // Test đầu vào & học thử (Laravel: "Lịch học thử" gắn quyền sửa lớp; "(AI)" chỉ là nhãn menu, không gọi AI)
  { path: '/trial-bookings', label: 'Lịch học thử', group: 'Test đầu vào & học thử', perms: ['class.update'] },
  { path: '/placement-tests', label: 'Đề test đầu vào (AI)', group: 'Test đầu vào & học thử', perms: ['placement_test.view'] },
  {
    path: '/placement-tests/rubric-guide',
    label: 'Thang điểm & Hướng dẫn chấm',
    group: 'Test đầu vào & học thử',
    perms: ['placement_test.view'],
  },
  { path: '/teacher/trial-guests', label: 'Khách học thử', group: 'Cổng Giáo viên', perms: ['portal.teacher', 'portal.assistant'] },
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
