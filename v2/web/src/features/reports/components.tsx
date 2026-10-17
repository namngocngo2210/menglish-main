// Thành phần dùng chung của các màn báo cáo: tab, băng trạng thái kiểm tra, lịch sử kỳ, báo cáo chữ cũ, mục số liệu,
// bảng KPI, hộp thoại rộng, phân trang, bản nháp ghi chú trên máy.
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Alert, Badge, Button, Dialog } from '../../components/ui'
import { navigate } from '../../router'
import type { Detail, HistoryItem, KpiGroup, LegacyReport, MetricItem, Page, Review, Section, Tab } from './api'
import { formatDate, historyBadge, kindPath, sameNotes } from './logic'

export function PageHeader({ title, meta, actions }: { title: string; meta?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 className="text-xl font-semibold">{title}</h1>
        {meta && <p className="mt-1 text-sm text-on-surface-variant">{meta}</p>}
      </div>
      {actions}
    </div>
  )
}

export function Panel({ title, children, className = '' }: { title?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={`space-y-4 rounded-xl border border-surface-container-highest bg-surface p-5 shadow-sm ${className}`}>
      {title && <h2 className="text-base font-semibold">{title}</h2>}
      {children}
    </section>
  )
}

// Tab "Báo cáo định kỳ của tôi": kind rỗng = báo cáo chữ tự do (/reports).
export function ReportTabs({ tabs, current }: { tabs: Tab[]; current: string }) {
  if (!tabs.length) return null
  return (
    <nav aria-label="Loại báo cáo" className="mb-6 flex gap-1 overflow-x-auto border-b border-surface-container-highest">
      {tabs.map((t) => {
        const path = t.kind ? kindPath(t.kind) : '/reports'
        return (
          <a
            key={t.kind || 'free'}
            href={path}
            onClick={(e) => {
              e.preventDefault()
              navigate(path)
            }}
            aria-current={t.kind === current ? 'page' : undefined}
            className="-mb-px border-b-2 border-transparent px-3 py-2 text-sm whitespace-nowrap text-on-surface-variant hover:text-on-surface aria-[current=page]:border-primary aria-[current=page]:font-semibold aria-[current=page]:text-primary"
          >
            {t.label}
          </a>
        )
      })}
    </nav>
  )
}

// Trạng thái Admin kiểm tra, hiện cho người viết trên màn nhập.
export function ReviewState({ review }: { review: Review | null }) {
  if (review?.status === 'returned') {
    return (
      <Alert>
        <strong>Admin yêu cầu bổ sung</strong>
        <p className="whitespace-pre-line">{review.note}</p>
        <p className="mt-1 text-xs">{review.by ? `${review.by} gửi. ` : ''}Sửa báo cáo rồi bấm Lưu để nộp lại.</p>
      </Alert>
    )
  }
  if (review?.status === 'reviewed') {
    return (
      <Alert tone="success">
        {review.by ?? 'Admin'} đã kiểm tra báo cáo này{review.at ? ` lúc ${formatDate(review.at, 'H:i d/m/Y')}` : ''}.
      </Alert>
    )
  }
  return null
}

// Danh sách kỳ đã nộp (báo cáo ngày kèm ngày còn thiếu) — bấm để mở lại kỳ đó.
export function HistoryList({
  items,
  current,
  title,
  onPick,
}: {
  items: HistoryItem[]
  current: string
  title: string
  onPick: (period: string) => void
}) {
  return (
    <aside className="h-fit rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm">
      <h2 className="mb-2 text-xs font-semibold text-on-surface-variant uppercase">{title}</h2>
      {items.length ? (
        <ul className="space-y-1">
          {items.map((item) => {
            const badge = historyBadge(item)
            return (
              <li key={item.period}>
                <button
                  type="button"
                  onClick={() => onPick(item.period)}
                  className={`block w-full rounded-lg px-2 py-1 text-left text-sm hover:bg-surface-container-low ${item.period === current ? 'font-semibold text-primary' : ''}`}
                >
                  {item.label} {badge && <Badge tone={badge.tone}>{badge.label}</Badge>}
                  {item.updated_at && (
                    <span className="block text-xs text-on-surface-variant">Cập nhật {formatDate(item.updated_at, 'H:i d/m/Y')}</span>
                  )}
                </button>
              </li>
            )
          })}
        </ul>
      ) : (
        <p className="text-sm text-on-surface-variant italic">Chưa nộp kỳ nào.</p>
      )}
    </aside>
  )
}

