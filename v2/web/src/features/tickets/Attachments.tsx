import { useEffect, useMemo, useRef, useState } from 'react'
import { Icon } from '../../components/ui'
import { formatBytes } from '../../lib/files'
import { ACCEPT, addFiles, pastedName } from './logic'

// Ô đính kèm của ticket (AttachmentUploader.vue): chọn / kéo thả file, dán ảnh từ clipboard (Ctrl + V) khi trang đang mở.
// compact: ô gọn một dòng (ô trả lời).
export function Attachments({ files, onChange, compact }: { files: File[]; onChange: (f: File[]) => void; compact?: boolean }) {
  const input = useRef<HTMLInputElement>(null)
  const [dragging, setDragging] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const latest = useRef(files)
  latest.current = files

  const add = (list: File[]) => {
    const res = addFiles(latest.current, list)
    setError(res.error)
    onChange(res.files)
  }

  useEffect(() => {
    let n = 0
    const onPaste = (e: ClipboardEvent) => {
      const images: File[] = []
      for (const item of e.clipboardData?.items ?? []) {
        if (!item.type.startsWith('image/')) continue
        const blob = item.getAsFile()
        if (blob) images.push(new File([blob], pastedName(item.type, latest.current.length + images.length + ++n), { type: item.type }))
      }
      if (images.length) add(images)
    }
    window.addEventListener('paste', onPaste)
    return () => window.removeEventListener('paste', onPaste)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const previews = useMemo(() => files.map((f) => ({ f, url: f.type.startsWith('image/') ? URL.createObjectURL(f) : '' })), [files])
  useEffect(() => () => previews.forEach((p) => p.url && URL.revokeObjectURL(p.url)), [previews])

  return (
    <div className="space-y-2">
      {!compact && <span className="block text-sm font-medium text-on-surface">Hình ảnh đính kèm minh chứng / Ảnh chụp màn hình lỗi</span>}
      <button
        type="button"
        onClick={() => input.current?.click()}
        onDragOver={(e) => {
          e.preventDefault()
          setDragging(true)
        }}
        onDragLeave={() => setDragging(false)}
        onDrop={(e) => {
          e.preventDefault()
          setDragging(false)
          add([...e.dataTransfer.files])
        }}
        className={`flex w-full cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed text-center transition ${
          compact ? 'p-3' : 'p-6'
        } ${dragging ? 'border-primary bg-primary-light' : 'border-outline-variant bg-surface-container-low/50 hover:border-primary'}`}
      >
        <Icon name={compact ? 'add_photo_alternate' : 'cloud_upload'} className="text-[24px] text-primary" />
        <span className="text-xs font-medium text-on-surface">
          Kéo thả ảnh hoặc <span className="text-primary underline">chọn từ thiết bị</span> · dán trực tiếp (Ctrl + V)
        </span>
        {!compact && <span className="text-xs text-on-surface-variant">PNG, JPG, GIF, WEBP hoặc PDF / Word / Excel — tối đa 15 MB/file, 4 tệp mỗi lần gửi</span>}
      </button>
      <input
        ref={input}
        type="file"
        multiple
        accept={ACCEPT}
        className="hidden"
        aria-label="Chọn tệp đính kèm"
        onChange={(e) => {
          add([...(e.target.files ?? [])])
          e.target.value = ''
        }}
      />
      {error && (
        <p className="text-xs text-error" role="alert">
          {error}
        </p>
      )}
      {previews.length > 0 && (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
          {previews.map(({ f, url }, i) => (
            <div key={`${f.name}-${i}`} className="relative overflow-hidden rounded-lg border border-surface-container-highest bg-surface p-1">
              <div className="flex h-20 items-center justify-center overflow-hidden rounded bg-surface-container">
                {url ? <img src={url} alt="" className="h-full w-full object-cover" /> : <Icon name="draft" className="text-[28px] text-on-surface-variant" />}
              </div>
              <p className="truncate px-0.5 pt-1 text-xs font-medium">{f.name}</p>
              <p className="px-0.5 text-xs text-on-surface-variant">{formatBytes(f.size)}</p>
              <button
                type="button"
                className="absolute top-1.5 right-1.5 flex size-6 items-center justify-center rounded-full bg-error text-white shadow"
                aria-label={`Xóa tệp ${f.name}`}
                onClick={() => onChange(files.filter((_, j) => j !== i))}
              >
                <Icon name="close" className="text-[16px]" />
              </button>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
