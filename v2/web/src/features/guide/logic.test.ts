import { expect, test } from 'vitest'
import type { Me } from '../../api'
import { navItemFor } from '../../nav'
import { GUIDES } from './content'
import { COMMON, filterSections, GUIDE_ROLES, guidesFor, normalize, openUrl, parts, ROUTE_PATHS, selectedId } from './logic'

const me = (roles: string[], permissions: string[]) =>
  ({ user: {}, roles: roles.map((name) => ({ name, label: name, short_label: name })), permissions, scopes: {}, branches: [] }) as unknown as Me

test('nội dung sinh từ Laravel đủ 9 bộ, mỗi mục có id duy nhất', () => {
  expect(GUIDES.map((g) => g.id)).toEqual([...Object.keys(GUIDE_ROLES), COMMON])
  for (const g of GUIDES) {
    const ids = g.sections.map((s) => s.id)
    expect(new Set(ids).size).toBe(ids.length)
    expect(g.sections.length).toBeGreaterThan(0)
  }
})

test('bộ của mình trước, học viên chỉ thấy bộ Học viên + phần chung', () => {
  const teacher = me(['teacher_fulltime'], ['portal.staff', 'class.view'])
  const list = guidesFor(teacher, GUIDES)
  expect(list[0].id).toBe('giao-vien')
  expect(list[0].own).toBe(true)
  expect(list.map((g) => g.id)).toHaveLength(9)
  expect(list[list.length - 1]).toMatchObject({ id: 'chung', common: true, own: false })
  const student = me(['student'], ['portal.student'])
  expect(guidesFor(student, GUIDES).map((g) => g.id)).toEqual(['hoc-vien', 'chung'])
  expect(selectedId(list, 'hoc-vien')).toBe('hoc-vien')
  expect(selectedId(guidesFor(student, GUIDES), 'admin')).toBe('hoc-vien')
})

test('nút "Mở màn hình" chỉ khi mở được', () => {
  const sales = me(['sales_consultant'], ['lead.view', 'lead.create', 'lead.update', 'class.view'])
  expect(openUrl(sales, 'crm.pipeline')).toBe('/crm/pipeline')
  // Khu CRM neo lead.create / lead.update (SidebarMenu::CRM): chỉ có lead.view thì không có nút mở.
  expect(openUrl(me(['teacher_fulltime'], ['lead.view']), 'crm.pipeline')).toBeNull()
  expect(openUrl(sales, 'users.index')).toBeNull()
  expect(openUrl(sales, 'classes.create')).toBeNull()
  expect(openUrl(me(['manager'], ['class.update', 'class.create']), 'classes.create')).toBe('/classes/new')
  // Không có quyền / màn không có ở v2
  expect(openUrl(sales, 'teacher.home')).toBeNull()
  expect(openUrl(me(['teacher_fulltime'], ['attendance_student.record', 'portal.teacher']), 'teacher.home')).toBe('/teacher/home')
  expect(openUrl(me(['admin'], ['lead.convert']), 'crm.closing-wizard')).toBeNull()
  expect(openUrl(sales, undefined)).toBeNull()
  // Việc cần duyệt: chỉ Admin
  expect(openUrl(me(['manager'], ['tuition.approve']), 'approvals.index')).toBeNull()
  expect(openUrl(me(['admin'], ['tuition.approve']), 'approvals.index')).toBe('/approvals')
  // Mọi đường dẫn khai báo đều trỏ tới màn có trong menu v2
  for (const path of Object.values(ROUTE_PATHS)) {
    if (path !== 'settings') expect(navItemFor(path), path).not.toBeNull()
  }
  expect(openUrl(me(['admin'], ['branch.view']), 'settings.index')).toBe('/branches')
})

test('tìm không dấu và chữ đậm', () => {
  expect(normalize('Đăng nhập')).toBe('dang nhap')
  const sections = GUIDES.find((g) => g.id === COMMON)!.sections
  expect(filterSections(sections, 'dang nhap').some((s) => s.id === 'dang-nhap')).toBe(true)
  expect(filterSections(sections, 'zzzz')).toEqual([])
  expect(filterSections(sections, '  ')).toBe(sections)
  expect(parts('Bấm **Lưu** để xong')).toEqual([
    { text: 'Bấm ', bold: false },
    { text: 'Lưu', bold: true },
    { text: ' để xong', bold: false },
  ])
})
