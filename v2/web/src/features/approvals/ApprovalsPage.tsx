import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type MouseEvent, type ReactNode } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Dialog, Icon } from '../../components/ui'
import { formatMoney } from '../catalogs/format'
import { Textarea } from '../crm/components'
import { formatDateTime, timeAgo } from '../platform/format'
import { EmptyState, PageHeader } from '../platform/parts'
import { navigate, useQueryParam } from '../../router'
import { approvalsApi, type Action, type ApprovalItem, type BulkResult, type Section } from './api'
import {
  allSelected,
  canAct,
  failedOf,
  groupPath,
  keepPending,
  REASON_MAX,
  reasonError,
  refOf,
  selectable,
  toggle,
  toggleAll,
  visibleSections,
} from './logic'

// "Việc cần duyệt" (ApprovalController, chỉ Admin): gom mục chờ duyệt từ các phân hệ, chip lọc theo nhóm, mỗi nguồn tối
// đa 15 mục ("Xem tất cả" sang màn gốc). Bấm dòng → hộp chi tiết; chọn nhiều → Duyệt hàng loạt / Từ chối (lý do).
export function ApprovalsPage(_: { me: Me }) {
  const group = useQueryParam('group') || null
  const qc = useQueryClient()
  const list = useQuery({ queryKey: ['approvals', 'index', group], queryFn: () => approvalsApi.index(group), placeholderData: keepPreviousData })
  const [selected, setSelected] = useState<string[]>([])
  const [confirming, setConfirming] = useState<Action | null>(null)
  const [detail, setDetail] = useState<ApprovalItem | null>(null)
  const [result, setResult] = useState<BulkResult | null>(null)
  const sections = list.data?.sections ?? []

  // Danh sách đổi → bỏ chọn mục không còn chờ.
  useEffect(() => {
    if (list.data) setSelected((cur) => keepPending(cur, list.data.sections))
  }, [list.data])

  function done(res: BulkResult) {
    setResult(res)
    setConfirming(null)
    setDetail(null)
    setSelected([])
    void qc.invalidateQueries({ queryKey: ['approvals'] })
  }

  const shown = visibleSections(sections)
  const chip = (on: boolean) =>
    `inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1 text-sm font-medium transition-colors ${
      on ? 'border-primary bg-primary text-white' : 'border-outline-variant bg-surface text-on-surface hover:border-primary hover:text-primary'
    }`

  return (
    <>
      <PageHeader title="Việc cần duyệt" description="Mọi yêu cầu đang chờ bạn duyệt, gom từ Học phí, Đào tạo, Công việc và Nhân sự." />
      <div aria-live="polite">{result && <ResultAlert result={result} onClose={() => setResult(null)} />}</div>
      {list.isError && (
        <div className="mb-4">
          <Alert>{errorMessage(list.error)}</Alert>
        </div>
      )}
      {list.data && (
        <>
          <nav className="mb-4 flex items-center gap-2 overflow-x-auto" aria-label="Lọc theo nhóm">
            <Chip href={groupPath(null)} className={chip(list.data.group === null)} current={list.data.group === null}>
              Tất cả <span className="tabular-nums">{list.data.total}</span>
            </Chip>
            {list.data.groups.map((g) => (
              <Chip key={g.slug} href={groupPath(g.slug)} className={chip(list.data.group === g.slug)} current={list.data.group === g.slug}>
                {g.label} <span className="tabular-nums">{g.count}</span>
              </Chip>
            ))}
          </nav>
          {shown.length === 0 && (
            <div className="rounded-xl border border-outline-variant bg-surface shadow-sm">
              <EmptyState icon="task_alt" title="Không còn việc chờ duyệt" description="Bạn đã xử lý hết các yêu cầu đang chờ." />
            </div>
          )}
          <div className="space-y-4">
            {shown.map((s) => (
              <SectionCard key={s.key} section={s} selected={selected} onSelect={setSelected} onOpen={setDetail} />
            ))}
          </div>
        </>
      )}

      {selected.length > 0 && (
        <div className="sticky bottom-4 z-20 mt-4 flex flex-wrap items-center gap-2 rounded-xl border border-outline-variant bg-surface px-4 py-2 shadow-lg">
          <span className="text-sm">
            Đã chọn <strong>{selected.length}</strong>
          </span>
          <Button variant="secondary" className="h-8" onClick={() => setSelected([])}>
            Bỏ chọn
          </Button>
          <div className="ml-auto flex flex-wrap gap-2">
            <Button
              variant="danger"
              disabled={!canAct(selected, sections, 'reject')}
              title={canAct(selected, sections, 'reject') ? '' : 'Có mục phải xử lý ở màn gốc'}
              onClick={() => setConfirming('reject')}
            >
              <Icon name="block" className="mr-1 text-[18px]" /> Từ chối
            </Button>
            <Button
              disabled={!canAct(selected, sections, 'approve')}
              title={canAct(selected, sections, 'approve') ? '' : 'Có mục phải duyệt ở màn gốc'}
              onClick={() => setConfirming('approve')}
            >
              <Icon name="done_all" className="mr-1 text-[18px]" /> Duyệt hàng loạt
            </Button>
          </div>
        </div>
      )}

      {confirming && <BulkDialog action={confirming} items={selected} onClose={() => setConfirming(null)} onDone={done} />}
      {detail && <DetailDialog item={detail} onClose={() => setDetail(null)} onDone={done} />}
    </>
  )
}

