// Thành phần dùng chung của module test đầu vào (bổ sung cho src/components/ui.tsx).
import { useId, type MouseEvent, type ReactNode, type TextareaHTMLAttributes } from 'react'
import { Button } from '../../components/ui'
import { navigate } from '../../router'

export function PageHeader({
  title,
  description,
  back,
  actions,
  badges,
}: {
  title: ReactNode
  description?: ReactNode
  back?: string
  actions?: ReactNode
  badges?: ReactNode
}) {
  return (
    <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div className="min-w-0 space-y-1">
        {back && <Link href={back} className="text-xs text-on-surface-variant hover:text-primary">← Quay lại</Link>}
        <h1 className="flex flex-wrap items-center gap-2 text-xl font-semibold text-on-surface">
          {title}
          {badges}
        </h1>
        {description && <p className="text-sm text-on-surface-variant">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  )
}

export function Link({ href, className = '', children }: { href: string; className?: string; children: ReactNode }) {
  return (
    <a
      href={href}
      className={className}
      onClick={(e: MouseEvent) => {
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return
        e.preventDefault()
        navigate(href)
      }}
    >
      {children}
    </a>
  )
}

export function LinkButton({ href, variant = 'secondary', children }: { href: string; variant?: 'primary' | 'secondary'; children: ReactNode }) {
  return (
    <Button variant={variant} type="button" onClick={() => navigate(href)}>
      {children}
    </Button>
  )
}

export function StatCard({ label, value, hint, tone = 'neutral' }: { label: string; value: ReactNode; hint?: string; tone?: 'neutral' | 'primary' | 'success' | 'warning' }) {
  const accent = { neutral: 'text-on-surface', primary: 'text-primary', success: 'text-success', warning: 'text-warning' }[tone]
  return (
    <div className="rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm">
      <p className="text-xs font-medium text-on-surface-variant">{label}</p>
      <p className={`mt-1 text-2xl font-semibold ${accent}`}>{value}</p>
      {hint && <p className="mt-0.5 text-xs text-on-surface-subtle">{hint}</p>}
    </div>
  )
}

export function Textarea({ label, hint, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement> & { label?: string; hint?: string }) {
  const id = useId()
  return (
    <div className="space-y-1.5">
      {label && (
        <label htmlFor={id} className="block text-sm font-medium text-on-surface">
          {label}
        </label>
      )}
      <textarea
        id={id}
        className="block w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
        {...props}
      />
      {hint && <p className="text-xs text-on-surface-variant">{hint}</p>}
    </div>
  )
}

export function Tabs<T extends string>({ value, onChange, items }: { value: T; onChange: (v: T) => void; items: { value: T; label: string }[] }) {
  return (
    <div role="tablist" className="flex gap-1 border-b border-surface-container-highest">
      {items.map((it) => (
        <button
          key={it.value}
          role="tab"
          type="button"
          aria-selected={value === it.value}
          onClick={() => onChange(it.value)}
          className="border-b-2 border-transparent px-3 py-2 text-sm text-on-surface-variant hover:text-on-surface aria-selected:border-primary aria-selected:font-medium aria-selected:text-primary"
        >
          {it.label}
        </button>
      ))}
    </div>
  )
}

export function Pagination({ page, perPage, total, unit, onPage }: { page: number; perPage: number; total: number; unit: string; onPage: (p: number) => void }) {
  const pages = Math.max(1, Math.ceil(total / perPage))
  return (
    <div className="flex items-center justify-between gap-4 pt-3 text-xs text-on-surface-variant">
      <span>
        {total} {unit}
      </span>
      {pages > 1 && (
        <span className="flex items-center gap-2">
          <Button variant="secondary" className="h-8" disabled={page <= 1} onClick={() => onPage(page - 1)}>
            Trước
          </Button>
          Trang {page}/{pages}
          <Button variant="secondary" className="h-8" disabled={page >= pages} onClick={() => onPage(page + 1)}>
            Sau
          </Button>
        </span>
      )}
    </div>
  )
}

export function Empty({ colSpan, children }: { colSpan: number; children: ReactNode }) {
  return (
    <tr>
      <td colSpan={colSpan} className="py-8 text-center text-sm text-on-surface-variant">
        {children}
      </td>
    </tr>
  )
}

export const th = 'py-2 pr-4 font-medium whitespace-nowrap'
export const td = 'py-2 pr-4 align-top'
