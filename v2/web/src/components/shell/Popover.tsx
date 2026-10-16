import { useEffect, useRef, useState, type ReactNode } from 'react'

// Menu thả xuống (như UiDropdown của Laravel): bấm nút để mở, bấm ra ngoài hoặc Esc để đóng.
export function Popover({
  trigger,
  label,
  width = 'w-56',
  children,
}: {
  trigger: ReactNode
  label: string
  width?: string
  children: (close: () => void) => ReactNode
}) {
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false)
    }
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        aria-label={label}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
        className="relative flex items-center gap-2 rounded-lg p-2 text-on-surface-variant transition-colors hover:bg-surface-container-low focus-visible:outline-2 focus-visible:outline-primary"
      >
        {trigger}
      </button>
      {open && (
        <div
          className={`absolute right-0 z-50 mt-2 ${width} max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-surface-container-highest bg-surface shadow-lg`}
        >
          {children(() => setOpen(false))}
        </div>
      )}
    </div>
  )
}
