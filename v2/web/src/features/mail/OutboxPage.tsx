// Hàng đợi email (Cài đặt › Hệ thống): thư đã xếp gửi, lọc thư lỗi, xem nội dung, gửi lại / hủy. Mới ở v2 (Laravel gửi
// email đồng bộ, lỗi chỉ ghi log).
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Dialog, Field, Icon, Select } from '../../components/ui'
import { formatDateTime, formatNumber } from '../platform/format'
import { EmptyState, PageHeader, Pager, StatCard } from '../platform/parts'
import { attemptLabel, canCancel, canRetry, mailApi, statusTone, type MailFilter, type MailItem, type Option } from './api'

const label = (opts: Option[] | undefined, v: string) => opts?.find((o) => o.value === v)?.label ?? v

export function OutboxPage() {
  const qc = useQueryClient()
  const [filter, setFilter] = useState<MailFilter>({ page: 1 })
  const [draft, setDraft] = useState('')
  const [openId, setOpenId] = useState<string | null>(null)
  const [done, setDone] = useState('')
  const list = useQuery({
    queryKey: ['mail-outbox', filter],
    queryFn: () => mailApi.list(filter),
    placeholderData: keepPreviousData,
  })
  const set = (patch: MailFilter) => setFilter((f) => ({ ...f, ...patch, page: patch.page ?? 1 }))
  const act = useMutation({
    mutationFn: ({ id, op }: { id: string; op: 'retry' | 'cancel' }) => (op === 'retry' ? mailApi.retry(id) : mailApi.cancel(id)),
    onSuccess: (r) => {
      setDone(r.message)
      void qc.invalidateQueries({ queryKey: ['mail-outbox'] })
      void qc.invalidateQueries({ queryKey: ['mail-outbox-item'] })
    },
    onError: () => setDone(''),
  })
  const d = list.data
  const counts = d?.counts ?? {}

  return (
    <>
      <PageHeader
        title="Hàng đợi email"
        description="Email hệ thống xếp gửi sau khi lưu nghiệp vụ (xác nhận lịch hẹn, cảnh báo vận hành, đặt lại mật khẩu…). Thư lỗi tự thử lại theo thời gian giãn dần; hết lượt thì nằm ở đây để xem lỗi và gửi lại."
        actions={
          <Button variant="secondary" onClick={() => void list.refetch()}>
            <Icon name="refresh" className="mr-1.5 text-[20px]" /> Làm mới
          </Button>
        }
      />
      {d?.blocked && (
        <div className="mb-4">
          <Alert tone="info">
            Môi trường này đang chặn gửi email ra ngoài (MAIL_BLOCK_OUTGOING): thư không được gửi, chuyển sang "Bị chặn".
          </Alert>
        </div>
      )}
      <div className="mb-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard label="Chờ gửi" value={formatNumber((counts.pending ?? 0) + (counts.sending ?? 0))} icon="schedule_send" tone="primary" />
        <StatCard label="Đã gửi" value={formatNumber(counts.sent ?? 0)} icon="mark_email_read" tone="success" />
        <StatCard label="Lỗi" value={formatNumber(counts.failed ?? 0)} icon="error" tone="error" />
        <StatCard label="Bị chặn / đã hủy" value={formatNumber((counts.blocked ?? 0) + (counts.cancelled ?? 0))} icon="block" />
      </div>

      <section className="mb-4 space-y-3 rounded-xl border border-surface-container-highest bg-surface p-4">
        <div className="flex flex-wrap gap-1.5" aria-label="Lọc theo trạng thái">
          <Chip active={!filter.status} onClick={() => set({ status: undefined })}>
            Tất cả
          </Chip>
          {d?.statuses.map((s) => (
            <Chip key={s.value} active={filter.status === s.value} onClick={() => set({ status: s.value })}>
              {s.label}
              {counts[s.value as keyof typeof counts] ? ` (${counts[s.value as keyof typeof counts]})` : ''}
            </Chip>
          ))}
        </div>
        <form
          className="grid gap-3 sm:grid-cols-[1fr_16rem_auto]"
          role="search"
          onSubmit={(e) => {
            e.preventDefault()
            set({ q: draft.trim() || undefined })
          }}
        >
          <Field label="Tìm kiếm" placeholder="Địa chỉ người nhận hoặc tiêu đề…" value={draft} onChange={(e) => setDraft(e.target.value)} />
          <Select label="Loại thư" value={filter.kind ?? ''} onChange={(e) => set({ kind: e.target.value || undefined })}>
            <option value="">Mọi loại</option>
            {d?.kinds.map((k) => (
              <option key={k.value} value={k.value}>
                {k.label}
              </option>
            ))}
          </Select>
          <Button type="submit" variant="secondary" className="self-end">
            Tìm
          </Button>
        </form>
      </section>

      {done && (
        <div className="mb-4">
          <Alert tone="success">{done}</Alert>
        </div>
      )}
      {(list.isError || act.isError) && (
        <div className="mb-4">
          <Alert>{errorMessage(list.error ?? act.error)}</Alert>
        </div>
      )}

      <section className="overflow-hidden rounded-xl border border-surface-container-highest bg-surface">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[860px] text-left text-sm">
            <thead className="bg-surface-container-low text-xs text-on-surface-variant">
              <tr>
                <th className="px-4 py-2.5 font-medium">Thời điểm xếp</th>
                <th className="px-4 py-2.5 font-medium">Người nhận / Tiêu đề</th>
                <th className="px-4 py-2.5 font-medium">Loại</th>
                <th className="px-4 py-2.5 font-medium">Trạng thái</th>
                <th className="px-4 py-2.5 text-right font-medium">Thao tác</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-surface-container-highest">
              {d?.items.map((m) => (
                <tr key={m.id} className="align-top hover:bg-surface-container-low">
                  <td className="px-4 py-3 font-mono text-xs whitespace-nowrap">{formatDateTime(m.created_at)}</td>
                  <td className="max-w-[360px] px-4 py-3">
                    <div className="font-medium break-all text-on-surface">{m.to}</div>
                    <div className="text-on-surface-variant">{m.subject}</div>
                    {m.last_error && m.status !== 'sent' && <div className="mt-1 text-xs break-words text-error">{m.last_error}</div>}
                  </td>
                  <td className="px-4 py-3 whitespace-nowrap">{label(d.kinds, m.kind)}</td>
                  <td className="px-4 py-3 whitespace-nowrap">
                    <Badge tone={statusTone[m.status]}>{label(d.statuses, m.status)}</Badge>
                    <div className="mt-1 text-xs text-on-surface-variant">{statusNote(m)}</div>
                  </td>
                  <td className="px-4 py-3 text-right whitespace-nowrap">
                    <RowActions m={m} busy={act.isPending} onOpen={() => setOpenId(m.id)} onAct={(op) => act.mutate({ id: m.id, op })} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {d && d.items.length === 0 && <EmptyState icon="outgoing_mail" title="Không có email nào phù hợp với bộ lọc" />}
        {d && d.total > 0 && <Pager page={d.page} perPage={d.per_page} total={d.total} unit="email" onPage={(page) => set({ page })} />}
      </section>

      {openId && <DetailDialog id={openId} kinds={d?.kinds} statuses={d?.statuses} onClose={() => setOpenId(null)} />}
    </>
  )
}

function statusNote(m: MailItem) {
  if (m.status === 'sent' && m.sent_at) return `Gửi lúc ${formatDateTime(m.sent_at)}`
  if (m.status === 'pending' && m.attempts > 0) return `${attemptLabel(m)} · thử lại ${formatDateTime(m.next_attempt_at)}`
  return attemptLabel(m)
}

function RowActions({
  m,
  busy,
  onOpen,
  onAct,
}: {
  m: MailItem
  busy: boolean
  onOpen: () => void
  onAct: (op: 'retry' | 'cancel') => void
}) {
  return (
    <div className="inline-flex gap-1">
      <IconButton icon="visibility" label="Xem nội dung" onClick={onOpen} />
      {canRetry(m) && <IconButton icon="send" label="Gửi lại" disabled={busy} onClick={() => onAct('retry')} />}
      {canCancel(m) && <IconButton icon="cancel_schedule_send" label="Hủy thư" disabled={busy} onClick={() => onAct('cancel')} />}
    </div>
  )
}

function IconButton({ icon, label, onClick, disabled }: { icon: string; label: string; onClick: () => void; disabled?: boolean }) {
  return (
    <button
      type="button"
      title={label}
      aria-label={label}
      disabled={disabled}
      onClick={onClick}
      className="rounded-lg p-1.5 text-on-surface-variant hover:bg-surface-container-high disabled:opacity-50"
    >
      <Icon name={icon} className="text-[20px]" />
    </button>
  )
}

function DetailDialog({ id, kinds, statuses, onClose }: { id: string; kinds?: Option[]; statuses?: Option[]; onClose: () => void }) {
  const q = useQuery({
    queryKey: ['mail-outbox-item', id],
    queryFn: () => mailApi.get(id),
  })
  const m = q.data
  return (
    <Dialog title="Nội dung email" wide onClose={onClose}>
      {q.isError && <Alert>{errorMessage(q.error)}</Alert>}
      {m && (
        <div className="space-y-4 text-sm">
          <dl className="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1.5">
            <dt className="text-on-surface-variant">Người nhận</dt>
            <dd className="break-all">{m.to_name ? `${m.to_name} <${m.to}>` : m.to}</dd>
            <dt className="text-on-surface-variant">Tiêu đề</dt>
            <dd>{m.subject}</dd>
            <dt className="text-on-surface-variant">Loại</dt>
            <dd>{label(kinds, m.kind)}</dd>
            <dt className="text-on-surface-variant">Trạng thái</dt>
            <dd>
              <Badge tone={statusTone[m.status]}>{label(statuses, m.status)}</Badge>{' '}
              <span className="text-xs text-on-surface-variant">{statusNote(m)}</span>
            </dd>
            {m.created_by && (
              <>
                <dt className="text-on-surface-variant">Người thao tác</dt>
                <dd>{m.created_by}</dd>
              </>
            )}
            {m.last_error && (
              <>
                <dt className="text-on-surface-variant">Lỗi gần nhất</dt>
                <dd className="break-words text-error">{m.last_error}</dd>
              </>
            )}
          </dl>
          {m.has_fallback && (
            <Alert tone="info">Thư lỗi hẳn thì hệ thống báo người phụ trách trong ứng dụng để gửi thủ công (vd. qua Zalo).</Alert>
          )}
          {m.sensitive ? (
            <Alert tone="info">Thư chứa thông tin bảo mật (link đặt lại mật khẩu) nên không hiển thị nội dung.</Alert>
          ) : (
            <pre className="max-h-96 overflow-y-auto rounded-lg border border-surface-container-highest bg-surface-container-low p-3 font-body text-sm whitespace-pre-wrap break-words">
              {m.preview ?? 'Không dựng được nội dung thư.'}
            </pre>
          )}
        </div>
      )}
    </Dialog>
  )
}

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={`rounded-full border px-3 py-1 text-xs font-medium ${
        active
          ? 'border-primary bg-primary-light text-primary'
          : 'border-outline-variant text-on-surface-variant hover:bg-surface-container-low'
      }`}
    >
      {children}
    </button>
  )
}
