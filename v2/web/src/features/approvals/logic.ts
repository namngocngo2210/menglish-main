// Logic thuần của hộp "Việc cần duyệt" (có test): mã mục, chọn nhiều, nút được bấm, badge.
import type { Action, ApprovalItem, ItemResult, Section } from './api'

export const APPROVALS_PATH = '/approvals'

// Mã mục "<nguồn>:<id>" — đúng dạng items[] của POST /approvals/bulk.
export const refOf = (item: Pick<ApprovalItem, 'source' | 'id'>) => `${item.source}:${item.id}`

// Nguồn hiện trên trang: chỉ nguồn còn mục (như visibleSections của Index.vue).
export const visibleSections = (sections: Section[]) => sections.filter((s) => s.items.length > 0)

export const selectable = (s: Section) => s.approve || s.reject

// Mã mục → thao tác nguồn của mục hỗ trợ.
export function supportMap(sections: Section[]) {
  const map = new Map<string, { approve: boolean; reject: boolean }>()
  for (const s of sections) for (const item of s.items) map.set(refOf(item), { approve: s.approve, reject: s.reject })
  return map
}

// Bấm được Duyệt / Từ chối khi mọi mục đã chọn đều thuộc nguồn hỗ trợ thao tác đó.
export function canAct(selected: string[], sections: Section[], action: Action) {
  const map = supportMap(sections)
  return selected.length > 0 && selected.every((r) => map.get(r)?.[action] === true)
}

export const refsOf = (s: Section) => s.items.map(refOf)

export const allSelected = (selected: string[], s: Section) => s.items.length > 0 && refsOf(s).every((r) => selected.includes(r))

export function toggleAll(selected: string[], s: Section, on: boolean) {
  const refs = refsOf(s)
  return on ? [...new Set([...selected, ...refs])] : selected.filter((r) => !refs.includes(r))
}

export const toggle = (selected: string[], ref: string, on: boolean) =>
  on ? (selected.includes(ref) ? selected : [...selected, ref]) : selected.filter((r) => r !== ref)

// Danh sách đổi (vừa duyệt / từ chối) → bỏ chọn mục không còn chờ.
export function keepPending(selected: string[], sections: Section[]) {
  const map = supportMap(sections)
  return selected.filter((r) => map.has(r))
}

// Badge sidebar như SidebarLink.vue: >99 hiện "99+", 0 / null thì ẩn.
export function badgeText(n: number | null | undefined) {
  if (!n || n <= 0) return null
  return n > 99 ? '99+' : String(n)
}

export const failedOf = (results: ItemResult[]) => results.filter((r) => !r.ok)

// Thẻ chip "Tất cả" / nhóm → đường dẫn trang.
export const groupPath = (slug: string | null) => (slug ? `${APPROVALS_PATH}?group=${encodeURIComponent(slug)}` : APPROVALS_PATH)

// Lý do từ chối bắt buộc, tối đa 1000 ký tự (như form Laravel).
export const REASON_MAX = 1000
export function reasonError(reason: string) {
  if (!reason.trim()) return 'Vui lòng nhập lý do từ chối.'
  if ([...reason.trim()].length > REASON_MAX) return `Lý do từ chối không được vượt quá ${REASON_MAX} ký tự.`
  return null
}
