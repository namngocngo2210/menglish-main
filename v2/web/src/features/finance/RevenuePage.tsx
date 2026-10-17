// Báo cáo doanh thu tạm tính (Finance/Reports/ProvisionalRevenue.vue): Tổng thu (phiếu đã duyệt) − Chi vận hành (chi tự
// nhập + chi lương tự động), bóc tách nguồn thu / cơ cấu chi và ma trận so sánh hiệu quả giữa các chi nhánh.
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Card, Icon, Select } from '../../components/ui'
import { financeApi, type RevenueReport } from './api'
import { branchFilterOptions, marginTone, millions, money, monthLabel, vnd } from './logic'

export function RevenuePage() {
  const [month, setMonth] = useState('')
  const [branchId, setBranchId] = useState('')
  const report = useQuery({
    queryKey: ['finance', 'revenue', month, branchId],
    queryFn: () => financeApi.revenue(month, branchId),
    placeholderData: keepPreviousData,
  })
  const d = report.data

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-semibold">
            Báo cáo Doanh thu tạm tính <Badge tone="success">Tính toán thời gian thực</Badge>
          </h1>
          <p className="text-sm text-on-surface-variant">Tổng hợp đối soát Thu (học phí & phụ thu hợp lệ) trừ Chi vận hành (chi thường xuyên & chi lương)</p>
        </div>
        <span className="flex flex-wrap gap-2">
          <Button variant="secondary" onClick={() => report.refetch()} aria-label="Làm mới số liệu thời gian thực" title="Làm mới số liệu thời gian thực">
            <Icon name="sync" className="text-[18px]" />
          </Button>
          {d && (
            <a
              className="inline-flex h-10 items-center gap-1 rounded-lg border border-outline-variant px-4 text-sm font-medium hover:bg-surface-container-low"
              href={financeApi.revenueExportUrl(d.month, d.branch_id)}
            >
              <Icon name="download" className="text-[18px]" /> Xuất báo cáo
            </a>
          )}
        </span>
      </div>

      {report.isError && <Alert>{errorMessage(report.error)}</Alert>}
      {d?.revenue_pending && <Alert tone="info">{d.revenue_pending}</Alert>}
      {d?.payroll_pending && <Alert tone="info">{d.payroll_pending}</Alert>}

      {d && (
        <>
          <Card>
            <div className="grid gap-3 sm:grid-cols-2 lg:max-w-xl">
              <Select label="Kỳ tháng" value={d.month} onChange={(e) => setMonth(e.target.value)}>
                {d.month_options.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
              <Select label="Cơ sở" value={d.branch_id} onChange={(e) => setBranchId(e.target.value)}>
                {branchFilterOptions(d.branches, d.branch_scoped).map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
            </div>
          </Card>
          <Headline d={d} />
          <div className="grid gap-6 lg:grid-cols-2">
            <RevenueMix d={d} />
            <ExpenseMix d={d} />
          </div>
          <Matrix d={d} />
        </>
      )}
    </div>
  )
}

function Big({ label, icon, value, children, footer }: { label: string; icon: string; value: string; children: ReactNode; footer: ReactNode }) {
  return (
    <section className="flex flex-col justify-between rounded-xl border border-surface-container-highest bg-surface p-6 shadow-sm">
      <div>
        <div className="mb-3 flex items-center justify-between text-xs font-bold tracking-wider text-on-surface-variant uppercase">
          {label} <Icon name={icon} className="text-xl text-primary" />
        </div>
        <div className="flex items-baseline gap-2">
          <span className="text-3xl font-extrabold tracking-tight">{value}</span>
          <span className="font-bold text-on-surface-variant">VNĐ</span>
        </div>
        <div className="mt-2.5 text-xs font-medium">{children}</div>
      </div>
      <div className="mt-6 flex items-center justify-between border-t border-surface-container-highest pt-4 text-xs text-on-surface-variant">{footer}</div>
    </section>
  )
}

function Headline({ d }: { d: RevenueReport }) {
  return (
    <div className="grid gap-6 md:grid-cols-3">
      <Big
        label="Tổng thu thực tế"
        icon="payments"
        value={vnd(d.total_revenue)}
        footer={
          <>
            <span>Gồm {d.valid_receipts_count} phiếu thu hợp lệ</span>
            <i>Đã gồm phụ thu</i>
          </>
        }
      >
        <span className={d.revenue_diff_is_up ? 'text-success' : 'text-error'}>
          {d.revenue_diff_is_up ? '↑ Tăng' : '↓ Giảm'} {d.revenue_diff_percent}% so với tháng {d.prev_month_label}
        </span>
      </Big>
      <Big
        label="Chi phí vận hành"
        icon="receipt"
        value={vnd(d.total_expense)}
        footer={
          <>
            <span className="font-medium text-error">Chiếm {d.expense_percentage_of_revenue}% tổng thu</span>
            <span>{d.total_expense_items_count} mục chi</span>
          </>
        }
      >
        <span className="text-on-surface-variant">Khoản tự nhập + Lương kỳ {monthLabel(d.month)}</span>
      </Big>
      <Big
        label="Doanh thu tạm tính"
        icon="account_balance_wallet"
        value={`${d.is_profit_positive ? '+' : ''}${vnd(d.provisional_revenue)}`}
        footer={
          <>
            <span className="font-semibold">Tỷ suất lợi nhuận gộp ước tính:</span>
            <span className={`rounded-md px-2 py-0.5 text-sm font-black ${d.is_profit_positive ? 'bg-success-container' : 'bg-error-container'}`}>
              {d.gross_profit_margin}%
            </span>
          </>
        }
      >
        <span className="text-on-surface-variant">
          = Tổng thu ({millions(d.total_revenue, 1)}M) − Chi vận hành ({millions(d.total_expense, 2)}M)
        </span>
      </Big>
    </div>
  )
}

function Line({ title, hint, amount, percent, tag }: { title: string; hint: string; amount: number; percent: string; tag?: string }) {
  return (
    <div className="flex items-center justify-between gap-3 rounded-lg border border-surface-container-highest bg-surface-container-low p-3.5">
      <div>
        <p className="flex items-center gap-2 text-sm font-semibold">
          {title} {tag && <Badge tone="info">{tag}</Badge>}
        </p>
        <p className="text-xs text-on-surface-variant">{hint}</p>
      </div>
      <div className="text-right whitespace-nowrap">
        <p className="font-mono text-sm font-bold">{money(amount)}</p>
        <p className="text-xs text-on-surface-variant">{percent}</p>
      </div>
    </div>
  )
}

function RevenueMix({ d }: { d: RevenueReport }) {
  return (
    <Card
      title={
        <span className="flex items-center justify-between gap-2">
          <span>
            Cơ cấu Nguồn Thu
            <span className="block text-xs font-normal text-on-surface-variant">Tất cả phiếu thu còn hiệu lực (đã loại bỏ phiếu bị hủy hóa đơn)</span>
          </span>
          <span className="font-mono text-success">{money(d.total_revenue)}</span>
        </span>
      }
    >
      <div className="space-y-3">
        <Line
          title="Học phí các khóa học"
          hint="Phần học phí của các phiếu thu đã duyệt trong tháng"
          amount={d.tuition_revenue}
          percent={`${d.tuition_percent}%`}
        />
        <Line
          title="Phụ thu phát sinh"
          hint="Giáo trình in ấn bổ sung, lệ phí thi thử, thẻ học viên"
          amount={d.surcharge_revenue}
          percent={`${d.surcharge_percent}%`}
        />
        <div>
          <div className="mb-1.5 flex justify-between text-xs font-semibold text-on-surface-variant">
            <span>Hình thức: Chuyển khoản ({d.revenue_transfer_percent}%)</span>
            <span>Tiền mặt ({d.revenue_cash_percent}%)</span>
          </div>
          <div className="flex h-2 overflow-hidden rounded-full bg-surface-container-low" aria-hidden="true">
            <div className="h-full bg-success" style={{ width: `${d.revenue_transfer_percent}%` }} />
            <div className="h-full bg-success/40" style={{ width: `${d.revenue_cash_percent}%` }} />
          </div>
        </div>
        <Alert tone="success">
          <strong>Nguyên tắc ghi nhận doanh thu:</strong> Chỉ cộng phiếu thu <strong>đã duyệt</strong> theo ngày thu (phiếu nháp / chờ duyệt / bị từ chối chưa
          là doanh thu). Phiếu bị <strong>Hủy hóa đơn</strong> tự động bị loại trừ; phiếu hoàn phí / chuyển nhượng (số âm) được trừ vào tổng thu.
        </Alert>
      </div>
    </Card>
  )
}

function ExpenseMix({ d }: { d: RevenueReport }) {
  return (
    <Card
      title={
        <span className="flex items-center justify-between gap-2">
          <span>
            Cơ cấu Chi Vận Hành
            <span className="block text-xs font-normal text-on-surface-variant">Khoản chi tự nhập thực tế + Chi lương tự động từ bảng lương đã chốt</span>
          </span>
          <span className="font-mono text-error">{money(d.total_expense)}</span>
        </span>
      }
    >
      <div className="space-y-3">
        <Line
          title={`Chi trả lương nhân sự (Kỳ ${monthLabel(d.month)})`}
          tag="Tự động"
          hint="Tổng thực nhận của toàn bộ GV, TA, Học vụ đã chốt bảng"
          amount={d.salary_total}
          percent={`${d.salary_percent_of_expense}% chi phí`}
        />
        <Line
          title="Mặt bằng & Tiện ích"
          hint="Tiền thuê trụ sở, điện nước, internet cáp quang"
          amount={d.rent_utilities_expense}
          percent={`${d.rent_percent_of_expense}%`}
        />
        <Line
          title="In ấn giáo trình & Vận hành lớp"
          hint="Sách bổ trợ, văn phòng phẩm, nước uống, bảo dưỡng thiết bị"
          amount={d.curriculum_operations_expense}
          percent={`${d.curriculum_percent_of_expense}%`}
        />
        <Line title="Chi khác" hint="Các khoản chi không thuộc 2 nhóm trên" amount={d.other_expense} percent={`${d.other_percent_of_expense}%`} />
        <Alert tone="info">
          <strong>Quy tắc chuẩn hóa kỳ lương:</strong> Chi lương tự động tính theo <strong>kỳ lương khớp tháng đã chọn</strong>, kể cả khi ngày thanh toán thực
          tế lệch sang tháng dương lịch tiếp theo.
        </Alert>
      </div>
    </Card>
  )
}

function Matrix({ d }: { d: RevenueReport }) {
  const th = 'py-2 pr-4 font-medium'
  const signed = (n: number) => `${n > 0 ? '+' : ''}${vnd(n)}`
  return (
    <Card
      title={
        <span className="flex flex-wrap items-center justify-between gap-2">
          <span>
            So sánh Hiệu quả Doanh thu giữa các Chi nhánh
            <span className="block text-xs font-normal text-on-surface-variant">Tổng hợp đối soát chi tiết theo từng cơ sở đào tạo</span>
          </span>
          <span className="text-xs font-semibold text-on-surface-variant">Đang hiển thị {d.branch_matrix.length} chi nhánh đang hoạt động</span>
        </span>
      }
    >
      <div className="overflow-x-auto">
        <table className="w-full min-w-[720px] text-left text-sm">
          <thead className="text-xs text-on-surface-variant">
            <tr>
              <th className={th}>Chi nhánh / Cơ sở</th>
              <th className={`${th} text-right`}>Tổng thu</th>
              <th className={`${th} text-right`}>Chi vận hành</th>
              <th className={`${th} text-right`}>Doanh thu tạm tính</th>
              <th className={`${th} text-center`}>Tỷ suất gộp</th>
              <th className={`${th} text-center`}>Tình trạng</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-surface-container-highest">
            {d.branch_matrix.map((r) => (
              <tr key={r.branch.id}>
                <td className="py-2 pr-4 font-semibold">{r.branch.name}</td>
                <td className="py-2 pr-4 text-right font-mono text-success">{vnd(r.revenue)}</td>
                <td className="py-2 pr-4 text-right font-mono text-error">{vnd(r.expense)}</td>
                <td className="py-2 pr-4 text-right font-mono font-extrabold">{signed(r.profit)}</td>
                <td className="py-2 pr-4 text-center">
                  <Badge tone={marginTone(r.status)}>{r.margin}%</Badge>
                </td>
                <td className="py-2 pr-4 text-center text-xs font-semibold">{r.status.label}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="border-t-2 border-surface-container-highest bg-surface-container-low font-bold">
              <td className="py-2 pr-4 pl-2 text-xs uppercase">{d.branch_scoped ? 'Tổng cộng' : 'Tổng cộng toàn hệ thống'}</td>
              <td className="py-2 pr-4 text-right font-mono text-success">{vnd(d.total_matrix_revenue)}</td>
              <td className="py-2 pr-4 text-right font-mono text-error">{vnd(d.total_matrix_expense)}</td>
              <td className="py-2 pr-4 text-right font-mono">{signed(d.total_matrix_profit)}</td>
              <td className="py-2 pr-4 text-center text-xs">{d.total_matrix_margin}%</td>
              <td className="py-2 pr-4 text-center text-xs">{d.total_matrix_status.label}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </Card>
  )
}
