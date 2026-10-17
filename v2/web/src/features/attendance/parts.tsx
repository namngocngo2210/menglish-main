// Thành phần dùng chung của các màn Chấm công.
import { useEffect, type MouseEvent, type ReactNode } from 'react'
import { Button, Icon, Select } from '../../components/ui'
import { navigate } from '../../router'
import type { Option } from './api'

// Link trong trang: mở bằng router, giữ Ctrl/Cmd + bấm để mở tab mới.
export function link(path: string) {
  return (e: MouseEvent) => {
    if (e.metaKey || e.ctrlKey || e.shiftKey) return
    e.preventDefault()
    navigate(path)
  }
}

export function AppLink({ to, className = 'text-primary hover:underline', children }: { to: string; className?: string; children: ReactNode }) {
  return (
    <a href={to} onClick={link(to)} className={className}>
      {children}
    </a>
  )
}

// Chuyển tiếp URL cũ (vd. /m, /m/lich-su của Laravel) sang URL mới.
export function Redirect({ to }: { to: string }) {
  useEffect(() => {
    window.history.replaceState(null, '', to)
    window.dispatchEvent(new PopStateEvent('popstate'))
  }, [to])
  return null
}

// Thanh chuyển tháng: ‹ Tháng 10/2026 ›.
export function MonthNav({ label, prev, next, onChange }: { label: string; prev: string; next: string | null; onChange: (m: string) => void }) {
  return (
    <div className="flex items-center justify-between gap-2 rounded-xl border border-surface-container-highest bg-surface px-2 py-2 shadow-sm">
      <Button variant="secondary" className="h-9 px-3" aria-label="Tháng trước" onClick={() => onChange(prev)}>
        <Icon name="chevron_left" />
      </Button>
      <span className="text-sm font-semibold">{label}</span>
      <Button variant="secondary" className="h-9 px-3" aria-label="Tháng sau" disabled={!next} onClick={() => next && onChange(next)}>
        <Icon name="chevron_right" />
      </Button>
    </div>
  )
}

// Ô chọn từ danh sách Option, mục đầu là "tất cả".
export function OptionSelect({ label, value, all, options, onChange }: { label: string; value: string; all: string; options: Option[]; onChange: (v: string) => void }) {
  return (
    <Select label={label} value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="">{all}</option>
      {options.map((o) => (
        <option key={o.value} value={o.value}>
          {o.label}
        </option>
      ))}
    </Select>
  )
}

// Bảng có khung thẻ, cuộn ngang trên điện thoại.
export function TableCard({ children, footer }: { children: ReactNode; footer?: ReactNode }) {
  return (
    <div className="overflow-hidden rounded-xl border border-surface-container-highest bg-surface shadow-sm">
      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">{children}</table>
      </div>
      {footer}
    </div>
  )
}

// /m, /m/lich-su, /m/xin-duyet (giao diện điện thoại cũ) → trang mới; /m/can-duyet → hộp "Việc cần duyệt" (web.php).
export function legacyMobileTarget(path: string) {
  const map: Record<string, string> = {
    '/m': '/cham-cong',
    '/m/lich-su': '/cham-cong/lich-su',
    '/m/xin-duyet': '/cham-cong/don',
    '/m/can-duyet': '/approvals',
  }
  return map[path.replace(/\/+$/, '')] ?? '/cham-cong'
}

export const TH ='px-4 py-2 text-xs font-semibold whitespace-nowrap text-on-surface-variant'
export const TD = 'px-4 py-2 align-top'
