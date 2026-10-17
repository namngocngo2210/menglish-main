// Thành phần dùng chung của màn Giáo trình & Big Test (bổ sung cho src/components/ui.tsx).
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useId, useState, type MouseEvent, type ReactNode, type TextareaHTMLAttributes } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Icon } from '../../components/ui'
import { navigate } from '../../router'
import type { Message } from './api'
import { badgeTone } from './logic'

export function PageHeader({ title, description, back, actions }: { title: ReactNode; description?: ReactNode; back?: string; actions?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div className="min-w-0 space-y-1">
        {back && (
          <Link href={back} className="text-xs text-on-surface-variant hover:text-primary">
            ← Quay lại
          </Link>
        )}
        <h1 className="text-xl font-semibold text-on-surface">{title}</h1>
        {description && <p className="text-sm text-on-surface-variant">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  )
}

// Link nội bộ (History API); Ctrl/Cmd + bấm mở tab mới như link thường.
export function Link({ href, className = '', children, title }: { href: string; className?: string; children: ReactNode; title?: string }) {
  return (
    <a
      href={href}
      title={title}
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

export function LinkButton({
  href,
  variant = 'secondary',
  icon,
  className = '',
  children,
}: {
  href: string
  variant?: 'primary' | 'secondary'
  icon?: string
  className?: string
  children: ReactNode
}) {
  return (
    <Button type="button" variant={variant} className={`gap-1.5 ${className}`} onClick={() => navigate(href)}>
      {icon && <Icon name={icon} className="text-[18px]" />}
      {children}
    </Button>
  )
}

export function Pagination({
  page,
  pageSize,
  total,
  unit,
  onPage,
}: {
  page: number
  pageSize: number
  total: number
  unit: string
  onPage: (p: number) => void
}) {
  const pages = Math.max(1, Math.ceil(total / Math.max(1, pageSize)))
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

export function EmptyRow({ colSpan, title, hint, icon = 'inbox' }: { colSpan: number; title: string; hint?: string; icon?: string }) {
  return (
    <tr>
      <td colSpan={colSpan} className="py-10 text-center text-sm text-on-surface-variant">
        <Icon name={icon} className="mb-1 block text-3xl" />
        <span className="block font-medium text-on-surface">{title}</span>
        {hint && <span className="block text-xs">{hint}</span>}
      </td>
    </tr>
  )
}

export function Empty({ title, hint, icon = 'inbox', children }: { title: string; hint?: string; icon?: string; children?: ReactNode }) {
  return (
    <div className="flex flex-col items-center gap-1.5 rounded-xl border border-outline-variant bg-surface p-8 text-center">
      <Icon name={icon} className="text-4xl text-on-surface-variant" />
      <p className="font-semibold text-on-surface">{title}</p>
      {hint && <p className="text-sm text-on-surface-variant">{hint}</p>}
      {children}
    </div>
  )
}

export function TextArea({
  label,
  hint,
  required,
  ...props
}: TextareaHTMLAttributes<HTMLTextAreaElement> & {
  label: string
  hint?: string
}) {
  const id = useId()
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="block text-sm font-medium text-on-surface">
        {label}
        {required && <span className="text-error"> *</span>}
      </label>
      <textarea
        id={id}
        required={required}
        className="block w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25 disabled:bg-surface-container-low"
        {...props}
      />
      {hint && <p className="text-xs text-on-surface-variant">{hint}</p>}
    </div>
  )
}

export function Tabs<T extends string>({ value, onChange, items }: { value: T; onChange: (v: T) => void; items: { value: T; label: string }[] }) {
  return (
    <div role="tablist" className="flex gap-1 overflow-x-auto border-b border-surface-container-highest">
      {items.map((it) => (
        <button
          key={it.value}
          role="tab"
          type="button"
          aria-selected={value === it.value}
          onClick={() => onChange(it.value)}
          className="shrink-0 border-b-2 border-transparent px-3 py-2 text-sm whitespace-nowrap text-on-surface-variant hover:text-on-surface aria-selected:border-primary aria-selected:font-medium aria-selected:text-primary"
        >
          {it.label}
        </button>
      ))}
    </div>
  )
}

export function StatCard({
  label,
  value,
  hint,
  tone = 'neutral',
}: {
  label: string
  value: ReactNode
  hint?: string
  tone?: 'neutral' | 'primary' | 'success' | 'error'
}) {
  const accent = {
    neutral: 'text-on-surface',
    primary: 'text-primary',
    success: 'text-success',
    error: 'text-error',
  }[tone]
  return (
    <div className="rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm">
      <p className="text-xs font-medium text-on-surface-variant">{label}</p>
      <p className={`mt-1 text-2xl font-semibold ${accent}`}>{value}</p>
      {hint && <p className="mt-0.5 text-xs text-on-surface-variant">{hint}</p>}
    </div>
  )
}

export function StatusBadge({ color, children }: { color: string | null | undefined; children: ReactNode }) {
  return <Badge tone={badgeTone(color)}>{children}</Badge>
}

// Ô hiển thị nhãn + giá trị trong khung chi tiết.
export function Info({ label, children, className = '' }: { label: string; children: ReactNode; className?: string }) {
  return (
    <div className={className}>
      <dt className="text-xs font-medium text-on-surface-variant uppercase">{label}</dt>
      <dd className="mt-1 text-sm text-on-surface">{children}</dd>
    </div>
  )
}

// Thông báo kết quả thao tác (thành công / cảnh báo) ở đầu trang.
export type Notice = { text: string; tone: 'success' | 'info' } | null
export function NoticeBar({ notice, onClose }: { notice: Notice; onClose: () => void }) {
  if (!notice) return null
  return (
    <div className="mb-4 flex items-start gap-2">
      <div className="flex-1">
        <Alert tone={notice.tone}>{notice.text}</Alert>
      </div>
      <button type="button" className="pt-2 text-xs text-on-surface-variant hover:text-on-surface" onClick={onClose}>
        Ẩn
      </button>
    </div>
  )
}

// useAction: gọi API ghi, xong thì nạp lại dữ liệu Giáo trình và báo kết quả; lỗi giữ ở action.error.
export function useAction<A extends unknown[]>(fn: (...args: A) => Promise<Message>, onDone?: (m: Message) => void) {
  const qc = useQueryClient()
  const [notice, setNotice] = useState<Notice>(null)
  const m = useMutation({
    mutationFn: (args: A) => fn(...args),
    onSuccess: (res) => {
      setNotice({
        text: res.message,
        tone: res.tone === 'warning' ? 'info' : 'success',
      })
      qc.invalidateQueries({ queryKey: ['syllabus'] })
      onDone?.(res)
    },
  })
  return {
    run: (...args: A) => m.mutate(args),
    pending: m.isPending,
    error: m.isError ? errorMessage(m.error) : '',
    reset: m.reset,
    notice,
    setNotice,
  }
}

export function ErrorText({ error }: { error: string }) {
  return error ? <Alert>{error}</Alert> : null
}

export const th = 'py-2 pr-4 text-xs font-medium whitespace-nowrap text-on-surface-variant'
export const td = 'py-2 pr-4 align-top'
export const tableCls = 'w-full text-left text-sm'
export const tbodyCls = 'divide-y divide-surface-container-highest'

// Thẻ bảng có tiêu đề + thanh lọc.
export function TableCard({ header, children, footer }: { header: ReactNode; children: ReactNode; footer?: ReactNode }) {
  return (
    <section className="rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm sm:p-6">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">{header}</div>
      <div className="overflow-x-auto">{children}</div>
      {footer}
    </section>
  )
}

// confirmThen: hỏi xác nhận trước thao tác xóa / gửi.
export function confirmThen(text: string, fn: () => void) {
  if (window.confirm(text)) fn()
}

// Cập nhật một tham số URL (giữ các tham số khác); rỗng thì bỏ.
export function setParam(name: string, value: string | null) {
  const u = new URL(window.location.href)
  if (value) u.searchParams.set(name, value)
  else u.searchParams.delete(name)
  navigate(u.pathname + u.search)
}
