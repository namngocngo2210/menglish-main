// Phiếu lương từng người (Payroll/Record.vue): mẫu theo loại nhân sự (GV Part-time, GV Full-time, Học vụ, Học thuật, nhân sự
// khác), bảng khoản cộng / trừ, chi tiết buổi dạy / đi muộn / KPI / hoa hồng / phạt; kỳ chưa khóa + payroll.edit thì sửa các
// khoản nhập tay (giữ qua Đồng bộ & Tính lại). "In phiếu lương" dùng bản in của trình duyệt.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Field, Icon, Select } from '../../components/ui'
import { can } from '../../nav'
import { navigate, usePath } from '../../router'
import { payrollApi, type CommissionItemRow, type RecordPage as Page } from './api'
import { adjustDraft, adjustInput, draftDelta, kpiTone, money, pad2, periodBadge, trimNumber, vnd, type AdjustDraft } from './logic'
import { Header, PayslipLines, Textarea, td, th } from './parts'

export function RecordRoute({ me }: { me: Me }) {
  const path = usePath()
  const id = path.split('/')[3] ?? ''
  const page = useQuery({
    queryKey: ['payroll', 'record', id],
    queryFn: () => payrollApi.record(id),
    enabled: id !== '',
  })
  if (!page.data) return page.isError ? <Alert>{errorMessage(page.error)}</Alert> : <p className="text-sm text-on-surface-variant">Đang tải…</p>
  return <RecordView key={page.dataUpdatedAt} me={me} p={page.data} />
}

