import type { Me } from './api'
import { seesDashboard } from './features/dashboard/logic'

// Menu theo quyền: mục chỉ hiện khi người dùng có ít nhất một quyền trong perms (giống SidebarMenu của bản Laravel).
// Chỉ gồm các màn đã có ở v2; module mới thêm mục của mình vào đây.
// group: tên nhóm menu như SidebarMenu của Laravel — tên workspace (vd. 'Khách hàng (CRM)', 'Học viên') hoặc tên nhóm
// của trang Cài đặt ('Tổ chức & Đào tạo', 'Lương', 'Hệ thống', 'Cài đặt › Học phí'); không có thì nằm nhóm đầu.
// Mỗi nhóm là một mục sidebar (mở mục đầu tiên người dùng được xem), các mục trong nhóm hiện thành tab ở đầu trang.
// icon: tên icon Material Symbols (tùy chọn), mặc định lấy icon của nhóm.
// menu: điều kiện thêm để hiện trên menu (vd. cả nhóm "Cần duyệt" chỉ Admin thấy, quyền neo của nhóm), không chặn mở trang.
export type NavItem = {
  path: string
  label: string
  perms?: string[]
  group?: string
  hidden?: boolean
  icon?: string
  menu?: (me: Me) => boolean
}

export const isAdmin = (me: Me) => me.roles.some((r) => r.name === 'admin')

const CRM = 'Khách hàng (CRM)'

