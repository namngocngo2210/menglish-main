// Thành phần dùng chung của các màn CRM: đầu trang + chip lọc nhanh, đồng hồ SLA, phân trang, hộp nhập lý do.
import { useQuery } from '@tanstack/react-query'
import { useEffect, useState, type MouseEvent, type ReactNode, type TextareaHTMLAttributes } from 'react'
import type { Me } from '../../api'
import { Alert, Badge, Button, Dialog, Select } from '../../components/ui'
import { can } from '../../nav'
import { navigate } from '../../router'
import { crmApi, type ChipCounts, type Meta, type Option, type SLA } from './api'
import { remainingLabel, slaState, slaTone } from './logic'

export const PATHS = {
  list: '/crm/customers',
  create: '/crm/customers/new',
  pipeline: '/crm/pipeline',
  won: '/crm/won',
  lost: '/crm/lost',
  waiting: '/crm/waiting',
  deleted: '/crm/deleted',
  reports: '/crm/reports',
  promotions: '/crm/promotions',
  import: '/crm/import',
  confirmations: '/crm/confirmations',
  transfers: '/crm/transfers',
} as const

export const customerPath = (id: string) => `${PATHS.list}/${id}`

export function Link({ href, children, className = '' }: { href: string; children: ReactNode; className?: string }) {
  return (
    <a
      href={href}
      className={className || 'font-medium text-primary hover:underline'}
      onClick={(e: MouseEvent) => {
        if (e.metaKey || e.ctrlKey) return
        e.preventDefault()
        navigate(href)
      }}
    >
      {children}
    </a>
  )
}

export type Chip = 'sla' | 'test_today' | 'follow_up'

// Đầu trang CRM: tiêu đề, các nút và chip lọc nhanh (Laravel CrmHeader).
export function CrmHeader({
  me,
  title,
  active,
  onChip,
  actions,
}: {
  me: Me
  title: string
  active?: Chip | 'waiting' | 'won' | 'lost' | 'deleted' | null
  onChip?: (c: Chip | null) => void
  actions?: ReactNode
}) {
  const counts = useQuery({ queryKey: ['crm', 'counts'], queryFn: crmApi.counts })
  const c: Partial<ChipCounts> = counts.data ?? {}
  const chip = (key: Chip, label: string) => (
    <ChipButton
      key={key}
      active={active === key}
      count={c[key]}
      onClick={() => {
        if (onChip) onChip(active === key ? null : key)
        else navigate(`${PATHS.list}?chip=${key}`)
      }}
    >
      {label}
    </ChipButton>
  )
  const page = (key: 'waiting' | 'won' | 'lost' | 'deleted', label: string, count?: number) => (
    <ChipButton key={key} active={active === key} count={count} onClick={() => navigate(PATHS[key])}>
      {label}
    </ChipButton>
  )
  return (
    <div className="mb-4 space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-lg font-semibold">{title}</h1>
        <div className="flex flex-wrap gap-2">
          {actions}
          {can(me, 'lead.create') && <Button onClick={() => navigate(PATHS.create)}>Thêm khách mới</Button>}
        </div>
      </div>
      <div className="flex flex-wrap gap-2" aria-label="Lọc nhanh">
        {chip('sla', 'Chưa liên hệ >24h')}
        {chip('test_today', 'Hẹn test hôm nay')}
        {chip('follow_up', 'Cần gọi lại')}
        {page('waiting', 'Chờ xếp lớp', c.waiting_class)}
        {page('won', 'Đã nhập học', c.won)}
        {page('lost', 'Thất bại', c.lost)}
        {can(me, 'lead.delete') && page('deleted', 'Đã xóa', c.deleted)}
      </div>
    </div>
  )
}

function ChipButton({ active, count, onClick, children }: { active: boolean; count?: number; onClick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={`inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-xs font-medium ${
        active ? 'border-primary bg-primary-light text-primary' : 'border-outline-variant bg-surface text-on-surface-variant hover:bg-surface-container-low'
      }`}
    >
      {children}
      {count !== undefined && <span className="rounded-full bg-surface-container-high px-1.5 text-[11px]">{count}</span>}
    </button>
  )
}

// useNow: giờ hiện tại cập nhật mỗi 30 giây (đồng hồ SLA đếm lùi).
export function useNow(intervalMs = 30_000) {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const id = window.setInterval(() => setNow(new Date()), intervalMs)
    return () => window.clearInterval(id)
  }, [intervalMs])
  return now
}

