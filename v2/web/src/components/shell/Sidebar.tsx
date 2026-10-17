import { useState, type MouseEvent } from 'react'
import type { Me } from '../../api'
import { buildSidebar, type SidebarLink } from '../../nav'
import { navigate } from '../../router'
import { Icon } from '../ui'

// Sidebar như Layouts/Shell/Sidebar.vue của Laravel: nền tối, khu (section) có tiêu đề, mỗi workspace một dòng có icon,
// "Cài đặt" ở cuối, vai trò + Đăng xuất ở chân. ≥1024px đầy đủ (thu gọn được thành cột icon, nhớ trong trình duyệt),
// 768–1023px luôn dạng icon, <768px là ngăn kéo mở bằng nút menu trên topbar.

const COLLAPSED_SECTIONS_KEY = 'nav_collapsed_sections'

function loadCollapsedSections(): string[] {
  try {
    return JSON.parse(localStorage.getItem(COLLAPSED_SECTIONS_KEY) ?? '[]')
  } catch {
    return []
  }
}

export function Sidebar({
  me,
  path,
  open,
  collapsed,
  onClose,
  onToggleCollapsed,
  onLogout,
}: {
  me: Me
  path: string
  open: boolean
  collapsed: boolean
  onClose: () => void
  onToggleCollapsed: () => void
  onLogout: () => void
}) {
  const sidebar = buildSidebar(me, path)
  const [closedSections, setClosedSections] = useState(loadCollapsedSections)
  // Chữ hiện ở ngăn kéo điện thoại và màn ≥1024px chưa thu gọn; dạng icon thì ẩn.
  const text = collapsed ? 'md:hidden' : 'md:hidden lg:inline'
  const block = collapsed ? 'md:hidden' : 'md:hidden lg:block'

  function toggleSection(label: string) {
    const next = closedSections.includes(label) ? closedSections.filter((s) => s !== label) : [...closedSections, label]
    setClosedSections(next)
    try {
      localStorage.setItem(COLLAPSED_SECTIONS_KEY, JSON.stringify(next))
    } catch {
      // Trình duyệt chặn lưu trữ: chỉ không nhớ trạng thái.
    }
  }

  return (
    <>
      <aside
        className={`fixed top-0 left-0 z-40 flex h-dvh w-64 flex-col bg-sidebar text-slate-300 shadow-xl transition-[transform,width] duration-200 md:translate-x-0 md:shadow-none ${
          open ? 'translate-x-0' : '-translate-x-full'
        } ${collapsed ? 'md:w-16' : 'md:w-16 lg:w-64'}`}
      >
        <button
          type="button"
          onClick={onToggleCollapsed}
          aria-label={collapsed ? 'Mở rộng menu' : 'Thu gọn menu'}
          title={collapsed ? 'Mở rộng menu' : 'Thu gọn menu'}
          className="absolute top-5 -right-3 z-50 hidden size-6 items-center justify-center rounded-full border border-surface-container-highest bg-surface text-on-surface-variant shadow-md hover:bg-primary hover:text-white lg:flex"
        >
          <Icon name={collapsed ? 'chevron_right' : 'chevron_left'} className="text-[18px]" />
        </button>

        <div className={`flex h-16 shrink-0 items-center gap-2 px-4 ${collapsed ? 'md:justify-center md:px-0' : 'md:justify-center md:px-0 lg:justify-start lg:px-4'}`}>
          <a
            href="/"
            onClick={(e) => go(e, '/', onClose)}
            className="flex min-w-0 items-center gap-2 rounded-lg focus-visible:outline-2 focus-visible:outline-primary"
            title="Về trang đầu"
          >
            <span className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-surface">
              <img src="/images/menglish-logo.png" alt="MEnglish" width={40} height={40} className="size-full object-contain" />
            </span>
            <span className={`min-w-0 flex-col ${collapsed ? 'flex md:hidden' : 'flex md:hidden lg:flex'}`}>
              <span className="text-lg leading-none font-bold tracking-tight text-brand">MENGLISH</span>
              <span className="mt-1 text-[11px] font-semibold tracking-widest text-slate-400 uppercase">Hệ thống quản trị</span>
            </span>
          </a>
          <button
            type="button"
            onClick={onClose}
            aria-label="Đóng menu"
            className="ml-auto inline-flex size-11 items-center justify-center rounded-lg text-slate-400 hover:bg-white/10 hover:text-white md:hidden"
          >
            <Icon name="close" className="text-[20px]" />
          </button>
        </div>

        <nav aria-label="Menu chính" className="min-h-0 flex-1 space-y-1 overflow-y-auto px-2 py-2">
          {sidebar.top.map((link) => (
            <SideLink key={link.key} link={link} text={text} onNavigate={onClose} />
          ))}
          {sidebar.sections.map((section) => {
            const closed = closedSections.includes(section.label) && !section.links.some((l) => l.active)
            return (
              <div key={section.label}>
                <button
                  type="button"
                  onClick={() => toggleSection(section.label)}
                  aria-expanded={!closed}
                  className={`flex w-full items-center justify-between px-3 pt-4 pb-1 text-xs font-semibold tracking-widest text-slate-400 uppercase hover:text-white ${block}`}
                >
                  {section.label}
                  <Icon name={closed ? 'expand_more' : 'expand_less'} className="text-[18px]" />
                </button>
                <div aria-hidden="true" className={`mx-auto my-2 hidden h-px w-8 bg-white/10 ${collapsed ? 'md:block' : 'md:block lg:hidden'}`} />
                {!closed && (
                  <div className="space-y-1">
                    {section.links.map((link) => (
                      <SideLink key={link.key} link={link} text={text} onNavigate={onClose} />
                    ))}
                  </div>
                )}
              </div>
            )
          })}
          {sidebar.settings && (
            <>
              <div aria-hidden="true" className="mx-3 my-2 h-px bg-white/10" />
              <SideLink
                link={{ key: 'settings', label: 'Cài đặt', icon: 'settings', path: sidebar.settings.path, active: sidebar.settings.active }}
                text={text}
                onNavigate={onClose}
              />
            </>
          )}
        </nav>

        <div className="shrink-0 space-y-1 border-t border-white/10 px-2 py-2">
          <div className={`flex items-center gap-2 px-3 py-1 ${collapsed ? 'md:justify-center md:px-0' : 'md:justify-center md:px-0 lg:justify-start lg:px-3'}`} title={`Vai trò: ${roleLabel(me)}`}>
            <span className="size-2 shrink-0 rounded-full bg-success" />
            <span className={`truncate text-xs text-slate-400 ${text}`}>
              Vai trò: <span className="font-semibold text-white">{roleLabel(me)}</span>
            </span>
          </div>
          <button
            type="button"
            onClick={onLogout}
            title="Đăng xuất"
            className={`flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-red-300 hover:bg-error/20 hover:text-red-200 ${collapsed ? 'md:justify-center md:px-0' : 'md:justify-center md:px-0 lg:justify-start lg:px-3'}`}
          >
            <Icon name="logout" />
            <span className={text}>Đăng xuất</span>
          </button>
        </div>
      </aside>
      {open && <div className="fixed inset-0 z-30 bg-black/50 md:hidden" onClick={onClose} aria-hidden="true" />}
    </>
  )
}

function roleLabel(me: Me) {
  return me.roles[0]?.short_label ?? 'Người dùng'
}

function go(e: MouseEvent, path: string, after: () => void) {
  if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return
  e.preventDefault()
  navigate(path)
  after()
}

function SideLink({ link, text, onNavigate }: { link: SidebarLink; text: string; onNavigate: () => void }) {
  return (
    <a
      href={link.path}
      onClick={(e) => go(e, link.path, onNavigate)}
      aria-current={link.active ? 'page' : undefined}
      title={link.label}
      className={`relative flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors md:min-h-0 ${
        text.includes('lg:inline') ? 'md:justify-center md:px-0 lg:justify-start lg:px-3' : 'md:justify-center md:px-0'
      } ${link.active ? 'bg-primary text-white shadow-lg shadow-primary/20' : 'hover:bg-white/10 hover:text-white'}`}
    >
      <Icon name={link.icon} fill={link.active} />
      <span className={`truncate ${text}`}>{link.label}</span>
    </a>
  )
}
