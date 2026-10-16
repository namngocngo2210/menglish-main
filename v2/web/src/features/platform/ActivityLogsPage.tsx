import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { type ReactNode, useEffect, useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Field, Icon, Select } from '../../components/ui'
import { Avatar } from '../../components/shell/Topbar'
import { auditApi, type AuditFilter, type AuditLog } from './api'
import { formatDate, formatNumber, formatTime } from './format'
import { EmptyState, PageHeader, Pager, StatCard } from './parts'

// Nhật ký vận hành (Laravel ActivityLogs/Index.vue): thống kê nhanh, lọc nhanh theo nhóm module, lọc phân hệ / người /
// loại / khoảng ngày, tìm kiếm, bảng + panel "Chi tiết đối chiếu" (trước / sau, chi tiết kỹ thuật), xuất CSV.
export function ActivityLogsPage() {
  const [draft, setDraft] = useState('')
  const [filter, setFilter] = useState<AuditFilter>({ page: 1 })
  const [openId, setOpenId] = useState<string | null>(null)
  const options = useQuery({ queryKey: ['audit-options'], queryFn: auditApi.options })
  const logs = useQuery({ queryKey: ['audit-logs', filter], queryFn: () => auditApi.list(filter), placeholderData: keepPreviousData })
  const set = (patch: AuditFilter) => setFilter((f) => ({ ...f, ...patch, page: patch.page ?? 1 }))
  const open = logs.data?.logs.find((l) => l.id === openId) ?? null

  return (
    <>
      <PageHeader
        title="Nhật ký vận hành"
        description="Theo dõi và đối soát các thay đổi dữ liệu hệ thống. Mỗi thao tác ghi một dòng kèm dữ liệu trước / sau."
        actions={
          <>
            <a
              href={auditApi.exportUrl(filter)}
              download
              className="inline-flex h-10 items-center gap-1.5 rounded-lg border border-outline-variant bg-surface px-4 text-sm font-medium text-on-surface hover:bg-surface-container-low"
            >
              <Icon name="download" className="text-[20px]" /> Xuất Excel (CSV)
            </a>
            <Button
              variant="secondary"
              onClick={() => {
                void logs.refetch()
                void options.refetch()
              }}
            >
              <Icon name="refresh" className="mr-1.5 text-[20px]" /> Làm mới
            </Button>
          </>
        }
      />

      {options.data && (
        <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
          <StatCard label="Tổng số thao tác đã ghi" value={formatNumber(options.data.stats.total)} icon="dataset" />
          <StatCard label="Thao tác trong ngày" value={formatNumber(options.data.stats.today)} icon="today" tone="success" />
          <StatCard label="Nhân viên hoạt động hôm nay" value={`${formatNumber(options.data.stats.active_users)} người`} icon="group" tone="primary" />
        </div>
      )}

      <section className="mb-4 space-y-3 rounded-xl border border-surface-container-highest bg-surface p-4">
        <form
          className="flex gap-2"
          role="search"
          onSubmit={(e) => {
            e.preventDefault()
            set({ q: draft.trim() })
          }}
        >
          <div className="flex-1">
            <Field label="Tìm kiếm" placeholder="Tìm tên người thực hiện, nội dung, mã bản ghi..." value={draft} onChange={(e) => setDraft(e.target.value)} />
          </div>
          <Button type="submit" variant="secondary" className="self-end">
            Tìm
          </Button>
        </form>
        <div className="flex flex-wrap gap-1.5" aria-label="Lọc nhanh theo module">
          <Chip active={!filter.group} onClick={() => set({ group: undefined })}>
            Tất cả
          </Chip>
          {options.data?.groups.map((g) => (
            <Chip key={g.value} active={filter.group === g.value} onClick={() => set({ group: g.value })}>
              {g.label}
            </Chip>
          ))}
        </div>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <Select label="Phân hệ" value={filter.module ?? ''} onChange={(e) => set({ module: e.target.value || undefined })}>
            <option value="">Mọi phân hệ</option>
            {options.data?.modules.map((m) => (
              <option key={m} value={m}>
                {m}
              </option>
            ))}
          </Select>
          <Select label="Người thực hiện" value={filter.actor_id ?? ''} onChange={(e) => set({ actor_id: e.target.value || undefined })}>
            <option value="">Mọi người thực hiện</option>
            {options.data?.actors.map((a) => (
              <option key={a.value} value={a.value}>
                {a.label}
              </option>
            ))}
          </Select>
          <Select label="Loại thao tác" value={filter.event ?? ''} onChange={(e) => set({ event: e.target.value || undefined })}>
            <option value="">Mọi loại</option>
            {options.data?.events.map((ev) => (
              <option key={ev.value} value={ev.value}>
                {ev.label}
              </option>
            ))}
          </Select>
          <Field label="Từ ngày" type="date" value={filter.date_from ?? ''} onChange={(e) => set({ date_from: e.target.value || undefined })} />
          <Field label="Đến ngày" type="date" value={filter.date_to ?? ''} onChange={(e) => set({ date_to: e.target.value || undefined })} />
        </div>
      </section>

      {logs.isError && (
        <div className="mb-4">
          <Alert>{errorMessage(logs.error)}</Alert>
        </div>
      )}

      <section className="overflow-hidden rounded-xl border border-surface-container-highest bg-surface">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[900px] text-left text-sm">
            <thead className="bg-surface-container-low text-xs text-on-surface-variant">
              <tr>
                <th className="px-4 py-2.5 font-medium">Người thực hiện</th>
                <th className="px-4 py-2.5 font-medium">Thời điểm</th>
                <th className="px-4 py-2.5 font-medium">Module</th>
                <th className="px-4 py-2.5 font-medium">Loại</th>
                <th className="px-4 py-2.5 font-medium">Hành động</th>
                <th className="px-4 py-2.5 text-right font-medium">
                  <span className="sr-only">Chi tiết</span>
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-surface-container-highest">
              {logs.data?.logs.map((log) => (
                <tr
                  key={log.id}
                  tabIndex={0}
                  onClick={() => setOpenId(log.id)}
                  onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && e.target === e.currentTarget && (e.preventDefault(), setOpenId(log.id))}
                  className={`cursor-pointer hover:bg-surface-container-low ${openId === log.id ? 'bg-primary-light/60' : ''}`}
                >
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <Avatar name={log.actor_name ?? 'Hệ thống'} />
                      <div className="min-w-0">
                        <div className="font-semibold text-on-surface">{log.actor_name ?? 'Hệ thống tự động'}</div>
                        <div className="text-xs text-on-surface-variant">
                          {log.actor_role ?? 'Hệ thống'}
                          {log.actor_code && <span className="font-mono"> · {log.actor_code}</span>}
                        </div>
                      </div>
                    </div>
                  </td>
                  <td className="px-4 py-3 font-mono whitespace-nowrap">
                    {formatTime(log.created_at)}
                    <span className="block text-xs text-on-surface-variant">{formatDate(log.created_at)}</span>
                  </td>
                  <td className="px-4 py-3">
                    <Badge>{log.module}</Badge>
                  </td>
                  <td className="px-4 py-3 whitespace-nowrap">
                    <EventTag log={log} />
                  </td>
                  <td className="max-w-[380px] px-4 py-3">
                    <div className="text-on-surface">{log.description}</div>
                    {log.subject && <div className="text-xs text-on-surface-variant">{log.subject}</div>}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <button
                      type="button"
                      aria-label="Xem chi tiết đối chiếu"
                      onClick={(e) => {
                        e.stopPropagation()
                        setOpenId(log.id)
                      }}
                      className="rounded-lg p-1.5 text-on-surface-variant hover:bg-surface-container-high"
                    >
                      <Icon name="visibility" className="text-[20px]" />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {logs.data && logs.data.logs.length === 0 && (
          <EmptyState icon="history" title="Không tìm thấy nhật ký vận hành nào phù hợp với bộ lọc" />
        )}
        {logs.data && logs.data.total > 0 && (
          <Pager page={logs.data.page} perPage={logs.data.per_page} total={logs.data.total} unit="nhật ký" onPage={(page) => set({ page })} />
        )}
      </section>

      {open && <DetailPanel log={open} onClose={() => setOpenId(null)} />}
    </>
  )
}

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      aria-pressed={active}
      onClick={onClick}
      className={`rounded-full border px-3 py-0.5 text-sm ${active ? 'border-primary bg-primary text-white' : 'border-outline-variant text-on-surface-variant hover:bg-surface-container-low'}`}
    >
      {children}
    </button>
  )
}

