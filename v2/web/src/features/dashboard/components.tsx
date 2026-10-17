// Thành phần dùng chung của Tổng quan và Báo cáo & sự vụ lớp: liên kết nội bộ, khung thẻ có tiêu đề, thẻ số liệu, trạng
// thái rỗng, ghi chú "Chờ phân hệ …", biểu đồ thanh ngang (BarList.vue).
import { useState, type MouseEvent, type ReactNode } from 'react'
import { Icon } from '../../components/ui'
import { navigate } from '../../router'
import { barWidth, formatScore, toneClass, type BarRow } from './logic'

// Liên kết trong ứng dụng: bấm thường thì đổi trang không tải lại, Ctrl / Cmd + bấm vẫn mở tab mới.
export function Link({ href, className = '', children, title }: { href: string; className?: string; children: ReactNode; title?: string }) {
  const go = (e: MouseEvent<HTMLAnchorElement>) => {
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return
    e.preventDefault()
    navigate(href)
  }
  return (
    <a href={href} onClick={go} className={className} title={title}>
      {children}
    </a>
  )
}

export function Panel({
  title,
  action,
  className = '',
  children,
  ...data
}: {
  title: ReactNode
  action?: ReactNode
  className?: string
  children: ReactNode
  [key: `data-${string}`]: string | boolean | undefined
}) {
  return (
    <section className={`rounded-xl border border-surface-container-highest bg-surface shadow-sm ${className}`} {...data}>
      <div className="flex items-center justify-between gap-3 border-b border-surface-container-highest px-4 py-3">
        <h3 className="text-sm font-semibold text-on-surface">{title}</h3>
        {action}
      </div>
      {children}
    </section>
  )
}

export function PanelLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <Link href={href} className="text-sm text-primary hover:underline">
      {children}
    </Link>
  )
}

export function Empty({ icon = 'inbox', title }: { icon?: string; title: string }) {
  return (
    <div className="flex flex-col items-center gap-2 px-4 py-8 text-center text-sm text-on-surface-variant">
      <Icon name={icon} className="text-[32px] text-on-surface-subtle" />
      <p>{title}</p>
    </div>
  )
}

// Ghi chú cho phần chưa có số liệu vì phân hệ nguồn chưa chuyển sang v2.
export function Pending({ children }: { children: ReactNode }) {
  return (
    <p className="flex items-center gap-1.5 text-xs text-on-surface-variant italic">
      <Icon name="hourglass_empty" className="text-[16px]" />
      {children}
    </p>
  )
}

export function StatCard({
  label,
  value,
  icon,
  tone = 'default',
  hint,
  pending,
}: {
  label: string
  value: ReactNode
  icon: string
  tone?: string
  hint?: string | null
  pending?: string | null
}) {
  return (
    <div className="flex h-full items-start gap-3 rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm">
      <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-surface-container-low text-on-surface-variant">
        <Icon name={icon} className="text-[22px]" />
      </span>
      <div className="min-w-0">
        <p className="text-xs text-on-surface-variant">{label}</p>
        {pending ? (
          <p className="mt-1 text-sm text-on-surface-variant italic">{pending}</p>
        ) : (
          <p className={`mt-1 text-2xl font-semibold tabular-nums ${toneClass(tone)}`}>{value}</p>
        )}
        {hint && !pending && <p className="mt-0.5 text-xs text-on-surface-variant">{hint}</p>}
      </div>
    </div>
  )
}

// Biểu đồ thanh ngang một chỉ số: dòng chưa có dữ liệu hiện "—" (không vẽ thanh 0); rê chuột / focus hiện chi tiết.
export function BarList({
  title,
  rows,
  max = 100,
  unit = '',
  emptyText = 'Chưa có dữ liệu trong tháng',
}: {
  title: string
  rows: BarRow[]
  max?: number
  unit?: string
  emptyText?: string
}) {
  const [hovered, setHovered] = useState<string | null>(null)
  const format = (v: number | null) => (v === null ? '—' : `${formatScore(v)}${unit}`)
  return (
    <figure className="rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm" data-bar-list={title}>
      <figcaption className="mb-2 flex items-baseline justify-between gap-2">
        <span className="text-sm font-semibold text-on-surface">{title}</span>
        <span className="text-xs text-on-surface-variant">
          thang 0–{max}
          {unit}
        </span>
      </figcaption>
      {rows.some((r) => r.value !== null) ? (
        <ul className="space-y-1">
          {rows.map((row) => (
            <li
              key={row.key}
              tabIndex={0}
              className="relative grid grid-cols-[minmax(0,7rem)_1fr_3.5rem] items-center gap-2 rounded-md px-1 py-1 outline-none hover:bg-surface-container-low focus-visible:ring-2 focus-visible:ring-primary/40"
              aria-label={`${row.label}: ${format(row.value)}${row.hint ? ' · ' + row.hint : ''}`}
              onMouseEnter={() => setHovered(row.key)}
              onMouseLeave={() => setHovered(null)}
              onFocus={() => setHovered(row.key)}
              onBlur={() => setHovered(null)}
            >
              <span className="truncate text-xs text-on-surface-variant" title={row.label}>
                {row.label}
              </span>
              <span className="relative h-2.5 rounded-full bg-surface-container-low" aria-hidden="true">
                {row.value !== null && (
                  <span className="absolute inset-y-0 left-0 rounded-full bg-primary" style={{ width: barWidth(row.value, max) }} />
                )}
              </span>
              <span className="text-right text-xs text-on-surface tabular-nums">{format(row.value)}</span>
              {hovered === row.key && row.hint && (
                <span
                  role="tooltip"
                  className="pointer-events-none absolute -top-8 left-1/2 z-10 -translate-x-1/2 rounded-md bg-on-surface px-2 py-1 text-xs whitespace-nowrap text-surface shadow-md"
                >
                  {row.label} · {row.hint}
                </span>
              )}
            </li>
          ))}
        </ul>
      ) : (
        <p className="py-2 text-xs text-on-surface-variant italic">{emptyText}</p>
      )}
    </figure>
  )
}
