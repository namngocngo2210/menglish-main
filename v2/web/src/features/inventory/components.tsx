// Thành phần dùng chung của Kho vật phẩm / Order học liệu.
import type { ReactNode, TextareaHTMLAttributes } from 'react'
import { Button } from '../../components/ui'
import type { Movement } from './api'
import { formatTimeDate, pageCount, signed } from './logic'

export type Tone = 'neutral' | 'success' | 'error' | 'info' | 'warning'

// Nhãn màu (Badge của ui.tsx chưa có màu cảnh báo).
export function Tag({ tone = 'neutral', children }: { tone?: Tone; children: ReactNode }) {
  const styles = {
    neutral: 'bg-surface-container-low text-on-surface-variant',
    success: 'bg-success-container text-on-success-container',
    error: 'bg-error-container text-on-error-container',
    info: 'bg-info-container text-on-info-container',
    warning: 'bg-warning-container text-warning',
  }[tone]
  return <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ${styles}`}>{children}</span>
}

// Hộp thoại rộng (bảng nhật ký, form nhiều cột); Esc hoặc bấm nền để đóng.
export function WideDialog({
  title,
  subtitle,
  onClose,
  children,
}: {
  title: string
  subtitle?: string
  onClose: () => void
  children: ReactNode
}) {
  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-10"
      onMouseDown={(e) => e.target === e.currentTarget && onClose()}
      onKeyDown={(e) => e.key === 'Escape' && onClose()}
    >
      <section role="dialog" aria-modal="true" aria-label={title} className="w-full max-w-3xl rounded-xl bg-surface p-6 shadow-lg">
        <div className="mb-4 flex items-start justify-between gap-4">
          <div>
            <h2 className="text-base font-semibold">{title}</h2>
            {subtitle && <p className="text-xs text-on-surface-variant">{subtitle}</p>}
          </div>
          <button type="button" onClick={onClose} className="text-sm text-on-surface-variant hover:text-on-surface" aria-label="Đóng">
            Đóng
          </button>
        </div>
        {children}
      </section>
    </div>
  )
}

export function Pager({ page, total, size, onPage }: { page: number; total: number; size: number; onPage: (p: number) => void }) {
  const pages = pageCount(total, size)
  if (pages <= 1) return null
  return (
    <div className="mt-4 flex items-center justify-between gap-2 text-sm text-on-surface-variant">
      <span>
        Trang {page}/{pages} · {total} dòng
      </span>
      <span className="flex gap-2">
        <Button variant="secondary" disabled={page <= 1} onClick={() => onPage(page - 1)}>
          Trước
        </Button>
        <Button variant="secondary" disabled={page >= pages} onClick={() => onPage(page + 1)}>
          Sau
        </Button>
      </span>
    </div>
  )
}

const TYPE_TONES: Record<string, Tone> = {
  import: 'success',
  opening: 'success',
  transfer_in: 'success',
  return: 'info',
  sale: 'warning',
  issue: 'warning',
  transfer_out: 'warning',
  count: 'neutral',
}

// Bảng nhật ký xuất nhập kho (trang Tồn kho và hộp thoại lịch sử một mặt hàng).
export function MovementTable({ movements, showItem = false }: { movements: Movement[]; showItem?: boolean }) {
  if (movements.length === 0) return <p className="text-sm text-on-surface-variant">Chưa có lần xuất nhập nào.</p>
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[560px] text-left text-sm">
        <thead className="text-xs text-on-surface-variant">
          <tr>
            <th className="py-2 pr-4 font-medium">Thời gian</th>
            {showItem && <th className="py-2 pr-4 font-medium">Mặt hàng</th>}
            <th className="py-2 pr-4 font-medium">Loại</th>
            <th className="py-2 pr-4 text-right font-medium">SL</th>
            <th className="py-2 pr-4 text-right font-medium">Tồn sau</th>
            <th className="py-2 font-medium">Chứng từ / Ghi chú</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-surface-container-highest">
          {movements.map((m) => (
            <tr key={m.id}>
              <td className="py-2 pr-4 font-mono text-xs whitespace-nowrap">{formatTimeDate(m.created_at)}</td>
              {showItem && <td className="py-2 pr-4">{m.item_name}</td>}
              <td className="py-2 pr-4">
                <Tag tone={TYPE_TONES[m.type] ?? 'neutral'}>{m.type_label}</Tag>
              </td>
              <td className={`py-2 pr-4 text-right font-mono font-semibold ${m.change < 0 ? 'text-error' : 'text-success'}`}>{signed(m.change)}</td>
              <td className={`py-2 pr-4 text-right font-mono ${m.balance < 0 ? 'font-semibold text-error' : ''}`}>{m.balance}</td>
              <td className="py-2 text-xs">
                {m.document_number && <div className="font-mono">{m.document_number}</div>}
                {m.counterpart_branch && (
                  <div className="text-on-surface-variant">
                    {m.type === 'transfer_out' ? 'Sang ' : 'Từ '}
                    {m.counterpart_branch}
                  </div>
                )}
                {m.note && <div className="text-on-surface-variant">{m.note}</div>}
                {m.user_name && <div className="text-on-surface-subtle">{m.user_name}</div>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export function Textarea({ label, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string }) {
  return (
    <label className="block space-y-1.5">
      <span className="block text-sm font-medium text-on-surface">{label}</span>
      <textarea
        className="block w-full rounded-lg border border-outline-variant px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
        {...props}
      />
    </label>
  )
}