function Chip({ href, className, current, children }: { href: string; className: string; current: boolean; children: ReactNode }) {
  return (
    <a
      href={href}
      className={className}
      aria-current={current ? 'page' : undefined}
      onClick={(e: MouseEvent) => {
        if (e.metaKey || e.ctrlKey) return
        e.preventDefault()
        navigate(href)
      }}
    >
      {children}
    </a>
  )
}

function ResultAlert({ result, onClose }: { result: BulkResult; onClose: () => void }) {
  const failed = failedOf(result.results)
  const tone = result.tone === 'success' ? 'success' : result.tone === 'warning' ? 'info' : 'error'
  return (
    <div className="mb-4">
      <Alert tone={tone}>
        <div className="flex items-start gap-2">
          <div className="min-w-0 flex-1">
            <p className="font-medium">{result.message}</p>
            {failed.length > 0 && result.results.length > 1 && (
              <ul className="mt-1 list-disc space-y-0.5 pl-5">
                {failed.map((r) => (
                  <li key={r.ref}>
                    <span className="font-semibold">{r.title}</span>: {r.message}
                  </li>
                ))}
              </ul>
            )}
          </div>
          <button type="button" onClick={onClose} aria-label="Đóng thông báo" className="text-sm opacity-70 hover:opacity-100">
            <Icon name="close" className="text-[18px]" />
          </button>
        </div>
      </Alert>
    </div>
  )
}

function openOriginal(e: MouseEvent, url: string) {
  if (e.metaKey || e.ctrlKey) return
  e.preventDefault()
  navigate(url)
}

