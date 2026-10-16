import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '../../components/ui'
import { Popover } from '../../components/shell/Popover'
import { navigate } from '../../router'
import { notifyApi, TONE_CLASS, unreadBadge, type NotificationItem } from './api'
import { timeAgo } from './format'
import { EmptyState } from './parts'

// Hỏi lại số chưa đọc mỗi phút (Laravel nạp lại cùng trang; v2 là SPA nên hỏi định kỳ).
const POLL_MS = 60_000

// Mở thông báo: đánh dấu đã đọc rồi chuyển tới đường dẫn của nó (nếu có).
export function useOpenNotification() {
  const qc = useQueryClient()
  const read = useMutation({
    mutationFn: notifyApi.read,
    onSettled: () => {
      void qc.invalidateQueries({ queryKey: ['notify-summary'] })
      void qc.invalidateQueries({ queryKey: ['notifications'] })
    },
  })
  return {
    read,
    open: (n: NotificationItem) => {
      if (!n.is_read) read.mutate(n.id)
      if (n.link) navigate(n.link)
    },
  }
}

export function useReadAll() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: notifyApi.readAll,
    onSettled: () => {
      void qc.invalidateQueries({ queryKey: ['notify-summary'] })
      void qc.invalidateQueries({ queryKey: ['notifications'] })
    },
  })
}

// Chuông trên topbar (Layouts/Shell/NotificationMenu.vue): số chưa đọc, 6 thông báo mới nhất, đọc tất cả, xem tất cả.
export function NotificationBell() {
  const summary = useQuery({ queryKey: ['notify-summary'], queryFn: notifyApi.summary, refetchInterval: POLL_MS })
  const { open } = useOpenNotification()
  const readAll = useReadAll()
  const unread = summary.data?.unread ?? 0
  const items = summary.data?.items ?? []

  return (
    <Popover
      label={unread > 0 ? `Thông báo (${unread} chưa đọc)` : 'Thông báo'}
      width="w-[380px]"
      trigger={
        <>
          <Icon name="notifications" />
          {unread > 0 && (
            <span
              aria-hidden="true"
              className="absolute top-1 right-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full border-2 border-surface bg-error px-1 font-mono text-[11px] font-bold text-white"
            >
              {unreadBadge(unread)}
            </span>
          )}
        </>
      }
    >
      {(close) => (
        <div>
          <div className="flex items-center justify-between gap-2 border-b border-surface-container-high bg-surface-container-low px-4 py-3">
            <div className="flex items-center gap-1.5 text-sm font-semibold text-on-surface">
              <Icon name="notifications_active" className="text-[18px] text-error" />
              Thông báo
              {unread > 0 && <span className="rounded-full bg-error-container px-2 text-xs text-on-error-container">{unread} chưa đọc</span>}
            </div>
            {unread > 0 && (
              <button type="button" onClick={() => readAll.mutate()} className="text-xs font-semibold text-primary hover:underline">
                Đã đọc tất cả
              </button>
            )}
          </div>
          <ul className="max-h-[380px] divide-y divide-surface-container-high overflow-y-auto">
            {items.map((n) => (
              <li key={n.id} className={`flex items-start gap-3 px-4 py-3 hover:bg-surface-container-low ${n.is_read ? '' : 'bg-error-container/20'}`}>
                <span className={`flex size-9 shrink-0 items-center justify-center rounded-lg ${TONE_CLASS[n.tone]}`}>
                  <Icon name={n.icon} className="text-[18px]" />
                </span>
                <div className="min-w-0 flex-1 space-y-1">
                  <div className="flex items-center justify-between gap-2">
                    <span className="truncate text-sm font-semibold text-on-surface">{n.title}</span>
                    {!n.is_read && <span className="size-2 shrink-0 rounded-full bg-error" aria-label="Chưa đọc" />}
                  </div>
                  {n.body && <p className="line-clamp-2 text-xs text-on-surface-variant">{n.body}</p>}
                  <div className="flex items-center justify-between">
                    <span className="font-mono text-xs text-on-surface-subtle">{timeAgo(n.created_at)}</span>
                    {n.link && (
                      <a
                        href={n.link}
                        onClick={(e) => {
                          e.preventDefault()
                          close()
                          open(n)
                        }}
                        className="inline-flex items-center gap-0.5 text-xs font-semibold text-info hover:underline"
                      >
                        Xử lý ngay <Icon name="arrow_forward" className="text-[14px]" />
                      </a>
                    )}
                  </div>
                </div>
              </li>
            ))}
          </ul>
          {summary.data && items.length === 0 && (
            <EmptyState icon="notifications_paused" title="Không có thông báo" description="Chưa có thông báo hoặc cảnh báo nào." />
          )}
          <div className="border-t border-surface-container-high bg-surface-container-low p-2 text-center">
            <a
              href="/notifications"
              onClick={(e) => {
                e.preventDefault()
                close()
                navigate('/notifications')
              }}
              className="inline-flex items-center gap-1 text-sm font-semibold text-primary hover:underline"
            >
              Xem tất cả thông báo <Icon name="chevron_right" className="text-[16px]" />
            </a>
          </div>
        </div>
      )}
    </Popover>
  )
}
