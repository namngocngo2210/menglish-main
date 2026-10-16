import type { Me } from './api'

// Menu theo quyền: mục chỉ hiện khi người dùng có ít nhất một quyền trong perms (giống SidebarMenu của bản Laravel).
// Chỉ gồm các màn đã có ở v2; module mới thêm mục của mình vào đây.
// group: tên nhóm menu như SidebarMenu của Laravel — tên workspace (vd. 'Khách hàng (CRM)', 'Học viên') hoặc tên nhóm
// của trang Cài đặt ('Tổ chức & Đào tạo', 'Lương', 'Hệ thống', 'Cài đặt › Học phí'); không có thì nằm nhóm đầu.
// Mỗi nhóm là một mục sidebar (mở mục đầu tiên người dùng được xem), các mục trong nhóm hiện thành tab ở đầu trang.
// icon: tên icon Material Symbols (tùy chọn), mặc định lấy icon của nhóm.
export type NavItem = { path: string; label: string; perms?: string[]; group?: string; hidden?: boolean; icon?: string }

export const NAV: NavItem[] = [
  { path: '/', label: 'Tài khoản của tôi', icon: 'account_circle' },
  { path: '/notifications', label: 'Thông báo', perms: ['notification.view'], group: 'Của tôi' },
  { path: '/users', label: 'Người dùng & Tài khoản', perms: ['user.view'], group: 'Người dùng' },
  { path: '/branches', label: 'Cơ sở & Chi nhánh', perms: ['branch.view'], group: 'Tổ chức & Đào tạo' },
  { path: '/roles', label: 'Vai trò & quyền', perms: ['role.view'], group: 'Hệ thống' },
  { path: '/activity-logs', label: 'Nhật ký vận hành', perms: ['activity_log.view'], group: 'Hệ thống' },
  // Mở từ ô tìm kiếm trên topbar, không có trên menu.
  { path: '/search', label: 'Tìm kiếm', hidden: true },
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
  return nav.filter((item) => !item.hidden && allowed(me, item))
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