function SectionCard({
  section: s,
  selected,
  onSelect,
  onOpen,
}: {
  section: Section
  selected: string[]
  onSelect: (refs: string[]) => void
  onOpen: (item: ApprovalItem) => void
}) {
  return (
    <section className="overflow-hidden rounded-xl border border-outline-variant bg-surface shadow-sm" data-approval-source={s.key}>
      <header className="flex flex-wrap items-center gap-2 border-b border-surface-container px-4 py-2">
        {selectable(s) && (
          <input
            type="checkbox"
            className="size-4 accent-primary"
            aria-label={`Chọn tất cả ${s.label}`}
            checked={allSelected(selected, s)}
            onChange={(e) => onSelect(toggleAll(selected, s, e.target.checked))}
          />
        )}
        <h2 className="text-base font-semibold">{s.label}</h2>
        <Badge tone="warning">{s.count}</Badge>
        <span className="text-xs text-on-surface-variant">{s.group}</span>
        {!selectable(s) && <span className="text-xs text-on-surface-variant">· duyệt tại màn gốc</span>}
        <a href={s.index_url} onClick={(e) => openOriginal(e, s.index_url)} className="ml-auto inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline">
          Xem tất cả <Icon name="arrow_forward" className="text-[16px]" />
        </a>
      </header>
      <ul className="divide-y divide-surface-container">
        {s.items.map((item) => {
          const ref = refOf(item)
          return (
            <li key={ref} className="flex items-center gap-2 px-4 py-2 hover:bg-surface-container-low">
              {selectable(s) && (
                <input
                  type="checkbox"
                  className="size-4 shrink-0 accent-primary"
                  aria-label={`Chọn ${item.title}`}
                  checked={selected.includes(ref)}
                  onChange={(e) => onSelect(toggle(selected, ref, e.target.checked))}
                />
              )}
              <button type="button" onClick={() => onOpen(item)} className="flex min-w-0 flex-1 items-center gap-3 rounded-lg text-left focus-visible:outline-2 focus-visible:outline-primary">
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium" title={item.title}>
                    {item.title}
                  </span>
                  {item.subtitle && <span className="block truncate text-xs text-on-surface-variant">{item.subtitle}</span>}
                </span>
                {item.flag && (
                  <span className="hidden sm:inline-flex">
                    <Badge tone="error">{item.flag}</Badge>
                  </span>
                )}
                {item.amount !== null && <span className="hidden shrink-0 text-sm tabular-nums sm:block">{formatMoney(item.amount)}</span>}
                {item.created_at && (
                  <time
                    dateTime={item.created_at}
                    title={formatDateTime(item.created_at)}
                    className="hidden w-24 shrink-0 text-right text-xs text-on-surface-variant md:block"
                  >
                    {timeAgo(item.created_at)}
                  </time>
                )}
                <Icon name="chevron_right" className="text-[20px] text-on-surface-variant" />
              </button>
            </li>
          )
        })}
      </ul>
      {s.count > s.items.length && (
        <div className="border-t border-surface-container px-4 py-2 text-center">
          <a href={s.index_url} onClick={(e) => openOriginal(e, s.index_url)} className="text-sm font-medium text-primary hover:underline">
            Xem tất cả {s.count} mục ở màn gốc
          </a>
        </div>
      )}
    </section>
  )
}

function useBulk(onDone: (r: BulkResult) => unknown) {
  return useMutation({ mutationFn: (v: { action: Action; items: string[]; reason?: string }) => approvalsApi.bulk(v.action, v.items, v.reason), onSuccess: onDone })
}

function ReasonField({ value, onChange, error }: { value: string; onChange: (v: string) => void; error: string | null }) {
  return (
    <div className="space-y-1">
      <Textarea label="Lý do từ chối" required rows={3} maxLength={REASON_MAX} value={value} onChange={(e) => onChange(e.target.value)} autoFocus />
      {error && <p className="text-sm text-error">{error}</p>}
    </div>
  )
}

