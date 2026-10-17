// Bảng lương theo kỳ (Payroll/Periods.vue, Show.vue, Department.vue): danh sách kỳ + khởi tạo kỳ mới; /payroll/periods/<id>
// là bảng lương một kỳ (lịch chốt, thống kê, lọc, Đồng bộ & Tính lại / Chốt / Đánh dấu đã trả, xuất CSV);
// /payroll/periods/<id>/departments/<khối> là bảng lương theo khối.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Dialog, Field, Icon, Select } from '../../components/ui'
import { can } from '../../nav'
import { navigate, usePath } from '../../router'
import { payrollApi, type PeriodFilter, type RecordRow } from './api'
import { kpiTone, money, numOrNull, pad2, periodBadge, statusTone, trimNumber, vnd } from './logic'
import { Header, Pager, Stat, Textarea, td, th } from './parts'

export function PeriodsRoute({ me }: { me: Me }) {
  const path = usePath()
  const m = path.match(/^\/payroll\/periods\/([^/]+)(?:\/departments\/([^/]+))?$/)
  if (m && m[2]) return <DepartmentView id={m[1]} department={m[2]} />
  if (m) return <PeriodDetail key={m[1]} id={m[1]} me={me} />
  return <PeriodsPage me={me} />
}

function PeriodsPage({ me }: { me: Me }) {
  const [filter, setFilter] = useState({ search: '', status: '', page: 1 })
  const [search, setSearch] = useState('')
  const [creating, setCreating] = useState(false)
  const page = useQuery({
    queryKey: ['payroll', 'periods', filter],
    queryFn: () => payrollApi.periods(filter),
    placeholderData: keepPreviousData,
  })
  const d = page.data
  return (
    <div className="space-y-6">
      <Header
        title="Bảng lương theo kỳ"
        description="Mỗi tháng một kỳ lương: khởi tạo kỳ tự tính từ chấm công, hoa hồng, KPI và biên bản phạt; soát, chốt rồi đánh dấu đã trả."
        actions={
          can(me, 'payroll.create') && (
            <Button onClick={() => setCreating(true)}>
              <Icon name="add_circle" className="mr-1 text-[18px]" />
              Khởi tạo kỳ lương
            </Button>
          )
        }
      />
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      <Card>
        <form
          className="grid gap-3 sm:grid-cols-[1fr_200px_auto] sm:items-end"
          role="search"
          onSubmit={(e) => {
            e.preventDefault()
            setFilter({ ...filter, search, page: 1 })
          }}
        >
          <Field label="Tìm kỳ lương" type="search" value={search} placeholder="Mã hoặc tên kỳ…" onChange={(e) => setSearch(e.target.value)} />
          <Select
            label="Trạng thái"
            value={filter.status}
            onChange={(e) =>
              setFilter({
                ...filter,
                status: e.target.value,
                page: 1,
              })
            }
          >
            <option value="">Tất cả trạng thái</option>
            {d?.status_options.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
          <Button type="submit" variant="secondary">
            Lọc
          </Button>
        </form>
        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[760px] text-left text-sm">
            <thead className="text-xs text-on-surface-variant">
              <tr>
                <th className={th}>Kỳ lương</th>
                <th className={th}>Thời gian</th>
                <th className={`${th} text-right`}>Nhân sự</th>
                <th className={`${th} text-right`}>Giờ dạy</th>
                <th className={`${th} text-right`}>Tổng chi</th>
                <th className={th}>Trạng thái</th>
              </tr>
            </thead>
            <tbody>
              {d?.periods.map((p) => (
                <tr
                  key={p.id}
                  className="cursor-pointer border-t border-surface-container hover:bg-surface-container-low"
                  tabIndex={0}
                  onClick={() => navigate(`/payroll/periods/${p.id}`)}
                  onKeyDown={(e) => e.key === 'Enter' && navigate(`/payroll/periods/${p.id}`)}
                >
                  <td className={td}>
                    <p className="font-semibold">{p.title}</p>
                    <p className="font-mono text-xs text-on-surface-variant">{p.code}</p>
                  </td>
                  <td className={`${td} whitespace-nowrap`}>
                    {p.start_date} → {p.end_date}
                  </td>
                  <td className={`${td} text-right`}>{p.staff}</td>
                  <td className={`${td} text-right font-mono`}>{trimNumber(p.hours)}</td>
                  <td className={`${td} text-right font-mono font-semibold text-primary`}>{money(p.amount)}</td>
                  <td className={td}>
                    <Badge tone={statusTone(p.status_color)}>{p.status_label}</Badge>
                  </td>
                </tr>
              ))}
              {d && d.periods.length === 0 && (
                <tr>
                  <td colSpan={6} className="py-8 text-center text-on-surface-variant">
                    <p className="font-medium text-on-surface">Chưa có kỳ lương nào</p>
                    Bấm "Khởi tạo kỳ lương" để tạo kỳ đầu tiên.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        {d && <Pager paging={d.paging} unit="kỳ" onPage={(p) => setFilter({ ...filter, page: p })} />}
      </Card>
      {creating && d && <CreateDialog month={d.default_month} year={d.default_year} onClose={() => setCreating(false)} />}
    </div>
  )
}

function CreateDialog({ month, year, onClose }: { month: number; year: number; onClose: () => void }) {
  const qc = useQueryClient()
  const [m, setM] = useState(String(month))
  const [y, setY] = useState(String(year))
  const create = useMutation({
    mutationFn: () => payrollApi.createPeriod(numOrNull(m), numOrNull(y)),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['payroll'] })
      navigate(`/payroll/periods/${r.id}`)
    },
  })
  return (
    <Dialog title="Khởi tạo kỳ lương" onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          create.mutate()
        }}
      >
        <p className="text-sm text-on-surface-variant">
          Hệ thống tạo kỳ "Bảng lương Tháng m/năm" và tự tính phiếu lương từ chấm công, hoa hồng, KPI và biên bản phạt của tháng.
        </p>
        {create.isError && <Alert>{errorMessage(create.error)}</Alert>}
        <div className="grid grid-cols-2 gap-3">
          <Select label="Tháng" value={m} onChange={(e) => setM(e.target.value)}>
            <option value="">Chọn tháng</option>
            {Array.from({ length: 12 }, (_, i) => (
              <option key={i + 1} value={i + 1}>
                Tháng {i + 1}
              </option>
            ))}
          </Select>
          <Field label="Năm" type="number" value={y} min={2025} max={2100} onChange={(e) => setY(e.target.value)} />
        </div>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={create.isPending}>
            {create.isPending ? 'Đang tính…' : 'Khởi tạo & tính lương'}
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

