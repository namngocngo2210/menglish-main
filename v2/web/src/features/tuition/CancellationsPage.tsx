// Duyệt hủy hóa đơn (Tuition/InvoiceCancellations.vue): số liệu, danh sách yêu cầu theo cơ sở / trạng thái, chi tiết yêu cầu
// đang xem kèm phiếu thu gốc, duyệt hủy (vô hiệu phiếu + hoàn tác công nợ) / từ chối, form "Yêu cầu hủy HĐ" (điền sẵn từ
// ?cancel_invoice=).
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Dialog, Field, Select } from '../../components/ui'
import { acceptOf, checkFile, type FileRule } from '../../lib/files'
import { can } from '../../nav'
import { navigate, useQueryParam } from '../../router'
import { tuitionApi, type CancellationDetail, type CancellationStatus, type Tone } from './api'
import { Stat, Tag } from './components'
import { formatMoney, toInt } from './logic'

const PROOF_RULE: FileRule = {
  types: ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
  maxBytes: 5 * 1024 * 1024,
  tooLarge: 'Dung lượng tập tin ảnh minh chứng không được vượt quá 5120 kB.',
}

const statusTag: Record<CancellationStatus, [Tone, string]> = {
  pending: ['warning', 'Chờ duyệt'],
  approved: ['success', 'Đã duyệt hủy'],
  rejected: ['error', 'Đã từ chối'],
}

