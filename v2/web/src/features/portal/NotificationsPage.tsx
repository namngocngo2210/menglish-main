import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Button, Icon } from '../../components/ui'
import { navigate } from '../../router'
import { TONE_CLASS } from '../platform/api'
import { timeAgo } from '../platform/format'
import { portalApi, type InboxItem } from './api'
import { Empty, PortalFrame, QueryError, useNotice, useStudentParam } from './components'

// Hộp thư thông báo của học viên (Portal/Notifications.vue): đánh dấu tất cả / từng thông báo đã đọc, xóa thông báo.
export function PortalNotificationsPage() {
  const sid = useStudentParam()
  const q = useQuery({ queryKey: ['portal', 'notifications', sid], queryFn: () => portalApi.notifications(sid) })
  const qc = useQueryClient()
  const notice = useNotice()
  const done = (r: { message: string }) => {
    void qc.invalidateQueries({ queryKey: ['portal'] })
    notice.ok(r.message)
  }
  const studentId = q.data?.student?.id ?? ''
  const readAll = useMutation({ mutationFn: () => portalApi.readAll(studentId), onSuccess: done, onError: notice.fail })
  const read = useMutation({ mutationFn: (id: string) => portalApi.read(studentId, id), onSuccess: done, onError: notice.fail })
  const del = useMutation({ mutationFn: (id: string) => portalApi.deleteNotification(studentId, id), onSuccess: done, onError: notice.fail })
  if (q.isError) return <QueryError error={q.error} />
  const d = q.data

  function open(n: InboxItem) {
    if (!n.link) return
    if (n.unread && d?.can_write) read.mutate(n.id)
    navigate(n.link)
  }

  return (
    <PortalFrame ctx={d} title="Thông báo" tab="notifications">
      {notice.view}
      {d && (
        <section className="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface shadow-sm">
          <div className="flex items-center justify-between border-b border-surface-container-highest bg-surface-container-low px-4 py-3">
            <span className="text-xs font-bold">Tất cả thông báo</span>
            {d.can_write && d.student && (
              <Button variant="secondary" className="h-8 px-3 text-xs" disabled={readAll.isPending || d.unread_count === 0} onClick={() => readAll.mutate()}>
                Đánh dấu tất cả đã đọc
              </Button>
            )}
          </div>
          <ul className="divide-y divide-surface-container-highest">
            {d.notifications.map((n) => (
              <li key={n.id} className={`relative flex items-start px-4 py-3.5 ${n.unread ? 'bg-primary/5' : 'opacity-80'}`}>
                {n.unread && <span className="absolute top-1/2 left-2 size-2 -translate-y-1/2 rounded-full bg-primary" aria-label="Chưa đọc" />}
                <span className={`mr-3 ml-2 flex size-10 shrink-0 items-center justify-center rounded-full border ${TONE_CLASS[n.tone] ?? TONE_CLASS.neutral}`}>
                  <Icon name={n.icon || 'notifications'} fill className="text-[20px]" />
                </span>
                <div className="min-w-0 flex-1">
                  <div className="mb-0.5 flex items-start justify-between gap-2">
                    <button
                      type="button"
                      onClick={() => open(n)}
                      className={`truncate text-left text-xs ${n.unread ? 'font-bold text-on-surface' : 'font-medium text-on-surface-variant'} ${n.link ? 'hover:underline' : 'cursor-default'}`}
                    >
                      {n.title}
                    </button>
                    <span className="text-xs font-medium whitespace-nowrap text-primary">{timeAgo(n.time)}</span>
                  </div>
                  <p className="text-xs leading-relaxed text-on-surface-variant">{n.content}</p>
                  {d.can_write && (
                    <div className="mt-2 flex items-center gap-3 text-xs">
                      {n.unread && (
                        <button type="button" onClick={() => read.mutate(n.id)} className="inline-flex items-center gap-1 font-medium text-primary hover:underline">
                          <Icon name="drafts" className="text-[16px]" /> Đánh dấu đã đọc
                        </button>
                      )}
                      <button
                        type="button"
                        onClick={() => window.confirm('Xóa thông báo này?') && del.mutate(n.id)}
                        className="inline-flex items-center gap-1 font-medium text-error hover:underline"
                      >
                        <Icon name="delete" className="text-[16px]" /> Xóa
                      </button>
                    </div>
                  )}
                </div>
              </li>
            ))}
          </ul>
          {d.notifications.length === 0 ? (
            <div className="p-4">
              <Empty icon="notifications_off" title="Chưa có thông báo nào." />
            </div>
          ) : (
            <p className="flex items-center justify-center gap-1.5 py-6 text-xs text-on-surface-variant">
              <Icon name="done_all" className="text-[16px]" /> Đã tải hết thông báo gần đây
            </p>
          )}
        </section>
      )}
    </PortalFrame>
  )
}