const kpiIcons: Record<string, string> = {
  done: 'check',
  pending: 'close',
  na: 'remove',
  self: 'insights',
}

const DEPARTMENT_TABS = [
  { key: 'all', label: 'Tất cả' },
  { key: 'parttime', label: 'GV Part-time' },
  { key: 'fulltime', label: 'Giáo viên Full-time' },
  { key: 'academic', label: 'Khối Học thuật' },
  { key: 'operations', label: 'Khối Học vụ & Vận hành' },
]

function PeriodTabs({ id, current, onAll }: { id: string; current: string; onAll?: (type: string) => void }) {
  return (
    <nav className="flex flex-wrap gap-2 border-b border-surface-container pb-2" aria-label="Bảng lương theo khối">
      {DEPARTMENT_TABS.map((t) => (
        <Button
          key={t.key}
          variant={current === t.key ? 'primary' : 'secondary'}
          className="h-8 px-3"
          onClick={() => {
            if (t.key === 'all' || t.key === 'parttime') {
              if (onAll) onAll(t.key === 'parttime' ? 'teacher_parttime' : '')
              else navigate(`/payroll/periods/${id}`)
            } else navigate(`/payroll/periods/${id}/departments/${t.key}`)
          }}
        >
          {t.label}
        </Button>
      ))}
    </nav>
  )
}

