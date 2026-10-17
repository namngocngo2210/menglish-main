// Thành phần dùng chung của phân hệ Lớp học.
import type { ReactNode } from 'react'
import { toneClass } from './logic'

export function Pill({ tone, children }: { tone: string; children: ReactNode }) {
  return <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap ${toneClass(tone)}`}>{children}</span>
}

export function Header({ title, description, actions }: { title: ReactNode; description?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <h1 className="text-xl font-semibold text-on-surface">{title}</h1>
        {description && <p className="mt-1 text-sm text-on-surface-variant">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  )
}

export function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      aria-pressed={active}
      onClick={onClick}
      className={`rounded-full border px-3 py-1 text-xs font-medium ${
        active ? 'border-primary bg-primary text-white' : 'border-outline-variant text-on-surface-variant hover:bg-surface-container-low'
      }`}
    >
      {children}
    </button>
  )
}

export function Stat({ label, value, tone }: { label: string; value: ReactNode; tone?: string }) {
  return (
    <div className="rounded-lg border border-surface-container-highest bg-surface px-4 py-3">
      <p className="text-xs text-on-surface-variant">{label}</p>
      <p className={`mt-1 text-lg font-semibold ${tone === 'error' ? 'text-error' : ''}`}>{value}</p>
    </div>
  )
}

export function Loading() {
  return <p className="text-sm text-on-surface-variant">Đang tải…</p>
}

export const th = 'py-2 pr-4 font-medium'
export const td = 'py-2 pr-4 align-top'