function BulkDialog({ action, items, onClose, onDone }: { action: Action; items: string[]; onClose: () => void; onDone: (r: BulkResult) => void }) {
  const bulk = useBulk(onDone)
  const [reason, setReason] = useState('')
  const [touched, setTouched] = useState(false)
  const err = action === 'reject' && touched ? reasonError(reason) : null
  return (
    <Dialog title={action === 'approve' ? 'Duyệt các mục đã chọn?' : 'Từ chối các mục đã chọn?'} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          setTouched(true)
          if (action === 'reject' && reasonError(reason)) return
          bulk.mutate({ action, items, reason: action === 'reject' ? reason : undefined })
        }}
      >
        {action === 'approve' ? (
          <p className="text-sm">
            Duyệt <strong>{items.length}</strong> mục. Mỗi mục được xử lý riêng theo đúng quy tắc của màn gốc; mục không hợp lệ sẽ được báo lại, không ảnh
            hưởng mục khác.
          </p>
        ) : (
          <>
            <p className="text-sm">
              Từ chối <strong>{items.length}</strong> mục. Lý do được gửi kèm cho người đề nghị.
            </p>
            <ReasonField value={reason} onChange={setReason} error={err} />
          </>
        )}
        {bulk.isError && <Alert>{errorMessage(bulk.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" variant={action === 'approve' ? 'primary' : 'danger'} disabled={bulk.isPending}>
            {action === 'approve' ? 'Duyệt' : 'Từ chối'}
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function DetailDialog({ item, onClose, onDone }: { item: ApprovalItem; onClose: () => void; onDone: (r: BulkResult) => void }) {
  const q = useQuery({ queryKey: ['approvals', 'show', item.source, item.id], queryFn: () => approvalsApi.show(item.source, item.id) })
  // Một mục lỗi nghiệp vụ: giữ hộp mở và báo lỗi (như single=1 của Laravel).
  const bulk = useBulk((r) => r.tone !== 'error' && onDone(r))
  const [rejecting, setRejecting] = useState(false)
  const [reason, setReason] = useState('')
  const [touched, setTouched] = useState(false)
  const d = q.data
  const it = d?.item ?? item
  const ref = refOf(item)
  return (
    <Dialog title={it.title} onClose={onClose} wide>
      {d && (
        <p className="-mt-3 mb-4 text-sm text-on-surface-variant">
          {d.source.label} · {d.source.value}
        </p>
      )}
      {q.isError && <Alert>{errorMessage(q.error)}</Alert>}
      {q.isPending && <p className="text-sm text-on-surface-variant">Đang tải…</p>}
      {d && (
        <div className="space-y-4">
          {it.flag && <Badge tone="error">{it.flag}</Badge>}
          <dl className="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
            {it.amount !== null && (
              <>
                <dt className="text-on-surface-variant">Số tiền</dt>
                <dd className="tabular-nums sm:col-span-2">{formatMoney(it.amount)}</dd>
              </>
            )}
            {(it.meta ?? []).map((row) => (
              <MetaRow key={row.label} label={row.label} value={row.value} />
            ))}
            {it.created_at && <MetaRow label="Gửi lúc" value={`${formatDateTime(it.created_at)} (${timeAgo(it.created_at)})`} />}
          </dl>
          {!(d.can_approve || d.can_reject) && <Alert tone="info">Mục này cần nhập thêm thông tin khi duyệt — vui lòng xử lý ở màn gốc.</Alert>}
          {rejecting && <ReasonField value={reason} onChange={setReason} error={touched ? reasonError(reason) : null} />}
          {bulk.isError && <Alert>{errorMessage(bulk.error)}</Alert>}
          {bulk.data && bulk.data.tone === 'error' && <Alert>{bulk.data.message}</Alert>}
        </div>
      )}
      <div className="mt-6 flex flex-wrap items-center justify-end gap-2 border-t border-surface-container pt-4">
        <a
          href={it.url}
          onClick={(e) => openOriginal(e, it.url)}
          className="mr-auto inline-flex h-10 items-center gap-1 rounded-lg border border-outline-variant px-4 text-sm font-medium hover:bg-surface-container-low"
        >
          <Icon name="open_in_new" className="text-[18px]" /> Mở màn gốc
        </a>
        {d?.can_reject &&
          (rejecting ? (
            <Button
              variant="danger"
              disabled={bulk.isPending}
              onClick={() => {
                setTouched(true)
                if (!reasonError(reason)) bulk.mutate({ action: 'reject', items: [ref], reason })
              }}
            >
              Xác nhận từ chối
            </Button>
          ) : (
            <Button variant="danger" onClick={() => setRejecting(true)}>
              Từ chối
            </Button>
          ))}
        {d?.can_approve && !rejecting && (
          <Button disabled={bulk.isPending} onClick={() => bulk.mutate({ action: 'approve', items: [ref] })}>
            <Icon name="check" className="mr-1 text-[18px]" /> Duyệt
          </Button>
        )}
      </div>
    </Dialog>
  )
}

function MetaRow({ label, value }: { label: string; value: string }) {
  return (
    <>
      <dt className="text-on-surface-variant">{label}</dt>
      <dd className="break-words whitespace-pre-line sm:col-span-2">{value}</dd>
    </>
  )
}