// Báo cáo chữ tự do đã nộp trước khi có màn có cấu trúc — bấm để xem lại.
export function LegacyList({ items }: { items: LegacyReport[] }) {
  const [viewing, setViewing] = useState<LegacyReport | null>(null)
  if (!items.length) return null
  return (
    <aside className="h-fit rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm">
      <h2 className="mb-2 text-xs font-semibold text-on-surface-variant uppercase">Báo cáo dạng chữ trước đây</h2>
      <ul className="space-y-1">
        {items.map((item) => (
          <li key={item.id}>
            <button type="button" className="block w-full rounded-lg px-2 py-1 text-left text-sm hover:bg-surface-container-low" onClick={() => setViewing(item)}>
              {item.title}
              <span className="block text-xs text-on-surface-variant">{formatDate(item.report_date)}</span>
            </button>
          </li>
        ))}
      </ul>
      {viewing && (
        <Dialog title={viewing.title} onClose={() => setViewing(null)}>
          <p className="text-sm whitespace-pre-line">{viewing.content}</p>
        </Dialog>
      )}
    </aside>
  )
}

const toneBadge: Record<string, 'success' | 'error' | 'info' | 'neutral'> = { success: 'success', warning: 'info', error: 'error', muted: 'neutral' }

function DetailLine({ d }: { d: Detail }) {
  const cls = d.tone === 'strong' ? 'font-semibold' : d.tone === 'muted' ? 'text-on-surface-variant line-through' : ''
  return (
    <li className="text-sm">
      {d.tag && (
        <span className="mr-1 align-middle">
          <Badge tone={toneBadge[d.tone ?? ''] ?? 'neutral'}>{d.tag}</Badge>
        </span>
      )}
      {d.url ? (
        <a href={d.url} className={`hover:text-primary hover:underline ${cls}`}>
          {d.text}
        </a>
      ) : (
        <span className={cls}>{d.text}</span>
      )}
    </li>
  )
}

// Một mục số liệu tự động: nhãn + cách tính, số chỉ đọc, chi tiết (5 dòng đầu, "Xem thêm"); full = hiện hết.
export function MetricCard({ item, full = false }: { item: MetricItem; full?: boolean }) {
  const [open, setOpen] = useState(false)
  const details = item.details ?? []
  const shown = full || open ? details : details.slice(0, 5)
  const hasValue = item.value !== null && item.value !== undefined
  return (
    <div className={`flex flex-col gap-2 rounded-lg border border-surface-container-highest bg-surface-container-low p-3 ${hasValue ? '' : 'md:col-span-2 xl:col-span-3'}`}>
      <div className="flex items-start justify-between gap-2">
        <p className="text-xs font-medium text-on-surface-variant uppercase">{item.label}</p>
        <span title={item.info} aria-label={`Cách tính: ${item.label}`} className="cursor-help text-xs text-on-surface-variant">
          ⓘ
        </span>
      </div>
      {hasValue && <p className="text-2xl font-semibold">{item.value}</p>}
      {item.unavailable ? (
        <p className="text-xs text-on-surface-variant italic">Chưa có số liệu: {item.unavailable}</p>
      ) : details.length ? (
        <ul className="space-y-1">
          {shown.map((d, i) => (
            <DetailLine key={i} d={d} />
          ))}
        </ul>
      ) : (
        !hasValue && <p className="text-sm text-on-surface-variant">Không có phát sinh.</p>
      )}
      {!full && details.length > 5 && (
        <button type="button" className="self-start text-sm font-semibold text-primary hover:underline" onClick={() => setOpen(!open)}>
          {open ? 'Thu gọn' : `Xem thêm ${details.length - 5} dòng`}
        </button>
      )}
    </div>
  )
}

