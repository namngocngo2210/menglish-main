// Định tuyến tối thiểu theo đường dẫn (History API); bước 3 thay bằng router đầy đủ cùng khung giao diện.
import { useSyncExternalStore } from 'react'

const listeners = new Set<() => void>()

function subscribe(fn: () => void) {
  listeners.add(fn)
  window.addEventListener('popstate', fn)
  return () => {
    listeners.delete(fn)
    window.removeEventListener('popstate', fn)
  }
}

// navigate nhận cả query (vd. '/search?q=abc'); trùng địa chỉ hiện tại thì bỏ qua.
export function navigate(path: string) {
  if (path === window.location.pathname + window.location.search) return
  window.history.pushState(null, '', path)
  listeners.forEach((fn) => fn())
}

export function usePath() {
  return useSyncExternalStore(subscribe, () => window.location.pathname)
}

// useQueryParam đọc một tham số trên URL hiện tại ('' nếu không có); đổi khi navigate / quay lại.
export function useQueryParam(name: string) {
  const search = useSyncExternalStore(subscribe, () => window.location.search)
  return new URLSearchParams(search).get(name) ?? ''
}
