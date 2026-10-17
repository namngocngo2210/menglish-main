// Thành phần dùng chung của màn công việc: nhãn trạng thái, khung trang, ô nhập nhiều dòng, form đổi trạng thái.
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useId, useState, type ReactNode, type TextareaHTMLAttributes } from 'react'
import { errorMessage } from '../../api'
import { Alert, Button } from '../../components/ui'
import { tasksApi, type Task, type Transition } from './api'
import { firstSafe, reasonLabel, reasonRequired, statusTone } from './logic'

// Thông báo "đã lưu" chuyển sang trang kế tiếp (lưu ở form riêng rồi quay về danh sách).
let pendingFlash = ''
export const flash = {
  set: (m: string) => {
    pendingFlash = m
  },
  take: () => {
    const m = pendingFlash
    pendingFlash = ''
    return m
  },
}

export function StatusBadge({ status, label }: { status: string; label: string }) {
  return <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ${statusTone(status)}`}>{label}</span>
}

export function Chip({ children, tone = 'neutral' }: { children: ReactNode; tone?: 'neutral' | 'primary' }) {
  const cls = tone === 'primary' ? 'bg-primary-light text-primary' : 'bg-surface-container-high text-on-surface-variant'
  return <span className={`inline-flex items-center rounded px-2 py-0.5 text-xs ${cls}`}>{children}</span>
}

export function PageHeader({ title, description, actions }: { title: string; description?: string; actions?: ReactNode }) {
  return (
    <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 className="text-xl font-semibold text-on-surface">{title}</h1>
        {description && <p className="mt-1 text-sm text-on-surface-variant">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  )
}

export function TextArea({
  label,
  hint,
  required,
  ...props
}: TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string; hint?: string }) {
  const id = useId()
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="block text-sm font-medium text-on-surface">
        {label}
        {required && <span className="text-error"> *</span>}
      </label>
      <textarea
        id={id}
        required={required}
        className="block w-full rounded-lg border border-outline-variant px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
        {...props}
      />
      {hint && <p className="text-xs text-on-surface-variant">{hint}</p>}
    </div>
  )
}

export function StatCard({ label, value, tone = 'neutral' }: { label: string; value: number; tone?: 'neutral' | 'primary' | 'warning' | 'error' }) {
  const color = { neutral: 'text-on-surface', primary: 'text-primary', warning: 'text-warning', error: 'text-error' }[tone]
  return (
    <div className="rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm">
      <p className="text-xs text-on-surface-variant">{label}</p>
      <p className={`mt-1 text-2xl font-semibold ${color}`}>{value}</p>
    </div>
  )
}

// Ghi chú khi "Chuyển lại cho người giao": nói rõ việc về tay ai.
export function handBackText(task: Pick<Task, 'creator' | 'assignee'>, creatorIsMe: boolean) {
  return creatorIsMe
    ? `Công việc chuyển về bạn (người giao) với trạng thái Mới, lý do bị chặn vẫn giữ trong chi tiết. ${task.assignee ?? 'Người đang làm'} nhận được thông báo và không cần làm tiếp.`
    : `Công việc sẽ chuyển sang ${task.creator || 'người giao'} (người giao) với trạng thái Mới. Lý do bị chặn vẫn giữ trong chi tiết, người giao nhận được thông báo.`
}

// Form đổi trạng thái: chọn bước (chỉ các bước máy chủ cho phép), ghi chú / lý do. "Hủy công việc" tô đỏ, không chọn sẵn.
export function StatusForm({
  task,
  creatorIsMe,
  initial,
  onDone,
}: {
  task: Task
  creatorIsMe: boolean
  initial?: string
  onDone: (message: string) => void
}) {
  const qc = useQueryClient()
  const values = task.allowed.map((a) => a.value)
  const [next, setNext] = useState(initial && values.includes(initial as Transition['value']) ? initial : firstSafe(values))
  const [reason, setReason] = useState('')
  const save = useMutation({
    mutationFn: () => tasksApi.changeStatus(task.id, next, reason),
    onSuccess: (res) => {
      setReason('')
      qc.invalidateQueries({ queryKey: ['tasks'] })
      onDone(res.message)
    },
  })
  if (!task.allowed.length) return null
  return (
    <form
      className="space-y-3 rounded-lg border border-outline-variant p-4"
      onSubmit={(e) => {
        e.preventDefault()
        save.mutate()
      }}
    >
      <p className="text-sm font-semibold">Cập nhật trạng thái</p>
      {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
      <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Trạng thái mới">
        {task.allowed.map((opt) => {
          const active = next === opt.value
          const danger = opt.value === 'canceled'
          const cls = danger
            ? active
              ? 'border-error bg-error-container text-error'
              : 'border-error/40 text-error'
            : active
              ? 'border-primary bg-primary-light text-primary'
              : 'border-outline-variant text-on-surface-variant'
          return (
            <label key={opt.value} className={`inline-flex cursor-pointer items-center rounded-lg border px-3 py-1.5 text-sm ${cls}`}>
              <input type="radio" name="status" value={opt.value} checked={active} onChange={() => setNext(opt.value)} className="sr-only" />
              {opt.label}
            </label>
          )
        })}
      </div>
      {next === 'hand_back' && <Alert tone="info">{handBackText(task, creatorIsMe)}</Alert>}
      <TextArea
        label={reasonLabel(next)}
        required={reasonRequired(next)}
        rows={3}
        maxLength={1000}
        value={reason}
        onChange={(e) => setReason(e.target.value)}
        placeholder="Nhập lý do chi tiết khiến công việc bị chặn / kết quả..."
        hint="Bắt buộc khi chuyển Bị chặn hoặc Hủy công việc."
      />
      <div className="flex justify-end">
        <Button type="submit" variant={next === 'canceled' ? 'danger' : 'primary'} disabled={!next || save.isPending}>
          {save.isPending ? 'Đang lưu…' : 'Cập nhật trạng thái'}
        </Button>
      </div>
    </form>
  )
}