export function CancellationsPage({ me }: { me: Me }) {
  const qc = useQueryClient()
  const selectedId = useQueryParam('selected_id')
  const cancelInvoice = useQueryParam('cancel_invoice')
  const [status, setStatus] = useState('pending')
  const [branch, setBranch] = useState('all')
  const [q, setQ] = useState('')
  const [search, setSearch] = useState('')
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const [creating, setCreating] = useState(!!cancelInvoice)
  const filter = { status, branch_id: branch, q: search }

  const list = useQuery({
    queryKey: ['tuition', 'cancellations', filter, selectedId, cancelInvoice],
    queryFn: () => tuitionApi.cancellations({ ...filter, selected_id: selectedId, cancel_invoice: cancelInvoice }),
    placeholderData: keepPreviousData,
  })
  const d = list.data
  const select = (id: string) => navigate(`/tuition/invoices/cancellations?selected_id=${id}`)
  const done = (text: string) => {
    setNotice({ tone: 'success', text })
    qc.invalidateQueries({ queryKey: ['tuition'] })
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Duyệt hủy hóa đơn</h1>
          <p className="text-sm text-on-surface-variant">
            Mọi thao tác duyệt hủy đều trừ lùi doanh thu và hoàn trả công nợ học viên tự động. Admin phê duyệt (theo phân quyền).
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <a
            className="inline-flex h-10 items-center rounded-lg border border-outline-variant px-4 text-sm font-medium hover:bg-surface-container-low"
            href={tuitionApi.cancellationsExportUrl(filter)}
          >
            Xuất danh sách
          </a>
          {can(me, 'invoice.request_cancel') && (
            <Button variant="secondary" onClick={() => setCreating(true)}>
              Yêu cầu hủy HĐ
            </Button>
          )}
        </div>
      </div>
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      {d && (
        <>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <Stat label="Chờ duyệt hủy" value={`${d.pending_count} hóa đơn`} tone="warning" />
            <Stat label="Đã duyệt hủy (Tháng này)" value={`${d.approved_month_count} hóa đơn`} tone="success" />
            <Stat label="Đã từ chối" value={`${d.rejected_count} yêu cầu`} />
          </div>
          <div className="space-y-1 rounded-xl bg-surface-container-low p-4 text-xs text-on-surface-variant">
            <p>
              • <strong>Bảo toàn dải số:</strong> Số hóa đơn đã hủy vẫn tính là đã sử dụng trong dải số, không được cấp phát hay tái sử dụng lại.
            </p>
            <p>
              • <strong>Tự động hoàn tác công nợ:</strong> Khi duyệt hủy phiếu từng ở trạng thái "Đã duyệt", hệ thống trừ lùi số tiền đã thu và hoàn
              trả công nợ còn lại của học viên.
            </p>
          </div>

          <Card title="Danh sách yêu cầu hủy">
            <form
              className="mb-4 grid gap-3 sm:grid-cols-[1fr_200px_240px_auto] sm:items-end"
              onSubmit={(e) => {
                e.preventDefault()
                setSearch(q)
              }}
            >
              <Field label="Tìm kiếm" placeholder="Tìm theo mã phiếu, số hóa đơn, học viên..." value={q} onChange={(e) => setQ(e.target.value)} />
              <Select label="Cơ sở" value={branch} onChange={(e) => setBranch(e.target.value)}>
                <option value="all">Tất cả cơ sở</option>
                {d.branches.map((b) => (
                  <option key={b.value} value={b.value}>
                    {b.label}
                  </option>
                ))}
              </Select>
              <Select label="Trạng thái" value={status} onChange={(e) => setStatus(e.target.value)}>
                <option value="pending">Chờ duyệt hủy ({d.pending_count})</option>
                <option value="approved">Đã duyệt hủy</option>
                <option value="rejected">Đã từ chối hủy</option>
                <option value="all">Tất cả</option>
              </Select>
              <Button type="submit" variant="secondary">
                Lọc
              </Button>
            </form>
            {d.cancellations.length === 0 ? (
              <p className="text-sm text-on-surface-variant">Không có yêu cầu hủy nào.</p>
            ) : (
              <ul className="divide-y divide-surface-container-highest">
                {d.cancellations.map((c) => (
                  <li
                    key={c.id}
                    className={`flex flex-wrap items-start justify-between gap-3 py-3 text-sm ${c.id === selectedId ? 'bg-primary/5' : ''}`}
                  >
                    <div className="space-y-0.5">
                      <div className="font-medium">
                        <span className="font-mono">{c.invoice_number}</span> · {c.receipt_number ?? 'PT-Trực tiếp'}{' '}
                        <Tag tone={statusTag[c.status][0]}>{statusTag[c.status][1]}</Tag>
                      </div>
                      <div>
                        {c.student_name ?? '—'}{' '}
                        {c.student_code && <span className="font-mono text-xs text-on-surface-variant">{c.student_code}</span>} ·{' '}
                        <span className="font-mono">{formatMoney(c.amount)}</span>
                      </div>
                      <div className="text-xs text-on-surface-variant">Lý do: {c.reason}</div>
                      <div className="text-xs text-on-surface-variant">
                        {c.requester_name ?? '—'} · {c.created_at} ({c.created_ago})
                      </div>
                    </div>
                    <Button variant="secondary" className="h-8 px-3 text-xs" onClick={() => select(c.id)}>
                      Xem
                    </Button>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          {d.selected && (
            <Detail c={d.selected} canApprove={d.can_approve_cancel} onDone={done} onError={(m) => setNotice({ tone: 'error', text: m })} />
          )}
        </>
      )}

      {creating && d && (
        <CreateDialog
          prefill={d.prefill}
          onClose={() => {
            setCreating(false)
            if (cancelInvoice) navigate('/tuition/invoices/cancellations')
          }}
          onDone={(m) => {
            setCreating(false)
            if (cancelInvoice) navigate('/tuition/invoices/cancellations')
            done(m)
          }}
        />
      )}
    </div>
  )
}

function Detail({
  c,
  canApprove,
  onDone,
  onError,
}: {
  c: CancellationDetail
  canApprove: boolean
  onDone: (m: string) => void
  onError: (m: string) => void
}) {
  const [confirming, setConfirming] = useState(false)
  const [rejecting, setRejecting] = useState(false)
  const approve = useMutation({
    mutationFn: () => tuitionApi.approveCancellation(c.id),
    onSuccess: (x) => {
      setConfirming(false)
      onDone(x.message)
    },
    onError: (e) => {
      setConfirming(false)
      onError(errorMessage(e))
    },
  })
  const rc = c.receipt
  return (
    <Card title={`Yêu cầu hủy hóa đơn ${c.invoice_number}`}>
      <div className="space-y-4 text-sm">
        <div className="flex flex-wrap items-center gap-2">
          <Tag tone={statusTag[c.status][0]}>{c.status === 'pending' ? 'Đang yêu cầu hủy' : statusTag[c.status][1]}</Tag>
          <span className="text-on-surface-variant">Trạng thái phiếu thu:</span>
          <Tag tone={(rc?.status_color as Tone) ?? 'neutral'}>{rc?.status_label ?? 'Không xác định phiếu thu'}</Tag>
        </div>
        <div className="rounded-lg bg-error-container/40 p-3">
          <div className="text-xs text-error">
            Người gửi yêu cầu: <strong>{c.requester_name ?? '—'}</strong> · Thời gian gửi: {c.created_at}
          </div>
          <div className="mt-1 font-medium">Nội dung giải trình lý do hủy: {c.reason}</div>
          {c.rejection_reason && <div className="mt-1 text-error">Lý do từ chối: {c.rejection_reason}</div>}
        </div>
        <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3">
          <Info label="Học viên" value={`${c.student_name ?? '—'}${c.student_code ? ` (${c.student_code})` : ''}`} />
          <Info label="Lớp học hiện tại" value={c.class_name} />
          <Info label="Cơ sở" value={c.branch_name} />
          <Info
            label="Người nộp tiền"
            value={`${rc?.payer_name ?? c.payer_name ?? '—'}${(rc?.payer_phone ?? c.payer_phone) ? ` · ${rc?.payer_phone ?? c.payer_phone}` : ''}`}
          />
          <Info label="Hình thức thu" value={rc?.method_label ?? '—'} />
          <Info label="Số hóa đơn giấy" value={rc?.paper_invoice_number ?? '—'} />
          <Info label="Hóa đơn đỏ (VAT)" value={rc?.is_vat_invoice ? 'Có' : 'Không'} />
          <Info label="Phiếu thu gốc" value={rc?.receipt_number ?? '—'} />
          <Info label="Tổng số tiền trên hóa đơn" value={formatMoney(c.amount)} />
        </dl>
        {rc && (rc.surcharge_amount > 0 || rc.discount_amount > 0) && (
          <div className="text-xs text-on-surface-variant">
            Học phí đào tạo {formatMoney(rc.tuition_amount)}
            {rc.discount_amount > 0 && ` · giảm ${formatMoney(rc.discount_amount)}`}
            {rc.surcharge_amount > 0 && ` · phụ thu ${formatMoney(rc.surcharge_amount)}${rc.surcharge_reason ? ` (${rc.surcharge_reason})` : ''}`}
          </div>
        )}
        {c.proof_url ? (
          <a className="text-primary hover:underline" href={c.proof_url} target="_blank" rel="noreferrer">
            Xem ảnh hóa đơn hỏng / gạch chéo
          </a>
        ) : (
          <p className="text-xs text-on-surface-variant">
            Người yêu cầu không đính kèm ảnh hóa đơn hỏng / gạch chéo. Đối chiếu bản giấy trước khi duyệt.
          </p>
        )}
        {c.status === 'pending' && !canApprove && (
          <Alert tone="info">Yêu cầu đang chờ Admin (hoặc người được cấp quyền duyệt hủy hóa đơn) phê duyệt.</Alert>
        )}
        {c.status === 'approved' && (
          <Alert tone="success">
            Hóa đơn đã được phê duyệt hủy và hoàn tác công nợ{c.approver_name ? ` (${c.approver_name}, ${c.updated_at})` : ''}.
          </Alert>
        )}
        {c.status === 'pending' && canApprove && (
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setRejecting(true)}>
              Từ chối hủy
            </Button>
            <Button onClick={() => setConfirming(true)}>Duyệt hủy hóa đơn</Button>
          </div>
        )}
      </div>
      {confirming && (
        <Dialog title="Xác nhận duyệt hủy hóa đơn" onClose={() => setConfirming(false)}>
          <div className="space-y-3 text-sm">
            <p className="text-xs text-on-surface-variant">Hành động này là quyết định cuối cùng và không thể hoàn tác.</p>
            <div>
              Số hóa đơn hủy: <strong className="font-mono">{c.invoice_number}</strong>
            </div>
            <div>
              Số tiền hủy &amp; hoàn công nợ: <strong className="font-mono">{formatMoney(c.amount)}</strong>
            </div>
            <div>Học viên: {c.student_name ?? '—'}</div>
            <p className="text-xs">
              Số hóa đơn <code>{c.invoice_number}</code> sẽ bị đánh dấu Đã hủy và nằm lại trong dải số, không cấp lại cho bất kỳ ai.
            </p>
            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setConfirming(false)}>
                Quay lại kiểm tra
              </Button>
              <Button variant="danger" disabled={approve.isPending} onClick={() => approve.mutate()}>
                Xác nhận duyệt hủy ngay
              </Button>
            </div>
          </div>
        </Dialog>
      )}
      {rejecting && (
        <RejectDialog
          id={c.id}
          onClose={() => setRejecting(false)}
          onDone={(m) => {
            setRejecting(false)
            onDone(m)
          }}
        />
      )}
    </Card>
  )
}

function Info({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs text-on-surface-variant uppercase">{label}</dt>
      <dd>{value}</dd>
    </div>
  )
}

function TextArea({ label, placeholder, value, onChange }: { label: string; placeholder?: string; value: string; onChange: (v: string) => void }) {
  return (
    <label className="block space-y-1.5 text-sm font-medium">
      <span>{label}</span>
      <textarea
        className="block min-h-20 w-full rounded-lg border border-outline-variant px-3 py-2 text-sm font-normal outline-none focus:border-primary"
        maxLength={1000}
        placeholder={placeholder}
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
    </label>
  )
}

function RejectDialog({ id, onClose, onDone }: { id: string; onClose: () => void; onDone: (m: string) => void }) {
  const [reason, setReason] = useState('')
  const [error, setError] = useState('')
  const save = useMutation({
    mutationFn: () => tuitionApi.rejectCancellation(id, reason),
    onSuccess: (x) => onDone(x.message),
    onError: (e) => setError(errorMessage(e)),
  })
  return (
    <Dialog title="Từ chối yêu cầu hủy" onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {error && <Alert>{error}</Alert>}
        <TextArea
          label="Lý do từ chối yêu cầu hủy"
          placeholder="Nhập lý do chi tiết (ví dụ: Hóa đơn chưa có chữ ký xác nhận của phụ huynh, thông tin hợp lệ không cần hủy)..."
          value={reason}
          onChange={setReason}
        />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            Xác nhận từ chối
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function CreateDialog({
  prefill,
  onClose,
  onDone,
}: {
  prefill: { invoice_number: string; amount: number } | null
  onClose: () => void
  onDone: (m: string) => void
}) {
  const [invoice, setInvoice] = useState(prefill?.invoice_number ?? '')
  const [amount, setAmount] = useState(prefill ? String(prefill.amount) : '')
  const [reason, setReason] = useState('')
  const [proof, setProof] = useState<File | null>(null)
  const [error, setError] = useState('')
  const save = useMutation({
    mutationFn: () => tuitionApi.createCancellation({ invoice_number: invoice, amount: amount.trim() === '' ? null : toInt(amount), reason }, proof),
    onSuccess: (x) => onDone(x.message),
    onError: (e) => setError(errorMessage(e)),
  })
  return (
    <Dialog title="Yêu cầu hủy hóa đơn" onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {error && <Alert>{error}</Alert>}
        <Field
          label="Số Hóa đơn / Biên lai cần hủy"
          placeholder="Ví dụ: C26MEN-0001001"
          hint="Số HĐĐT của phiếu đã duyệt, hoặc số hóa đơn giấy tiền mặt hệ thống đã cấp (kể cả phiếu chưa duyệt, khi ghi sai số trên giấy). Phiếu lập mới sẽ nhận số kế tiếp."
          value={invoice}
          onChange={(e) => setInvoice(e.target.value)}
        />
        <Field
          label="Số tiền trên hóa đơn (VNĐ)"
          inputMode="numeric"
          placeholder="13500000"
          value={amount}
          onChange={(e) => setAmount(e.target.value)}
        />
        <label className="block space-y-1.5 text-sm font-medium">
          <span>Đính kèm ảnh hóa đơn hỏng / gạch chéo</span>
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
        <TextArea
          label="Lý do giải trình hủy hóa đơn"
          placeholder="Nhập lý do chi tiết (ví dụ: Viết sai tên phụ huynh, rách liên đỏ khi xé hóa đơn giao khách)..."
          value={reason}
          onChange={setReason}
        />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy bỏ
          </Button>
          <Button type="submit" variant="danger" disabled={save.isPending}>
            Gửi yêu cầu hủy
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
