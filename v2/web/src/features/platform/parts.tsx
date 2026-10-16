import type { ReactNode } from 'react'
import { Button, Icon } from '../../components/ui'

// Thẻ số liệu (như UiStatCard).
export function StatCard({ label, value, icon, tone = 'neutral' }: { label: string; value: ReactNode; icon: string; tone?: 'neutral' | 'success' | 'primary' | 'error' | 'warning' }) {
  const toneClass = {
    neutral: 'bg-surface-container-low text-on-surface-variant',
    success: 'bg-success-container text-on-success-container',
    primary: 'bg-primary-light text-primary',
    error: 'bg-error-container text-on-error-container',
    warning: 'bg-warning-container text-warning',
  }[tone]
  return (
    <div className="flex items-center gap-4 rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm">
      <span className={`flex size-11 items-center justify-center rounded-xl ${toneClass}`}>
        <Icon name={icon} />
      </span>
      <div className="min-w-0">
        <div className="text-xs text-on-surface-variant">{label}</div>
        <div className="text-xl font-semibold text-on-surface">{value}</div>
      </div>
    </div>
  )
}

// Trạng thái rỗng (như UiEmptyState).
export function EmptyState({ icon, title, description }: { icon: string; title: string; description?: string }) {
  return (
    <div className="flex flex-col items-center gap-2 px-4 py-10 text-center">
      <Icon name={icon} className="text-[40px] text-on-surface-subtle" />
      <p className="text-sm font-semibold text-on-surface">{title}</p>
      {description && <p className="max-w-md text-sm text-on-surface-variant">{description}</p>}
    </div>
  )
}

// Phân trang đơn giản: Trước / Sau + "Trang x / y · n <đơn vị>".
export function Pager({ page, perPage, total, unit, onPage }: { page: number; perPage: number; total: number; unit: string; onPage: (p: number) => void }) {
  const pages = Math.max(1, Math.ceil(total / perPage))
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-surface-container-highest px-4 py-3 text-sm text-on-surface-variant">
      <span>
        Trang {page} / {pages} · {total} {unit}
      </span>
      <span className="flex gap-2">
        <Button variant="secondary" className="h-9" disabled={page <= 1} onClick={() => onPage(page - 1)}>
          Trước
        </Button>
        <Button variant="secondary" className="h-9" disabled={page >= pages} onClick={() => onPage(page + 1)}>
          Sau
        </Button>
      </span>
    </div>
  )
}

// Tiêu đề trang (như UiPageHeader): tiêu đề, mô tả, nút hành động.
export function PageHeader({ title, description, actions }: { title: string; description?: string; actions?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <h1 className="text-xl font-semibold text-on-surface">{title}</h1>
        {description && <p className="mt-1 max-w-3xl text-sm text-on-surface-variant">{description}</p>}
      </div>
      {actions && <div className="flex shrink-0 flex-wrap gap-2">{actions}</div>}
    </div>
  )
}
