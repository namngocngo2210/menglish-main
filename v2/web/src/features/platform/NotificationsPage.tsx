import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Icon, Select } from '../../components/ui'
import { can } from '../../nav'
import { notifyApi, TONE_CLASS, type NotificationFilter, type NotificationItem } from './api'
import { useOpenNotification, useReadAll } from './NotificationBell'
import { formatDateTime, formatNumber, timeAgo } from './format'
import { EmptyState, PageHeader, Pager, StatCard } from './parts'

const STALE = 'stale_lead_24h'

// Trang Thông báo (Laravel Notifications/Index.vue): thống kê, lọc nhanh (Tất cả / Lead tồn đọng >24h / Chưa đọc),
// lọc theo loại, danh sách + đánh dấu đã đọc. Thẻ và nút lọc lead tồn đọng chỉ cho người xem khách (lead.view).
export function NotificationsPage({ me }: { me: Me }) {
  const [filter, setFilter] = useState<NotificationFilter>({ page: 1 })
  const data = useQuery({ queryKey: ['notifications', filter], queryFn: () => notifyApi.list(filter), placeholderData: keepPreviousData })
  const readAll = useReadAll()
  const { open, read } = useOpenNotification()
  const [copied, setCopied] = useState<string | null>(null)
  const leads = can(me, 'lead.view')
  const stats = data.data?.stats
  const set = (f: NotificationFilter) => setFilter({ page: 1, ...f })

  async function copy(n: NotificationItem, text: string) {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(n.id)
    } catch {
      setCopied(null)
    }
  }

  return (
    <>
      <PageHeader
        title="Thông báo"
        description="Thông báo và cảnh báo gửi riêng cho bạn. Bấm vào thông báo có đường dẫn để mở và đánh dấu đã đọc."
        actions={
          <Button onClick={() => readAll.mutate()} disabled={readAll.isPending || !stats?.unread}>
            <Icon name="done_all" className="mr-1.5 text-[20px]" /> Đánh dấu tất cả đã đọc
          </Button>
        }
      />

      {stats && (
        <div className={`mb-4 grid grid-cols-1 gap-4 ${leads ? 'sm:grid-cols-3' : 'sm:grid-cols-2'}`}>
          <StatCard label="Tổng thông báo" value={formatNumber(stats.total)} icon="notifications" />
          {leads && <StatCard label="Lead bị sót >24h (Chưa xử lý)" value={formatNumber(stats.alerts)} icon="person_alert" tone="error" />}
          <StatCard label="Thông báo chưa đọc" value={formatNumber(stats.unread)} icon="mark_email_unread" tone="warning" />
        </div>
      )}

      <section className="mb-4 flex flex-wrap items-end justify-between gap-3 rounded-xl border border-surface-container-highest bg-surface p-4">
        <div className="flex flex-wrap items-center gap-2" aria-label="Lọc nhanh">
          <QuickFilter active={!filter.type && !filter.unread} onClick={() => set({})}>
            Tất cả
          </QuickFilter>
          {leads && (
            <QuickFilter active={filter.type === STALE} onClick={() => set({ type: STALE })}>
              Lead tồn đọng &gt;24h ({stats?.alerts ?? 0})
            </QuickFilter>
          )}
          <QuickFilter active={!!filter.unread} onClick={() => set({ unread: true, type: filter.type })}>
            Chưa đọc ({stats?.unread ?? 0})
          </QuickFilter>
        </div>
        <div className="w-full sm:w-64">
          <Select label="Loại thông báo" value={filter.type ?? ''} onChange={(e) => set({ type: e.target.value || undefined, unread: filter.unread })}>
            <option value="">Mọi loại</option>
            {data.data?.types.map((t) => (
              <option key={t.value} value={t.value}>
                {t.label}
              </option>
            ))}
          </Select>
        </div>
      </section>

      {data.isError && (
        <div className="mb-4">
          <Alert>{errorMessage(data.error)}</Alert>
        </div>
      )}

      <ul className="space-y-3">
        {data.data?.notifications.map((n) => {
          const lead = typeof n.data.customer_id === 'string' || typeof n.data.customer_id === 'number' ? n.data : null
          const copyText = typeof n.data.copy_text === 'string' ? n.data.copy_text : null
          return (
            <li
              key={n.id}
              className={`rounded-xl border p-4 ${n.is_read ? 'border-surface-container-highest bg-surface opacity-80' : 'border-error/30 bg-error/5 shadow-sm'}`}
            >
              <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                <div className="flex min-w-0 flex-1 items-start gap-3">
                  <span className={`flex size-10 shrink-0 items-center justify-center rounded-xl ${TONE_CLASS[n.tone]}`}>
                    <Icon name={n.icon} />
                  </span>
                  <div className="min-w-0 flex-1 space-y-1.5">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className={`rounded-full border px-2.5 py-0.5 text-xs font-semibold ${TONE_CLASS[n.tone]}`}>{n.type_label}</span>
                      <h2 className="text-sm font-semibold text-on-surface">{n.title}</h2>
                      {!n.is_read && <span className="size-2 rounded-full bg-error" aria-label="Chưa đọc" />}
                    </div>
                    {n.body && <p className="text-sm text-on-surface-variant">{n.body}</p>}
                    {lead && (
                      <div className="flex flex-wrap gap-4 text-xs text-on-surface-variant">
                        <span>
                          Khách hàng: <strong className="text-on-surface">{String(lead.customer_name ?? '—')}</strong>
                        </span>
                        <span>
                          SĐT: <strong className="font-mono text-on-surface">{String(lead.customer_phone ?? '—')}</strong>
                        </span>
                        <span>
                          Sales: <strong className="text-info">{String(lead.assigned_user ?? '—')}</strong>
                        </span>
                        {lead.hours_elapsed != null && (
                          <span>
                            Thời gian trễ: <strong className="font-mono text-error">{String(lead.hours_elapsed)}h</strong>
                          </span>
                        )}
                      </div>
                    )}
                    {copyText && (
                      <div className="rounded-lg border border-surface-container-highest bg-surface-container-low p-3 text-xs break-words whitespace-pre-line text-on-surface">
                        {copyText}
                      </div>
                    )}
                    <div className="font-mono text-xs text-on-surface-subtle">
                      Ghi nhận lúc: {formatDateTime(n.created_at)} ({timeAgo(n.created_at)})
                    </div>
                  </div>
                </div>
                <div className="flex shrink-0 flex-wrap items-center justify-end gap-2 self-end sm:self-center">
                  {copyText && (
                    <Button variant="secondary" className="h-9" onClick={() => void copy(n, copyText)}>
                      <Icon name="content_copy" className="mr-1 text-[18px]" /> {copied === n.id ? 'Đã sao chép' : 'Sao chép nội dung'}
                    </Button>
                  )}
                  {n.link && (
                    <Button className="h-9" onClick={() => open(n)}>
                      <Icon name={lead ? 'call' : 'open_in_new'} className="mr-1 text-[18px]" /> {lead ? 'Xử lý Lead ngay' : 'Mở'}
                    </Button>
                  )}
                  {!n.is_read && (
                    <Button variant="secondary" className="h-9" aria-label="Đánh dấu đã đọc" title="Đánh dấu đã đọc" onClick={() => read.mutate(n.id)}>
                      <Icon name="check" className="text-[18px]" />
                    </Button>
                  )}
                </div>
              </div>
            </li>
          )
        })}
      </ul>

      {data.data && data.data.notifications.length === 0 && (
        <div className="rounded-xl border border-surface-container-highest bg-surface">
          <EmptyState icon="task_alt" title="Không có thông báo nào" description="Không có thông báo hoặc cảnh báo chưa xử lý phù hợp với bộ lọc." />
        </div>
      )}
      {data.data && data.data.total > data.data.per_page && (
        <div className="mt-4 overflow-hidden rounded-xl border border-surface-container-highest bg-surface">
          <Pager page={data.data.page} perPage={data.data.per_page} total={data.data.total} unit="thông báo" onPage={(page) => setFilter((f) => ({ ...f, page }))} />
        </div>
      )}
    </>
  )
}

function QuickFilter({ active, onClick, children }: { active: boolean; onClick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      aria-pressed={active}
      onClick={onClick}
      className={`rounded-xl px-3 py-1.5 text-xs font-semibold transition ${active ? 'bg-primary text-white' : 'bg-surface-container-low text-on-surface-variant hover:bg-surface-container-high'}`}
    >
      {children}
    </button>
  )
}