const EVENT_STYLE: Record<string, [string, string, string]> = {
  created: ['add_circle', 'Thêm', 'text-success'],
  updated: ['edit', 'Sửa', 'text-info'],
  deleted: ['delete', 'Xóa', 'text-error'],
  restored: ['restore', 'Khôi phục', 'text-success'],
}

function EventTag({ log }: { log: AuditLog }) {
  const [icon, label, color] = EVENT_STYLE[log.event] ?? ['bolt', log.event_label, 'text-on-surface-variant']
  return (
    <span className={`inline-flex items-center gap-1 text-sm ${color}`}>
      <Icon name={icon} className="text-[16px]" />
      {label}
    </span>
  )
}

// Panel "Chi tiết đối chiếu": thông tin chung, so sánh trước / sau, chi tiết kỹ thuật. Esc hoặc bấm nền để đóng.
function DetailPanel({ log, onClose }: { log: AuditLog; onClose: () => void }) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])
  return (
    <div className="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="Chi tiết đối chiếu">
      <div className="absolute inset-0 bg-black/40" onClick={onClose} />
      <aside className="absolute inset-y-0 right-0 flex w-full max-w-lg flex-col bg-surface shadow-xl">
        <header className="flex items-start justify-between gap-2 border-b border-surface-container-high px-4 py-3">
          <div>
            <h2 className="flex items-center gap-1.5 text-base font-semibold text-on-surface">
              <Icon name="info" className="text-info" /> Chi tiết đối chiếu
            </h2>
            <p className="font-mono text-xs text-on-surface-variant">Bản ghi {log.subject ?? `#${log.id}`}</p>
          </div>
          <button type="button" onClick={onClose} aria-label="Đóng" className="rounded-lg p-1.5 text-on-surface-variant hover:bg-surface-container-high">
            <Icon name="close" />
          </button>
        </header>
        <div className="flex-1 space-y-4 overflow-y-auto p-4 text-sm">
          <dl className="grid grid-cols-2 gap-2 rounded-lg bg-surface-container-low p-3">
            <div>
              <dt className="text-on-surface-variant">Module</dt>
              <dd className="font-semibold">{log.module}</dd>
            </div>
            <div>
              <dt className="text-on-surface-variant">Thao tác bởi</dt>
              <dd className="font-semibold">{log.actor_name ?? 'Hệ thống tự động'}</dd>
            </div>
            <div>
              <dt className="text-on-surface-variant">Thời điểm</dt>
              <dd className="font-mono">
                {formatTime(log.created_at)} {formatDate(log.created_at)}
              </dd>
            </div>
            <div>
              <dt className="text-on-surface-variant">Loại</dt>
              <dd>{log.event_label}</dd>
            </div>
            <div className="col-span-2">
              <dt className="text-on-surface-variant">Nội dung</dt>
              <dd>{log.description}</dd>
            </div>
          </dl>

          <section className="space-y-1.5">
            <h3 className="text-xs font-semibold text-on-surface-variant uppercase">So sánh trước / sau</h3>
            {log.diff.length === 0 ? (
              <p className="text-on-surface-variant italic">Thao tác không có dữ liệu trước / sau (chỉ ghi nhận hành động).</p>
            ) : (
              <div className="overflow-hidden rounded-lg border border-outline-variant">
                <div className="grid grid-cols-2 bg-surface-container-low text-xs font-semibold text-on-surface-variant uppercase">
                  <span className="px-2 py-1.5">Dữ liệu trước</span>
                  <span className="border-l border-outline-variant px-2 py-1.5">Dữ liệu sau</span>
                </div>
                {log.diff.map((row) => (
                  <div key={row.field} className="grid grid-cols-2 border-t border-outline-variant font-mono text-xs">
                    <span className={`px-2 py-1.5 break-all ${row.old !== row.new ? 'bg-error-container/40 text-error' : ''}`}>
                      {row.field}: "{row.old}"
                    </span>
                    <span className={`border-l border-outline-variant px-2 py-1.5 break-all ${row.old !== row.new ? 'bg-success-container/50 text-success' : ''}`}>
                      {row.field}: "{row.new}"
                    </span>
                  </div>
                ))}
              </div>
            )}
          </section>

          <section className="space-y-1 rounded-lg bg-on-surface p-3 font-mono text-xs text-white">
            <h3 className="flex items-center gap-1 font-semibold uppercase">
              <Icon name="terminal" className="text-[16px]" /> Chi tiết kỹ thuật
            </h3>
            <p>IP Address: {log.ip ?? '—'}</p>
            <p className="break-all">User-Agent: {log.user_agent ?? '—'}</p>
            <p className="break-all">Transaction ID: {log.request_id ?? '—'}</p>
            {log.url && <p className="break-all">URL: {log.url}</p>}
          </section>
        </div>
        <footer className="border-t border-surface-container-high bg-surface-container-low p-4">
          <Button variant="secondary" className="w-full" onClick={onClose}>
            Đóng chi tiết
          </Button>
        </footer>
      </aside>
    </div>
  )
}
