// Nút / ô chọn file tải lên kho file (dùng chung: hợp đồng nhân sự, file nghe / ảnh / đề PDF của đề test…).
// Kiểm đuôi + dung lượng trước khi gửi (rule), gọi onUpload(file); lỗi máy chủ hiện ngay dưới nút.
import { useId, useRef, useState, type ReactNode } from 'react'
import { errorMessage } from '../api'
import { acceptOf, checkFile, type FileRule } from '../lib/files'
import { Icon } from './ui'

export function FileUpload({
  rule,
  label,
  busyLabel = 'Đang tải lên…',
  onUpload,
  appearance = 'button',
  icon = 'upload_file',
  hint,
  disabled,
  accept,
}: {
  rule: FileRule
  label: ReactNode
  busyLabel?: ReactNode
  // Trả Promise: nút ở trạng thái đang tải tới khi xong; lỗi ném ra hiện dưới nút.
  onUpload: (file: File) => Promise<unknown>
  // button: nút nhỏ; zone: ô kéo-thả lớn có viền nét đứt
  appearance?: 'button' | 'zone'
  icon?: string
  hint?: ReactNode
  disabled?: boolean
  // Mặc định theo rule (".pdf,.docx"); vd. "audio/*" để máy điện thoại mở đúng trình chọn
  accept?: string
}) {
  const id = useId()
  const input = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [over, setOver] = useState(false)
  const off = disabled || busy

  async function pick(file: File | undefined) {
    if (!file || off) return
    const msg = checkFile(file, rule)
    if (msg) {
      setError(msg)
      return
    }
    setError('')
    setBusy(true)
    try {
      await onUpload(file)
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
      if (input.current) input.current.value = ''
    }
  }

  const field = (
    <input
      ref={input}
      id={id}
      type="file"
      className="sr-only"
      accept={accept ?? acceptOf(rule)}
      disabled={off}
      onChange={(e) => pick(e.target.files?.[0])}
    />
  )
  const err = error && (
    <p role="alert" className="text-xs text-error">
      {error}
    </p>
  )

  if (appearance === 'zone') {
    return (
      <div className="space-y-1.5">
        <label
          htmlFor={id}
          onDragOver={(e) => {
            e.preventDefault()
            setOver(true)
          }}
          onDragLeave={() => setOver(false)}
          onDrop={(e) => {
            e.preventDefault()
            setOver(false)
            pick(e.dataTransfer.files?.[0])
          }}
          className={`flex flex-col items-center gap-1.5 rounded-xl border-2 border-dashed p-6 text-center transition-colors ${
            off ? 'cursor-not-allowed opacity-70' : 'cursor-pointer hover:border-primary hover:bg-primary-light'
          } ${over ? 'border-primary bg-primary-light' : 'border-outline-variant'}`}
        >
          <Icon name={busy ? 'progress_activity' : icon} className={`text-3xl text-primary ${busy ? 'animate-spin' : ''}`} />
          <span className="text-sm font-semibold text-on-surface">{busy ? busyLabel : label}</span>
          {hint && <span className="max-w-xl text-xs text-on-surface-variant">{hint}</span>}
          {field}
        </label>
        {err}
      </div>
    )
  }
  return (
    <div className="space-y-1">
      <label
        htmlFor={id}
        className={`inline-flex h-9 items-center gap-1.5 rounded-lg border border-outline-variant bg-surface px-3 text-sm font-medium text-on-surface transition-colors focus-within:outline-2 focus-within:outline-primary ${
          off ? 'cursor-not-allowed opacity-50' : 'cursor-pointer hover:bg-surface-container-low'
        }`}
      >
        <Icon name={busy ? 'progress_activity' : icon} className={`text-[18px] ${busy ? 'animate-spin' : ''}`} />
        {busy ? busyLabel : label}
        {field}
      </label>
      {hint && <p className="text-xs text-on-surface-variant">{hint}</p>}
      {err}
    </div>
  )
}