export function SectionMetrics({ section, full = false, children }: { section: Section; full?: boolean; children?: ReactNode }) {
  return (
    <Panel title={section.title}>
      {!section.items.length && section.empty && <p className="text-sm text-on-surface-variant">{section.empty}</p>}
      {section.items.length > 0 && (
        <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
          {section.items.map((item) => (
            <MetricCard key={item.key} item={item} full={full} />
          ))}
        </div>
      )}
      {children}
    </Panel>
  )
}

// Bảng số lần phát sinh theo mục KPI (báo cáo tuần Học vụ).
export function KpiTable({ groups }: { groups: KpiGroup[] }) {
  const [open, setOpen] = useState<Record<string, boolean>>({})
  if (!groups.length) {
    return (
      <p className="p-4 text-sm text-on-surface-variant">
        Chưa có mục KPI nào đang áp dụng. Admin cấu hình mục KPI ở màn Tiêu chí KPI.
      </p>
    )
  }
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-left text-sm">
        <thead className="text-xs text-on-surface-variant">
          <tr>
            <th className="px-4 py-2 font-medium">Hạng mục công việc</th>
            <th className="px-4 py-2 font-medium">Nguồn</th>
            <th className="px-4 py-2 text-right font-medium">Số lần phát sinh</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-surface-container-highest">
          {groups.map((g) => (
            <GroupRows key={g.name} group={g} open={open} toggle={(id) => setOpen({ ...open, [id]: !open[id] })} />
          ))}
        </tbody>
      </table>
    </div>
  )
}

function GroupRows({ group, open, toggle }: { group: KpiGroup; open: Record<string, boolean>; toggle: (id: string) => void }) {
  return (
    <>
      <tr className="bg-surface-container-low">
        <td colSpan={3} className="px-4 py-1 text-xs font-semibold text-on-surface-variant italic">
          Nhóm {group.name}
        </td>
      </tr>
      {group.items.map((item) => (
        <FragmentRow key={item.id} item={item} open={!!open[item.id]} toggle={() => toggle(item.id)} />
      ))}
    </>
  )
}

function FragmentRow({ item, open, toggle }: { item: KpiGroup['items'][number]; open: boolean; toggle: () => void }) {
  return (
    <>
      <tr>
        <td className="px-4 py-2">
          <span title={item.info}>
            {item.name} <span className="font-mono text-xs text-on-surface-variant">{item.code}</span>
          </span>
          {item.details.length > 0 && (
            <button type="button" className="block text-xs font-semibold text-primary hover:underline" onClick={toggle}>
              {open ? 'Ẩn chi tiết' : `Xem ${item.details.length} dòng`}
            </button>
          )}
        </td>
        <td className="px-4 py-2">
          <Badge tone={item.source === 'auto' ? 'info' : 'neutral'}>{item.source === 'auto' ? 'Tự động' : 'Nhật ký'}</Badge>
        </td>
        <td className="px-4 py-2 text-right font-semibold whitespace-nowrap">
          {item.count} <span className="font-normal text-on-surface-variant">{item.unit}</span>
        </td>
      </tr>
      {open && (
        <tr>
          <td colSpan={3} className="px-6 pb-2">
            <ul className="space-y-1">
              {item.details.map((d, i) => (
                <DetailLine key={i} d={d} />
              ))}
            </ul>
          </td>
        </tr>
      )}
    </>
  )
}

// Ô chỉ đọc trông như ô nhập (chi tiết báo cáo của Admin); trống hiện "—".
export function ReadonlyField({ label, value, multiline = false }: { label: string; value: string | number | null | undefined; multiline?: boolean }) {
  const empty = value === null || value === undefined || String(value).trim() === ''
  return (
    <div className="flex flex-col gap-1">
      <p className="text-xs text-on-surface-variant">{label}</p>
      <div
        className={`rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm ${multiline ? 'min-h-16 break-words whitespace-pre-line' : ''} ${empty ? 'text-on-surface-variant' : ''}`}
      >
        {empty ? '—' : value}
      </div>
    </div>
  )
}

