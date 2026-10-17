// Thành phần dùng chung của các màn danh mục: phân trang, ô nhiều dòng, thông báo kết quả, bảng.
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useId, useState, type ReactNode, type TextareaHTMLAttributes } from 'react'
import { errorMessage } from '../../api'
import { Alert, Button } from '../../components/ui'
import type { Msg } from './api'

export function Pager({ page, total, size, onPage }: { page: number; total: number; size: number; onPage: (p: number) => void }) {
  const pages = Math.max(1, Math.ceil(total / Math.max(1, size)))
  if (pages <= 1) return null
  return (
    <div className="mt-4 flex items-center justify-end gap-2 text-sm">
      <Button variant="secondary" disabled={page <= 1} onClick={() => onPage(page - 1)}>
        Trước
      </Button>
      <span>
        Trang {page}/{pages}
      </span>
      <Button variant="secondary" disabled={page >= pages} onClick={() => onPage(page + 1)}>
        Sau
      </Button>
    </div>
  )
}

export function TextArea({ label, hint, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string; hint?: string }) {
  const id = useId()
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="block text-sm font-medium text-on-surface">
        {label}
      </label>
      <textarea
        id={id}
        rows={3}
        className="block w-full rounded-lg border border-outline-variant px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
        {...props}
      />
      {hint && <p className="text-xs text-on-surface-variant">{hint}</p>}
    </div>
  )
}

export function PageTitle({ title, children }: { title: ReactNode; children?: ReactNode }) {
  return (
    <span className="flex flex-wrap items-center justify-between gap-4">
      {title}
      {children && <span className="flex flex-wrap gap-2">{children}</span>}
    </span>
  )
}

export function Stat({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="rounded-lg border border-surface-container-highest px-4 py-3">
      <p className="text-xs text-on-surface-variant">{label}</p>
      <p className="mt-1 text-lg font-semibold">{value}</p>
    </div>
  )
}

export function Table({ head, children }: { head: ReactNode[]; children: ReactNode }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-left text-sm">
        <thead className="text-xs text-on-surface-variant">
          <tr>
            {head.map((h, i) => (
              <th key={i} className="py-2 pr-4 font-medium">
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

export const td = 'py-2 pr-4 align-top'
export const actionsTd = 'space-x-2 py-2 text-right whitespace-nowrap align-top'

// Thao tác ghi một lần (bật/tắt, xóa...): hỏi xác nhận nếu có, chạy, làm mới các query, giữ thông báo kết quả.
export function useAction(keys: string[][]) {
  const qc = useQueryClient()
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const m = useMutation({
    mutationFn: (fn: () => Promise<Msg>) => fn(),
    onSuccess: (r) => {
      setNotice({ tone: 'success', text: r.message })
      keys.forEach((k) => qc.invalidateQueries({ queryKey: k }))
    },
    onError: (e) => setNotice({ tone: 'error', text: errorMessage(e) }),
  })
  const run = (fn: () => Promise<Msg>, confirmText?: string) => {
    if (confirmText && !window.confirm(confirmText)) return
    m.mutate(fn)
  }
  const view = notice && <Alert tone={notice.tone}>{notice.text}</Alert>
  return { run, notice: view, setNotice, pending: m.isPending }
}

export function Empty({ children }: { children: ReactNode }) {
  return <p className="py-4 text-sm text-on-surface-variant">{children}</p>
}

// Số nguyên từ ô nhập; trống thì null.
export function intOrNull(v: string) {
  if (v.trim() === '') return null
  const n = Number(v)
  return Number.isFinite(n) ? Math.trunc(n) : null
}
