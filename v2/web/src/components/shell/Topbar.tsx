import type { ReactNode } from 'react'
import type { Me } from '../../api'
import { navigate } from '../../router'
import { Icon } from '../ui'
import { Popover } from './Popover'

// Topbar như Layouts/Shell/Topbar.vue: nút menu (điện thoại) · tiêu đề trang · ô tìm kiếm chung · chuông · tài khoản.
export function Topbar({
  me,
  title,
  bell,
  onOpenMenu,
  onOpenSearch,
  onLogout,
}: {
  me: Me
  title: string
  bell: ReactNode
  onOpenMenu: () => void
  onOpenSearch: () => void
  onLogout: () => void
}) {
  const login = me.user.code ?? me.user.email ?? me.user.phone ?? ''
  return (
    <header className="sticky top-0 z-30 flex h-16 shrink-0 items-center justify-between gap-3 border-b border-surface-container-highest bg-surface px-4 lg:px-6">
      <div className="flex min-w-0 flex-1 items-center gap-3 lg:gap-6">
        <button
          type="button"
          onClick={onOpenMenu}
          aria-label="Mở menu"
          className="inline-flex size-11 shrink-0 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container-high md:hidden"
        >
          <Icon name="menu" />
        </button>
        <div className="min-w-0 truncate text-lg font-semibold text-on-surface">{title}</div>
        <button
          type="button"
          onClick={onOpenSearch}
          className="hidden w-[300px] shrink-0 items-center gap-2 rounded-full bg-surface-container-low py-2 pr-3 pl-3 text-left text-sm text-on-surface-variant hover:bg-surface-container-high lg:flex"
        >
          <Icon name="search" className="text-[20px]" />
          <span className="flex-1 truncate">Tìm màn hình, người dùng, chi nhánh...</span>
          <kbd className="rounded border border-outline-variant px-1.5 font-mono text-[11px]">Ctrl K</kbd>
        </button>
        <button
          type="button"
          onClick={onOpenSearch}
          aria-label="Tìm kiếm"
          className="inline-flex size-10 shrink-0 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container-high lg:hidden"
        >
          <Icon name="search" />
        </button>
      </div>

      <div className="flex shrink-0 items-center gap-1 sm:gap-3">
        {bell}
        <Popover
          label="Tài khoản"
          trigger={
            <>
              <span className="hidden text-right lg:block">
                <span className="block max-w-[160px] truncate text-sm leading-tight font-medium text-on-surface">{me.user.full_name}</span>
                <span className="block text-xs text-on-surface-variant">{login}</span>
              </span>
              <Avatar name={me.user.full_name} />
            </>
          }
        >
          {(close) => (
            <div role="menu">
              <div className="border-b border-surface-container-high px-4 py-3">
                <div className="truncate text-sm font-semibold text-on-surface">{me.user.full_name}</div>
                <div className="truncate text-xs text-on-surface-variant">{login}</div>
              </div>
              <button
                type="button"
                role="menuitem"
                onClick={() => {
                  close()
                  navigate('/')
                }}
                className="flex w-full items-center gap-2 px-4 py-2.5 text-sm text-on-surface hover:bg-surface-container-low"
              >
                <Icon name="account_circle" className="text-[20px] text-on-surface-variant" /> Tài khoản của tôi
              </button>
              <button
                type="button"
                role="menuitem"
                onClick={() => {
                  close()
                  onLogout()
                }}
                className="flex w-full items-center gap-2 px-4 py-2.5 text-sm text-error hover:bg-error-container/40"
              >
                <Icon name="logout" className="text-[20px]" /> Đăng xuất
              </button>
            </div>
          )}
        </Popover>
      </div>
    </header>
  )
}

// Ảnh đại diện chữ cái đầu (như UiAvatar): chữ cái đầu của tên cuối.
export function Avatar({ name, size = 'size-8' }: { name: string; size?: string }) {
  const words = name.trim().split(/\s+/)
  const initials = ((words.length > 1 ? words[0][0] : '') + (words[words.length - 1]?.[0] ?? '?')).toUpperCase()
  return (
    <span
      className={`flex ${size} shrink-0 items-center justify-center rounded-full bg-primary-light text-xs font-semibold text-primary ring-2 ring-primary/20`}
      aria-hidden="true"
    >
      {initials}
    </span>
  )
}
