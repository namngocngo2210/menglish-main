import { useEffect, useLayoutEffect, useRef, useState, type MouseEvent, type SyntheticEvent } from 'react'
import type { Me } from '../../api'
import { isPortalOnly } from '../../features/portal/logic'
import { buildSidebar, type SidebarLink } from '../../nav'
import { navigate } from '../../router'
import { Icon } from '../ui'

// Sidebar như Layouts/Shell/Sidebar.vue của Laravel: nền tối, tiêu đề khu (section) cố định, mỗi workspace một dòng có icon,
// "Cài đặt" ở cuối, vai trò + Đăng xuất ở chân. ≥1200px rộng 240px (thu gọn được thành cột icon 72px, nhớ trong trình
// duyệt), 768–1199px luôn dạng icon, <768px là ngăn kéo mở bằng nút menu trên topbar (rộng hơn chút cho đủ chỗ dòng chữ
// dưới logo cạnh nút đóng). Dạng icon: rê chuột / focus vào mục hiện tooltip tên mục.

const SCROLL_KEY = 'sidebar_scroll_top'

type Tip = { text: string; top: number; left: number }

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
  const root = useRef<HTMLElement>(null)
  const nav = useRef<HTMLElement>(null)
  const saveTimer = useRef<number | undefined>(undefined)
  const [moreBelow, setMoreBelow] = useState(false)
  const [tip, setTip] = useState<Tip | null>(null)

  // Chữ hiện ở ngăn kéo điện thoại và màn ≥1200px chưa thu gọn; dạng icon thì ẩn.
  const text = collapsed ? 'md:hidden' : 'md:hidden desktop:inline'
  const block = collapsed ? 'md:hidden' : 'md:hidden desktop:block'
  const iconOnly = collapsed ? 'md:block' : 'md:block desktop:hidden'
  const center = collapsed ? 'md:justify-center md:px-0' : 'md:justify-center md:px-0 desktop:justify-start desktop:px-4'

  function checkMore() {
    const el = nav.current
    setMoreBelow(!!el && el.scrollTop + el.clientHeight < el.scrollHeight - 8)
  }

  // Giữ vị trí cuộn giữa các lần tải trang; lần đầu thì cuộn tới mục đang mở.
  useLayoutEffect(() => {
    const el = nav.current
    if (!el) return
    let saved: string | null = null
    try {
      saved = sessionStorage.getItem(SCROLL_KEY)
    } catch {
      // Trình duyệt chặn lưu trữ.
    }
    if (saved !== null) el.scrollTop = parseInt(saved, 10) || 0
    else el.querySelector('[aria-current="page"]')?.scrollIntoView?.({ block: 'nearest' })
    checkMore()
  }, [])

  // Số mục đổi (quyền, thu gọn) hoặc cửa sổ đổi cỡ thì tính lại mũi tên "còn mục bên dưới".
  useEffect(() => {
    checkMore()
    window.addEventListener('resize', checkMore, { passive: true })
    return () => window.removeEventListener('resize', checkMore)
  }, [collapsed, me, path])

  function onScroll() {
    setTip(null)
    checkMore()
    window.clearTimeout(saveTimer.current)
    saveTimer.current = window.setTimeout(() => {
      try {
        sessionStorage.setItem(SCROLL_KEY, String(nav.current?.scrollTop ?? 0))
      } catch {
        // Không lưu được thì chỉ không nhớ.
      }
    }, 100)
  }

  function showTip(e: SyntheticEvent) {
    const item = (e.target as HTMLElement).closest?.<HTMLElement>('[data-tooltip]')
    if (!item || (root.current?.offsetWidth ?? 0) > 100) {
      setTip(null)
      return
    }
    const rect = item.getBoundingClientRect()
    setTip({ text: item.dataset.tooltip ?? '', top: rect.top + rect.height / 2, left: rect.right + 12 })
  }

  return (
    <>
      <aside
        ref={root}
        onMouseOver={showTip}
        onFocus={showTip}
        onMouseLeave={() => setTip(null)}
        onBlur={() => setTip(null)}
        className={`fixed top-0 left-0 z-40 flex h-dvh w-72 flex-col bg-sidebar text-slate-300 shadow-xl transition-[transform,width] duration-200 md:translate-x-0 md:shadow-none ${
          open ? 'translate-x-0' : '-translate-x-full'
        } ${collapsed ? 'md:w-18' : 'md:w-18 desktop:w-60'}`}
      >
        <button
          type="button"
          onClick={() => {
            setTip(null)
            onToggleCollapsed()
          }}
          aria-expanded={!collapsed}
          aria-label="Thu gọn / mở rộng menu"
          data-tooltip={collapsed ? 'Mở rộng menu' : 'Thu gọn menu'}
          className="absolute top-5 -right-3 z-50 hidden size-6 items-center justify-center rounded-full border border-surface-container-highest bg-surface text-on-surface-variant shadow-md transition-colors hover:bg-primary hover:text-white desktop:flex"
        >
          <Icon name={collapsed ? 'chevron_right' : 'chevron_left'} className="text-[18px]" />
        </button>

        <div className={`flex h-16 shrink-0 items-center gap-2 px-4 ${center}`}>
          <a
            href="/"
            onClick={(e) => go(e, '/', onClose)}
            className="flex min-w-0 items-center gap-2 rounded-lg focus-visible:outline-2 focus-visible:outline-primary"
            title="Về trang tổng quan"
          >
            <span className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-surface">
              <img src="/images/menglish-logo.png" alt="MEnglish" width={40} height={40} className="size-full object-contain" />
            </span>
            <span className={`min-w-0 flex-col ${collapsed ? 'flex md:hidden' : 'flex md:hidden desktop:flex'}`}>
              <span className="text-lg leading-none font-bold tracking-tight text-brand">MENGLISH</span>
              <span className="mt-1 text-xs font-semibold tracking-widest whitespace-nowrap text-slate-400 uppercase">{isPortalOnly(me) ? 'Cổng học viên' : 'Hệ thống quản trị'}</span>
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

        <div className="relative flex min-h-0 flex-1 flex-col">
          <nav
            ref={nav}
            aria-label="Menu chính"
            onScroll={onScroll}
            className={`sidebar-scrollbar min-h-0 flex-1 space-y-1 overflow-y-auto px-2 py-2 ${
              moreBelow ? '[mask-image:linear-gradient(to_bottom,#000_calc(100%-2rem),transparent)]' : ''
            }`}
          >
            {sidebar.top.map((link) => (
              <SideLink key={link.key} link={link} text={text} center={center} onNavigate={onClose} />
            ))}
            {sidebar.sections.map((section) => (
              <div key={section.label} className="space-y-1">
                <div className={`px-4 pt-4 pb-1 text-xs font-semibold tracking-widest text-slate-400 uppercase ${block}`}>{section.label}</div>
                <div aria-hidden="true" className={`mx-auto my-2 hidden h-px w-8 bg-white/10 ${iconOnly}`} />
                {section.links.map((link) => (
                  <SideLink key={link.key} link={link} text={text} center={center} onNavigate={onClose} />
                ))}
              </div>
            ))}
            {sidebar.settings && (
              <>
                <div aria-hidden="true" className="mx-4 my-2 h-px bg-white/10" />
                <SideLink
                  link={{ key: 'settings', label: 'Cài đặt', icon: 'settings', path: sidebar.settings.path, active: sidebar.settings.active }}
                  text={text}
                  center={center}
                  onNavigate={onClose}
                />
              </>
            )}
          </nav>
          {moreBelow && (
            <div className="flex shrink-0 justify-center border-t border-white/10 py-1">
              <button
                type="button"
                aria-label="Cuộn xuống xem thêm mục menu"
                onClick={() => nav.current?.scrollBy({ top: nav.current.clientHeight * 0.6, behavior: 'smooth' })}
                className="inline-flex items-center justify-center rounded-full p-0.5 text-slate-400 hover:bg-white/10 hover:text-white max-md:min-h-11 max-md:min-w-11"
              >
                <Icon name="keyboard_arrow_down" className="text-[20px]" />
              </button>
            </div>
          )}
        </div>

        <div className="shrink-0 space-y-1 border-t border-white/10 px-2 py-2">
          <div className={`flex items-center gap-2 px-4 py-1 ${center}`} data-tooltip={`Vai trò: ${roleLabel(me)}`}>
            <span className="size-2 shrink-0 rounded-full bg-success" />
            <span className={`truncate text-xs text-slate-400 ${text}`}>
              Vai trò: <span className="font-semibold text-white">{roleLabel(me)}</span>
            </span>
          </div>
          <button
            type="button"
            onClick={onLogout}
            aria-label="Đăng xuất"
            data-tooltip="Đăng xuất"
            className={`flex w-full items-center gap-4 rounded-lg px-4 py-2 text-sm font-medium text-red-300 transition-colors hover:bg-error/20 hover:text-red-200 max-md:min-h-11 ${center}`}
          >
            <Icon name="logout" className="shrink-0" />
            <span className={text}>Đăng xuất</span>
          </button>
        </div>

        {tip && (
          <div
            role="tooltip"
            className="pointer-events-none fixed z-50 -translate-y-1/2 rounded-md bg-slate-800 px-2.5 py-1.5 text-xs font-semibold whitespace-nowrap text-white shadow-lg ring-1 ring-white/10"
            style={{ top: tip.top, left: tip.left }}
          >
            {tip.text}
          </div>
        )}
      </aside>
      {open && <div className="fixed inset-0 z-30 bg-black/50 backdrop-blur-xs md:hidden" onClick={onClose} aria-hidden="true" />}
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

function SideLink({ link, text, center, onNavigate }: { link: SidebarLink; text: string; center: string; onNavigate: () => void }) {
  return (
    <a
      href={link.path}
      onClick={(e) => go(e, link.path, onNavigate)}
      aria-current={link.active ? 'page' : undefined}
      aria-label={link.label}
      data-tooltip={link.label}
      className={`relative flex items-center gap-4 rounded-lg px-4 py-2 text-sm font-medium transition-colors duration-150 active:scale-95 max-md:min-h-11 ${center} ${
        link.active ? 'bg-primary text-white shadow-lg shadow-primary/20' : 'hover:bg-white/10 hover:text-white'
      }`}
    >
      <Icon name={link.icon} fill={link.active} className="shrink-0" />
      <span className={`truncate ${text}`}>{link.label}</span>
    </a>
  )
}
