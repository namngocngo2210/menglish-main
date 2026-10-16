// Order học liệu (MaterialOrders/*.vue): giáo viên tạo; Học vụ / Trưởng Học thuật xử lý. Bấm dòng mở chi tiết
// (đường dẫn /material-orders/<id>, link trong thông báo mở thẳng được) — nhận xử lý / hoàn thành / từ chối ở đó.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { errorMessage } from '../../api'
import { Alert, Button, Card, Dialog, Field, Select } from '../../components/ui'
import { navigate, usePath } from '../../router'
import { inventoryApi, type Order, type OrderFormOptions } from './api'
import { Pager, Tag, Textarea, WideDialog } from './components'
import { DEADLINES, STATUS_TONES, dueRule, formatDate, formatDateTime, previewDueAt, toInt } from './logic'

const BASE = '/material-orders'

export function MaterialOrdersPage() {
  const path = usePath()
  const detailId = path.startsWith(BASE + '/') ? path.slice(BASE.length + 1) : ''
  const qc = useQueryClient()
  const [q, setQ] = useState('')
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [category, setCategory] = useState('')
  const [branchId, setBranchId] = useState('')
  const [page, setPage] = useState(1)
  const [creating, setCreating] = useState(false)
  const [notice, setNotice] = useState<{ text: string; warning?: string } | null>(null)

  const list = useQuery({
    queryKey: ['material-orders', search, status, category, branchId, page],
    queryFn: () => inventoryApi.orders({ search, status, category, branch_id: branchId, page }),
    placeholderData: keepPreviousData,
  })
  const data = list.data
  const filter = (f: () => void) => {
    f()
    setPage(1)
  }
  const refresh = (text: string, warning?: string) => {
    setNotice({ text, warning })
    qc.invalidateQueries({ queryKey: ['material-orders'] })
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Order học liệu</h1>
          <p className="text-sm text-on-surface-variant">Đạo cụ, in ấn, học liệu GVNN và học liệu học thuật — Học vụ / Học thuật xử lý trước hạn.</p>
        </div>
        {data?.can_create && <Button onClick={() => setCreating(true)}>Tạo order</Button>}
      </div>

      {notice && <Alert tone="success">{notice.text}</Alert>}
      {notice?.warning && <Alert>{notice.warning}</Alert>}
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}

      <Card>
        <form
          className="grid gap-3 sm:grid-cols-[1fr_180px_180px_180px_auto] sm:items-end"
          onSubmit={(e) => {
            e.preventDefault()
            filter(() => setSearch(q))
          }}
        >
          <Field label="Tìm kiếm" placeholder="Tìm mã, tên order..." maxLength={100} value={q} onChange={(e) => setQ(e.target.value)} />
          <Select label="Trạng thái" value={status} onChange={(e) => filter(() => setStatus(e.target.value))}>
            <option value="">Mọi trạng thái</option>
            {data?.statuses.map((s) => (
              <option key={s.value} value={s.value}>
                {s.label}
              </option>
            ))}
          </Select>
          <Select label="Loại order" value={category} onChange={(e) => filter(() => setCategory(e.target.value))}>
            <option value="">Mọi loại</option>
            {data?.categories.map((c) => (
              <option key={c.value} value={c.value}>
                {c.label}
              </option>
            ))}
          </Select>
          {data && data.branches.length > 1 ? (
            <Select label="Chi nhánh" value={branchId} onChange={(e) => filter(() => setBranchId(e.target.value))}>
              <option value="">Mọi chi nhánh</option>
              {data.branches.map((b) => (
                <option key={b.value} value={b.value}>
                  {b.label}
                </option>
              ))}
            </Select>
          ) : (
            <span />
          )}
          <Button type="submit" variant="secondary">
            Lọc
          </Button>
        </form>

        {data && data.orders.length === 0 && (
          <p className="mt-4 text-sm text-on-surface-variant">
            {data.can_create ? 'Chưa có order học liệu. Bấm “Tạo order” để đặt đạo cụ, in ấn hoặc học liệu.' : 'Không có order nào khớp bộ lọc.'}
          </p>
        )}
        {data && data.orders.length > 0 && (
          <div className="mt-4 overflow-x-auto">
            <table className="w-full min-w-[900px] text-left text-sm">
              <thead className="text-xs text-on-surface-variant">
                <tr>
                  <th className="py-2 pr-4 font-medium">Order</th>
                  <th className="py-2 pr-4 font-medium">Loại</th>
                  <th className="py-2 pr-4 font-medium">Người tạo</th>
                  <th className="py-2 pr-4 font-medium">Ngày sử dụng</th>
                  <th className="py-2 pr-4 font-medium">Hạn xử lý</th>
                  <th className="py-2 font-medium">Trạng thái</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-surface-container-highest">
                {data.orders.map((o) => (
                  <tr
                    key={o.id}
                    tabIndex={0}
                    className="cursor-pointer hover:bg-surface-container-low"
                    onClick={() => navigate(`${BASE}/${o.id}`)}
                    onKeyDown={(e) => e.key === 'Enter' && navigate(`${BASE}/${o.id}`)}
                  >
                    <td className="py-2 pr-4">
                      <div className="font-semibold">{o.title}</div>
                      <div className="text-xs text-on-surface-variant">
                        <span className="font-mono">{o.code}</span> · {o.branch}
                        {o.quantity ? ` · SL ${o.quantity}` : ''}
                      </div>
                    </td>
                    <td className="py-2 pr-4">
                      <Tag>{o.category_label}</Tag>
                    </td>
                    <td className="py-2 pr-4">{o.requester}</td>
                    <td className="py-2 pr-4 font-mono text-xs whitespace-nowrap">{formatDate(o.use_date)}</td>
                    <td className="py-2 pr-4">
                      <DueCell order={o} />
                    </td>
                    <td className="py-2">
                      <Tag tone={STATUS_TONES[o.status]}>{o.status_label}</Tag>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {data && <Pager page={data.page} total={data.total} size={data.page_size} onPage={setPage} />}
      </Card>

      {creating && (
        <CreateDialog
          onClose={() => setCreating(false)}
          onCreated={(text, warning) => {
            setCreating(false)
            refresh(text, warning)
          }}
        />
      )}
      {detailId && <DetailDialog id={detailId} onClose={() => navigate(BASE)} onChanged={(text) => refresh(text)} />}
    </div>
  )
}

function DueCell({ order }: { order: Order }) {
  return (
    <div className="flex flex-wrap items-center gap-1">
      <span className="font-mono text-xs whitespace-nowrap">{formatDateTime(order.due_at)}</span>
      {order.deadline_state && <Tag tone={DEADLINES[order.deadline_state].tone}>{DEADLINES[order.deadline_state].label}</Tag>}
      {order.created_late && <Tag tone="warning">Tạo trễ</Tag>}
    </div>
  )
}

function CreateDialog({ onClose, onCreated }: { onClose: () => void; onCreated: (text: string, warning?: string) => void }) {
  const opts = useQuery({ queryKey: ['material-orders', 'form'], queryFn: inventoryApi.orderFormOptions })
  return (
    <WideDialog title="Tạo order học liệu" subtitle="Gửi yêu cầu đạo cụ, in ấn hoặc học liệu cho Học vụ / Học thuật." onClose={onClose}>
      {opts.isError && <Alert>{errorMessage(opts.error)}</Alert>}
      {opts.data && <CreateForm opts={opts.data} onClose={onClose} onCreated={onCreated} />}
    </WideDialog>
  )
}

function CreateForm({ opts, onClose, onCreated }: { opts: OrderFormOptions; onClose: () => void; onCreated: (text: string, warning?: string) => void }) {
  const [category, setCategory] = useState('props')
  const [branch, setBranch] = useState(opts.default_branch_id ?? '')
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [useDate, setUseDate] = useState('')
  const [quantity, setQuantity] = useState('')
  const save = useMutation({
    mutationFn: () =>
      inventoryApi.createOrder({ category, branch_id: branch || null, title, description, quantity: toInt(quantity), use_date: useDate }),
    onSuccess: (r) => onCreated(r.message, r.warning),
  })
  const due = previewDueAt(category, useDate, opts.start_of_month_day, opts.day_before_cutoff)
  const late = due !== null && due.getTime() < Date.now()
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate()
  }

  return (
    <form className="space-y-4" onSubmit={submit}>
      {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
      {opts.branches.length === 0 && <Alert>Tài khoản chưa được gán chi nhánh, chưa tạo được order.</Alert>}
      <div className="grid gap-4 sm:grid-cols-2">
        <Select label="Loại order" required value={category} onChange={(e) => setCategory(e.target.value)}>
          {opts.categories.map((c) => (
            <option key={c.value} value={c.value}>
              {c.label}
            </option>
          ))}
        </Select>
        <Select label="Chi nhánh" required value={branch} onChange={(e) => setBranch(e.target.value)}>
          {opts.branches.map((b) => (
            <option key={b.value} value={b.value}>
              {b.label}
            </option>
          ))}
        </Select>
      </div>
      <Field label="Nội dung order" required maxLength={255} placeholder="VD: Flashcard unit 5, in 20 bộ đề..." value={title} onChange={(e) => setTitle(e.target.value)} />
      <Textarea label="Mô tả chi tiết" rows={3} maxLength={2000} value={description} onChange={(e) => setDescription(e.target.value)} />
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Ngày sử dụng" required type="date" value={useDate} onChange={(e) => setUseDate(e.target.value)} />
        <Field label="Số lượng" type="number" min={1} max={100000} value={quantity} onChange={(e) => setQuantity(e.target.value)} />
      </div>
      <Alert tone="info">
        <strong>Hạn xử lý.</strong> {dueRule(category, opts.start_of_month_day, opts.day_before_cutoff)}
        {due && (
          <>
            {' '}
            Hạn của order này: <strong>{formatDateTime(due)}</strong>.
          </>
        )}
      </Alert>
      {late && <Alert>Tạo trễ: đã quá hạn xử lý — order vẫn được gửi nhưng sẽ gắn nhãn “Tạo trễ”.</Alert>}
      <div className="flex justify-end gap-2">
        <Button type="button" variant="secondary" onClick={onClose}>
          Hủy
        </Button>
        <Button type="submit" disabled={save.isPending || opts.branches.length === 0}>
          Gửi order
        </Button>
      </div>
    </form>
  )
}

function DetailDialog({ id, onClose, onChanged }: { id: string; onClose: () => void; onChanged: (text: string) => void }) {
  const qc = useQueryClient()
  const detail = useQuery({ queryKey: ['material-orders', 'detail', id], queryFn: () => inventoryApi.order(id), retry: false })
  const [note, setNote] = useState('')
  const [rejecting, setRejecting] = useState(false)
  const [reason, setReason] = useState('')
  const act = useMutation({
    mutationFn: (kind: 'claim' | 'complete' | 'reject') =>
      kind === 'claim' ? inventoryApi.claimOrder(id) : kind === 'complete' ? inventoryApi.completeOrder(id, note) : inventoryApi.rejectOrder(id, reason),
    onSuccess: (r) => {
      setRejecting(false)
      onChanged(r.message)
      qc.invalidateQueries({ queryKey: ['material-orders', 'detail', id] })
    },
  })
  const o = detail.data?.order
  const actions = detail.data?.actions

  return (
    <WideDialog title={o?.title ?? 'Order học liệu'} subtitle={o ? `Order ${o.code}` : undefined} onClose={onClose}>
      {detail.isError && <Alert>{errorMessage(detail.error)}</Alert>}
      {o && actions && (
        <div className="space-y-5 text-sm">
          <div className="flex flex-wrap items-center gap-2">
            <Tag tone={STATUS_TONES[o.status]}>{o.status_label}</Tag>
            <Tag>{o.category_label}</Tag>
            {o.deadline_state && <Tag tone={DEADLINES[o.deadline_state].tone}>{DEADLINES[o.deadline_state].label}</Tag>}
            {o.created_late && <Tag tone="warning">Tạo trễ</Tag>}
            {o.processed_late && <Tag tone="error">Xử lý trễ</Tag>}
          </div>
          <dl className="grid gap-3 sm:grid-cols-2">
            <Info label="Người tạo" value={o.requester} />
            <Info label="Chi nhánh" value={o.branch} />
            <Info label="Số lượng" value={o.quantity ?? '—'} />
            <Info label="Ngày sử dụng" value={formatDate(o.use_date)} />
            <Info label="Hạn xử lý" value={formatDateTime(o.due_at)} />
            {o.processed_by && <Info label="Người xử lý" value={o.processed_by} />}
            {o.processed_at && <Info label="Xử lý lúc" value={formatDateTime(o.processed_at)} />}
          </dl>
          {o.description && (
            <div className="rounded-lg bg-surface-container-low p-3">
              <p className="mb-1 text-xs font-medium text-on-surface-variant uppercase">Mô tả</p>
              <p className="whitespace-pre-line">{o.description}</p>
            </div>
          )}
          {o.reject_reason && <Alert>Lý do từ chối: {o.reject_reason}</Alert>}
          {o.processor_note && <Alert tone="info">Ghi chú của người xử lý: {o.processor_note}</Alert>}

          {(actions.claim || actions.complete || actions.reject) && (
            <div className="space-y-3 rounded-lg border border-outline-variant p-4">
              <p className="font-semibold">Xử lý order</p>
              {act.isError && <Alert>{errorMessage(act.error)}</Alert>}
              {actions.claim && (
                <Button variant="secondary" disabled={act.isPending} onClick={() => act.mutate('claim')}>
                  Nhận xử lý
                </Button>
              )}
              {actions.complete && <Textarea label="Ghi chú xử lý" rows={2} maxLength={2000} value={note} onChange={(e) => setNote(e.target.value)} />}
              <div className="flex flex-wrap justify-end gap-2">
                {actions.reject && (
                  <Button variant="danger" onClick={() => setRejecting(true)}>
                    Từ chối
                  </Button>
                )}
                {actions.complete && (
                  <Button disabled={act.isPending} onClick={() => act.mutate('complete')}>
                    Hoàn thành
                  </Button>
                )}
              </div>
            </div>
          )}
        </div>
      )}
      {rejecting && (
        <Dialog title="Từ chối order" onClose={() => setRejecting(false)}>
          <form
            className="space-y-4"
            onSubmit={(e) => {
              e.preventDefault()
              act.mutate('reject')
            }}
          >
            {act.isError && <Alert>{errorMessage(act.error)}</Alert>}
            <Textarea label="Lý do từ chối" required rows={3} maxLength={2000} value={reason} onChange={(e) => setReason(e.target.value)} />
            <div className="flex justify-end gap-2">
              <Button type="button" variant="secondary" onClick={() => setRejecting(false)}>
                Hủy
              </Button>
              <Button type="submit" variant="danger" disabled={act.isPending}>
                Từ chối order
              </Button>
            </div>
          </form>
        </Dialog>
      )}
    </WideDialog>
  )
}

function Info({ label, value }: { label: string; value: string | number }) {
  return (
    <div>
      <dt className="text-xs text-on-surface-variant">{label}</dt>
      <dd>{value}</dd>
    </div>
  )
}