export const NAV: NavItem[] = [
  // Tổng quan (DashboardController) cho nhân sự; học viên / phụ huynh thấy "Tài khoản của tôi" thay chỗ (routes.tsx).
  { path: '/', label: 'Tổng quan', icon: 'dashboard', menu: seesDashboard },
  { path: '/profile', label: 'Tài khoản của tôi', icon: 'account_circle', menu: (me) => !seesDashboard(me) },
  // URL Tổng quan cũ của Laravel (/dashboard), giữ cho liên kết đã lưu.
  { path: '/dashboard', label: 'Tổng quan', hidden: true },
  { path: '/notifications', label: 'Thông báo', perms: ['notification.view'], group: 'Của tôi' },
  { path: '/users', label: 'Người dùng & Tài khoản', perms: ['user.view'], group: 'Người dùng' },
  { path: '/branches', label: 'Cơ sở & Chi nhánh', perms: ['branch.view'], group: 'Tổ chức & Đào tạo' },
  { path: '/roles', label: 'Vai trò & quyền', perms: ['role.view'], group: 'Hệ thống' },
  { path: '/activity-logs', label: 'Nhật ký vận hành', perms: ['activity_log.view'], group: 'Hệ thống' },
  // Mở từ ô tìm kiếm trên topbar, không có trên menu.
  { path: '/search', label: 'Tìm kiếm', hidden: true },
  // Trang con /students/<id>, /students/enrollments do StudentsPage tự đọc đường dẫn
  { path: '/students', label: 'Danh sách học viên', perms: ['student.view'], group: 'Học viên' },
  // Trang con /classes/new, /classes/<id>[/edit|/attendance] do ClassesPage tự đọc đường dẫn
  { path: '/classes', label: 'Danh sách lớp', perms: ['class.update', 'class.teach', 'class.assist', 'attendance_student.record_any'], group: 'Lớp học' },
  { path: '/schedule-config', label: 'Lịch & TKB lớp', perms: ['work_task.assign'], group: 'Tổ chức & Đào tạo' },
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
  { path: '/system-config/debt-reminders', label: 'Nhắc nợ học phí', perms: ['fee_reminder_config.manage'], group: 'Cài đặt › Học phí' },
  { path: '/system-config/bank-accounts', label: 'Tài khoản ngân hàng', perms: ['bank_account.manage'], group: 'Cài đặt › Học phí' },
  { path: '/system-config/ticket-emails', label: 'Email nhận Ticket', perms: ['support_ticket.update'], group: 'Hệ thống' },
  { path: '/system-config/sla', label: 'Cấu hình SLA', perms: ['sla.configure'], group: 'Hệ thống' },
  { path: '/system-config/mail-outbox', label: 'Hàng đợi email', perms: ['mail_config.manage'], group: 'Hệ thống' },
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
  // Báo cáo ngày / tuần / tháng theo vai trò (StaffReportController)
  { path: '/reports', label: 'Báo cáo định kỳ', perms: ['staff_report.submit'], group: 'Báo cáo của tôi' },
  { path: '/reports/all', label: 'Tổng hợp báo cáo & nhật ký', perms: ['staff_report.view_all'], group: 'Nhân sự & KPI' },
  // Laravel: @can staff_report.submit và thêm user.view hoặc kpi.view
  {
    path: '/reports/journal',
    label: 'Nhật ký Học vụ',
    perms: ['staff_report.submit'],
    group: 'Nhân sự & KPI',
    menu: (me) => can(me, 'user.view', 'kpi.view'),
  },
  // Báo cáo & sự vụ lớp (AcademicDashboardController): /academic/dashboards[/reports|/incidents] do AcademicPage tự đọc đường dẫn.
  { path: '/academic/dashboards', label: 'Báo cáo & sự vụ lớp', perms: ['class.update'], group: 'Đào tạo & Chất lượng lớp' },
  // URL cũ academic.reports / academic.incidents của Laravel.
  { path: '/academic/reports', label: 'Báo cáo & sự vụ lớp', perms: ['class.update'], group: 'Đào tạo & Chất lượng lớp', hidden: true },
  { path: '/academic/incidents', label: 'Báo cáo & sự vụ lớp', perms: ['class.update'], group: 'Đào tạo & Chất lượng lớp', hidden: true },
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

// Workspace của sidebar theo đúng thứ tự SidebarMenu::definition(): khu (section) → workspace (label, icon).
type GroupDef = { label: string; section: string; icon: string }
const WORKSPACES: GroupDef[] = [
  { section: 'Phê duyệt', label: 'Cần duyệt', icon: 'fact_check' },
  { section: 'Phê duyệt', label: 'Xin duyệt', icon: 'outgoing_mail' },
  { section: 'Giao việc', label: 'Tạo đầu việc', icon: 'add_task' },
  { section: 'Giao việc', label: 'Danh sách đầu việc', icon: 'task_alt' },
  { section: 'Tuyển sinh', label: 'Khách hàng (CRM)', icon: 'person_search' },
  { section: 'Tuyển sinh', label: 'Test đầu vào & học thử', icon: 'quiz' },
  { section: 'Đào tạo', label: 'Học viên', icon: 'school' },
  { section: 'Đào tạo', label: 'Lớp học', icon: 'co_present' },
  { section: 'Đào tạo', label: 'Giáo trình', icon: 'menu_book' },
  { section: 'Đào tạo', label: 'Big Test', icon: 'assignment' },
  { section: 'Đào tạo', label: 'Khảo sát', icon: 'poll' },
  { section: 'Tài chính', label: 'Học phí', icon: 'monetization_on' },
  { section: 'Tài chính', label: 'Thu - Chi', icon: 'query_stats' },
  { section: 'Tài chính', label: 'Kho vật phẩm', icon: 'inventory_2' },
  { section: 'Người dùng', label: 'Người dùng', icon: 'badge' },
  { section: 'Người dùng', label: 'Ticket hỗ trợ', icon: 'confirmation_number' },
  { section: 'Người dùng', label: 'Chấm công', icon: 'schedule' },
  { section: 'Người dùng', label: 'Lương & Phạt', icon: 'payments' },
  { section: 'Báo cáo', label: 'Doanh thu', icon: 'query_stats' },
  { section: 'Báo cáo', label: 'Đào tạo & Chất lượng lớp', icon: 'monitoring' },
  { section: 'Báo cáo', label: 'Nhân sự & KPI', icon: 'leaderboard' },
  { section: 'Báo cáo', label: 'Báo cáo của tôi', icon: 'summarize' },
  { section: 'Cá nhân', label: 'Của tôi', icon: 'person' },
  { section: 'Cá nhân', label: 'Cổng Giáo viên', icon: 'cast_for_education' },
  { section: 'Cá nhân', label: 'Cổng Phụ huynh & Học sinh', icon: 'family_restroom' },
  { section: 'Cá nhân', label: 'Hướng dẫn sử dụng', icon: 'help' },
]

// Nhóm của trang Cài đặt theo SidebarMenu::settingsDefinition(). "Học phí" trùng tên workspace nên mục Cài đặt
// dùng 'Cài đặt › Học phí'; mọi group dạng 'Cài đặt › <nhóm>' hoặc 'Cài đặt' đều vào trang Cài đặt.
const SETTINGS_SECTIONS = ['Tổ chức & Đào tạo', 'Lương', 'Học phí', 'Hệ thống']
const SETTINGS_PREFIX = 'Cài đặt › '
const SETTINGS_OTHER = 'Khác'
const OTHER_SECTION = 'Khác'

export function can(me: Me, ...perms: string[]) {
  return perms.some((p) => me.permissions.includes(p))
}

function allowed(me: Me, item: NavItem) {
  return !item.perms || can(me, ...item.perms)
}

export function visibleNav(me: Me, nav = NAV) {
  return nav.filter((item) => !item.hidden && allowed(me, item) && (!item.menu || item.menu(me)))
}

// Mục menu ứng với đường dẫn (khớp tiền tố dài nhất), null nếu không có.
export function navItemFor(path: string, nav = NAV) {
  return (
    nav.filter((item) => path === item.path || (item.path !== '/' && path.startsWith(item.path + '/'))).sort(
      (a, b) => b.path.length - a.path.length,
    )[0] ?? null
  )
}

// Nhóm Cài đặt của mục (null nếu mục không thuộc trang Cài đặt).
export function settingsSectionOf(group?: string): string | null {
  if (!group) return null
  if (SETTINGS_SECTIONS.includes(group) && group !== 'Học phí') return group
  if (group === 'Cài đặt') return SETTINGS_OTHER
  if (group.startsWith(SETTINGS_PREFIX)) return group.slice(SETTINGS_PREFIX.length) || SETTINGS_OTHER
  return null
}

function workspaceDef(group: string): GroupDef {
  return WORKSPACES.find((w) => w.label === group) ?? { label: group, section: OTHER_SECTION, icon: 'folder' }
}

function order<T>(keys: T[], known: T[]) {
  const rank = (k: T) => (known.includes(k) ? known.indexOf(k) : known.length)
  return keys.map((k, i) => ({ k, i })).sort((a, b) => rank(a.k) - rank(b.k) || a.i - b.i).map((x) => x.k)
}

export type SidebarLink = { key: string; label: string; icon: string; path: string; active: boolean }
export type Sidebar = {
  top: SidebarLink[]
  sections: { label: string; links: SidebarLink[] }[]
  settings: { path: string; active: boolean } | null
}

// buildSidebar dựng sidebar như AppShell::sidebarGroups của Laravel: mục không nhóm ở đầu, rồi các khu theo thứ tự
// SidebarMenu (mỗi workspace một dòng, link tới mục đầu tiên được xem), cuối cùng là "Cài đặt".
export function buildSidebar(me: Me, path: string, nav = NAV): Sidebar {
  const items = visibleNav(me, nav)
  const current = navItemFor(path, nav)
  const top: SidebarLink[] = []
  const byGroup = new Map<string, NavItem[]>()
  const settingsItems: NavItem[] = []
  for (const item of items) {
    if (!item.group) {
      top.push({ key: item.path, label: item.label, icon: item.icon ?? 'dashboard', path: item.path, active: current?.path === item.path })
    } else if (settingsSectionOf(item.group)) {
      settingsItems.push(item)
    } else {
      byGroup.set(item.group, [...(byGroup.get(item.group) ?? []), item])
    }
  }
  const groups = order([...byGroup.keys()], WORKSPACES.map((w) => w.label))
  const sections: Sidebar['sections'] = []
  for (const group of groups) {
    const def = workspaceDef(group)
    const list = byGroup.get(group)!
    const link = { key: group, label: def.label, icon: list[0].icon ?? def.icon, path: list[0].path, active: current?.group === group }
    const last = sections[sections.length - 1]
    if (last && last.label === def.section) last.links.push(link)
    else sections.push({ label: def.section, links: [link] })
  }
  const settingsFirst = settingsNav(me, path, nav)[0]?.items[0]
  return {
    top,
    sections,
    settings: settingsFirst ? { path: settingsFirst.path, active: !!settingsSectionOf(current?.group) } : null,
  }
}

export type Tab = { path: string; label: string; active: boolean }

// Tab của workspace chứa trang hiện tại (như WorkspaceTabs của Laravel); null nếu trang không thuộc workspace.
export function workspaceTabs(me: Me, path: string, nav = NAV): { label: string; tabs: Tab[] } | null {
  const current = navItemFor(path, nav)
  if (!current?.group || settingsSectionOf(current.group)) return null
  const tabs = visibleNav(me, nav)
    .filter((item) => item.group === current.group)
    .map((item) => ({ path: item.path, label: item.label, active: item.path === current.path }))
  return { label: workspaceDef(current.group).label, tabs }
}

// Menu con trang Cài đặt (như SettingsNav của Laravel), đã lọc quyền, bỏ nhóm rỗng.
export function settingsNav(me: Me, path: string, nav = NAV): { label: string; items: Tab[] }[] {
  const current = navItemFor(path, nav)
  const bySection = new Map<string, Tab[]>()
  for (const item of visibleNav(me, nav)) {
    const section = settingsSectionOf(item.group)
    if (!section) continue
    bySection.set(section, [...(bySection.get(section) ?? []), { path: item.path, label: item.label, active: current?.path === item.path }])
  }
  return order([...bySection.keys()], [...SETTINGS_SECTIONS, SETTINGS_OTHER]).map((label) => ({ label, items: bySection.get(label)! }))
}

// Tiêu đề trang trên topbar: tên mục đang mở, không có thì "MEnglish".
export function pageTitle(path: string) {
  return navItemFor(path)?.label ?? 'MEnglish'
}

// normalize bỏ dấu tiếng Việt và viết thường để so khớp ("Học viên" ~ "hoc vien").
export function normalize(text: string) {
  return text
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/đ/g, 'd')
    .replace(/Đ/g, 'D')
    .toLowerCase()
    .trim()
}

export type Screen = { title: string; path: string }

// Màn hình người dùng mở được có tên khớp từ khóa (như SidebarMenu::searchScreens), tối thiểu 2 ký tự.
export function searchScreens(me: Me, term: string, nav = NAV): Screen[] {
  const needle = normalize(term)
  if ([...needle].length < 2) return []
  return visibleNav(me, nav)
    .map((item) => {
      const section = settingsSectionOf(item.group)
      const group = section ? 'Cài đặt' : item.group
      return { title: group && group !== item.label ? `${group} › ${item.label}` : item.label, path: item.path }
    })
    .filter((s) => normalize(s.title).includes(needle))
}
