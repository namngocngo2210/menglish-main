// Logic thuần trang Hướng dẫn sử dụng (App\Support\Guide\UserGuide, có test): bộ hướng dẫn theo vai trò, nút
// "Mở màn hình", tìm kiếm không dấu, chữ đậm **...**.
import type { Me } from '../../api'
import { buildSidebar, can, navItemFor } from '../../nav'

export const GUIDE_PATH = '/huong-dan'
export const GUIDE_PARAM = 'vai-tro'

export type GuideImage = { src: string; caption?: string; mobile: boolean }
export type GuideSection = {
  id: string
  title: string
  icon?: string
  menu?: string
  route?: string
  intro?: string
  images: GuideImage[]
  steps: string[]
  tips: string[]
}
export type GuideContent = { id: string; label: string; icon: string; summary: string; sections: GuideSection[] }

export type Guide = Omit<GuideContent, 'sections'> & {
  own: boolean
  common: boolean
  sections: (GuideSection & { url: string | null })[]
}

// Bộ hướng dẫn → vai trò dùng nó, theo thứ tự hiển thị (UserGuide::GUIDES).
export const GUIDE_ROLES: Record<string, string[]> = {
  admin: ['admin'],
  'quan-ly': ['manager'],
  'hoc-thuat': ['academic_lead'],
  'hoc-vu': ['academic_staff'],
  'tu-van': ['sales_consultant'],
  'giao-vien': ['teacher_fulltime', 'teacher_parttime'],
  'tro-giang': ['assistant'],
  'hoc-vien': ['student'],
}
export const COMMON = 'chung'

// Tên route Laravel → đường dẫn v2. Màn chưa có ở v2 thì chưa có nút "Mở màn hình".
// TODO(gộp): thêm route của Chấm công, Lương & Phạt, Cổng Giáo viên, Cổng PH/HS, Chất lượng lớp, Giáo trình khi gộp
// (teacher.home, payroll.*, penalties.index, staff-attendance.index, portal.student.*, portal.teacher.submissions,
// portal.my-salary, syllabus.*, class-quality.academic, academic-projects.index, reports.periodic.teacher-monthly).
export const ROUTE_PATHS: Record<string, string> = {
  dashboard: '/dashboard',
  'profile.edit': '/profile',
  'notifications.index': '/notifications',
  'settings.index': 'settings',
  'users.index': '/users',
  'roles.index': '/roles',
  'branches.index': '/branches',
  'activity-logs.index': '/activity-logs',
  'approvals.index': '/approvals',
  'crm.pipeline': '/crm/pipeline',
  'crm.customers.index': '/crm/customers',
  'crm.customers.won': '/crm/won',
  'crm.lost-deals': '/crm/lost',
  'students.index': '/students',
  'students.enrollments': '/students/enrollments',
  'classes.index': '/classes',
  'classes.create': '/classes/new',
  'tuition.students': '/tuition/students',
  'tuition.overdue': '/tuition/overdue',
  'tuition.refunds': '/tuition/refunds',
  'tuition.receipts.create': '/tuition/receipts/new',
  'tuition.receipts.approve': '/tuition/receipts/approve',
  'finance.reports.revenue': '/finance/reports/provisional-revenue',
  'finance.expenses.index': '/finance/expenses',
  'material-orders.index': '/material-orders',
  'merchandise.stock.index': '/inventory/stock',
  'tasks.index': '/tasks',
  'tasks.ta-assign': '/tasks/ta-assign',
  'portal.ta-tasks': '/portal/ta-tasks',
  'teacher.trial-guests': '/teacher/trial-guests',
  'reports.my': '/reports',
  'kpi.mine': '/kpi/mine',
  'kpi.monthly': '/kpi/monthly',
}

// Quyền riêng của màn con (đường dẫn con dùng chung mục menu với màn cha).
const ROUTE_PERMS: Record<string, string[]> = { 'classes.create': ['class.create'] }

// Học viên / phụ huynh (portal.student, không portal.staff) chỉ thấy hướng dẫn Học viên (User::isPortalStudentOnly).
export const isPortalStudentOnly = (me: Me) => can(me, 'portal.student') && !can(me, 'portal.staff')

// openUrl: đường dẫn mở màn hình của mục, chỉ khi người xem mở được (quyền + điều kiện menu của mục).
export function openUrl(me: Me, route: string | undefined): string | null {
  const path = route ? ROUTE_PATHS[route] : undefined
  if (!path) return null
  if (path === 'settings') return buildSidebar(me, GUIDE_PATH).settings?.path ?? null
  const item = navItemFor(path)
  if (!item) return null
  if (item.perms && !can(me, ...item.perms)) return null
  if (item.menu && !item.menu(me)) return null
  const extra = ROUTE_PERMS[route!]
  if (extra && !can(me, ...extra)) return null
  return path
}

// guidesFor: của mình trước (own), rồi các vai trò khác, cuối cùng là phần chung (UserGuide::for).
export function guidesFor(me: Me, content: GuideContent[]): Guide[] {
  const roles = me.roles.map((r) => r.name)
  const ids = Object.keys(GUIDE_ROLES)
  const own = ids.filter((id) => GUIDE_ROLES[id].some((r) => roles.includes(r)))
  const visible = isPortalStudentOnly(me) ? ['hoc-vien'] : ids
  const ordered = [...own.filter((id) => visible.includes(id)), ...visible.filter((id) => !own.includes(id)), COMMON]
  const byId = new Map(content.map((g) => [g.id, g]))
  return ordered
    .map((id) => byId.get(id))
    .filter((g): g is GuideContent => !!g)
    .map((g) => ({
      ...g,
      own: own.includes(g.id),
      common: g.id === COMMON,
      sections: g.sections.map((s) => ({ ...s, url: openUrl(me, s.route) })),
    }))
}

// Bộ đang chọn: ?vai-tro= hợp lệ, không thì bộ đầu tiên.
export const selectedId = (list: Guide[], param: string) => (list.some((g) => g.id === param) ? param : (list[0]?.id ?? COMMON))

// Tìm không dấu: "dang nhap" khớp "Đăng nhập".
export const normalize = (text: string) =>
  text
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/đ/g, 'd')
    .replace(/Đ/g, 'D')
    .toLowerCase()

export function filterSections<S extends GuideSection>(sections: S[], query: string): S[] {
  const needle = normalize(query.trim())
  if (!needle) return sections
  return sections.filter((s) => normalize([s.title, s.intro ?? '', s.menu ?? '', ...s.steps, ...s.tips].join(' ')).includes(needle))
}

// "Bấm **Lưu** để ..." → [{ text: 'Bấm ' }, { text: 'Lưu', bold: true }, { text: ' để ...' }]
export const parts = (text: string) =>
  text
    .split('**')
    .map((t, i) => ({ text: t, bold: i % 2 === 1 }))
    .filter((p) => p.text)

export const imageSrc = (src: string) => `/images/huong-dan/${src}`
