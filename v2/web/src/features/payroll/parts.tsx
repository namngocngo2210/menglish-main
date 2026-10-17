// Thành phần dùng chung của các màn Lương & Phạt: tiêu đề trang, thẻ số liệu, phân trang, bảng khoản cộng / trừ.
import type { ReactNode } from 'react'
import { Button, Icon } from '../../components/ui'
import type { Line, Paging } from './api'
import { money } from './logic'

export function Header({ title, description, actions }: { title: string; description?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div className="min-w-0">
        <h1 className="text-xl font-semibold">{title}</h1>
        {description && <p className="text-sm text-on-surface-variant">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  )
}

const statTones = {
  neutral: 'text-on-surface',
  primary: 'text-primary',
  success: 'text-success',
  error: 'text-error',
  warning: 'text-warning',
}

export function Stat({
  label,
  value,
  hint,
  icon,
  tone = 'neutral',
}: {
  label: string
  value: ReactNode
  hint?: string
  icon?: string
  tone?: keyof typeof statTones
}) {
  return (
    <div className="rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm">
      <div className="flex items-center justify-between gap-2">
        <p className="text-xs text-on-surface-variant">{label}</p>
        {icon && <Icon name={icon} className={`text-[20px] ${statTones[tone]}`} />}
      </div>
      <p className={`mt-1 text-lg font-semibold ${statTones[tone]}`}>{value}</p>
      {hint && <p className="text-xs text-on-surface-variant">{hint}</p>}
    </div>
  )
}

export function Pager({ paging, unit, onPage }: { paging: Paging; unit: string; onPage: (page: number) => void }) {
  if (paging.last_page <= 1) return null
  return (
    <div className="mt-3 flex items-center justify-end gap-2 text-sm">
      <Button variant="secondary" disabled={paging.current_page <= 1} onClick={() => onPage(paging.current_page - 1)}>
        Trước
      </Button>
      <span>
        Trang {paging.current_page}/{paging.last_page} · {paging.total} {unit}
      </span>
      <Button variant="secondary" disabled={paging.current_page >= paging.last_page} onClick={() => onPage(paging.current_page + 1)}>
        Sau
      </Button>
    </div>
  )
}

// PayslipLines: bảng khoản cộng / trừ của phiếu (PayslipLines.vue).
export function PayslipLines({
  title,
  lines,
  total,
  totalLabel,
  negative,
  showQuantity,
}: {
  title: string
  lines: (Line & { quantity?: string })[]
  total: number
  totalLabel: string
  negative?: boolean
  showQuantity?: boolean
}) {
  return (
    <section className="rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm">
      <h2 className="mb-2 text-sm font-semibold">{title}</h2>
      <table className="w-full text-sm">
        <tbody>
          {lines.map((l) => (
            <tr key={l.key + l.label} className="border-t border-surface-container align-top">
              <td className="py-2 pr-3">
                {l.label}
                {l.hint && <p className="text-xs text-on-surface-variant">{l.hint}</p>}
              </td>
              {showQuantity && <td className="py-2 pr-3 text-right whitespace-nowrap text-on-surface-variant">{l.quantity}</td>}
              <td className={`py-2 text-right font-mono whitespace-nowrap ${negative ? 'text-error' : ''}`}>
                {negative && l.amount > 0 ? '-' : ''}
                {money(l.amount)}
              </td>
            </tr>
          ))}
          {lines.length === 0 && (
            <tr>
              <td className="py-2 text-on-surface-variant" colSpan={3}>
                Không có khoản nào.
              </td>
            </tr>
          )}
        </tbody>
        <tfoot>
          <tr className="border-t-2 border-surface-container-highest font-semibold">
            <td className="py-2 pr-3" colSpan={showQuantity ? 2 : 1}>
              {totalLabel}
            </td>
            <td className={`py-2 text-right font-mono ${negative ? 'text-error' : ''}`}>
              {negative && total > 0 ? '-' : ''}
              {money(total)}
            </td>
          </tr>
        </tfoot>
      </table>
    </section>
  )
}

export function Textarea({
  label,
  value,
  onChange,
  rows = 3,
  placeholder,
}: {
  label: string
  value: string
  onChange: (v: string) => void
  rows?: number
  placeholder?: string
}) {
  return (
    <label className="block space-y-1.5 text-sm">
      <span className="block font-medium text-on-surface">{label}</span>
      <textarea
        className="block w-full rounded-lg border border-outline-variant px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
        rows={rows}
        value={value}
        placeholder={placeholder}
        onChange={(e) => onChange(e.target.value)}
      />
    </label>
  )
}

export const th = 'py-2 pr-4 font-medium'
export const td = 'py-2 pr-4'
