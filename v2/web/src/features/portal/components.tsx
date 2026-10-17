// Khung chung các màn cổng học viên (PortalTopHeader / PortalBottomNav của Laravel): thanh trên có học viên đang xem và
// ô đổi học viên, thanh điều hướng đáy trên điện thoại, thẻ khối nội dung, chuông thông báo của tài khoản cổng.
import { useQuery } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { errorMessage } from '../../api'
import { Alert, Icon } from '../../components/ui'
import { navigate, usePath, useQueryParam } from '../../router'
import { unreadBadge } from '../platform/api'
import { studentsApi } from '../students/api'
import { portalApi, type PortalContext } from './api'
import { portalHref } from './logic'

// Học viên đang xem lấy từ ?student_id= (trống = học viên đầu tiên gắn với tài khoản).
export function useStudentParam() {
  return useQueryParam('student_id')
}

type Tab = 'home' | 'learning' | 'pronunciation' | 'survey' | 'notifications'

const BOTTOM: { key: Tab; path: string; icon: string; label: string }[] = [
  { key: 'home', path: '/portal/home', icon: 'home', label: 'Trang chủ' },
  { key: 'learning', path: '/portal/student-homework', icon: 'menu_book', label: 'Học tập' },
  { key: 'pronunciation', path: '/portal/pronunciation', icon: 'mic', label: 'Phát âm' },
  { key: 'survey', path: '/portal/survey', icon: 'assignment', label: 'Khảo sát' },
  { key: 'notifications', path: '/portal/notifications', icon: 'notifications', label: 'Thông báo' },
]

function go(e: React.MouseEvent, href: string) {
  if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return
  e.preventDefault()
  navigate(href)
}

export function PortalLink({ href, className, children, title }: { href: string; className?: string; children: ReactNode; title?: string }) {
  return (
    <a href={href} onClick={(e) => go(e, href)} className={className} title={title}>
      {children}
    </a>
  )
}

// Khung màn cổng: thanh trên, nội dung, thanh đáy (điện thoại).
export function PortalFrame({
  ctx,
  title,
  tab,
  actions,
  children,
}: {
  ctx: PortalContext | undefined
  title: string
  tab: Tab
  actions?: ReactNode
  children: ReactNode
}) {
  const path = usePath()
  const student = ctx?.student ?? null
  const students = ctx?.students ?? []
  return (
    <div className="mx-auto max-w-5xl pb-24 md:pb-0">
      <header className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-surface-container-highest bg-surface px-4 py-3 shadow-sm">
        <div className="min-w-0">
          <h1 className="text-lg font-bold text-primary">{title}</h1>
          {student && (
            <p className="truncate text-xs font-medium text-on-surface-variant">
              {student.name} • {student.class_name ?? 'Chưa xếp lớp'}
            </p>
          )}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {actions}
          {students.length > 1 && (
            <select
              aria-label="Chọn học viên"
              value={student?.id ?? ''}
              onChange={(e) => navigate(portalHref(path, e.target.value))}
              className="h-9 rounded-lg border border-outline-variant bg-surface px-2 text-xs font-semibold outline-none focus:border-primary"
            >
              {students.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name} ({s.class_name ?? 'Chưa xếp lớp'})
                </option>
              ))}
            </select>
          )}
          <PortalLink
            href={portalHref('/portal/app-shell', student?.id)}
            title="Xem App Shell & Menu"
            className="flex size-9 items-center justify-center rounded-full bg-primary/10 text-primary hover:opacity-80"
          >
            <Icon name="account_circle" className="text-[22px]" />
          </PortalLink>
        </div>
      </header>
      {ctx?.staff && <StaffPicker path={path} />}
      {children}
      <nav
        aria-label="Điều hướng cổng học viên"
        className="fixed right-0 bottom-0 left-0 z-40 mx-auto flex w-full max-w-[430px] items-center justify-around rounded-t-2xl border-t border-surface-container-highest bg-surface py-2 shadow-lg md:hidden"
      >
        {BOTTOM.map((b) => {
          const active = b.key === tab
          return (
            <PortalLink
              key={b.key}
              href={portalHref(b.path, student?.id)}
              className={`flex flex-col items-center px-2 py-1 ${active ? 'font-bold text-primary' : 'text-on-surface-variant'}`}
            >
              <span className="relative">
                <Icon name={b.icon} fill={active} className="text-[24px]" />
                {b.key === 'notifications' && (ctx?.unread_count ?? 0) > 0 && (
                  <span className="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-error px-1 text-[10px] font-bold text-white">
                    {unreadBadge(ctx?.unread_count ?? 0)}
                  </span>
                )}
              </span>
              <span className="text-xs">{b.label}</span>
            </PortalLink>
          )
        })}
      </nav>
    </div>
  )
}

