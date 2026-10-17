// Thành phần dùng chung của cổng giáo viên.
import { useId, type ReactNode, type TextareaHTMLAttributes } from 'react'
import { Alert, Button, Card, Icon } from '../../components/ui'
import { errorMessage } from '../../api'
import { navigate } from '../../router'

export function Header({ title, description, actions }: { title: ReactNode; description?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div className="min-w-0">
        <h1 className="text-xl font-semibold text-on-surface">{title}</h1>
        {description && <p className="mt-1 text-sm text-on-surface-variant">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  )
}

export function Loading() {
  return <p className="text-sm text-on-surface-variant">Đang tải…</p>
}

export function LoadError({ title, error, back }: { title: string; error: unknown; back?: { label: string; path: string } }) {
  return (
    <Card title={title}>
      <Alert>{errorMessage(error)}</Alert>
      {back && (
        <Button variant="secondary" className="mt-4" onClick={() => navigate(back.path)}>
          {back.label}
        </Button>
      )}
    </Card>
  )
}

// LinkButton: nút điều hướng nhỏ trong ứng dụng.
export function LinkButton({ to, icon, children, primary }: { to: string; icon?: string; children: ReactNode; primary?: boolean }) {
  return (
    <a
      href={to}
      onClick={(e) => {
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return
        e.preventDefault()
        navigate(to)
      }}
      className={`inline-flex h-8 items-center gap-1 rounded-lg px-2.5 text-xs font-medium ${
        primary ? 'bg-primary text-white hover:bg-primary-hover' : 'border border-outline-variant bg-surface text-on-surface hover:bg-surface-container-low'
      }`}
    >
      {icon && <Icon name={icon} className="text-base" />}
      {children}
    </a>
  )
}

export function Textarea({ label, hint, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string; hint?: string }) {
  const id = useId()
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="block text-sm font-medium text-on-surface">
        {label}
      </label>
      <textarea
        id={id}
        className="block w-full rounded-lg border border-outline-variant px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
        {...props}
      />
      {hint && <p className="text-xs text-on-surface-variant">{hint}</p>}
    </div>
  )
}

// ClassTabs: chuyển nhanh giữa các trang của lớp (giao bài / nhập điểm / nhận xét / điểm danh).
export function ClassTabs({ classId, active, sessionId }: { classId: string; active: 'homework' | 'scores' | 'remarks'; sessionId?: string }) {
  const q = sessionId ? `?session=${sessionId}` : ''
  const tabs = [
    { key: 'homework', label: 'Giao bài tập', to: `/teacher/classes/${classId}/homework${q}` },
    { key: 'scores', label: 'Nhập điểm', to: `/teacher/classes/${classId}/scores` },
    { key: 'remarks', label: 'Nhận xét buổi học', to: `/teacher/classes/${classId}/remarks${q}` },
    { key: 'attendance', label: 'Điểm danh', to: `/classes/${classId}/attendance${q}` },
  ]
  return (
    <nav className="mb-4 flex flex-wrap gap-2" aria-label="Trang của lớp">
      {tabs.map((t) => (
        <a
          key={t.key}
          href={t.to}
          aria-current={t.key === active ? 'page' : undefined}
          onClick={(e) => {
            e.preventDefault()
            navigate(t.to)
          }}
          className={`rounded-full border px-3 py-1 text-xs font-medium ${
            t.key === active ? 'border-primary bg-primary text-white' : 'border-outline-variant text-on-surface-variant hover:bg-surface-container-low'
          }`}
        >
          {t.label}
        </a>
      ))}
    </nav>
  )
}

export function Empty({ children }: { children: ReactNode }) {
  return <p className="text-sm text-on-surface-variant">{children}</p>
}

export const th = 'py-2 pr-4 font-medium'
export const td = 'py-2 pr-4 align-top'