function RecordView({ me, p }: { me: Me; p: Page }) {
  const qc = useQueryClient()
  const r = p.record
  const [draft, setDraft] = useState<AdjustDraft>(() => adjustDraft(p))
  const [notice, setNotice] = useState('')
  const [showAll, setShowAll] = useState(false)
  const save = useMutation({
    mutationFn: () => payrollApi.adjust(r.id, adjustInput(p, draft)),
    onSuccess: (res) => {
      setNotice(res.message)
      qc.invalidateQueries({ queryKey: ['payroll'] })
    },
  })
  const [statusText, statusColor] = periodBadge(p.period.status)
  const set = (patch: Partial<AdjustDraft>) => setDraft({ ...draft, ...patch })
  const setLine = (i: number, patch: Partial<AdjustDraft['lines'][number]>) =>
    set({
      lines: draft.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)),
    })
  const delta = draftDelta(draft.lines)
  const sessions = showAll ? p.timesheets : p.timesheets.slice(0, 10)
  const usesRetention = r.kpi_source === 'retention' || r.teaching_concurrent
  return (
    <div className="space-y-6">
      <nav className="text-sm text-on-surface-variant print:hidden">
        <button type="button" className="hover:text-primary" onClick={() => navigate(`/payroll/periods/${p.period.id}`)}>
          <Icon name="arrow_back" className="mr-1 align-middle text-[16px]" />
          Quay lại danh sách
        </button>{' '}
        / <span>{p.period.title}</span>
      </nav>
      <Header
        title={p.variant.title}
        actions={
          <span className="print:hidden">
            <Button variant="secondary" onClick={() => window.print()}>
              <Icon name="picture_as_pdf" className="mr-1 text-[18px]" />
              In phiếu lương / Xuất PDF
            </Button>
          </span>
        }
      />
      <div className="flex flex-wrap items-center gap-3 rounded-xl border border-outline-variant bg-surface p-4">
        <div className="min-w-0">
          <h2 className="text-lg font-semibold">Phiếu lương: {r.name || 'Nhân sự'}</h2>
          <div className="mt-1 flex flex-wrap items-center gap-3 text-sm text-on-surface-variant">
            <span>{r.employee_code || 'Chưa có mã NV'}</span>
            <span>
              Kỳ lương {pad2(p.period.month)}/{p.period.year}
            </span>
            <span>
              {p.variant.type} · {r.employee_type_label}
            </span>
            <Badge tone={statusColor}>{statusText}</Badge>
            {r.kpi_state[1] && <Badge tone={kpiTone[r.kpi_state[0]] ?? 'neutral'}>{r.kpi_state[1]}</Badge>}
          </div>
        </div>
      </div>
      {notice && <Alert tone="success">{notice}</Alert>}
      {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
      {r.uses_q3 && (
        <Alert tone="info">
          {r.is_part_time ? (
            <>
              Công thức Part-time: <strong>số buổi × đơn giá buổi riêng + KPI giữ HS + buổi có GVNN + phụ cấp tự do − khoản trừ</strong>. Không trừ BHXH, Công
              đoàn, thuế TNCN.
            </>
          ) : (
            <>
              Công thức Full-time:{' '}
              <strong>
                lương cơ bản + các khoản cộng − BHXH {trimNumber(r.rate_insurance)}% − Công đoàn {trimNumber(r.rate_union)}% − thuế TNCN − phạt − khấu trừ
              </strong>
              .
            </>
          )}
        </Alert>
      )}
      <div className="grid items-start gap-6 lg:grid-cols-12">
        <div className="space-y-6 lg:col-span-8">
          <PayslipLines title="Các khoản thu nhập" lines={r.earning_lines} total={r.gross_income} totalLabel="Tổng thu nhập" />
          <PayslipLines title="Các khoản khấu trừ" lines={r.deduction_lines} total={r.total_deductions} totalLabel="Tổng khấu trừ" negative />

          {(r.is_part_time || r.teaching_concurrent) && (
            <Section title={`Chi tiết buổi dạy hợp lệ (${p.timesheets.length})`}>
              {p.attendance_pending && <Alert tone="info">Chờ phân hệ Chấm công: chưa có ca dạy hợp lệ để hiện chi tiết.</Alert>}
              {p.current_rate && (
                <p className="mb-2 text-sm text-on-surface-variant">
                  Đơn giá riêng hiện hành:{' '}
                  <strong>{p.current_rate.is_tuition_share ? `${trimNumber(p.current_rate.rate)}%` : money(p.current_rate.rate)}</strong>{' '}
                  {p.current_rate.unit_label} (từ {p.current_rate.effective_from})
                </p>
              )}
              <div className="overflow-x-auto">
                <table className="w-full min-w-[560px] text-left text-sm">
                  <thead className="text-xs text-on-surface-variant">
                    <tr>
                      <th className={th}>Ngày</th>
                      <th className={th}>Ca</th>
                      <th className={th}>Lớp</th>
                      <th className={`${th} text-right`}>Giờ</th>
                      <th className={`${th} text-right`}>Đơn giá</th>
                      <th className={`${th} text-right`}>Thành tiền</th>
                    </tr>
                  </thead>
                  <tbody>
                    {sessions.map((t) => (
                      <tr key={t.id} className="border-t border-surface-container">
                        <td className={td}>{t.date}</td>
                        <td className={td}>
                          {t.scheduled_time}
                          {t.type !== 'regular' && <p className="text-xs text-on-surface-variant">{t.type_label}</p>}
                        </td>
                        <td className={td}>{t.class}</td>
                        <td className={`${td} text-right`}>{trimNumber(t.hours)}</td>
                        <td className={`${td} text-right font-mono`}>
                          {vnd(t.rate)} {t.unit}
                        </td>
                        <td className={`${td} text-right font-mono`}>{money(t.amount)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {p.timesheets.length > 10 && (
                <Button variant="secondary" className="mt-2 h-8" onClick={() => setShowAll(!showAll)}>
                  {showAll ? 'Thu gọn' : `Xem tất cả ${p.timesheets.length} buổi`}
                </Button>
              )}
              {p.late_lines.length > 0 && (
                <div className="mt-3">
                  <p className="text-sm font-semibold">
                    Đi muộn / về sớm (ngưỡng {p.late_info.threshold} phút, trừ {vnd(p.late_info.per_minute)}đ/phút) — tổng trừ {money(p.late_info.total)}
                  </p>
                  <ul className="mt-1 list-disc pl-5 text-sm">
                    {p.late_lines.map((l, i) => (
                      <li key={i}>
                        {l.date} {l.class} — {l.minutes} phút:{' '}
                        {l.rule === 'notified' ? 'đã báo trước, trả theo phút dạy' : l.rule === 'void' ? 'không tính buổi' : 'trừ theo phút'} (−
                        {money(l.deduction)})
                      </li>
                    ))}
                  </ul>
                </div>
              )}
              {usesRetention && (
                <p className="mt-3 text-sm">
                  Giữ học sinh: <strong>{r.retention_students}</strong>/{r.retention_base_students} HS đầu kỳ (nghỉ {r.retention_lost})
                  {p.lost_students && <span className="text-on-surface-variant"> — HS nghỉ: {p.lost_students}</span>}
                </p>
              )}
            </Section>
          )}

          {p.kpi_groups.length > 0 && (
            <Section title={`KPI Học vụ — quỹ ${money(r.kpi_fund)}`}>
              <table className="w-full text-left text-sm">
                <thead className="text-xs text-on-surface-variant">
                  <tr>
                    <th className={th}>Tiêu chí</th>
                    <th className={`${th} text-right`}>Trọng số</th>
                    <th className={`${th} text-right`}>Điểm</th>
                    <th className={`${th} text-right`}>Thành tiền</th>
                  </tr>
                </thead>
                <tbody>
                  {p.kpi_groups.flatMap((g) => [
                    <tr key={`g-${g.group}`} className="border-t border-surface-container bg-surface-container-low font-semibold">
                      <td className={td}>{g.group || 'Khác'}</td>
                      <td className={`${td} text-right`}>{trimNumber(g.weight)}%</td>
                      <td className={td} />
                      <td className={`${td} text-right font-mono`}>{money(g.amount)}</td>
                    </tr>,
                    ...g.items.map((it) => (
                      <tr key={`${g.group}-${it.code}-${it.name}`} className="border-t border-surface-container">
                        <td className={td}>{it.name}</td>
                        <td className={`${td} text-right`}>{trimNumber(it.weight)}%</td>
                        <td className={`${td} text-right`}>{trimNumber(it.score)}%</td>
                        <td className={`${td} text-right font-mono`}>{money(it.amount)}</td>
                      </tr>
                    )),
                  ])}
                </tbody>
              </table>
            </Section>
          )}

          {(p.commission_tiers.length > 0 || p.paid_commission.length > 0 || p.deferred_commission.length > 0) && (
            <Section title={`Hoa hồng tuyển sinh — chốt ${r.commission_closed_count} HS trong kỳ`}>
              {p.commission_tiers.length > 0 && (
                <div className="mb-3 flex flex-wrap gap-2">
                  {p.commission_tiers.map((t) => (
                    <Badge key={t.id} tone={t.applied ? 'success' : 'neutral'}>
                      {t.name}: {t.range} · {trimNumber(t.percent)}%
                    </Badge>
                  ))}
                </div>
              )}
              <CommissionTable title="Khoản trả trong kỳ" rows={p.paid_commission} />
              {p.deferred_commission.length > 0 && <CommissionTable title="Khoản hoãn (chưa đạt gate 30 ngày + 3 mốc chăm sóc)" rows={p.deferred_commission} />}
            </Section>
          )}

          {p.renewal_classes.length > 0 && (
            <Section title="Thưởng tái tục theo lớp">
              <ul className="list-disc pl-5 text-sm">
                {p.renewal_classes.map((c, i) => (
                  <li key={i}>
                    {c.name}: {c.quits ?? 0} HS nghỉ · {trimNumber(c.percent ?? 0)}% doanh thu {money(c.revenue ?? 0)} = <strong>{money(c.amount ?? 0)}</strong>
                  </li>
                ))}
              </ul>
            </Section>
          )}

          {(p.penalties.length > 0 || p.clawbacks.length > 0) && (
            <Section title="Phạt & thu hồi hoa hồng trừ vào kỳ">
              <ul className="space-y-1 text-sm">
                {p.penalties.map((x) => (
                  <li key={x.id}>
                    <span className="font-mono">{x.code}</span> — {x.violation_type} ({x.violation_date}
                    {x.due_date ? `, hạn nộp ${x.due_date}` : ''}
                    ): <span className="text-error">-{money(x.amount)}</span>
                  </li>
                ))}
                {p.clawbacks.map((x) => (
                  <li key={x.id}>
                    {x.reason}: <span className="text-error">{money(x.amount)}</span>
                  </li>
                ))}
              </ul>
            </Section>
          )}
        </div>

        <aside className="space-y-4 lg:col-span-4">
          <div className="rounded-xl border border-primary/30 bg-surface p-4 shadow-sm">
            <p className="text-xs text-on-surface-variant">Thực lĩnh</p>
            <p className="text-2xl font-bold text-primary">{money(r.net_salary)}</p>
            <p className="text-xs text-on-surface-variant">
              {money(r.gross_income)} − {money(r.total_deductions)}
            </p>
          </div>
          {p.can_edit && can(me, 'payroll.edit') ? (
            <form
              className="space-y-3 rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm print:hidden"
              onSubmit={(e) => {
                e.preventDefault()
                setNotice('')
                save.mutate()
              }}
            >
              <h2 className="text-sm font-semibold">Khoản nhập tay</h2>
              {usesRetention && (
                <Select label="Bậc KPI giữ học sinh (đ/HS)" value={draft.retention_tier} onChange={(e) => set({ retention_tier: e.target.value })}>
                  <option value="">Chưa chọn bậc</option>
                  {p.retention_tiers.map((t) => (
                    <option key={t} value={t}>
                      {vnd(t)}đ / HS
                    </option>
                  ))}
                </Select>
              )}
              {r.kpi_source === 'manual' && (
                <Field
                  label="KPI (nhập tự do, VNĐ)"
                  type="number"
                  min={0}
                  step={1000}
                  value={draft.kpi_manual_amount}
                  hint="Nhập 0 nếu kỳ này không có KPI — bắt buộc trước khi chốt bảng lương."
                  onChange={(e) =>
                    set({
                      kpi_manual_amount: e.target.value,
                    })
                  }
                />
              )}
              {r.is_part_time && (
                <Field
                  label="Buổi có GVNN (chờ BA chốt, VNĐ)"
                  type="number"
                  min={0}
                  step={1000}
                  value={draft.foreign_session_pay}
                  onChange={(e) =>
                    set({
                      foreign_session_pay: e.target.value,
                    })
                  }
                />
              )}
              {r.is_full_time && (
                <Field
                  label="Thuế TNCN (VNĐ)"
                  type="number"
                  min={0}
                  step={1000}
                  value={draft.tax_deduction}
                  onChange={(e) => set({ tax_deduction: e.target.value })}
                />
              )}
              <div className="space-y-2">
                <p className="text-sm font-medium">Khoản cộng / trừ tự do</p>
                {draft.lines.map((l, i) => (
                  <div key={i} className="grid grid-cols-[90px_1fr_110px_auto] items-center gap-1">
                    <select
                      aria-label="Loại khoản"
                      className="h-9 rounded-lg border border-outline-variant bg-surface px-1 text-sm"
                      value={l.kind}
                      onChange={(e) =>
                        setLine(i, {
                          kind: e.target.value,
                        })
                      }
                    >
                      <option value="earning">Cộng</option>
                      <option value="deduction">Trừ</option>
                    </select>
                    <input
                      aria-label="Tên khoản"
                      className="h-9 rounded-lg border border-outline-variant px-2 text-sm"
                      value={l.label}
                      placeholder="VD: Gửi xe"
                      onChange={(e) =>
                        setLine(i, {
                          label: e.target.value,
                        })
                      }
                    />
                    <input
                      aria-label="Số tiền"
                      type="number"
                      min={0}
                      step={1000}
                      className="h-9 rounded-lg border border-outline-variant px-2 text-right text-sm"
                      value={l.amount}
                      onChange={(e) =>
                        setLine(i, {
                          amount: e.target.value,
                        })
                      }
                    />
                    <button
                      type="button"
                      aria-label="Xóa khoản"
                      className="text-on-surface-variant hover:text-error"
                      onClick={() =>
                        set({
                          lines: draft.lines.filter((_, j) => j !== i),
                        })
                      }
                    >
                      <Icon name="delete" className="text-[18px]" />
                    </button>
                  </div>
                ))}
                <Button
                  type="button"
                  variant="secondary"
                  className="h-8"
                  onClick={() =>
                    set({
                      lines: [
                        ...draft.lines,
                        {
                          kind: 'earning',
                          label: '',
                          amount: '',
                        },
                      ],
                    })
                  }
                >
                  <Icon name="add" className="mr-1 text-[16px]" />
                  Thêm khoản
                </Button>
                <p className="text-xs text-on-surface-variant">
                  Tự do: +{money(delta.earning)} / −{money(delta.deduction)}. Khoản 0đ không được lưu.
                </p>
              </div>
              <Textarea label="Ghi chú điều chỉnh" value={draft.adjustment_notes} onChange={(v) => set({ adjustment_notes: v })} rows={2} />
              <Button type="submit" className="w-full" disabled={save.isPending}>
                Lưu điều chỉnh
              </Button>
            </form>
          ) : (
            r.adjustment_notes && (
              <div className="rounded-xl border border-surface-container-highest bg-surface p-4 text-sm">
                <p className="font-semibold">Ghi chú điều chỉnh</p>
                <p className="whitespace-pre-line">{r.adjustment_notes}</p>
              </div>
            )
          )}
          {r.notes && <p className="text-xs text-on-surface-variant">{r.notes}</p>}
        </aside>
      </div>
    </div>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm">
      <h2 className="mb-2 text-sm font-semibold">{title}</h2>
      {children}
    </section>
  )
}

function CommissionTable({ title, rows }: { title: string; rows: CommissionItemRow[] }) {
  return (
    <div className="mb-3 overflow-x-auto">
      <p className="mb-1 text-xs font-semibold text-on-surface-variant">{title}</p>
      <table className="w-full min-w-[560px] text-left text-sm">
        <thead className="text-xs text-on-surface-variant">
          <tr>
            <th className={th}>Học viên</th>
            <th className={th}>Phiếu thu</th>
            <th className={`${th} text-right`}>Thứ tự chốt</th>
            <th className={`${th} text-right`}>Căn cứ</th>
            <th className={`${th} text-right`}>%</th>
            <th className={`${th} text-right`}>Hoa hồng</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((x) => (
            <tr key={x.id} className="border-t border-surface-container align-top">
              <td className={td}>
                {x.student ?? '—'}
                {x.deferred_reason && <p className="text-xs text-warning">{x.deferred_reason}</p>}
              </td>
              <td className={`${td} font-mono`}>{x.receipt_number}</td>
              <td className={`${td} text-right`}>{x.closing_rank}</td>
              <td className={`${td} text-right font-mono`}>{money(x.base_amount)}</td>
              <td className={`${td} text-right`}>{trimNumber(x.percent)}</td>
              <td className={`${td} text-right font-mono`}>{money(x.amount)}</td>
            </tr>
          ))}
          {rows.length === 0 && (
            <tr>
              <td colSpan={6} className="py-2 text-on-surface-variant">
                Không có khoản nào.
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  )
}