// Nhân sự xem hộ (student.view): tìm học viên trong phạm vi rồi mở cổng của học viên đó.
function StaffPicker({ path }: { path: string }) {
  const [term, setTerm] = useState('')
  const q = term.trim()
  const list = useQuery({
    queryKey: ['portal', 'staff-pick', q],
    queryFn: () => studentsApi.list({ q, statuses: ['waiting_start', 'studying', 'summer_break'], per_page: 8 }),
    enabled: q.length >= 2,
  })
  return (
    <div className="mb-4 rounded-2xl border border-surface-container-highest bg-surface p-4 shadow-sm">
      <label className="block space-y-1.5">
        <span className="block text-sm font-medium text-on-surface">Xem hộ học viên</span>
        <input
          type="search"
          value={term}
          onChange={(e) => setTerm(e.target.value)}
          placeholder="Nhập mã, tên hoặc số điện thoại học viên..."
          className="block h-10 w-full rounded-lg border border-outline-variant px-3 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
        />
      </label>
      {list.isError && (
        <div className="mt-2">
          <Alert>{errorMessage(list.error)}</Alert>
        </div>
      )}
      {list.data && (
        <ul className="mt-2 divide-y divide-surface-container-highest text-sm">
          {list.data.students.map((s) => (
            <li key={s.id}>
              <button
                type="button"
                onClick={() => navigate(portalHref(path, s.id))}
                className="flex w-full items-center justify-between gap-2 px-2 py-2 text-left hover:bg-surface-container-low"
              >
                <span>
                  <span className="font-medium">{s.full_name}</span> <span className="font-mono text-xs text-on-surface-variant">{s.code}</span>
                </span>
                <span className="text-xs text-on-surface-variant">{s.status_label}</span>
              </button>
            </li>
          ))}
          {list.data.students.length === 0 && <li className="px-2 py-2 text-xs text-on-surface-variant">Không tìm thấy học viên phù hợp.</li>}
        </ul>
      )}
    </div>
  )
}

// Hai tab con (Nộp bài tập / Luyện phát âm; Khảo sát định kỳ / Feedback chặng học).
export function SubTabs({ items }: { items: { href: string; label: string; icon: string; active: boolean }[] }) {
  return (
    <div className="mb-4 flex items-center gap-1 border-b border-surface-container-highest">
      {items.map((t) => (
        <PortalLink
          key={t.href}
          href={t.href}
          className={`flex items-center gap-1.5 border-b-2 px-4 py-2 text-xs ${t.active ? 'border-primary font-bold text-primary' : 'border-transparent font-semibold text-on-surface-variant hover:text-on-surface'}`}
        >
          <Icon name={t.icon} className="text-[16px]" />
          {t.label}
        </PortalLink>
      ))}
    </div>
  )
}

export function Block({ title, icon, aside, children, className = '' }: { title?: string; icon?: string; aside?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={`rounded-2xl border border-surface-container-highest bg-surface p-4 shadow-sm ${className}`}>
      {title && (
        <div className="mb-3 flex items-center justify-between gap-2">
          <h2 className="flex items-center gap-1.5 text-sm font-bold text-on-surface">
            {icon && <Icon name={icon} className="text-[18px] text-primary" />}
            {title}
          </h2>
          {aside}
        </div>
      )}
      {children}
    </section>
  )
}

export function Empty({ icon, title, description }: { icon: string; title: string; description?: string }) {
  return (
    <div className="flex flex-col items-center gap-1 rounded-xl border border-dashed border-surface-container-highest bg-surface-container-low px-4 py-6 text-center">
      <Icon name={icon} className="text-[28px] text-on-surface-variant" />
      <p className="text-sm font-medium text-on-surface">{title}</p>
      {description && <p className="max-w-sm text-xs text-on-surface-variant">{description}</p>}
    </div>
  )
}

export function Pill({ tone = 'neutral', children }: { tone?: 'neutral' | 'success' | 'error' | 'warning' | 'info'; children: ReactNode }) {
  const styles = {
    neutral: 'bg-surface-container-low text-on-surface-variant',
    success: 'bg-success-container text-on-success-container',
    error: 'bg-error-container text-on-error-container',
    info: 'bg-info-container text-on-info-container',
    warning: 'bg-warning-container text-warning',
  }[tone]
  return <span className={`inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ${styles}`}>{children}</span>
}

// Thông báo kết quả thao tác (flash "success" / lỗi của Laravel).
export function useNotice() {
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  return {
    notice,
    ok: (text: string) => setNotice({ tone: 'success', text }),
    fail: (e: unknown) => setNotice({ tone: 'error', text: errorMessage(e) }),
    clear: () => setNotice(null),
    view: notice ? (
      <div className="mb-4">
        <Alert tone={notice.tone}>{notice.text}</Alert>
      </div>
    ) : null,
  }
}

export function QueryError({ error }: { error: unknown }) {
  return (
    <div className="mx-auto max-w-5xl">
      <Alert>{errorMessage(error)}</Alert>
    </div>
  )
}

// Ô nhập nhiều dòng theo kiểu Field.
export function TextArea({ label, value, onChange, rows = 3, placeholder, maxLength }: {
  label: string
  value: string
  onChange: (v: string) => void
  rows?: number
  placeholder?: string
  maxLength?: number
}) {
  return (
    <label className="block space-y-1.5">
      <span className="block text-sm font-medium text-on-surface">{label}</span>
      <textarea
        value={value}
        rows={rows}
        placeholder={placeholder}
        maxLength={maxLength}
        onChange={(e) => onChange(e.target.value)}
        className="block w-full rounded-lg border border-outline-variant px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
      />
    </label>
  )
}

// Chuông trên topbar của tài khoản cổng (notificationMenu portal của Laravel): số chưa đọc, bấm mở Hộp thư thông báo.
export function PortalBell() {
  const s = useQuery({ queryKey: ['portal', 'summary'], queryFn: portalApi.summary, refetchInterval: 60_000 })
  const n = s.data?.unread_count ?? 0
  return (
    <a
      href="/portal/notifications"
      onClick={(e) => go(e, '/portal/notifications')}
      aria-label={n > 0 ? `Thông báo (${n} chưa đọc)` : 'Thông báo'}
      className="relative inline-flex size-10 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container-high"
    >
      <Icon name="notifications" />
      {n > 0 && (
        <span
          aria-hidden="true"
          className="absolute top-1 right-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full border-2 border-surface bg-error px-1 font-mono text-[11px] font-bold text-white"
        >
          {unreadBadge(n)}
        </span>
      )}
    </a>
  )
}