export function SlaBadge({ sla, compact }: { sla: SLA | null; compact?: boolean }) {
  const now = useNow()
  if (!sla) return null
  const state = slaState(sla, now)
  return (
    <span title={`${sla.label}: ${sla.deadline_label}`}>
      <Badge tone={slaTone[state]}>
        {compact ? '' : `${sla.label}: `}
        {remainingLabel(new Date(sla.deadline), now)}
      </Badge>
    </span>
  )
}

export function Pager({ meta, onPage }: { meta: Meta; onPage: (p: number) => void }) {
  const pages = Math.max(1, Math.ceil(meta.total / Math.max(1, meta.per_page)))
  if (pages <= 1) return null
  return (
    <div className="mt-4 flex items-center gap-3 text-sm">
      <Button variant="secondary" disabled={meta.page <= 1} onClick={() => onPage(meta.page - 1)}>
        Trước
      </Button>
      <span>
        Trang {meta.page}/{pages} · {meta.total} dòng
      </span>
      <Button variant="secondary" disabled={meta.page >= pages} onClick={() => onPage(meta.page + 1)}>
        Sau
      </Button>
    </div>
  )
}

export function Textarea({ label, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string }) {
  return (
    <label className="block space-y-1.5 text-sm font-medium">
      <span>{label}</span>
      <textarea
        className="block min-h-20 w-full rounded-lg border border-outline-variant px-3 py-2 text-sm font-normal outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
        {...props}
      />
    </label>
  )
}

// Hộp hỏi lý do / xác nhận (chuyển giai đoạn, huỷ chốt, từ chối chuyển cơ sở…).
export function ReasonDialog({
  title,
  description,
  label = 'Lý do',
  required = true,
  options,
  submitLabel = 'Xác nhận',
  onClose,
  onSubmit,
}: {
  title: string
  description?: ReactNode
  label?: string
  required?: boolean | ((choice: string) => boolean)
  options?: Option[]
  submitLabel?: string
  onClose: () => void
  onSubmit: (reason: string, choice: string) => Promise<unknown>
}) {
  const [reason, setReason] = useState('')
  const [choice, setChoice] = useState(options?.[0]?.value ?? '')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const need = typeof required === 'function' ? required(choice) : required
  return (
    <Dialog title={title} onClose={onClose}>
      <form
        className="space-y-3"
        onSubmit={async (e) => {
          e.preventDefault()
          setBusy(true)
          setError('')
          try {
            await onSubmit(reason.trim(), choice)
            onClose()
          } catch (err) {
            setError(err instanceof Error ? err.message : String(err))
          } finally {
            setBusy(false)
          }
        }}
      >
        {description && <div className="text-sm text-on-surface-variant">{description}</div>}
        {options && (
          <Select label="Chuyển đến" value={choice} onChange={(e) => setChoice(e.target.value)}>
            {options.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        )}
        <Textarea label={need ? `${label} (bắt buộc)` : label} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000} />
        {error && <Alert>{error}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={busy || (need && !reason.trim()) || (options && !choice)}>
            {submitLabel}
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

// Thông báo ngắn sau thao tác (thay toast của bản Laravel).
export function useFlash() {
  const [flash, setFlash] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  useEffect(() => {
    if (!flash) return
    const id = window.setTimeout(() => setFlash(null), 6000)
    return () => window.clearTimeout(id)
  }, [flash])
  const node = flash ? <Alert tone={flash.tone}>{flash.text}</Alert> : null
  return { node, ok: (text: string) => setFlash({ tone: 'success', text }), fail: (text: string) => setFlash({ tone: 'error', text }) }
}

export function Table({ head, children }: { head: ReactNode[]; children: ReactNode }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-left text-sm">
        <thead className="text-xs text-on-surface-variant">
          <tr>
            {head.map((h, i) => (
              <th key={i} className="py-2 pr-4 font-medium whitespace-nowrap">
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-surface-container-highest">{children}</tbody>
      </table>
    </div>
  )
}

export function Td({ children, className = '' }: { children?: ReactNode; className?: string }) {
  return <td className={`py-2 pr-4 align-top ${className}`}>{children}</td>
}

export function Empty({ children }: { children: ReactNode }) {
  return <p className="py-6 text-center text-sm text-on-surface-variant">{children}</p>
}
