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

export function navigate(path: string) {
  if (path === window.location.pathname) return
  window.history.pushState(null, '', path)
  listeners.forEach((fn) => fn())
}

export function usePath() {
  return useSyncExternalStore(subscribe, () => window.location.pathname)
}
