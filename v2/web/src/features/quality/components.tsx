// Thành phần dùng chung của Chất lượng lớp & Dự án học thuật: ô nhập nhiều dòng, thanh tiến độ, hộp xác nhận, ô chọn lọc.
import { useMutation } from '@tanstack/react-query'
import { useEffect, useId, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react'
import { errorMessage } from '../../api'
import { Alert, Button, Dialog } from '../../components/ui'
import { progressTone } from './logic'

export function Textarea({ label, hint, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string; hint?: string }) {
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

// Thanh tiến độ % (ProgressBar.vue): xanh khi xong, đỏ khi có mốc trễ, còn lại màu chính.
export function ProgressBar({ value, overdue = false, label, className = 'w-28' }: { value: number; overdue?: boolean; label?: string; className?: string }) {
  const tone = { done: 'bg-success', overdue: 'bg-error', normal: 'bg-primary' }[progressTone(value, overdue)]
  return (
    <div className={className}>
      <div
        className="h-1.5 overflow-hidden rounded-full bg-surface-container-low"
        role="progressbar"
        aria-valuenow={value}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label={label ?? `Tiến độ ${value}%`}
      >
        <div className={`h-full rounded-full ${tone}`} style={{ width: `${Math.min(100, value)}%` }} />
      </div>
      <span className="mt-0.5 block font-mono text-xs text-on-surface-variant">{label ?? `${value}%`}</span>
    </div>
  )
}

// Ô chọn của thanh lọc: có dòng "Tất cả …" ở đầu.
export function FilterSelect({
  label,
  all,
  options,
  ...props
}: SelectHTMLAttributes<HTMLSelectElement> & { label: string; all?: string; options: { value: string; label: string }[] }) {
  const id = useId()
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="block text-sm font-medium text-on-surface">
        {label}
      </label>
      <select
        id={id}
        className="block h-10 w-full rounded-lg border border-outline-variant bg-surface px-3 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
        {...props}
      >
        {all !== undefined && <option value="">{all}</option>}
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </div>
  )
}

// Hộp xác nhận một thao tác (xóa, đổi trạng thái…): gọi action, xong thì onDone(message).
export function ConfirmDialog({
  title,
  children,
  confirm,
  danger = false,
  action,
  onClose,
  onDone,
}: {
  title: string
  children: ReactNode
  confirm: string
  danger?: boolean
  action: () => Promise<{ message: string }>
  onClose: () => void
  onDone: (msg: string) => void
}) {
  const run = useMutation({ mutationFn: action, onSuccess: (r) => onDone(r.message) })
  return (
    <Dialog title={title} onClose={onClose}>
      {run.isError && (
        <div className="mb-3">
          <Alert>{errorMessage(run.error)}</Alert>
        </div>
      )}
      <div className="text-sm">{children}</div>
      <div className="mt-4 flex justify-end gap-2">
        <Button variant="secondary" onClick={onClose}>
          Hủy
        </Button>
        <Button variant={danger ? 'danger' : 'primary'} disabled={run.isPending} onClick={() => run.mutate()}>
          {confirm}
        </Button>
      </div>
    </Dialog>
  )
}

// Bảng cuộn ngang với kiểu chữ chung.
export function Table({ minWidth = 760, head, children }: { minWidth?: number; head: ReactNode; children: ReactNode }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-left text-sm" style={{ minWidth }}>
        <thead className="border-b border-surface-container-highest text-xs text-on-surface-variant [&_th]:px-3 [&_th]:py-2 [&_th]:font-medium">
          {head}
        </thead>
        <tbody className="divide-y divide-surface-container-highest [&_td]:px-3 [&_td]:py-2 [&_td]:align-top">{children}</tbody>
      </table>
    </div>
  )
}

// Khung thẻ không padding (bảng + phân trang sát viền).
export function Panel({ title, actions, children }: { title?: ReactNode; actions?: ReactNode; children: ReactNode }) {
  return (
    <section className="overflow-hidden rounded-xl border border-surface-container-highest bg-surface shadow-sm">
      {(title || actions) && (
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-surface-container-highest px-4 py-3">
          {title && <h2 className="text-base font-semibold">{title}</h2>}
          {actions}
        </div>
      )}
      {children}
    </section>
  )
}

// URL cũ của Laravel (/classes/qa-observation …): thay địa chỉ (không thêm lịch sử) rồi báo router đọc lại đường dẫn.
export function Redirect({ to }: { to: string }) {
  useEffect(() => {
    window.history.replaceState(null, '', to)
    window.dispatchEvent(new PopStateEvent('popstate'))
  }, [to])
  return null
}
