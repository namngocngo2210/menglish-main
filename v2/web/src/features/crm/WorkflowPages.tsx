// Duyệt chuyển cơ sở (Laravel: "Chuyển cơ sở (CRM)" trong Việc cần duyệt) và Xác nhận chính thức nhập học.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Checkbox, Field, Select } from '../../components/ui'
import { crmApi, type ConfirmationRow, type TransferRow } from './api'
import { customerPath, Empty, Link, Pager, ReasonDialog, Table, Td, useFlash } from './components'
import { formatDateTime } from './logic'

export function TransfersPage(_: { me: Me }) {
  const qc = useQueryClient()
  const [status, setStatus] = useState('pending')
  const [page, setPage] = useState(1)
  const [action, setAction] = useState<{ row: TransferRow; kind: 'approve' | 'reject' } | null>(null)
  const flash = useFlash()
  const list = useQuery({
    queryKey: ['crm', 'transfers', status, page],
    queryFn: () => crmApi.transfers(status, page),
    placeholderData: keepPreviousData,
  })
  return (
    <Card title="Duyệt chuyển cơ sở (CRM)">
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <Select label="Trạng thái" value={status} onChange={(e) => (setStatus(e.target.value), setPage(1))}>
          <option value="pending">Chờ duyệt</option>
          <option value="approved">Đã duyệt</option>
          <option value="rejected">Đã từ chối</option>
          <option value="all">Tất cả</option>
        </Select>
      </div>
      {flash.node}
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {list.data && list.data.data.length === 0 && <Empty>Không có yêu cầu nào.</Empty>}
      {list.data && list.data.data.length > 0 && (
        <Table head={['Khách', 'Chuyển', 'Người phụ trách', 'Người gửi / lý do', 'Trạng thái', '']}>
          {list.data.data.map((r) => (
            <tr key={r.id}>
              <Td>
                <Link href={customerPath(r.lead_id)}>{r.lead_name}</Link>
                <div className="text-xs text-on-surface-variant">
                  {r.lead_short_code}
                  {r.has_student && ' · đã là học viên'}
                </div>
              </Td>
              <Td>
                {r.from_branch ?? '(chưa có)'} → {r.to_branch}
              </Td>
              <Td>
                {r.from_user ?? '—'} → {r.to_user}
              </Td>
              <Td>
                {r.requester ?? '—'} · {formatDateTime(r.created_at)}
                {r.reason && <div className="text-xs text-on-surface-variant">{r.reason}</div>}
              </Td>
              <Td>
                <Badge tone={r.status === 'approved' ? 'success' : r.status === 'rejected' ? 'error' : 'info'}>{r.status_label}</Badge>
                {r.decider && (
                  <div className="text-xs text-on-surface-variant">
                    {r.decider} · {formatDateTime(r.decided_at)}
                    {r.decision_note && ` · ${r.decision_note}`}
                  </div>
                )}
              </Td>
              <Td className="space-x-2 whitespace-nowrap">
                {r.status === 'pending' && (
                  <>
                    <Button onClick={() => setAction({ row: r, kind: 'approve' })}>Duyệt</Button>
                    <Button variant="danger" onClick={() => setAction({ row: r, kind: 'reject' })}>
                      Từ chối
                    </Button>
                  </>
                )}
              </Td>
            </tr>
          ))}
        </Table>
      )}
      {list.data && <Pager meta={list.data.meta} onPage={setPage} />}
      {action && (
        <ReasonDialog
          title={action.kind === 'approve' ? `Duyệt chuyển ${action.row.lead_name}` : `Từ chối chuyển ${action.row.lead_name}`}
          label={action.kind === 'approve' ? 'Ghi chú duyệt' : 'Lý do từ chối'}
          required={action.kind === 'reject'}
          submitLabel={action.kind === 'approve' ? 'Duyệt' : 'Từ chối'}
          onClose={() => setAction(null)}
          onSubmit={async (text) => {
            const r = action.kind === 'approve' ? await crmApi.approveTransfer(action.row.id, text) : await crmApi.rejectTransfer(action.row.id, text)
            flash.ok(r.message)
            qc.invalidateQueries({ queryKey: ['crm'] })
          }}
        />
      )}
    </Card>
  )
}

