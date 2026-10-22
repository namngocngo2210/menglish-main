// Lương của tôi (Payroll/MySalary.vue) và Xếp hạng KPI & Thưởng (Payroll/KpiLeaderboard.vue).
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Card, Select } from '../../components/ui'
import { navigate, useQueryParam } from '../../router'
import { payrollApi, type LeaderboardFilter } from './api'
import { money, trimNumber, vnd } from './logic'
import { Header, Pager, PayslipLines, Stat, td, th } from './parts'

export function MySalaryPage() {
  const periodId = useQueryParam('period_id')
  const page = useQuery({
    queryKey: ['payroll', 'my-salary', periodId],
    queryFn: () => payrollApi.mySalary(periodId),
    placeholderData: keepPreviousData,
  })
  const [cls, setCls] = useState('')
  const [showAll, setShowAll] = useState(false)
  const d = page.data
  const classes = useMemo(() => [...new Set((d?.timesheets ?? []).map((t) => t.class).filter(Boolean))], [d])
  if (!d) return page.isError ? <Alert>{errorMessage(page.error)}</Alert> : <p className="text-sm text-on-surface-variant">Đang tải…</p>
  const r = d.record
  const deductionRows = (r?.deduction_lines ?? []).filter((l) => {
    if (l.key === 'penalty_deduction' && d.penalties.length) return false
    return l.amount !== 0 || l.key === 'penalty_deduction'
  })
  const sheets = d.timesheets.filter((t) => !cls || t.class === cls)
  const shown = showAll ? sheets : sheets.slice(0, 10)
  return (
    <div className="space-y-6">
      <Header
        title="Lương của tôi"
        description={r ? `Kỳ lương hiện tại: ${r.period_status_label} — ${r.period_title}` : 'Tra cứu phiếu lương cá nhân đã được duyệt.'}
        actions={
          <span className="flex flex-wrap items-end gap-2 print:hidden">
            {d.period_options.length > 0 && (
              <Select label="Kỳ lương" value={r?.payroll_period_id ?? ''} onChange={(e) => navigate(`/portal/my-salary?period_id=${e.target.value}`)}>
                {d.period_options.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
            )}
            {r && (
              <Button variant="secondary" onClick={() => window.print()}>
                Tải phiếu lương
              </Button>
            )}
          </span>
        }
      />
      {!r ? (
        <Card>
          <div className="py-8 text-center text-on-surface-variant">
            <p className="font-medium text-on-surface">Chưa có kỳ lương đã duyệt</p>
            Phiếu lương chỉ hiển thị sau khi Giám đốc duyệt bảng lương của kỳ.
          </div>
        </Card>
      ) : (
        <>
          <div className="grid gap-3 md:grid-cols-3">
            <Stat
              label="Tổng thu nhập"
              value={money(r.gross_income)}
              icon="account_balance_wallet"
              hint={r.change === null ? 'Chưa có kỳ trước để so sánh' : `${r.change >= 0 ? '+' : ''}${r.change}% so với kỳ trước`}
            />
            <Stat
              label="Khấu trừ"
              value={`-${money(r.total_deductions)}`}
              tone="error"
              icon="money_off"
              hint={r.is_full_time ? 'BHXH, Công đoàn, thuế, phạt…' : 'Phạt, khấu trừ khác'}
            />
            <Stat label="Thực nhận" value={money(r.net_salary)} tone="primary" icon="payments" hint={r.period_status_label} />
          </div>
          <div className="grid items-start gap-6 lg:grid-cols-2">
            <PayslipLines title="Chi tiết thu nhập" lines={r.earning_lines} total={r.gross_income} totalLabel="Tổng thu nhập" showQuantity />
            <div className="space-y-4">
              <PayslipLines title="Các khoản trừ" lines={deductionRows} total={r.total_deductions} totalLabel="Tổng khấu trừ" negative />
              {d.penalties.length > 0 && (
                <Card title="Biên bản phạt trừ vào kỳ">
                  <ul className="space-y-1 text-sm">
                    {d.penalties.map((x) => (
                      <li key={x.id}>
                        {x.violation_date} — {x.violation_type} <span className="font-mono text-xs">({x.code})</span>:{' '}
                        <span className="text-error">-{money(x.amount)}</span>
                      </li>
                    ))}
                  </ul>
                </Card>
              )}
              {r.adjustment_notes && <Alert tone="info">{r.adjustment_notes}</Alert>}
            </div>
          </div>
          <Card title="Buổi dạy hợp lệ trong kỳ">
            {d.attendance_pending && <Alert tone="info">Chờ phân hệ Chấm công: chưa có ca dạy hợp lệ để hiện chi tiết.</Alert>}
            {classes.length > 1 && (
              <div className="mb-2 max-w-xs">
                <Select label="Lớp" value={cls} onChange={(e) => setCls(e.target.value)}>
                  <option value="">Tất cả lớp</option>
                  {classes.map((c) => (
                    <option key={c} value={c}>
                      {c}
                    </option>
                  ))}
                </Select>
              </div>
            )}
            <div className="overflow-x-auto">
              <table className="w-full min-w-[520px] text-left text-sm">
                <thead className="text-xs text-on-surface-variant">
                  <tr>
                    <th className={th}>Ngày</th>
                    <th className={th}>Ca</th>
                    <th className={th}>Lớp</th>
                    <th className={`${th} text-right`}>Giờ</th>
                    <th className={`${th} text-right`}>Thành tiền</th>
                  </tr>
                </thead>
                <tbody>
                  {shown.map((t) => (
                    <tr key={t.id} className="border-t border-surface-container">
                      <td className={td}>{t.date}</td>
                      <td className={td}>{t.scheduled_time}</td>
                      <td className={td}>{t.class}</td>
                      <td className={`${td} text-right`}>{trimNumber(t.hours)}</td>
                      <td className={`${td} text-right font-mono`}>{money(t.amount)}</td>
                    </tr>
                  ))}
                  {sheets.length === 0 && (
                    <tr>
                      <td colSpan={5} className="py-4 text-center text-on-surface-variant">
                        Không có buổi dạy nào.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
            {sheets.length > 10 && (
              <Button variant="secondary" className="mt-2 h-8" onClick={() => setShowAll(!showAll)}>
                {showAll ? 'Thu gọn' : `Xem thêm ${sheets.length - 10} buổi`}
              </Button>
            )}
          </Card>
        </>
      )}
    </div>
  )
}

export function LeaderboardPage() {
  const [filter, setFilter] = useState<LeaderboardFilter>({
    period: '',
    branch_id: '',
    kpi_page: 1,
    sales_page: 1,
  })
  const page = useQuery({
    queryKey: ['payroll', 'leaderboard', filter],
    queryFn: () => payrollApi.leaderboard(filter),
    placeholderData: keepPreviousData,
  })
  const d = page.data
  return (
    <div className="space-y-6">
      <Header title="Xếp hạng KPI & Thưởng" description="Bảng xếp hạng giữ học sinh của giáo viên Part-time và hoa hồng tuyển sinh của tư vấn theo tháng." />
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {d && (
        <>
          <Card>
            <div className="grid gap-3 sm:grid-cols-2">
              <Select
                label="Tháng"
                value={d.selected_period}
                onChange={(e) =>
                  setFilter({
                    ...filter,
                    period: e.target.value,
                    kpi_page: 1,
                    sales_page: 1,
                  })
                }
              >
                {d.period_options.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
              <Select
                label="Cơ sở"
                value={d.branch_id ?? ''}
                onChange={(e) =>
                  setFilter({
                    ...filter,
                    branch_id: e.target.value,
                    kpi_page: 1,
                    sales_page: 1,
                  })
                }
              >
                <option value="">Toàn hệ thống</option>
                {d.branches.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
            </div>
            {d.period && !d.period.locked && <p className="mt-2 text-xs text-on-surface-variant">Kỳ lương tháng này chưa chốt — số liệu tạm tính.</p>}
          </Card>
          <Card title={`Giữ học sinh — Giáo viên Part-time (tháng ${d.month}/${d.year})`}>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[560px] text-left text-sm">
                <thead className="text-xs text-on-surface-variant">
                  <tr>
                    <th className={th}>#</th>
                    <th className={th}>Giáo viên</th>
                    <th className={`${th} text-right`}>Giữ HS</th>
                    {/* Số tiền chỉ người xem được bảng lương thấy; API trả null cho người khác. */}
                    {d.show_money && <th className={`${th} text-right`}>Bậc</th>}
                    {d.show_money && <th className={`${th} text-right`}>Thưởng KPI</th>}
                  </tr>
                </thead>
                <tbody>
                  {d.retention.map((r, i) => (
                    <tr key={r.id} className="border-t border-surface-container">
                      <td className={td}>{(d.retention_paging.current_page - 1) * d.retention_paging.per_page + i + 1}</td>
                      <td className={td}>
                        <p className="font-semibold">{r.name}</p>
                        <p className="text-xs text-on-surface-variant">{r.branch}</p>
                      </td>
                      <td className={`${td} text-right`}>
                        {r.retention_students}/{r.retention_base_students}
                      </td>
                      {d.show_money && <td className={`${td} text-right`}>{r.retention_tier !== null ? `${vnd(r.retention_tier)}đ` : '—'}</td>}
                      {d.show_money && <td className={`${td} text-right font-mono font-semibold text-primary`}>{money(r.kpi_bonus ?? 0)}</td>}
                    </tr>
                  ))}
                  {d.retention.length === 0 && (
                    <tr>
                      <td colSpan={d.show_money ? 5 : 3} className="py-4 text-center text-on-surface-variant">
                        Chưa có phiếu lương Part-time trong tháng.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
            <Pager paging={d.retention_paging} unit="giáo viên" onPage={(p) => setFilter({ ...filter, kpi_page: p })} />
          </Card>
          <Card title={`Hoa hồng tuyển sinh — Tư vấn (tháng ${d.month}/${d.year})`}>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[720px] text-left text-sm">
                <thead className="text-xs text-on-surface-variant">
                  <tr>
                    <th className={th}>#</th>
                    <th className={th}>Tư vấn</th>
                    <th className={th}>Mốc</th>
                    <th className={`${th} text-right`}>HS chốt</th>
                    <th className={`${th} text-right`}>Phiếu thu</th>
                    {d.show_money && <th className={`${th} text-right`}>Thực thu</th>}
                    {d.show_money && <th className={`${th} text-right`}>Hoa hồng</th>}
                  </tr>
                </thead>
                <tbody>
                  {d.sales.map((s, i) => (
                    <tr key={s.id} className="border-t border-surface-container align-top">
                      <td className={td}>{(d.sales_paging.current_page - 1) * d.sales_paging.per_page + i + 1}</td>
                      <td className={td}>
                        <p className="font-semibold">{s.name}</p>
                        <p className="text-xs text-on-surface-variant">{s.branch}</p>
                      </td>
                      <td className={td}>
                        <Badge tone="info">
                          {s.tier_name} · {trimNumber(s.percent)}%
                        </Badge>
                        {s.next_percent !== null && s.to_next !== null && (
                          <p className="mt-1 text-xs text-on-surface-variant">
                            Còn {s.to_next} HS lên {trimNumber(s.next_percent)}%
                          </p>
                        )}
                      </td>
                      <td className={`${td} text-right`}>{s.closed}</td>
                      <td className={`${td} text-right`}>{s.deals}</td>
                      {d.show_money && <td className={`${td} text-right font-mono`}>{money(s.revenue ?? 0)}</td>}
                      {d.show_money && <td className={`${td} text-right font-mono font-semibold text-primary`}>{money(s.commission ?? 0)}</td>}
                    </tr>
                  ))}
                  {d.sales.length === 0 && (
                    <tr>
                      <td colSpan={d.show_money ? 7 : 5} className="py-4 text-center text-on-surface-variant">
                        Chưa có phiếu thu tính hoa hồng trong tháng.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
            <Pager paging={d.sales_paging} unit="tư vấn" onPage={(p) => setFilter({ ...filter, sales_page: p })} />
          </Card>
        </>
      )}
    </div>
  )
}
