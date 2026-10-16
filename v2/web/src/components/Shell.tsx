import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { MouseEvent, ReactNode } from 'react'
import { api, type Me } from '../api'
import { navItemFor, visibleNav } from '../nav'
import { navigate, usePath } from '../router'
import { Button } from './ui'

// Khung tạm sau đăng nhập: thanh trên cùng có menu theo quyền. Bước 3 thay bằng sidebar như bản Laravel.
export function Shell({ me, children }: { me: Me; children: ReactNode }) {
  const qc = useQueryClient()
  const path = usePath()
  const meta = useQuery({ queryKey: ['meta'], queryFn: api.meta })
  const logout = useMutation({
    mutationFn: api.logout,
    onSettled: () => qc.invalidateQueries({ queryKey: ['me'] }),
  })
  const current = navItemFor(path)

  return (
    <div className="min-h-dvh bg-background text-on-surface">
      <header className="border-b border-surface-container-highest bg-surface">
        <div className="mx-auto flex h-14 max-w-6xl items-center justify-between gap-4 px-4">
          <span className="flex shrink-0 items-center gap-2 font-semibold">
            <img src="/images/menglish-logo.png" alt="" width={32} height={32} className="size-8 rounded-md" />
            MEnglish
          </span>
          <div className="flex items-center gap-3 text-sm">
            <span className="hidden text-on-surface-variant sm:inline">{me.user.full_name}</span>
            <Button variant="secondary" onClick={() => logout.mutate()} disabled={logout.isPending}>
              Đăng xuất
            </Button>
          </div>
        </div>
        <nav aria-label="Menu chính" className="mx-auto flex max-w-6xl gap-1 overflow-x-auto px-4">
          {visibleNav(me).map((item) => (
            <a
              key={item.path}
              href={item.path}
              onClick={(e: MouseEvent) => {
                e.preventDefault()
                navigate(item.path)
              }}
              aria-current={current?.path === item.path ? 'page' : undefined}
              className="border-b-2 border-transparent px-3 py-2 text-sm whitespace-nowrap text-on-surface-variant hover:text-on-surface aria-[current=page]:border-primary aria-[current=page]:font-medium aria-[current=page]:text-primary"
            >
              {item.label}
            </a>
          ))}
        </nav>
      </header>
      <main className="mx-auto max-w-6xl px-4 py-6">{children}</main>
      {meta.data && (
        <p className="pb-6 text-center text-xs text-on-surface-subtle">
          {meta.data.env} · {meta.data.version.slice(0, 12)}
        </p>
      )}
    </div>
  )
}
