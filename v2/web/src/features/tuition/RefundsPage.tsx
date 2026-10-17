// Hoàn tiền & Khất nợ (Tuition/Refunds.vue): lập hồ sơ hoàn phí / chuyển nhượng / khất nợ / bảo lưu với bảng căn cứ
// tính từ hợp đồng thật, khối hồ sơ chờ duyệt (quá hạn xử lý lên đầu), duyệt kèm ảnh bằng chứng + quyết định thu hồi hoa
// hồng, từ chối, bảng tất cả hồ sơ có lọc.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Checkbox, Dialog, Field, Select } from '../../components/ui'
import { acceptOf, checkFile, type FileRule } from '../../lib/files'
import { can } from '../../nav'
import { tuitionApi, type RefundInput, type RefundRow, type RefundType, type RefundsPage as Page } from './api'
import { Stat, Tag } from './components'
import { formatMoney, toInt } from './logic'

const PROOF_RULE: FileRule = { types: ['jpg', 'jpeg', 'png', 'webp'], maxBytes: 10 * 1024 * 1024, tooLarge: 'Ảnh bằng chứng tối đa 10MB.' }

const statusTone = { pending: 'warning', approved: 'success', rejected: 'error' } as const
const statusLabel = { pending: 'Chờ duyệt', approved: 'Đã duyệt', rejected: 'Từ chối' }

