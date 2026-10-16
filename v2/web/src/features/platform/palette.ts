import { useEffect, useState } from 'react'
import type { Screen } from '../../nav'
import type { SearchHit, SearchResult } from './api'

export const MIN_TERM = 2

// Một dòng trong bảng tìm nhanh (Ctrl K).
export type PaletteItem = { key: string; section: string; icon: string; title: string; subtitle: string; path: string }

// Dòng phụ của kết quả: mã · mô tả · trạng thái.
export function hitSubtitle(h: SearchHit) {
  return [h.code, h.subtitle, ...(h.meta ?? [])].filter(Boolean).join(' · ')
}

export function searchPath(term: string) {
  return `/search?q=${encodeURIComponent(term.trim())}`
}

// paletteItems gộp màn hình khớp tên (tìm ở giao diện) và kết quả từ máy chủ theo nhóm, thêm dòng "Xem tất cả".
export function paletteItems(screens: Screen[], result: SearchResult | undefined, term: string, maxScreens = 6): PaletteItem[] {
  const out: PaletteItem[] = screens.slice(0, maxScreens).map((s) => ({
    key: `screen:${s.path}`,
    section: 'Màn hình',
    icon: 'arrow_forward',
    title: s.title,
    subtitle: '',
    path: s.path,
  }))
  for (const g of result?.groups ?? []) {
    for (const h of g.items) {
      out.push({ key: `${g.key}:${h.id}`, section: g.label, icon: g.icon || 'search', title: h.title, subtitle: hitSubtitle(h), path: h.link })
    }
  }
  if ([...term.trim()].length >= MIN_TERM) {
    out.push({ key: 'all', section: '', icon: 'search', title: `Xem tất cả kết quả cho “${term.trim()}”`, subtitle: '', path: searchPath(term) })
  }
  return out
}

// Di chuyển dòng đang chọn bằng phím mũi tên, vòng lại đầu / cuối.
export function moveIndex(i: number, delta: number, len: number) {
  if (len === 0) return 0
  return (((i + delta) % len) + len) % len
}

// useDebounced trả value sau khi ngừng thay đổi ms mili giây (đỡ gọi API mỗi lần gõ phím).
export function useDebounced<T>(value: T, ms = 250) {
  const [v, setV] = useState(value)
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms)
    return () => clearTimeout(t)
  }, [value, ms])
  return v
}