function PeriodDetail({ id, me }: { id: string; me: Me }) {
  const qc = useQueryClient()
  const [filter, setFilter] = useState<PeriodFilter>({
    search: '',
    type: '',
    kpi: '',
    page: 1,
  })
  const [search, setSearch] = useState('')
  const [notice, setNotice] = useState<{ tone: 'success' | 'error' | 'info'; text: string }[]>([])
  const [foreign, setForeign] = useState<RecordRow | null>(null)
  const page = useQuery({
    queryKey: ['payroll', 'period', id, filter],
    queryFn: () => payrollApi.period(id, filter),
    placeholderData: keepPreviousData,
  })
  const act = useMutation({
    mutationFn: async (kind: 'calculate' | 'approve' | 'paid') => {
      if (kind === 'calculate') return payrollApi.calculate(id)
      if (kind === 'approve') return payrollApi.approve(id)
      return payrollApi.markPaid(id)
    },
    onSuccess: (r) => {
      const out: typeof notice = [{ tone: 'success', text: r.message }]
      const warning = (r as { warning?: string | null }).warning
      if (warning) out.push({ tone: 'info', text: warning })
      setNotice(out)
      qc.invalidateQueries({ queryKey: ['payroll'] })
    },
    onError: (e) => setNotice([{ tone: 'error', text: errorMessage(e) }]),
  })
  const d = page.data
  if (!d) return page.isError ? <Alert>{errorMessage(page.error)}</Alert> : <p className="text-sm text-on-surface-variant">Đang tải…</p>
  const p = d.period
  const [statusText, statusColor] = periodBadge(p.status)
  const showPending = d.kpi_pending.count > 0 && !p.locked
  const set = (patch: Partial<PeriodFilter>) => setFilter({ ...filter, ...patch, page: patch.page ?? 1 })
  return (
    <div className="space-y-6">
      <nav className="text-sm text-on-surface-variant">
        <button type="button" className="hover:text-primary" onClick={() => navigate('/payroll/periods')}>
          Kỳ lương
        </button>{' '}
        / <span>{p.title}</span>
      </nav>
      <Header
        title={`Bảng lương tháng ${p.month}/${p.year}`}
        description={`${p.title} (${p.code}, ${p.start_date} → ${p.end_date})`}
        actions={
          <>
            {can(me, 'payroll.calculate') && !p.locked && (
              <Button variant="secondary" disabled={act.isPending} onClick={() => act.mutate('calculate')}>
                <Icon name="sync" className="mr-1 text-[18px]" />
                Đồng bộ &amp; Tính lại
              </Button>
            )}
            {can(me, 'payroll.approve') && !p.locked && (
              <Button
                disabled={act.isPending || d.kpi_pending.count > 0}
                title={d.kpi_pending.count > 0 ? `Còn ${d.kpi_pending.count} nhân sự chưa chốt KPI` : undefined}
                onClick={() => window.confirm(`Chốt toàn bộ ${p.title}? Dữ liệu lương kỳ này sẽ bị khóa.`) && act.mutate('approve')}
              >
                <Icon name="task_alt" className="mr-1 text-[18px]" />
                Chốt bảng lương
              </Button>
            )}
            {can(me, 'payroll.mark_paid') && p.status !== 'paid' && (
              <Button
                variant="secondary"
                disabled={act.isPending || p.status !== 'approved'}
                title={p.status !== 'approved' ? 'Chỉ kỳ lương đã chốt mới đánh dấu đã trả' : undefined}
                onClick={() => act.mutate('paid')}
              >
                <Icon name="payments" className="mr-1 text-[18px]" />
                Đánh dấu đã trả
              </Button>
            )}
            <a
              href={payrollApi.exportUrl(id)}
              className="inline-flex h-10 items-center rounded-lg border border-outline-variant bg-surface px-4 text-sm font-medium hover:bg-surface-container-low"
            >
              <Icon name="download" className="mr-1 text-[18px]" />
              Xuất Excel (CSV)
            </a>
          </>
        }
      />
      <div className="flex flex-wrap items-center gap-2">
        <Badge tone={statusColor}>{statusText}</Badge>
        <Badge tone={statusTone(p.next_color)}>{p.next_step}</Badge>
      </div>
      {notice.map((n) => (
        <Alert key={n.text} tone={n.tone}>
          {n.text}
        </Alert>
      ))}
      {d.attendance_pending && <Alert tone="info">{d.attendance_pending}</Alert>}
      <div className="grid gap-3 rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
        <CalendarCell label="Chốt KPI" value={`Từ ${p.calendar.kpi_close_on}`} hint="Ngày cuối tháng" />
        <CalendarCell label="Chốt công" value={`Hết ${p.calendar.attendance_close_on}`} hint={`Cuối tháng + ${p.calendar.close_after_days} ngày`} />
        <CalendarCell label="Chốt lỗi (trừ lỗi)" value={`Hết ${p.calendar.violation_close_on}`} hint={`Chốt bảng lương từ ${p.calendar.approve_from}`} />
        <CalendarCell label="Trả lương" value={`${p.calendar.pay_from} – ${p.calendar.pay_to}`} hint="Ngày 10–15 tháng sau" />
      </div>
      {!p.locked && !p.calendar.can_approve && (
        <Alert tone="info">Chưa tới hạn chốt công / chốt lỗi — chốt bảng lương từ ngày {p.calendar.approve_from}.</Alert>
      )}
      {showPending && (
        <Alert>
          <strong>
            Chưa thể chốt bảng lương kỳ {pad2(p.month)}/{p.year} do: Còn {d.kpi_pending.count} nhân sự chưa chốt KPI
          </strong>
          <br />
          {d.kpi_pending.names}
          {d.kpi_pending.count > 8 ? '…' : ''}. Chọn bậc KPI giữ HS / nhập KPI trên phiếu lương, hoặc chốt đánh giá KPI Học vụ tháng rồi bấm "Đồng bộ &amp; Tính
          lại".{' '}
          <button type="button" className="font-semibold underline" onClick={() => set({ kpi: 'pending' })}>
            Xem danh sách
          </button>
        </Alert>
      )}
      {d.missing_staff && d.missing_staff.count > 0 && (
        <Alert tone="info">
          <strong>{d.missing_staff.count} nhân sự chưa có phiếu lương kỳ này</strong>
          <p>Kỳ lương chỉ tạo phiếu cho người có khoản phát sinh (lương cơ bản, buổi dạy hợp lệ, hoa hồng).</p>
          <ul className="mt-1 list-disc pl-5">
            {d.missing_staff.items.map((s) => (
              <li key={s.id}>
                <span className="font-semibold">{s.name}</span> — {s.reason}
              </li>
            ))}
          </ul>
        </Alert>
      )}
      <PeriodTabs id={id} current={filter.type === 'teacher_parttime' ? 'parttime' : filter.type ? '' : 'all'} onAll={(type) => set({ type })} />
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Stat
          label="Tổng chi quỹ lương"
          value={money(d.stats.total_amount)}
          tone="primary"
          icon="account_balance_wallet"
          hint={`${d.stats.records} nhân sự nhận lương`}
        />
        <Stat
          label="Tổng buổi dạy (Part-time)"
          value={`${d.stats.parttime_sessions} buổi`}
          icon="event_available"
          hint={`${trimNumber(d.stats.hours)} giờ chấm công hợp lệ`}
        />
        <Stat
          label="Tổng thưởng KPI / Giữ học sinh"
          value={money(d.stats.kpi_bonus)}
          tone="success"
          icon="trending_up"
          hint="Giữ HS (PT) · KPI tự do · KPI Học vụ"
        />
        <Stat
          label="Tổng khấu trừ & Phạt"
          value={`-${money(d.stats.deductions)}`}
          tone="error"
          icon="money_off"
          hint="BHXH, Công đoàn, TNCN, phạt, thu hồi, khấu trừ tự do"
        />
      </div>
      <Card>
        <form
          className="grid gap-3 lg:grid-cols-[220px_1fr_200px_200px_auto] lg:items-end"
          role="search"
          onSubmit={(e) => {
            e.preventDefault()
            set({ search })
          }}
        >
          <Select label="Kỳ lương" value={id} onChange={(e) => navigate(`/payroll/periods/${e.target.value}`)}>
            {d.period_options.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
          <Field label="Tìm nhân sự" type="search" value={search} placeholder="Tìm giáo viên / nhân sự..." onChange={(e) => setSearch(e.target.value)} />
          <Select label="Loại nhân sự" value={filter.type} onChange={(e) => set({ type: e.target.value })}>
            <option value="">Mọi loại nhân sự</option>
            {d.type_options.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
          <Select label="Trạng thái KPI" value={filter.kpi} onChange={(e) => set({ kpi: e.target.value })}>
            <option value="">Mọi trạng thái KPI</option>
            <option value="pending">Chưa chốt KPI</option>
            <option value="done">Đã chốt KPI</option>
          </Select>
          <Button type="submit" variant="secondary">
            Lọc
          </Button>
        </form>
        <h2 className="mt-4 text-base font-semibold">
          Bảng lương từng nhân sự (Kỳ {p.month}/{p.year})
        </h2>
        <div className="mt-2 overflow-x-auto">
          <table className="w-full min-w-[1200px] text-left text-sm">
            <thead className="text-xs text-on-surface-variant">
              <tr>
                <th className={th}>Tên giáo viên / nhân sự</th>
                <th className={th}>Loại</th>
                <th className={th}>Trạng thái KPI</th>
                <th className={`${th} text-right`}>Lương CB / Buổi dạy</th>
                <th className={`${th} text-right`}>KPI</th>
                <th className={`${th} text-right`}>Buổi GVNN</th>
                <th className={`${th} text-right`}>Hoa hồng</th>
                <th className={`${th} text-right`}>Tái tục</th>
                <th className={`${th} text-right`}>Phụ cấp tự do</th>
                <th className={`${th} text-right`}>BHXH + CĐ</th>
                <th className={`${th} text-right`}>Thuế TNCN</th>
                <th className={`${th} text-right`}>Phạt &amp; trừ khác</th>
                <th className={`${th} text-right`}>Thực nhận</th>
              </tr>
            </thead>
            <tbody>
              {d.records.map((r) => (
                <tr key={r.id} className="border-t border-surface-container align-top">
                  <td className={td}>
                    <button
                      type="button"
                      className="text-left font-semibold hover:text-primary hover:underline"
                      onClick={() => navigate(`/payroll/records/${r.id}`)}
                    >
                      {r.name}
                    </button>
                    <p className="text-xs text-on-surface-variant">{r.employee_code || r.email}</p>
                  </td>
                  <td className={td}>
                    <Badge tone={r.is_part_time ? 'info' : 'neutral'}>{r.employee_type_label}</Badge>
                    <p className="mt-1 text-xs text-on-surface-variant">{r.salary_role_label}</p>
                  </td>
                  <td className={td}>
                    {r.kpi_state[1] ? (
                      <Badge tone={kpiTone[r.kpi_state[0]] ?? 'neutral'}>
                        <Icon name={kpiIcons[r.kpi_state[0]] ?? 'remove'} className="mr-0.5 text-[14px]" />
                        {r.kpi_state[1]}
                      </Badge>
                    ) : (
                      <span className="text-on-surface-variant">—</span>
                    )}
                  </td>
                  <td className={`${td} text-right font-mono`}>
                    {r.is_part_time ? (
                      <>
                        {money(r.teaching_salary)}
                        <p className="text-xs text-on-surface-variant">{r.teaching_sessions} buổi</p>
                      </>
                    ) : (
                      money(r.base_salary + r.teaching_salary)
                    )}
                  </td>
                  <td className={`${td} text-right font-mono`}>
                    {r.kpi_source === 'self' ? (
                      r.kpi_score !== null ? (
                        `${trimNumber(r.kpi_score)}%`
                      ) : (
                        '—'
                      )
                    ) : (
                      <>
                        {money(r.kpi_bonus)}
                        {r.kpi_source === 'retention' && (
                          <p className="text-xs text-on-surface-variant">
                            {r.retention_students} HS × {r.retention_tier !== null ? vnd(r.retention_tier) : 'chưa chọn bậc'}
                          </p>
                        )}
                        {r.kpi_source === 'academic_kpi' && (
                          <p className="text-xs text-on-surface-variant">{r.kpi_score !== null ? `${trimNumber(r.kpi_score)}% quỹ` : 'chưa chốt KPI'}</p>
                        )}
                      </>
                    )}
                  </td>
                  <td className={`${td} text-right font-mono whitespace-nowrap`}>
                    {r.is_part_time ? money(r.foreign_session_pay) : '—'}
                    {!p.locked && r.is_part_time && can(me, 'payroll.edit') && (
                      <button
                        type="button"
                        className="ml-1 text-on-surface-variant hover:text-primary"
                        aria-label="Sửa lương buổi GVNN"
                        onClick={() => setForeign(r)}
                      >
                        <Icon name="edit" className="text-[16px]" />
                      </button>
                    )}
                  </td>
                  <td className={`${td} text-right font-mono`}>
                    {money(r.commission_bonus)}
                    {r.commission_deferred > 0 && <p className="text-xs font-semibold text-warning">Hoãn {money(r.commission_deferred)}</p>}
                  </td>
                  <td className={`${td} text-right font-mono`}>{money(r.renew_bonus)}</td>
                  <td className={`${td} text-right font-mono`}>{money(r.free_allowance)}</td>
                  <td className={`${td} text-right font-mono text-error`}>-{money(r.insurance_deduction + r.union_deduction)}</td>
                  <td className={`${td} text-right font-mono text-error`}>-{money(r.tax_deduction)}</td>
                  <td className={`${td} text-right font-mono text-error`}>-{money(r.other_deductions)}</td>
                  <td className={`${td} text-right font-mono font-bold whitespace-nowrap text-primary`}>{money(r.net_salary)}</td>
                </tr>
              ))}
              {d.records.length === 0 && (
                <tr>
                  <td colSpan={13} className="py-8 text-center text-on-surface-variant">
                    <p className="font-medium text-on-surface">Chưa có chi tiết lương</p>
                    {d.filtered ? 'Không có nhân sự khớp bộ lọc.' : 'Bấm "Đồng bộ & Tính lại" để tính phiếu lương từ chấm công.'}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <Pager paging={d.paging} unit="nhân sự" onPage={(pg) => setFilter({ ...filter, page: pg })} />
      </Card>
      {foreign && <ForeignDialog r={foreign} onClose={() => setForeign(null)} onDone={(m) => setNotice([{ tone: 'success', text: m }])} />}
    </div>
  )
}

function CalendarCell({ label, value, hint }: { label: string; value: string; hint: string }) {
  return (
    <div>
      <p className="text-xs text-on-surface-variant">{label}</p>
      <p className="font-semibold">{value}</p>
      <p className="text-xs text-on-surface-variant">{hint}</p>
    </div>
  )
}

function ForeignDialog({ r, onClose, onDone }: { r: RecordRow; onClose: () => void; onDone: (msg: string) => void }) {
  const qc = useQueryClient()
  const [amount, setAmount] = useState(String(Math.trunc(r.foreign_session_pay)))
  const [notes, setNotes] = useState(r.adjustment_notes ?? '')
  const save = useMutation({
    mutationFn: () => payrollApi.foreignPay(r.id, numOrNull(amount), notes),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['payroll'] })
      onDone(res.message)
      onClose()
    },
  })
  return (
    <Dialog title="Lương buổi có GVNN" onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <p className="text-sm text-on-surface-variant">{r.name}</p>
        <Alert tone="info">
          <strong>Chờ BA chốt:</strong> cách tính lương buổi có GVNN chưa được xác nhận. Kế toán nhập tổng tiền cộng cho GV ({r.foreign_teacher_sessions_count}{' '}
          buổi có GVNN cùng lớp trong kỳ).
        </Alert>
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <Field label="Số tiền (VNĐ)" type="number" min={0} step={1000} value={amount} onChange={(e) => setAmount(e.target.value)} required />
        <Textarea label="Ghi chú" value={notes} onChange={setNotes} rows={2} placeholder="Ghi chú thêm..." />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            Lưu
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function DepartmentView({ id, department }: { id: string; department: string }) {
  const page = useQuery({
    queryKey: ['payroll', 'department', id, department],
    queryFn: () => payrollApi.department(id, department),
  })
  const d = page.data
  if (!d) return page.isError ? <Alert>{errorMessage(page.error)}</Alert> : <p className="text-sm text-on-surface-variant">Đang tải…</p>
  const net = d.records.reduce((s, r) => s + r.net_salary, 0)
  return (
    <div className="space-y-6">
      <nav className="text-sm text-on-surface-variant">
        <button type="button" className="hover:text-primary" onClick={() => navigate('/payroll/periods')}>
          Kỳ lương
        </button>{' '}
        /{' '}
        <button type="button" className="hover:text-primary" onClick={() => navigate(`/payroll/periods/${id}`)}>
          {d.period.title}
        </button>{' '}
        / <span>{d.label}</span>
      </nav>
      <Header
        title={`Bảng lương ${d.label.toUpperCase()} — Tháng ${pad2(d.period.month)}/${d.period.year}`}
        actions={
          <a
            href={payrollApi.exportUrl(id, department)}
            className="inline-flex h-10 items-center rounded-lg border border-outline-variant bg-surface px-4 text-sm font-medium hover:bg-surface-container-low"
          >
            <Icon name="download" className="mr-1 text-[18px]" />
            Xuất Excel (CSV)
          </a>
        }
      />
      <PeriodTabs id={id} current={department} />
      <Card>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[960px] text-left text-sm">
            <thead className="text-xs text-on-surface-variant">
              <tr>
                <th className={th}>STT</th>
                <th className={th}>Họ tên</th>
                <th className={th}>Vai trò lương</th>
                <th className={`${th} text-right`}>Lương cơ bản</th>
                <th className={`${th} text-right`}>Đứng lớp / Buổi dạy</th>
                <th className={`${th} text-right`}>KPI</th>
                <th className={`${th} text-right`}>Hoa hồng + Tái tục</th>
                <th className={`${th} text-right`}>Tổng thu nhập</th>
                <th className={`${th} text-right`}>Khấu trừ</th>
                <th className={`${th} text-right`}>Thực lĩnh</th>
              </tr>
            </thead>
            <tbody>
              {d.records.map((r, i) => (
                <tr key={r.id} className="border-t border-surface-container">
                  <td className={td}>{i + 1}</td>
                  <td className={td}>
                    <button type="button" className="font-semibold hover:text-primary hover:underline" onClick={() => navigate(`/payroll/records/${r.id}`)}>
                      {r.name}
                    </button>
                  </td>
                  <td className={td}>{r.salary_role_label}</td>
                  <td className={`${td} text-right font-mono`}>{money(r.base_salary)}</td>
                  <td className={`${td} text-right font-mono`}>{money(r.teaching_salary)}</td>
                  <td className={`${td} text-right font-mono`}>{money(r.kpi_bonus + r.teaching_kpi_bonus)}</td>
                  <td className={`${td} text-right font-mono`}>{money(r.commission_bonus + r.renew_bonus)}</td>
                  <td className={`${td} text-right font-mono`}>{money(r.gross_income)}</td>
                  <td className={`${td} text-right font-mono text-error`}>-{money(r.total_deductions)}</td>
                  <td className={`${td} text-right font-mono font-bold text-primary`}>{money(r.net_salary)}</td>
                </tr>
              ))}
              {d.records.length === 0 && (
                <tr>
                  <td colSpan={10} className="py-8 text-center text-on-surface-variant">
                    Khối này chưa có phiếu lương trong kỳ.
                  </td>
                </tr>
              )}
            </tbody>
            {d.records.length > 0 && (
              <tfoot>
                <tr className="border-t-2 border-surface-container-highest font-semibold">
                  <td className={td} colSpan={9}>
                    Tổng thực lĩnh ({d.records.length} nhân sự)
                  </td>
                  <td className={`${td} text-right font-mono text-primary`}>{money(net)}</td>
                </tr>
              </tfoot>
            )}
          </table>
        </div>
      </Card>
    </div>
  )
}
