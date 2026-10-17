// Thành phần dùng chung của Học phí: nhãn màu, phân trang, hộp thoại lịch sử thanh toán, chi tiết phiếu thu.
import { useQuery } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { errorMessage } from '../../api'
import { Alert, Button, Icon } from '../../components/ui'
import { tuitionApi, type Page, type ReceiptDetail, type Tone } from './api'
import { DEPOSIT, formatMoney } from './logic'

export function Tag({ tone = 'neutral', children }: { tone?: Tone; children: ReactNode }) {
  const styles = {
    neutral: 'bg-surface-container-low text-on-surface-variant',
    success: 'bg-success-container text-on-success-container',
    error: 'bg-error-container text-on-error-container',
    info: 'bg-info-container text-on-info-container',
    warning: 'bg-warning-container text-warning',
  }[tone]
  return <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ${styles}`}>{children}</span>
}

export function Stat({ label, value, tone }: { label: string; value: string; tone?: 'success' | 'warning' | 'error' }) {
  const color = tone === 'success' ? 'text-success' : tone === 'warning' ? 'text-warning' : tone === 'error' ? 'text-error' : ''
  return (
    <div className="rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm">
      <div className="text-xs text-on-surface-variant">{label}</div>
      <div className={`mt-1 font-mono text-lg font-semibold ${color}`}>{value}</div>
    </div>
  )
}

export function Pager({ page, unit, onPage }: { page: Page; unit: string; onPage: (p: number) => void }) {
  if (page.last_page <= 1) return null
  return (
    <div className="mt-4 flex items-center justify-between gap-2 text-sm text-on-surface-variant">
      <span>
        Trang {page.page}/{page.last_page} · {page.total} {unit}
      </span>
      <span className="flex gap-2">
        <Button variant="secondary" disabled={page.page <= 1} onClick={() => onPage(page.page - 1)}>
          Trước
        </Button>
        <Button variant="secondary" disabled={page.page >= page.last_page} onClick={() => onPage(page.page + 1)}>
          Sau
        </Button>
      </span>
    </div>
  )
}

export function Modal({ title, subtitle, onClose, children }: { title: string; subtitle?: string; onClose: () => void; children: ReactNode }) {
  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-10"
      onMouseDown={(e) => e.target === e.currentTarget && onClose()}
      onKeyDown={(e) => e.key === 'Escape' && onClose()}
    >
      <section role="dialog" aria-modal="true" aria-label={title} className="w-full max-w-4xl rounded-xl bg-surface p-6 shadow-lg">
        <div className="mb-4 flex items-start justify-between gap-4">
          <div>
            <h2 className="text-base font-semibold">{title}</h2>
            {subtitle && <p className="text-xs text-on-surface-variant">{subtitle}</p>}
          </div>
          <button type="button" onClick={onClose} className="text-sm text-on-surface-variant hover:text-on-surface" aria-label="Đóng">
            Đóng
          </button>
        </div>
        {children}
      </section>
    </div>
  )
}

// Lịch sử thu học phí của học viên (Tuition/StudentPayments.vue): mọi đợt nộp, còn nợ sau từng đợt.
export function StudentPaymentsDialog({ studentId, onClose }: { studentId: string; onClose: () => void }) {
  const q = useQuery({ queryKey: ['tuition', 'payments', studentId], queryFn: () => tuitionApi.payments(studentId) })
  const d = q.data
  return (
    <Modal title="Lịch sử thu học phí" subtitle={d ? `${d.student.name} · ${d.student.code}` : undefined} onClose={onClose}>
      {q.isError && <Alert>{errorMessage(q.error)}</Alert>}
      {d && (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Stat label="Phải nộp" value={formatMoney(d.summary.final_amount)} />
            <Stat label="Đã nộp" value={formatMoney(d.summary.paid_amount)} tone="success" />
            <Stat label="Còn nợ" value={formatMoney(d.summary.debt_amount)} tone={d.summary.debt_amount > 0 ? 'error' : undefined} />
            <Stat label="Số đợt nộp" value={String(d.summary.installments)} />
          </div>
          {d.summary.is_settled && <Alert tone="success">Học viên đã hoàn tất học phí.</Alert>}
          {d.summary.next_due_date && !d.summary.is_settled && (
            <p className="text-sm text-on-surface-variant">Hạn nộp kế tiếp: {d.summary.next_due_date}</p>
          )}
          {d.tuitions.length > 0 && (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[640px] text-left text-sm">
                <thead className="text-xs text-on-surface-variant">
                  <tr>
                    <th className="py-2 pr-3 font-medium">Khoản học phí</th>
                    <th className="py-2 pr-3 text-right font-medium">Phải nộp</th>
                    <th className="py-2 pr-3 text-right font-medium">Đã nộp</th>
                    <th className="py-2 pr-3 text-right font-medium">Còn nợ</th>
                    <th className="py-2 pr-3 font-medium">Hạn nộp</th>
                    <th className="py-2 font-medium">Trạng thái</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-surface-container-highest">
                  {d.tuitions.map((t) => (
                    <tr key={t.id}>
                      <td className="py-2 pr-3">
                        <div className="font-medium">{t.label}</div>
                        <div className="text-xs text-on-surface-variant">{t.class_name ?? 'Chưa xếp lớp'}</div>
                      </td>
                      <td className="py-2 pr-3 text-right font-mono">{formatMoney(t.final_amount)}</td>
                      <td className="py-2 pr-3 text-right font-mono text-success">{formatMoney(t.paid_amount)}</td>
                      <td className={`py-2 pr-3 text-right font-mono ${t.debt_amount > 0 ? 'text-error' : ''}`}>{formatMoney(t.debt_amount)}</td>
                      <td className="py-2 pr-3 font-mono text-xs">{t.due_date ?? '—'}</td>
                      <td className="py-2">
                        <Tag tone={t.status_color}>{t.status_label}</Tag>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <h3 className="text-sm font-semibold">Các đợt đã nộp</h3>
          {d.payments.length === 0 ? (
            <p className="text-sm text-on-surface-variant">Chưa có đợt nộp nào được duyệt.</p>
          ) : (
            <ol className="space-y-2">
              {d.payments.map((p) => (
                <li key={p.id} className="flex flex-wrap items-start justify-between gap-2 rounded-lg border border-surface-container-highest p-3 text-sm">
                  <div>
                    <div className="flex items-center gap-2 font-medium">
                      <Icon name={p.method_icon} className="text-base text-on-surface-variant" />
                      {p.title}
                    </div>
                    <div className="text-xs text-on-surface-variant">
                      {p.number} · {p.date ?? '—'} · {p.method}
                      {p.invoice_number && ` · HĐ ${p.invoice_number}`}
                      {p.approver_name && ` · duyệt: ${p.approver_name}`}
                    </div>
                    {p.surcharge_detail && <div className="text-xs text-on-surface-variant">Phụ thu: {p.surcharge_detail}</div>}
                    {p.tuition_label && <div className="text-xs text-on-surface-variant">{p.tuition_label}</div>}
                  </div>
                  <div className="text-right">
                    <div className={`font-mono font-semibold ${p.amount < 0 ? 'text-error' : 'text-success'}`}>{formatMoney(p.amount)}</div>
                    {p.discount > 0 && <div className="text-xs text-on-surface-variant">Giảm {formatMoney(p.discount)}</div>}
                    {p.debt_after !== null && <div className="text-xs text-on-surface-variant">Còn nợ sau đợt: {formatMoney(p.debt_after)}</div>}
                  </div>
                </li>
              ))}
            </ol>
          )}
          {d.others.length > 0 && (
            <>
              <h3 className="text-sm font-semibold">Phiếu chưa được ghi nhận</h3>
              <ul className="space-y-1 text-sm">
                {d.others.map((o) => (
                  <li key={o.id} className="flex flex-wrap items-center justify-between gap-2">
                    <span>
                      {o.number} · {o.date ?? '—'} · {o.method}
                      {o.rejection_reason && <span className="text-xs text-error"> · {o.rejection_reason}</span>}
                    </span>
                    <span className="flex items-center gap-2">
                      <span className="font-mono">{formatMoney(o.amount)}</span>
                      <Tag tone={o.status_color}>{o.status_label}</Tag>
                    </span>
                  </li>
                ))}
              </ul>
            </>
          )}
        </div>
      )}
    </Modal>
  )
}

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex justify-between gap-3 py-1 text-sm">
      <dt className="text-on-surface-variant">{label}</dt>
      <dd className="text-right">{children}</dd>
    </div>
  )
}

// Chi tiết phiếu thu (khung bên phải màn duyệt, hộp thoại ở lịch sử thu).
export function ReceiptView({ r }: { r: ReceiptDetail }) {
  const deposit = r.deposit_state ? DEPOSIT[r.deposit_state] : null
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-mono text-base font-semibold">{r.code}</span>
        <Tag tone={r.status_color}>{r.status_label}</Tag>
        {deposit && <Tag tone={deposit.tone}>{deposit.label}</Tag>}
      </div>
      {r.rejection_reason && r.status === 'rejected' && <Alert>Lý do trả về: {r.rejection_reason}</Alert>}
      {r.sepay_warnings.length > 0 && (
        <Alert>
          SePay đã ghi nhận giao dịch có thể trùng:{' '}
          {r.sepay_warnings.map((w) => `${formatMoney(w.amount)} ngày ${w.date ?? '—'}${w.receipt_number ? ` (phiếu ${w.receipt_number})` : ''}`).join('; ')}
        </Alert>
      )}
      {r.reconciliation && (
        <div className="rounded-lg border border-surface-container-highest p-3 text-sm">
          <Tag tone={r.reconciliation.tone}>{r.reconciliation.label}</Tag>
          <p className="mt-1 text-on-surface-variant">{r.reconciliation.detail}</p>
        </div>
      )}
      <dl className="divide-y divide-surface-container-highest">
        <Row label="Học viên">
          {r.student_name ?? '—'}
          {r.student_code && <span className="ml-1 font-mono text-xs text-on-surface-variant">{r.student_code}</span>}
        </Row>
        <Row label="Lớp · Chi nhánh">
          {r.class_name} · {r.branch_name}
        </Row>
        {r.fee_label && <Row label="Khoản thu">{r.fee_label}</Row>}
        <Row label="Người nộp">
          {r.payer_name ?? '—'}
          {r.payer_phone && ` · ${r.payer_phone}`}
        </Row>
        <Row label="Hình thức">{r.method_label}</Row>
        <Row label="Mã tham chiếu">
          <span className="font-mono">{r.reference}</span>
        </Row>
        {r.beneficiary && <Row label="Tài khoản nhận">{r.beneficiary}</Row>}
        {r.invoice_number && (
          <Row label={r.issued_paper_invoice ? 'Số hóa đơn giấy' : 'Số hóa đơn'}>
            <span className="font-mono">{r.invoice_number}</span>
          </Row>
        )}
        {r.session && (
          <>
            <Row label={`Học phí ${r.session.session_count} buổi × ${formatMoney(r.session.unit_price)}`}>{formatMoney(r.session.session_value)}</Row>
            {r.session.material_fee > 0 && <Row label="Học liệu">{formatMoney(r.session.material_fee)}</Row>}
            {r.session.exam_fee > 0 && <Row label="Phí thi">{formatMoney(r.session.exam_fee)}</Row>}
            {r.session.other_fee > 0 && <Row label={r.session.other_fee_reason ?? 'Khoản khác'}>{formatMoney(r.session.other_fee)}</Row>}
          </>
        )}
        {r.discount_amount > 0 && (
          <Row label={`Giảm trừ${r.promotion_name ? ` (${r.promotion_name})` : ''}`}>
            -{formatMoney(r.discount_amount)}
            {r.discount_reason && <div className="text-xs text-on-surface-variant">{r.discount_reason}</div>}
          </Row>
        )}
        {r.surcharge_amount > 0 && (
          <Row label="Phụ thu">
            {formatMoney(r.surcharge_amount)}
            {r.surcharge_reason && <div className="text-xs text-on-surface-variant">{r.surcharge_reason}</div>}
          </Row>
        )}
        <Row label="Tổng thu">
          <span className="font-mono text-base font-semibold text-primary">{formatMoney(r.amount)}</span>
        </Row>
        <Row label="Ngày thu">{r.payment_date ?? '—'}</Row>
        <Row label="Người lập">
          {r.creator_name ?? '—'} · {r.created_at}
        </Row>
        {r.approver_name && <Row label="Người duyệt">{r.approver_name}</Row>}
        {r.deposited_at && (
          <Row label="Nộp về TK công ty">
            {r.deposited_at}
            {r.deposited_by_name && ` · ${r.deposited_by_name}`}
          </Row>
        )}
        {r.notes && <Row label="Ghi chú">{r.notes}</Row>}
      </dl>
      {r.stock_out.length > 0 && (
        <div className="text-sm">
          <div className="mb-1 font-medium">Xuất kho khi duyệt</div>
          <ul className="space-y-0.5 text-on-surface-variant">
            {r.stock_out.map((l) => (
              <li key={l.item_id + l.source}>
                {l.name} × {l.quantity} {l.unit} (tồn {l.stock} → {l.stock_after})
              </li>
            ))}
          </ul>
        </div>
      )}
      {r.proof_url && (
        <p className="text-sm">
          Minh chứng:{' '}
          {/^https?:\/\//.test(r.proof_url) ? (
            <a className="text-primary hover:underline" href={r.proof_url} target="_blank" rel="noopener noreferrer">
              Mở minh chứng
            </a>
          ) : (
            <span>{r.proof_url}</span>
          )}
        </p>
      )}
    </div>
  )
}