export function TextArea({
  label,
  value,
  onChange,
  rows = 3,
  placeholder,
  required,
  maxLength,
}: {
  label: string
  value: string
  onChange: (v: string) => void
  rows?: number
  placeholder?: string
  required?: boolean
  maxLength?: number
}) {
  return (
    <label className="block space-y-1.5">
      <span className="block text-sm font-medium">{label}</span>
      <textarea
        className="block w-full rounded-lg border border-outline-variant px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
        rows={rows}
        value={value}
        placeholder={placeholder}
        required={required}
        maxLength={maxLength}
        onChange={(e) => onChange(e.target.value)}
      />
    </label>
  )
}

// Hộp thoại rộng cho chi tiết báo cáo (Dialog của bộ UI chỉ rộng max-w-lg).
export function WideDialog({ title, onClose, children, footer }: { title: string; onClose: () => void; children: ReactNode; footer?: ReactNode }) {
  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-10"
      onMouseDown={(e) => e.target === e.currentTarget && onClose()}
      onKeyDown={(e) => e.key === 'Escape' && onClose()}
    >
      <section role="dialog" aria-modal="true" aria-label={title} className="w-full max-w-5xl rounded-xl bg-surface p-6 shadow-lg">
        <div className="mb-4 flex items-center justify-between gap-4">
          <h2 className="text-base font-semibold">{title}</h2>
          <button type="button" onClick={onClose} className="text-sm text-on-surface-variant hover:text-on-surface" aria-label="Đóng">
            Đóng
          </button>
        </div>
        {children}
        {footer && <div className="mt-6 flex flex-wrap justify-end gap-2 border-t border-surface-container-highest pt-4">{footer}</div>}
      </section>
    </div>
  )
}

export function Pager({ page, onPage }: { page: Page; onPage: (p: number) => void }) {
  if (page.last_page <= 1) return null
  return (
    <div className="flex items-center justify-between gap-4 text-sm">
      <span className="text-on-surface-variant">
        Trang {page.page}/{page.last_page} · {page.total} mục
      </span>
      <span className="flex gap-2">
        <Button variant="secondary" disabled={page.page <= 1} onClick={() => onPage(page.page - 1)}>
          Trước
        </Button>
        <Button variant="secondary" disabled={page.page >= page.last_page} onClick={() => onPage(page.page + 1)}>
          Sau
        </Button>
      </span>
    </div>
  )
}

// Ghi chú giữ trên máy người viết (localStorage): không mất chữ đang gõ khi tải lại số liệu / đổi kỳ / lỡ đóng tab.
// Trùng bản đã lưu trên máy chủ thì tự xoá.
export function useNoteDraft(key: string, initial: Record<string, string>) {
  const [notes, setNotes] = useState(initial)
  const [restored, setRestored] = useState(false)
  const base = useRef(initial)
  const initialJson = JSON.stringify(initial)

  useEffect(() => {
    const init = JSON.parse(initialJson) as Record<string, string>
    base.current = init
    let saved: Record<string, string> | null = null
    try {
      const raw = window.localStorage.getItem(key)
      saved = raw ? JSON.parse(raw) : null
    } catch {
      saved = null
    }
    if (saved && typeof saved === 'object' && !sameNotes(saved, init)) {
      setNotes({ ...init, ...saved })
      setRestored(true)
    } else {
      setNotes(init)
      setRestored(false)
      remove(key)
    }
  }, [key, initialJson])

  const update = (next: Record<string, string>) => {
    setNotes(next)
    try {
      if (sameNotes(next, base.current)) window.localStorage.removeItem(key)
      else window.localStorage.setItem(key, JSON.stringify(next))
    } catch {
      // Trình duyệt chặn lưu trữ: vẫn dùng được, chỉ không có bản nháp.
    }
  }
  const clear = () => {
    remove(key)
    setRestored(false)
  }
  const discard = () => {
    clear()
    setNotes(base.current)
  }
  return { notes, setNotes: update, restored, clear, discard }
}

function remove(key: string) {
  try {
    window.localStorage.removeItem(key)
  } catch {
    // bỏ qua
  }
}

export function Loading() {
  return <p className="text-sm text-on-surface-variant">Đang tải…</p>
}
