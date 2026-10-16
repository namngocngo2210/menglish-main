import { useQuery } from '@tanstack/react-query'
import { type KeyboardEvent, useEffect, useRef, useState } from 'react'
import type { Me } from '../../api'
import { Icon } from '../../components/ui'
import { searchScreens } from '../../nav'
import { navigate } from '../../router'
import { searchApi } from './api'
import { MIN_TERM, moveIndex, paletteItems, useDebounced } from './palette'

const PALETTE_LIMIT = 5

// Bảng tìm nhanh (mở bằng ô tìm kiếm trên topbar hoặc Ctrl K / ⌘ K): màn hình theo tên + kết quả các nhóm
// (người dùng, chi nhánh, nhóm của module khác). Mũi tên lên / xuống để chọn, Enter để mở, Esc để đóng.
export function CommandPalette({ me, onClose }: { me: Me; onClose: () => void }) {
  const [term, setTerm] = useState('')
  const [active, setActive] = useState(0)
  const input = useRef<HTMLInputElement>(null)
  const debounced = useDebounced(term.trim())
  const enabled = [...debounced].length >= MIN_TERM
  const result = useQuery({
    queryKey: ['search', debounced, PALETTE_LIMIT],
    queryFn: () => searchApi.search(debounced, PALETTE_LIMIT),
    enabled,
    staleTime: 30_000,
  })
  const items = paletteItems(searchScreens(me, term), enabled ? result.data : undefined, term)
  const current = Math.min(active, Math.max(items.length - 1, 0))

  useEffect(() => input.current?.focus(), [])

  function go(path: string) {
    onClose()
    navigate(path)
  }

  function onKeyDown(e: KeyboardEvent) {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      setActive(moveIndex(current, e.key === 'ArrowDown' ? 1 : -1, items.length))
    } else if (e.key === 'Enter') {
      e.preventDefault()
      if (items[current]) go(items[current].path)
    } else if (e.key === 'Escape') {
      e.preventDefault()
      onClose()
    }
  }

  let lastSection: string | null = null
  return (
    <div className="fixed inset-0 z-[60] flex items-start justify-center p-4 pt-[10vh]" role="dialog" aria-modal="true" aria-label="Tìm kiếm nhanh">
      <div className="absolute inset-0 bg-black/40" onClick={onClose} />
      <div className="relative w-full max-w-xl overflow-hidden rounded-2xl border border-surface-container-highest bg-surface shadow-2xl" onKeyDown={onKeyDown}>
        <div className="flex items-center gap-2 border-b border-surface-container-high px-4">
          <Icon name="search" className="text-on-surface-variant" />
          <input
            ref={input}
            type="search"
            role="combobox"
            aria-expanded={items.length > 0}
            aria-controls="palette-list"
            aria-activedescendant={items[current] ? `palette-${current}` : undefined}
            aria-label="Từ khóa tìm kiếm"
            placeholder="Tìm màn hình, người dùng, chi nhánh..."
            value={term}
            onChange={(e) => {
              setTerm(e.target.value)
              setActive(0)
            }}
            className="h-14 flex-1 bg-transparent text-base text-on-surface outline-none placeholder:text-on-surface-subtle"
          />
          <kbd className="rounded border border-outline-variant px-1.5 font-mono text-[11px] text-on-surface-variant">Esc</kbd>
        </div>
        <ul id="palette-list" role="listbox" aria-label="Kết quả" className="max-h-[60vh] overflow-y-auto py-2">
          {items.map((item, i) => {
            const header = item.section !== lastSection && item.section ? item.section : null
            lastSection = item.section
            return (
              <li key={item.key} role="presentation">
                {header && <div className="px-4 pt-2 pb-1 text-xs font-semibold text-on-surface-variant uppercase">{header}</div>}
                <div
                  id={`palette-${i}`}
                  role="option"
                  aria-selected={i === current}
                  onMouseEnter={() => setActive(i)}
                  onClick={() => go(item.path)}
                  className={`mx-2 flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 ${i === current ? 'bg-primary-light text-primary' : 'text-on-surface'}`}
                >
                  <Icon name={item.icon} className="text-[20px] text-on-surface-variant" />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium">{item.title}</span>
                    {item.subtitle && <span className="block truncate text-xs text-on-surface-variant">{item.subtitle}</span>}
                  </span>
                </div>
              </li>
            )
          })}
        </ul>
        {[...term.trim()].length < MIN_TERM && (
          <p className="px-4 pb-4 text-sm text-on-surface-variant">Nhập ít nhất 2 ký tự để tìm màn hình, người dùng, chi nhánh.</p>
        )}
        {enabled && result.isFetching && <p className="px-4 pb-3 text-xs text-on-surface-variant">Đang tìm...</p>}
      </div>
    </div>
  )
}