export function RefundsPage({ me }: { me: Me }) {
  const qc = useQueryClient()
  const [status, setStatus] = useState('')
  const [type, setType] = useState('')
  const [q, setQ] = useState('')
  const [search, setSearch] = useState('')
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const [approving, setApproving] = useState<RefundRow | null>(null)
  const [rejecting, setRejecting] = useState<RefundRow | null>(null)

  const list = useQuery({
    queryKey: ['tuition', 'refunds', status, type, search],
    queryFn: () => tuitionApi.refunds({ status, type, search }),
    placeholderData: keepPreviousData,
  })
  const d = list.data
  const done = (text: string) => {
    setNotice({ tone: 'success', text })
    setApproving(null)
    setRejecting(null)
    qc.invalidateQueries({ queryKey: ['tuition'] })
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold">Hoàn tiền &amp; Khất nợ</h1>
        <p className="text-sm text-on-surface-variant">
          Hoàn phí, chuyển nhượng số dư, khất nợ và bảo lưu học phí. Hoàn tiền / chuyển nhượng xử lý trong hạn
          {d ? ` (lập hôm nay: hạn ${d.deadline})` : ''}.
        </p>
      </div>
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      {d && (
        <>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <Stat label="Chờ duyệt" value={`${d.pending_requests.length} hồ sơ`} tone="warning" />
            <Stat label="Quá hạn xử lý" value={`${d.overdue_count} hồ sơ`} tone={d.overdue_count > 0 ? 'error' : undefined} />
            <Stat label="Tổng hồ sơ" value={String(d.total_count)} />
          </div>

          {can(me, 'refund_transfer.request') && <CreateForm d={d} onDone={(m) => done(m)} onError={(m) => setNotice({ tone: 'error', text: m })} />}

          <Card title="Yêu cầu chờ phê duyệt">
            {d.pending_requests.length === 0 ? (
              <p className="text-sm text-on-surface-variant">Không có hồ sơ nào chờ duyệt.</p>
            ) : (
              <ul className="divide-y divide-surface-container-highest">
                {d.pending_requests.map((r) => (
                  <li key={r.id} className="flex flex-wrap items-start justify-between gap-3 py-3 text-sm">
                    <RefundSummary r={r} />
                    <div className="flex flex-wrap gap-2">
                      {d.approvable_types.includes(r.type) && (
                        <Button className="h-8 px-3 text-xs" onClick={() => setApproving(r)}>
                          Duyệt
                        </Button>
                      )}
                      {d.can_reject_any && (
                        <Button variant="danger" className="h-8 px-3 text-xs" onClick={() => setRejecting(r)}>
                          Từ chối
                        </Button>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card title="Tất cả yêu cầu">
            <form
              className="mb-4 grid gap-3 sm:grid-cols-[1fr_180px_180px_auto] sm:items-end"
              onSubmit={(e) => {
                e.preventDefault()
                setSearch(q)
              }}
            >
              <Field label="Tìm học viên" placeholder="Tên hoặc mã học viên..." value={q} onChange={(e) => setQ(e.target.value)} />
              <Select label="Trạng thái" value={status} onChange={(e) => setStatus(e.target.value)}>
                <option value="">Tất cả</option>
                <option value="pending">Chờ duyệt</option>
                <option value="overdue">Quá hạn xử lý</option>
                <option value="approved">Đã duyệt</option>
                <option value="rejected">Từ chối</option>
              </Select>
              <Select label="Loại" value={type} onChange={(e) => setType(e.target.value)}>
                <option value="">Tất cả loại</option>
                {d.type_options.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
              <Button type="submit" variant="secondary">
                Lọc
              </Button>
            </form>
            {d.history_requests.length === 0 ? (
              <p className="text-sm text-on-surface-variant">Không có hồ sơ nào.</p>
            ) : (
              <ul className="divide-y divide-surface-container-highest">
                {d.history_requests.map((r) => (
                  <li key={r.id} className="flex flex-wrap items-start justify-between gap-3 py-3 text-sm">
                    <RefundSummary r={r} />
                    <div className="text-right text-xs text-on-surface-variant">
                      <Tag tone={statusTone[r.status]}>{statusLabel[r.status]}</Tag>
                      {r.approver_name && <div className="mt-1">Xử lý: {r.approver_name}</div>}
                      {r.rejection_reason && <div className="text-error">Lý do từ chối: {r.rejection_reason}</div>}
                      {r.clawback_commission && (
                        <div>
                          Thu hồi hoa hồng {formatMoney(r.clawback_amount)}
                          {r.clawback_user_name && ` (${r.clawback_user_name})`}
                        </div>
                      )}
                      {r.proof_url && (
                        <a className="text-primary hover:underline" href={r.proof_url} target="_blank" rel="noreferrer">
                          Xem ảnh bằng chứng
                        </a>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </>
      )}

      {approving && d && <ApproveDialog r={approving} hint={d.clawback_hints[approving.id]} onClose={() => setApproving(null)} onDone={done} />}
      {rejecting && <RejectDialog r={rejecting} onClose={() => setRejecting(null)} onDone={done} />}
    </div>
  )
}

function RefundSummary({ r }: { r: RefundRow }) {
  return (
    <div className="space-y-0.5">
      <div className="font-medium">
        <Tag tone="info">{r.type_label}</Tag> {r.student?.name} <span className="font-mono text-xs text-on-surface-variant">{r.student?.code}</span>
        {r.overdue && (
          <>
            {' '}
            <Tag tone="error">Quá hạn xử lý{r.overdue_days ? ` ${r.overdue_days} ngày` : ''}</Tag>
          </>
        )}
      </div>
      <div className="text-xs text-on-surface-variant">
        {r.ref} · lập {r.created_at}
        {r.requester_name && ` bởi ${r.requester_name}`}
        {r.processing_deadline && ` · hạn xử lý ${r.processing_deadline}`}
      </div>
      {(r.type === 'refund' || r.type === 'transfer') && (
        <div>
          {r.type === 'refund' ? 'Hoàn' : 'Chuyển'} <span className="font-mono">{formatMoney(r.refund_amount)}</span>
          {r.target && ` → ${r.target.name} (${r.target.code})`}
        </div>
      )}
      {r.type === 'extension' && <div>Hạn đóng mới: {r.extended_due_date}</div>}
      {r.type === 'deferral' && (
        <div>
          Bảo lưu {r.defer_from} – {r.defer_to}
        </div>
      )}
      <div className="text-xs">Lý do: {r.reason}</div>
      {r.no_transfer_reason && <div className="text-xs text-on-surface-variant">Không chuyển nhượng vì: {r.no_transfer_reason}</div>}
    </div>
  )
}

function CreateForm({ d, onDone, onError }: { d: Page; onDone: (m: string) => void; onError: (m: string) => void }) {
  const [type, setType] = useState<RefundType>('refund')
  const [studentId, setStudentId] = useState('')
  const [amount, setAmount] = useState('')
  const [targetId, setTargetId] = useState('')
  const [extended, setExtended] = useState('')
  const [from, setFrom] = useState(d.today)
  const [to, setTo] = useState('')
  const [reason, setReason] = useState('')
  const [noTransfer, setNoTransfer] = useState('')
  const basis = studentId ? d.student_finance[studentId] : undefined
  const options = type === 'extension' ? d.debtor_options : d.source_options
  const money = type === 'refund' || type === 'transfer'

  const save = useMutation({
    mutationFn: () => {
      const body: RefundInput = { student_id: studentId, type, reason }
      if (money) body.refund_amount = toInt(amount)
      if (type === 'transfer') body.target_student_id = targetId || undefined
      if (type === 'extension') body.extended_due_date = extended || undefined
      if (type === 'deferral') {
        body.defer_from = from || undefined
        body.defer_to = to || undefined
      }
      if (type === 'refund') body.no_transfer_reason = noTransfer || undefined
      return tuitionApi.createRefund(body)
    },
    onSuccess: (x) => {
      setAmount('')
      setReason('')
      setNoTransfer('')
      onDone(x.message)
    },
    onError: (e) => onError(errorMessage(e)),
  })

  return (
    <Card title="Lập hồ sơ">
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label="Loại hồ sơ" value={type} onChange={(e) => setType(e.target.value as RefundType)}>
            {d.type_options.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
          <Select
            label="Học viên"
            value={studentId}
            onChange={(e) => {
              setStudentId(e.target.value)
              const b = d.student_finance[e.target.value]
              if (b && type === 'refund') setAmount(String(b.suggested_refund))
            }}
          >
            <option value="">Chọn học viên</option>
            {options.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>
        {basis && (
          <dl className="grid grid-cols-2 gap-2 rounded-lg bg-surface-container-low p-3 text-xs sm:grid-cols-4">
            <div>
              <dt className="text-on-surface-variant">Đã nộp</dt>
              <dd className="font-mono">{formatMoney(basis.paid)}</dd>
            </div>
            <div>
              <dt className="text-on-surface-variant">Giá trị hợp đồng</dt>
              <dd className="font-mono">{formatMoney(basis.contract)}</dd>
            </div>
            <div>
              <dt className="text-on-surface-variant">Đã học / tổng buổi</dt>
              <dd>
                {basis.attended_sessions} / {basis.total_sessions ?? '—'}
              </dd>
            </div>
            <div>
              <dt className="text-on-surface-variant">Giá trị còn lại</dt>
              <dd className="font-mono">{formatMoney(basis.remaining_value)}</dd>
            </div>
            <div>
              <dt className="text-on-surface-variant">Phí quản trị ({d.admin_fee_percent}%)</dt>
              <dd className="font-mono">{formatMoney(basis.admin_fee)}</dd>
            </div>
            <div>
              <dt className="text-on-surface-variant">Đề xuất hoàn</dt>
              <dd className="font-mono text-success">{formatMoney(basis.suggested_refund)}</dd>
            </div>
            <div>
              <dt className="text-on-surface-variant">Còn nợ</dt>
              <dd className="font-mono">{formatMoney(basis.debt)}</dd>
            </div>
            <div>
              <dt className="text-on-surface-variant">Trạng thái</dt>
              <dd>{basis.status_label}</dd>
            </div>
          </dl>
        )}
        <div className="grid gap-3 sm:grid-cols-2">
          {money && <Field label="Số tiền (đ)" inputMode="numeric" value={amount} onChange={(e) => setAmount(e.target.value)} />}
          {type === 'transfer' && (
            <Select label="Học viên nhận" value={targetId} onChange={(e) => setTargetId(e.target.value)}>
              <option value="">Chọn học viên nhận</option>
              {d.targets
                .filter((t) => t.id !== studentId)
                .map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.name} · {t.code}
                    {t.debt > 0 ? ` · còn nợ ${formatMoney(t.debt)}` : ''}
                  </option>
                ))}
            </Select>
          )}
          {type === 'extension' && (
            <Field label="Hạn đóng mới" type="date" min={d.tomorrow} value={extended} onChange={(e) => setExtended(e.target.value)} />
          )}
          {type === 'deferral' && (
            <>
              <Field label="Bảo lưu từ ngày" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
              <Field label="Đến ngày" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
            </>
          )}
        </div>
        <TextArea label="Lý do" value={reason} onChange={setReason} />
        {type === 'refund' && (
          <TextArea label="Lý do không chuyển nhượng buổi dư (bắt buộc với hoàn tiền)" value={noTransfer} onChange={setNoTransfer} />
        )}
        <div className="flex justify-end">
          <Button type="submit" disabled={save.isPending}>
            Gửi duyệt
          </Button>
        </div>
      </form>
    </Card>
  )
}

function TextArea({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  return (
    <label className="block space-y-1.5 text-sm font-medium">
      <span>{label}</span>
      <textarea
        className="block min-h-20 w-full rounded-lg border border-outline-variant px-3 py-2 text-sm font-normal outline-none focus:border-primary"
        maxLength={1000}
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
    </label>
  )
}

function ApproveDialog({
  r,
  hint,
  onClose,
  onDone,
}: {
  r: RefundRow
  hint?: { suggest: boolean; amount: number; owner: string | null; start: string | null }
  onClose: () => void
  onDone: (m: string) => void
}) {
  const isRefund = r.type === 'refund'
  const [clawback, setClawback] = useState(hint?.suggest ?? false)
  const [amount, setAmount] = useState(String(hint?.amount ?? 0))
  const [proof, setProof] = useState<File | null>(null)
  const [error, setError] = useState('')
  const save = useMutation({
    mutationFn: () =>
      tuitionApi.approveRefund(r.id, isRefund ? { clawback_commission: clawback, clawback_amount: clawback ? toInt(amount) : 0 } : {}, proof),
    onSuccess: (x) => onDone(x.message),
    onError: (e) => setError(errorMessage(e)),
  })
  return (
    <Dialog title={`Duyệt hồ sơ ${r.type_label.toLowerCase()}`} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          if (isRefund && !proof) {
            setError('Hoàn tiền bắt buộc đính kèm ảnh bằng chứng (ủy nhiệm chi / biên nhận).')
            return
          }
          save.mutate()
        }}
      >
        <RefundSummary r={r} />
        {error && <Alert>{error}</Alert>}
        {isRefund && (
          <>
            <label className="block space-y-1.5 text-sm font-medium">
              <span>Ảnh bằng chứng chi tiền</span>
              <input
                type="file"
                accept={acceptOf(PROOF_RULE)}
                className="block text-sm font-normal"
                onChange={(e) => {
                  const f = e.target.files?.[0] ?? null
                  const msg = f ? checkFile(f, PROOF_RULE) : null
                  setError(msg ?? '')
                  setProof(msg ? null : f)
                }}
              />
            </label>
            <div className="space-y-2 rounded-lg bg-surface-container-low p-3 text-sm">
              <Checkbox label="Thu hồi hoa hồng của tư vấn viên" checked={clawback} onChange={(e) => setClawback(e.target.checked)} />
              <p className="text-xs text-on-surface-variant">
                {hint?.suggest ? 'Gợi ý: thu hồi (học chưa tới 1 tháng).' : 'Gợi ý: không thu hồi.'}
                {hint?.owner && ` Người hưởng: ${hint.owner}.`}
                {hint?.start && ` Bắt đầu học: ${hint.start}.`}
              </p>
              {clawback && <Field label="Số hoa hồng thu hồi (đ)" inputMode="numeric" value={amount} onChange={(e) => setAmount(e.target.value)} />}
            </div>
          </>
        )}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            Duyệt
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function RejectDialog({ r, onClose, onDone }: { r: RefundRow; onClose: () => void; onDone: (m: string) => void }) {
  const [reason, setReason] = useState('')
  const [error, setError] = useState('')
  const save = useMutation({
    mutationFn: () => tuitionApi.rejectRefund(r.id, reason),
    onSuccess: (x) => onDone(x.message),
    onError: (e) => setError(errorMessage(e)),
  })
  return (
    <Dialog title="Từ chối hồ sơ" onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <RefundSummary r={r} />
        {error && <Alert>{error}</Alert>}
        <TextArea label="Lý do từ chối" value={reason} onChange={setReason} />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" variant="danger" disabled={save.isPending}>
            Từ chối
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
