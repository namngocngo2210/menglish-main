import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type ReactNode } from 'react'
import { api, type Me } from '../api'
import { CommandPalette } from '../features/platform/CommandPalette'
import { NotificationBell } from '../features/platform/NotificationBell'
import { PortalBell } from '../features/portal/components'
import { isPortalOnly } from '../features/portal/logic'
import { can, pageTitle, settingsNav, workspaceTabs } from '../nav'
import { usePath } from '../router'
import { Sidebar } from './shell/Sidebar'
import { SettingsNav, WorkspaceTabs } from './shell/PageNav'
import { Topbar } from './shell/Topbar'

const COLLAPSED_KEY = 'sidebar_collapsed'

function loadCollapsed() {
  try {
    return localStorage.getItem(COLLAPSED_KEY) === '1'
  } catch {
    return false
  }
}

// Khung sau đăng nhập như AppLayout.vue của Laravel: sidebar trái theo nhóm (nav.ts), topbar (tiêu đề, tìm kiếm chung,
// thông báo, tài khoản), tab của workspace hoặc menu Cài đặt ở đầu nội dung, chân trang.
export function Shell({ me, children }: { me: Me; children: ReactNode }) {
  const qc = useQueryClient()
  const path = usePath()
  const meta = useQuery({ queryKey: ['meta'], queryFn: api.meta })
  const logout = useMutation({
    mutationFn: api.logout,
    onSettled: () => qc.invalidateQueries({ queryKey: ['me'] }),
  })
  const [open, setOpen] = useState(false)
  const [collapsed, setCollapsed] = useState(loadCollapsed)
  const [searching, setSearching] = useState(false)
  // Tài khoản chỉ dùng cổng học viên: không tìm kiếm chung, chuông mở Hộp thư thông báo của cổng.
  const portal = isPortalOnly(me)

  // Chuyển trang thì đóng ngăn kéo menu trên điện thoại.
  useEffect(() => setOpen(false), [path])

  // Ctrl K / ⌘ K mở bảng tìm nhanh ở mọi trang.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (!portal && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        setSearching(true)
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [portal])

  function toggleCollapsed() {
    setCollapsed((v) => {
      try {
        localStorage.setItem(COLLAPSED_KEY, v ? '0' : '1')
      } catch {
        // Không lưu được thì chỉ không nhớ.
      }
      return !v
    })
  }

  const ws = workspaceTabs(me, path)
  // Menu Cài đặt chỉ hiện khi trang đang mở thuộc trang Cài đặt.
  const sections = settingsNav(me, path)
  const settings = sections.some((s) => s.items.some((i) => i.active)) ? sections : []

  return (
    <div className="min-h-dvh bg-background text-on-surface" onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}>
      <a
        href="#main-content"
        className="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-[100] focus:rounded-lg focus:bg-surface focus:px-4 focus:py-2 focus:text-primary focus:shadow-lg"
      >
        Bỏ qua menu, tới nội dung chính
      </a>
      <Sidebar
        me={me}
        path={path}
        open={open}
        collapsed={collapsed}
        onClose={() => setOpen(false)}
        onToggleCollapsed={toggleCollapsed}
        onLogout={() => logout.mutate()}
      />
      <div className={`flex min-h-dvh min-w-0 flex-col transition-[padding] duration-200 ${collapsed ? 'md:pl-18' : 'md:pl-18 desktop:pl-60'}`}>
        <Topbar
          me={me}
          title={portal && path === '/' ? 'Trang chủ' : pageTitle(path)}
          bell={portal ? <PortalBell /> : can(me, 'notification.view') ? <NotificationBell /> : null}
          search={!portal}
          onOpenMenu={() => setOpen(true)}
          onOpenSearch={() => setSearching(true)}
          onLogout={() => logout.mutate()}
        />
        <main id="main-content" tabIndex={-1} className="flex-1 p-4 focus:outline-none lg:p-6">
          {ws && <WorkspaceTabs label={ws.label} tabs={ws.tabs} />}
          {settings.length > 0 ? (
            <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
              <SettingsNav sections={settings} />
              <div className="min-w-0 flex-1">{children}</div>
            </div>
          ) : (
            children
          )}
        </main>
        <footer className="mt-auto flex flex-col items-center justify-center gap-1 border-t border-surface-container-highest bg-surface px-4 py-4 text-xs text-on-surface-variant select-none sm:flex-row lg:px-6">
          <span className="font-semibold text-on-surface">MENGLISH</span>
          <span aria-hidden="true">•</span>
          <span>Hệ thống quản trị giáo dục &amp; học vụ</span>
          {meta.data && (
            <span className="text-on-surface-subtle sm:ml-2">
              {meta.data.env} · {meta.data.version.slice(0, 12)}
            </span>
          )}
        </footer>
      </div>
      {searching && <CommandPalette me={me} onClose={() => setSearching(false)} />}
    </div>
  )
}
