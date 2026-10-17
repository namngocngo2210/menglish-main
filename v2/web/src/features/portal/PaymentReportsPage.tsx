import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Dialog, Icon } from '../../components/ui'
import { can } from '../../nav'
import { navigate } from '../../router'
import { PageHeader } from '../platform/parts'
import { formatMoney } from '../tuition/logic'
import { portalApi, type PaymentReportStatus, type ReportRow } from './api'
import { Empty, Pill, TextArea, useNotice } from './components'
import { submittedAt } from './logic'

const TABS: { key: PaymentReportStatus; label: string }[] = [
  { key: 'pending', label: 'Chờ xác nhận' },
  { key: 'confirmed', label: 'Đã xác nhận' },
  { key: 'rejected', label: 'Đã từ chối' },
]

// Cần duyệt › Học viên báo đã đóng học phí (PaymentReportApprovalSource): người duyệt phiếu thu xác nhận đã nhận tiền
// (rồi lập phiếu thu) hoặc từ chối kèm lý do; học viên nhận kết quả ở hộp thư cổng.
export function PaymentReportsPage({ me }: { me: Me }) {
  const [status, setStatus] = useState<PaymentReportStatus>('pending')
  const q = useQuery({ queryKey: ['payment-reports', status], queryFn: () => portalApi.paymentReports(status) })
  const qc = useQueryClient()
  const notice = useNotice()
  const [rejecting, setRejecting] = useState<ReportRow | null>(null)
  const confirm = useMutation({
    mutationFn: portalApi.confirmReport,
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['payment-reports'] })
      notice.ok(r.message)
    },
    onError: notice.fail,
  })
  const rows = q.data?.data ?? []

  return (
    <>
      <PageHeader title="Học viên báo đã đóng học phí" description="Xác nhận khoản học viên / phụ huynh báo đã chuyển khoản, rồi lập phiếu thu cho học viên." />
      {notice.view}
      <div className="mb-4 flex flex-wrap gap-2" role="tablist">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            role="tab"
            aria-selected={status === t.key}
            onClick={() => setStatus(t.key)}
            className={`rounded-full px-4 py-2 text-xs font-bold ${status === t.key ? 'bg-primary text-white' : 'border border-surface-container-highest bg-surface text-on-surface-variant hover:bg-surface-container-low'}`}
          >
            {t.label}
          </button>
        ))}
      </div>
      {q.isError && <Alert>{errorMessage(q.error)}</Alert>}
      <section className="overflow-hidden rounded-xl border border-surface-container-highest bg-surface shadow-sm">
        <ul className="divide-y divide-surface-container-highest">
          {rows.map((r) => (
            <li key={r.id} className="flex flex-col gap-3 p-4 md:flex-row md:items-center md:justify-between">
              <div className="min-w-0 space-y-0.5 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-semibold">Báo đóng học phí · {r.student_name}</span>
                  <span className="font-mono text-xs text-on-surface-variant">{r.student_code}</span>
                  <Pill tone={r.status === 'pending' ? 'warning' : r.status === 'confirmed' ? 'success' : 'error'}>{r.status_label}</Pill>
                </div>
                <div className="text-xs text-on-surface-variant">
                  {r.class_name ?? 'Chưa xếp lớp'} · Gửi lúc {submittedAt(r.created_at)}
                  {r.creator_name && ` · ${r.creator_name}`}
                </div>
                <p className="text-xs">Nội dung: {r.content}</p>
                {r.rejection_reason && <p className="text-xs text-error">Lý do: {r.rejection_reason}</p>}
                {r.resolver_name && r.resolved_at && (
                  <p className="text-xs text-on-surface-variant">
                    {r.resolver_name} · {submittedAt(r.resolved_at)}
                  </p>
                )}
              </div>
              <div className="flex shrink-0 flex-wrap items-center gap-2">
                <span className="font-mono text-base font-semibold text-success">{formatMoney(r.amount)}</span>
                {r.status === 'pending' && (
                  <>
                    <Button className="h-9 px-3 text-xs" disabled={confirm.isPending} onClick={() => confirm.mutate(r.id)}>
                      <Icon name="check" className="mr-1 text-[16px]" /> Xác nhận
                    </Button>
                    <Button variant="danger" className="h-9 px-3 text-xs" onClick={() => setRejecting(r)}>
                      Từ chối
                    </Button>
                  </>
                )}
                {r.status === 'confirmed' && can(me, 'tuition.create') && (
                  <Button
                    variant="secondary"
                    className="h-9 px-3 text-xs"
                    onClick={() => navigate(r.tuition_id ? `/tuition/receipts/new?tuition_id=${r.tuition_id}` : `/tuition/receipts/new?student_id=${r.student_id}`)}
                  >
                    <Icon name="receipt_long" className="mr-1 text-[16px]" /> Lập phiếu thu
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
        {q.data && rows.length === 0 && (
          <div className="p-4">
            <Empty icon="task_alt" title={status === 'pending' ? 'Không có yêu cầu nào chờ xác nhận.' : 'Chưa có yêu cầu nào.'} />
          </div>
        )}
      </section>
      {rejecting && (
        <RejectDialog
          row={rejecting}
          onClose={() => setRejecting(null)}
          onDone={(m) => {
            setRejecting(null)
            notice.ok(m)
          }}
        />
      )}
    </>
  )
}

function RejectDialog({ row, onClose, onDone }: { row: ReportRow; onClose: () => void; onDone: (m: string) => void }) {
  const qc = useQueryClient()
  const [reason, setReason] = useState('')
  const m = useMutation({
    mutationFn: () => portalApi.rejectReport(row.id, reason),
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['payment-reports'] })
      onDone(r.message)
    },
  })
  return (
    <Dialog title="Từ chối báo đóng học phí" onClose={onClose}>
      <form
        onSubmit={(e) => {
          e.preventDefault()
          m.mutate()
        }}
        className="space-y-3"
      >
        <p className="text-sm">
          {row.student_name} · <span className="font-mono">{formatMoney(row.amount)}</span>
        </p>
        {m.isError && <Alert>{errorMessage(m.error)}</Alert>}
        <TextArea label="Lý do từ chối" value={reason} onChange={setReason} maxLength={1000} placeholder="Ví dụ: Chưa thấy tiền về tài khoản trung tâm." />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" variant="danger" disabled={m.isPending}>
            Từ chối
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
