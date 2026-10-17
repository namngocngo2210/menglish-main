// Bill "Thông báo nộp học phí" (crm/tuition-bill.blade.php): mở bằng id khoản học phí hoặc id học viên
// (/tuition/bill/<id>), in A4, tải mã VietQR cho phần còn phải nộp.
import { useQuery } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button } from '../../components/ui'
import { can } from '../../nav'
import { navigate, usePath } from '../../router'
import { tuitionApi } from './api'

const vnd = (v: number) => `${Math.round(v).toLocaleString('vi-VN')} VNĐ`

function Row({ label, children, className = '' }: { label: string; children: ReactNode; className?: string }) {
  return (
    <tr>
      <td className="w-[30%] border border-outline-variant bg-surface-container-low px-3 py-2 font-semibold">{label}</td>
      <td className={`border border-outline-variant px-3 py-2 ${className}`}>{children}</td>
    </tr>
  )
}

export function BillPage({ me }: { me: Me }) {
  const path = usePath()
  const id = path.match(/^\/tuition\/bill\/([0-9a-f-]{36})$/)?.[1] ?? ''
  const bill = useQuery({
    queryKey: ['tuition', 'bill', id],
    queryFn: () => tuitionApi.bill(id),
    enabled: !!id,
  })
  if (!id) return <Alert>Không tìm thấy thông tin công nợ / học phí của học viên.</Alert>
  if (bill.isError) return <Alert>{errorMessage(bill.error)}</Alert>
  const b = bill.data
  if (!b) return <p className="text-sm text-on-surface-variant">Đang tải…</p>
  const t = b.tuition
  const [dd, mm, yyyy] = b.today.split('/')
  const back = can(me, 'lead.view')
    ? { href: '/crm/pipeline', label: 'Quay lại CRM Pipeline' }
    : { href: '/tuition/history', label: 'Quay lại Lịch sử thu phí' }
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2 print:hidden">
        <a
          className="text-sm font-medium text-primary hover:underline"
          href={back.href}
          onClick={(e) => {
            e.preventDefault()
            navigate(back.href)
          }}
        >
          ← {back.label}
        </a>
        <div className="flex gap-2">
          <Button onClick={() => window.print()}>In thông báo (Print A4)</Button>
          {b.vietqr_url && (
            <a
              className="inline-flex items-center rounded-lg bg-surface-container-high px-4 py-2 text-sm font-medium"
              href={b.vietqr_url}
              download={`VietQR_${b.student.code || 'HocVien'}.png`}
              target="_blank"
              rel="noreferrer"
            >
              Tải mã QR
            </a>
          )}
        </div>
      </div>

      <main className="mx-auto max-w-4xl space-y-5 rounded-2xl border border-surface-container-highest bg-surface p-6 text-sm shadow-sm sm:p-10 print:border-0 print:shadow-none">
        <header>
          <div className="text-lg font-bold tracking-wide text-primary">{b.center.name}</div>
          <div className="text-xs leading-relaxed text-on-surface-variant">
            {b.center.branches.map((x, i) => (
              <div key={x}>
                {i === 0 ? 'Địa chỉ: ' : ''}
                {x}
              </div>
            ))}
            {b.center.phone && <div>Điện thoại: {b.center.phone}</div>}
          </div>
        </header>
        <div className="text-center">
          <h1 className="text-2xl font-bold">THÔNG BÁO NỘP HỌC PHÍ</h1>
          <div className="mt-1 text-on-surface-variant">
            Ngày {dd} tháng {mm} năm {yyyy}
          </div>
        </div>

        <table className="w-full border-collapse">
          <tbody>
            <Row label="Họ tên:">
              <strong>{b.student.name}</strong>
              <strong className="ml-6 text-primary">Mã: {b.student.code}</strong>
              {b.student.parent_name && <span className="ml-5 text-on-surface-variant">(PH: {b.student.parent_name})</span>}
            </Row>
            <Row label="Lớp :">
              <strong>{b.class_name ?? 'Chờ xếp lớp'}</strong>
              {b.schedule?.text && <span> · {b.schedule.text}</span>}
            </Row>
            <Row label="Thời gian học:">
              {b.schedule ? `Từ ngày: ${b.schedule.start_date} đến ${b.schedule.end_date}` : 'Sẽ thông báo khi Học vụ xếp lớp'}
            </Row>
            <Row label="Ca học:">{b.total_sessions || 24} buổi / khóa học</Row>
            <Row label="Học phí:">{vnd(t.total_amount)}</Row>
            <Row label="Ưu đãi:" className={t.discount_amount > 0 ? 'font-bold text-success' : ''}>
              {vnd(t.discount_amount)}
              {b.promotion_name && <span className="ml-1 text-xs font-normal text-on-surface-variant">({b.promotion_name})</span>}
            </Row>
            <Row label="Thu khác:">
              <strong>{vnd(t.other_fees)}</strong>
            </Row>
            {t.prepaid_amount > 0 && (
              <Row label="Thu trước (Đã cọc/đóng trước):" className="font-bold text-info">
                - {vnd(t.prepaid_amount)}
              </Row>
            )}
            <Row label="Tổng giá trị hợp đồng:">{vnd(t.final_amount)}</Row>
            <Row label="Đã thanh toán (Các đợt):" className="font-bold text-success">
              {vnd(t.paid_amount)}
            </Row>
            <Row label="Còn lại cần nộp:" className={b.amount_to_pay > 0 ? 'text-base font-extrabold text-primary' : 'font-bold text-success'}>
              {b.amount_to_pay > 0 ? (
                <>
                  {vnd(b.amount_to_pay)} <span className="text-xs font-normal text-warning">(Chưa gồm khoản chờ đối soát)</span>
                </>
              ) : b.pending_amount > 0 ? (
                <>
                  0 VNĐ <span className="ml-1 text-xs font-bold text-warning">Đang chờ đối soát</span>
                </>
              ) : (
                <>
                  0 VNĐ <span className="ml-1 text-xs font-bold text-success">✓ Đã hoàn tất thanh toán</span>
                </>
              )}
            </Row>
            {b.pending_amount > 0 && (
              <Row label="Khoản thu chờ duyệt:" className="font-bold text-warning">
                {vnd(b.pending_amount)}
              </Row>
            )}
            <Row label="Ghi chú:">{t.notes ?? ''}</Row>
          </tbody>
        </table>

        <section>
          <div className="mb-2 flex items-center justify-between font-bold">
            <span>LỊCH SỬ THANH TOÁN CÁC ĐỢT (TIẾN ĐỘ THU TIỀN)</span>
            {t.debt_amount <= 0 && t.paid_amount > 0 ? (
              <span className="rounded-full bg-success-container px-3 py-0.5 text-xs text-on-success-container">✓ ĐÃ HOÀN TẤT HỌC PHÍ (100%)</span>
            ) : b.amount_to_pay > 0 ? (
              <span className="rounded-full bg-warning-container px-3 py-0.5 text-xs text-warning">CÒN NỢ ĐỢT TIẾP THEO: {vnd(b.amount_to_pay)}</span>
            ) : b.pending_amount > 0 ? (
              <span className="rounded-full bg-warning-container px-3 py-0.5 text-xs text-warning">CHỜ KẾ TOÁN/ADMIN ĐỐI SOÁT</span>
            ) : null}
          </div>
          <table className="w-full border-collapse text-xs">
            <thead>
              <tr className="bg-surface-container-low text-left text-on-surface-variant">
                {['Đợt thu', 'Số phiếu / HĐĐT', 'Ngày thu', 'Hình thức', 'Nội dung / Khoản thu', 'Số tiền'].map((h) => (
                  <th key={h} className={`border border-outline-variant px-2 py-2 ${h === 'Số tiền' ? 'text-right' : ''}`}>
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {b.receipts.length === 0 && (
                <tr>
                  <td colSpan={6} className="border border-outline-variant px-2 py-3 text-center text-on-surface-variant">
                    Chưa có giao dịch thanh toán nào được ghi nhận. Học viên cần nộp đợt 1.
                  </td>
                </tr>
              )}
              {b.receipts.map((r) => (
                <tr key={r.code}>
                  <td className="border border-outline-variant px-2 py-2 font-bold text-primary">Đợt {r.installment}</td>
                  <td className="border border-outline-variant px-2 py-2 font-mono">
                    <strong>{r.code}</strong>
                    {r.invoice_number ? (
                      <div className="text-[11px] text-on-surface-variant">HĐ: {r.invoice_number}</div>
                    ) : r.status === 'pending' ? (
                      <div className="text-[11px] text-warning">Chờ đối soát</div>
                    ) : null}
                  </td>
                  <td className="border border-outline-variant px-2 py-2">{r.date}</td>
                  <td className="border border-outline-variant px-2 py-2">
                    {r.method_label}
                    {r.method === 'cash' && r.paper_invoice_number && <div className="text-[11px]">HĐ giấy: {r.paper_invoice_number}</div>}
                  </td>
                  <td className="border border-outline-variant px-2 py-2">{r.content}</td>
                  <td className="border border-outline-variant px-2 py-2 text-right font-semibold">{vnd(r.amount)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr className="font-bold">
                <td colSpan={5} className="border border-outline-variant px-2 py-2 text-right">
                  Tổng cộng đã thanh toán:
                </td>
                <td className="border border-outline-variant px-2 py-2 text-right text-success">{vnd(t.paid_amount)}</td>
              </tr>
              {b.amount_to_pay > 0 && (
                <tr className="font-bold">
                  <td colSpan={5} className="border border-outline-variant px-2 py-2 text-right">
                    Số tiền còn lại cần nộp (Công nợ các đợt tiếp theo):
                  </td>
                  <td className="border border-outline-variant px-2 py-2 text-right text-primary">{vnd(b.amount_to_pay)}</td>
                </tr>
              )}
            </tfoot>
          </table>
        </section>

        {b.amount_to_pay > 0 && b.bank_account ? (
          <section className="space-y-3">
            <p className="italic">Quý phụ huynh có thể nộp tiền mặt tại Trung tâm hoặc chuyển khoản theo thông tin sau:</p>
            <table className="w-full border-collapse">
              <tbody>
                <tr className="bg-surface-container-low font-semibold">
                  <td className="border border-outline-variant px-3 py-2">Chủ tài khoản</td>
                  <td className="border border-outline-variant px-3 py-2">Số tài khoản</td>
                  <td className="border border-outline-variant px-3 py-2">Ngân hàng</td>
                </tr>
                <tr>
                  <td className="border border-outline-variant px-3 py-2">{b.bank_account.account_holder}</td>
                  <td className="border border-outline-variant px-3 py-2 font-mono font-bold">{b.bank_account.account_number}</td>
                  <td className="border border-outline-variant px-3 py-2">{b.bank_account.bank_name}</td>
                </tr>
              </tbody>
            </table>
            <div className="rounded-md border border-warning-container bg-warning-container/40 px-3 py-2">
              <strong>Nội dung chuyển tiền :</strong> <span className="font-mono font-bold text-primary">{b.transfer_memo}</span>
            </div>
            <div>
              <strong className="block">Ghi chú:</strong>
              Tk công ty. Quý phụ huynh vui lòng giữ nguyên nội dung chuyển khoản để hệ thống tự động gạch nợ nhanh nhất.
            </div>
            {b.vietqr_url && (
              <div className="flex w-fit items-center gap-5 rounded-xl border border-dashed border-warning p-4">
                <img src={b.vietqr_url} alt="QR thanh toán VietQR" className="h-40 w-40 object-contain" />
                <div className="space-y-1">
                  <h4 className="font-bold">Quét mã VietQR thanh toán nhanh 24/7 (Đợt tiếp theo)</h4>
                  <div className="text-on-surface-variant">Mở ứng dụng ngân hàng bất kỳ để quét mã.</div>
                  <div>
                    Số tiền còn lại <strong>{Math.round(b.amount_to_pay).toLocaleString('vi-VN')}đ</strong> và nội dung{' '}
                    <strong>{b.transfer_memo}</strong> đã được tích hợp tự động.
                  </div>
                </div>
              </div>
            )}
          </section>
        ) : b.qr_warning ? (
          <Alert>
            <strong>Chưa cấu hình tài khoản nhận tiền.</strong> {b.qr_warning}
          </Alert>
        ) : (
          <div className="rounded-xl bg-surface-container-low p-4">
            <strong>{b.pending_amount > 0 ? 'Khoản thu đang chờ đối soát.' : '✓ Học viên đã hoàn tất học phí.'}</strong>
            <div className="text-on-surface-variant">Bill này không phát sinh mã thanh toán mới.</div>
          </div>
        )}
      </main>
    </div>
  )
}