export function ConfirmationsPage(_: { me: Me }) {
  const [status, setStatus] = useState('pending')
  const [q, setQ] = useState('')
  const [search, setSearch] = useState('')
  const [branch, setBranch] = useState('')
  const [klass, setKlass] = useState('')
  const [page, setPage] = useState(1)
  const flash = useFlash()
  const params = { status, search, branch_id: branch, class_id: klass, page }
  const list = useQuery({
    queryKey: ['crm', 'confirmations', params],
    queryFn: () => crmApi.confirmations(params),
    placeholderData: keepPreviousData,
  })
  const d = list.data
  return (
    <Card title="Xác nhận chính thức nhập học">
      <p className="mb-3 text-sm text-on-surface-variant">
        Học viên chốt từ CRM đã vào lớp: tick đủ 3 mục hồ sơ (tài khoản, nhóm Zalo, giáo trình) rồi bấm Xác nhận.
      </p>
      <form
        className="mb-4 grid gap-3 sm:grid-cols-5 sm:items-end"
        onSubmit={(e) => {
          e.preventDefault()
          setSearch(q)
          setPage(1)
        }}
      >
        <Select label="Trạng thái" value={status} onChange={(e) => (setStatus(e.target.value), setPage(1))}>
          <option value="pending">Chờ xác nhận {d ? `(${d.pending_count})` : ''}</option>
          <option value="confirmed">Đã xác nhận</option>
        </Select>
        <Field label="Tìm học viên, mã, SĐT" value={q} onChange={(e) => setQ(e.target.value)} />
        <Select label="Cơ sở" value={branch} onChange={(e) => (setBranch(e.target.value), setPage(1))}>
          <option value="">Tất cả</option>
          {d?.filter_branches.map((b) => (
            <option key={b.value} value={b.value}>
              {b.label}
            </option>
          ))}
        </Select>
        <Select label="Lớp" value={klass} onChange={(e) => (setKlass(e.target.value), setPage(1))}>
          <option value="">Tất cả</option>
          {d?.filter_classes.map((c) => (
            <option key={c.value} value={c.value}>
              {c.label}
            </option>
          ))}
        </Select>
        <Button type="submit" variant="secondary">
          Tìm
        </Button>
      </form>
      {flash.node}
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {d && d.data.length === 0 && <Empty>Không có học viên nào.</Empty>}
      {d && d.data.length > 0 && (
        <Table head={['Học viên', 'Lớp', 'Ngày chốt', 'Hồ sơ', '']}>
          {d.data.map((r) => (
            <ConfirmationLine key={r.id} row={r} items={d.checklist_items} onDone={flash.ok} onFail={flash.fail} />
          ))}
        </Table>
      )}
      {d && <Pager meta={d.meta} onPage={setPage} />}
    </Card>
  )
}

function ConfirmationLine({
  row,
  items,
  onDone,
  onFail,
}: {
  row: ConfirmationRow
  items: { value: string; label: string }[]
  onDone: (m: string) => void
  onFail: (m: string) => void
}) {
  const qc = useQueryClient()
  const [check, setCheck] = useState<Record<string, boolean>>(row.checklist)
  const m = useMutation({
    mutationFn: (action: string) =>
      crmApi.confirm(row.id, {
        account_sent: !!check.account_sent,
        zalo_group_added: !!check.zalo_group_added,
        curriculum_delivered: !!check.curriculum_delivered,
        action,
      }),
    onSuccess: (r) => {
      onDone(r.message)
      qc.invalidateQueries({ queryKey: ['crm'] })
    },
    onError: (e) => onFail(errorMessage(e)),
  })
  const confirmed = !!row.confirmed_at
  return (
    <tr>
      <Td>
        <Link href={customerPath(row.lead_id)}>{row.student_name}</Link>
        <div className="text-xs text-on-surface-variant">
          {row.student_code} · {row.phone}
        </div>
      </Td>
      <Td>
        {row.class_name}
        <div className="text-xs text-on-surface-variant">
          {row.branch ?? ''} {row.start_label && `· ${row.start_label}`}
        </div>
      </Td>
      <Td className="whitespace-nowrap">{formatDateTime(row.closed_at)}</Td>
      <Td>
        <div className="space-y-1">
          {items.map((i) => (
            <Checkbox
              key={i.value}
              label={i.label}
              checked={!!check[i.value]}
              disabled={confirmed}
              onChange={(e) => setCheck({ ...check, [i.value]: e.target.checked })}
            />
          ))}
        </div>
      </Td>
      <Td className="space-y-2">
        {confirmed ? (
          <span className="text-xs text-on-surface-variant">
            Đã xác nhận {formatDateTime(row.confirmed_at)} · {row.confirmed_by}
          </span>
        ) : (
          <div className="flex flex-col gap-2">
            <Button variant="secondary" onClick={() => m.mutate('save')} disabled={m.isPending}>
              Lưu tiến độ
            </Button>
            <Button onClick={() => m.mutate('confirm')} disabled={m.isPending}>
              Xác nhận
            </Button>
          </div>
        )}
      </Td>
    </tr>
  )
}
