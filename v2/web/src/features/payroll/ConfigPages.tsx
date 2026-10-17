// Cài đặt › Lương (Payroll/Config/Settings.vue, Rates.vue, Commissions.vue): tham số tính lương, đơn giá giáo viên (bảng cấp
// bậc + phiên bản đơn giá riêng từng GV), mốc hoa hồng theo thứ tự chốt + bảng thưởng tái tục.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Card, Dialog, Field, Select } from '../../components/ui'
import {
  payrollApi,
  type Option,
  type RankRate,
  type RankRateInput,
  type RateHistoryRow,
  type RenewalRow,
  type Settings,
  type SettingsInput,
  type TeacherRow,
  type TierInput,
  type TierRow,
} from './api'
import { money, numOrNull, statusTone, trimNumber, vnd } from './logic'
import { Header, Pager, Textarea, td, th } from './parts'

type RenewalDraft = { quits: string; percent: string; pending: boolean }

const renewalDraft = (rows: RenewalRow[]): RenewalDraft[] =>
  rows.map((r) => ({
    quits: String(r.quits),
    percent: String(r.percent),
    pending: r.pending,
  }))
const renewalInput = (rows: RenewalDraft[]) =>
  rows.map((r) => ({
    quits: numOrNull(r.quits),
    percent: numOrNull(r.percent),
    pending: r.pending,
  }))

function RenewalEditor({ rows, onChange }: { rows: RenewalDraft[]; onChange: (rows: RenewalDraft[]) => void }) {
  const set = (i: number, patch: Partial<RenewalDraft>) => onChange(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)))
  return (
    <div className="space-y-2">
      <table className="w-full text-left text-sm">
        <thead className="text-xs text-on-surface-variant">
          <tr>
            <th className={th}>Số HS nghỉ</th>
            <th className={th}>% thưởng doanh thu lớp</th>
            <th className={th}>Chờ BA chốt</th>
            <th />
          </tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i} className="border-t border-surface-container">
              <td className={td}>
                <input
                  aria-label="Số HS nghỉ"
                  type="number"
                  min={0}
                  max={50}
                  className="h-9 w-24 rounded-lg border border-outline-variant px-2"
                  value={r.quits}
                  onChange={(e) => set(i, { quits: e.target.value })}
                />
              </td>
              <td className={td}>
                <input
                  aria-label="% thưởng"
                  type="number"
                  min={0}
                  max={100}
                  step={0.1}
                  className="h-9 w-24 rounded-lg border border-outline-variant px-2"
                  value={r.percent}
                  onChange={(e) => set(i, { percent: e.target.value })}
                />
              </td>
              <td className={td}>
                <input
                  type="checkbox"
                  aria-label="Chờ BA chốt"
                  className="accent-primary"
                  checked={r.pending}
                  onChange={(e) => set(i, { pending: e.target.checked })}
                />
              </td>
              <td className={td}>
                <Button type="button" variant="danger" className="h-8 px-2" onClick={() => onChange(rows.filter((_, j) => j !== i))}>
                  Xóa
                </Button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <Button type="button" variant="secondary" className="h-8" onClick={() => onChange([...rows, { quits: '', percent: '', pending: false }])}>
        Thêm mốc
      </Button>
    </div>
  )
}

export function SettingsPage() {
  const page = useQuery({
    queryKey: ['payroll', 'settings'],
    queryFn: payrollApi.settings,
  })
  if (!page.data) return page.isError ? <Alert>{errorMessage(page.error)}</Alert> : <p className="text-sm text-on-surface-variant">Đang tải…</p>
  return <SettingsForm key={page.dataUpdatedAt} s={page.data.settings} defaults={page.data.defaults} />
}

function SettingsForm({ s, defaults }: { s: Settings; defaults: Settings }) {
  const qc = useQueryClient()
  const [f, setF] = useState({
    insurance: String(s.insurance_rate_percent),
    union: String(s.union_rate_percent),
    fund: String(s.academic_kpi_fund),
    beyond: String(s.renewal_beyond_percent),
    threshold: String(s.late_threshold_minutes),
    perMinute: String(s.late_deduction_per_minute),
    share: String(s.teaching_share_percent),
  })
  const [renewal, setRenewal] = useState(renewalDraft(s.renewal))
  const [notice, setNotice] = useState('')
  const save = useMutation({
    mutationFn: () => {
      const input: SettingsInput = {
        insurance_rate_percent: numOrNull(f.insurance),
        union_rate_percent: numOrNull(f.union),
        academic_kpi_fund: numOrNull(f.fund),
        renewal: renewalInput(renewal),
        renewal_beyond_percent: numOrNull(f.beyond),
        late_threshold_minutes: numOrNull(f.threshold),
        late_deduction_per_minute: numOrNull(f.perMinute),
        teaching_share_percent: numOrNull(f.share),
      }
      return payrollApi.saveSettings(input)
    },
    onSuccess: (r) => {
      setNotice(r.message)
      qc.invalidateQueries({ queryKey: ['payroll', 'settings'] })
    },
  })
  const field = (key: keyof typeof f, label: string, hint: string, step = '0.1') => (
    <Field label={label} hint={hint} type="number" step={step} value={f[key]} onChange={(e) => setF({ ...f, [key]: e.target.value })} />
  )
  return (
    <form
      className="space-y-6"
      onSubmit={(e) => {
        e.preventDefault()
        setNotice('')
        save.mutate()
      }}
    >
      <Header
        title="Tham số lương"
        description="Tỉ lệ khấu trừ, quỹ KPI, quy tắc đi muộn và thưởng tái tục — áp dụng cho các lần tính / tính lại kỳ lương sau."
      />
      {notice && <Alert tone="success">{notice}</Alert>}
      {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
      <Card title="Khấu trừ & KPI Học vụ">
        <div className="grid gap-3 sm:grid-cols-3">
          {field('insurance', 'BHXH (% lương cơ bản)', `Mặc định ${trimNumber(defaults.insurance_rate_percent)}%`)}
          {field('union', 'Công đoàn (% lương cơ bản)', `Mặc định ${trimNumber(defaults.union_rate_percent)}%`)}
          {field('fund', 'Quỹ KPI Học vụ (VNĐ / tháng)', `Mặc định ${money(defaults.academic_kpi_fund)}`, '1000')}
        </div>
      </Card>
      <Card title="Đi muộn / về sớm (GV)">
        <div className="grid gap-3 sm:grid-cols-3">
          {field('threshold', 'Ngưỡng không tính buổi (phút)', 'Từ ngưỡng này không tính buổi dạy', '1')}
          {field('perMinute', 'Trừ mỗi phút đi muộn (đ)', 'Từ 4.000đ đến 5.000đ', '100')}
          {field('share', '% học phí kiêm nhiệm giảng dạy', 'Học thuật đứng lớp: % học phí mỗi buổi', '0.1')}
        </div>
        <p className="mt-2 text-xs text-on-surface-variant">Bậc KPI giữ học sinh: {s.retention_tiers.map((t) => `${vnd(t)}đ`).join(' / ')} mỗi HS / tháng.</p>
      </Card>
      <Card title="Thưởng tái tục (GV Full-time)">
        <RenewalEditor rows={renewal} onChange={setRenewal} />
        <div className="mt-3 max-w-xs">{field('beyond', '% thưởng khi số HS nghỉ vượt bảng', 'Thường là 0%')}</div>
      </Card>
      <div className="flex justify-end">
        <Button type="submit" disabled={save.isPending}>
          Lưu tham số
        </Button>
      </div>
    </form>
  )
}

export function RatesPage() {
  const qc = useQueryClient()
  const [teacherId, setTeacherId] = useState('')
  const [page, setPage] = useState(1)
  const [notice, setNotice] = useState('')
  const [editing, setEditing] = useState(false)
  const q = useQuery({
    queryKey: ['payroll', 'rates', teacherId, page],
    queryFn: () => payrollApi.rates(teacherId, page),
    placeholderData: keepPreviousData,
  })
  const d = q.data
  const done = (msg: string) => {
    setNotice(msg)
    qc.invalidateQueries({ queryKey: ['payroll', 'rates'] })
  }
  return (
    <div className="space-y-6">
      <Header
        title="Đơn giá giáo viên"
        description="Đơn giá riêng từng giáo viên theo phiên bản hiệu lực (đ/buổi, đ/giờ hoặc % học phí); bảng cấp bậc tham khảo."
        actions={<Button onClick={() => setEditing(true)}>Sửa bảng cấp bậc</Button>}
      />
      {notice && <Alert tone="success">{notice}</Alert>}
      {q.isError && <Alert>{errorMessage(q.error)}</Alert>}
      {d && (
        <>
          <div className="grid items-start gap-6 lg:grid-cols-2">
            <PersonalRateForm
              key={teacherId}
              teachers={d.teachers}
              types={d.teacher_types}
              today={d.today_date}
              teacherId={teacherId}
              onTeacher={setTeacherId}
              onDone={done}
            />
            <Card title="Bảng đơn giá theo cấp bậc">
              <table className="w-full text-left text-sm">
                <thead className="text-xs text-on-surface-variant">
                  <tr>
                    <th className={th}>Cấp bậc</th>
                    <th className={`${th} text-right`}>Giao tiếp</th>
                    <th className={`${th} text-right`}>IELTS</th>
                  </tr>
                </thead>
                <tbody>
                  {d.rates.map((r) => (
                    <tr key={r.id} className="border-t border-surface-container align-top">
                      <td className={td}>
                        <p className="font-semibold">{r.rank_title}</p>
                        {r.criteria && <p className="text-xs text-on-surface-variant">{r.criteria}</p>}
                      </td>
                      <td className={`${td} text-right font-mono`}>{money(r.communication_rate)}</td>
                      <td className={`${td} text-right font-mono`}>{money(r.ielts_rate)}</td>
                    </tr>
                  ))}
                  {d.rates.length === 0 && (
                    <tr>
                      <td colSpan={3} className="py-4 text-center text-on-surface-variant">
                        Chưa khai báo cấp bậc. Mặc định {d.default_rate}.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </Card>
          </div>
          {d.selected_teacher && (
            <Card title={`${d.selected_teacher.name} — ${d.selected_teacher.type_label}`}>
              {d.selected_teacher.profile_rate && (
                <p className="mb-2 text-sm text-on-surface-variant">Đơn giá giờ trên hồ sơ: {d.selected_teacher.profile_rate}</p>
              )}
              <RateHistory rows={d.selected_teacher.history} />
            </Card>
          )}
          <Card title="Lịch sử phiên bản đơn giá">
            <RateHistory rows={d.history} />
            <Pager paging={d.paging} unit="phiên bản" onPage={setPage} />
          </Card>
          {editing && (
            <RankRatesDialog
              rows={d.rates}
              onClose={() => setEditing(false)}
              onDone={(m) => {
                setEditing(false)
                done(m)
              }}
            />
          )}
        </>
      )}
    </div>
  )
}

function RateHistory({ rows }: { rows: RateHistoryRow[] }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[720px] text-left text-sm">
        <thead className="text-xs text-on-surface-variant">
          <tr>
            <th className={th}>Giáo viên</th>
            <th className={th}>Loại</th>
            <th className={`${th} text-right`}>Đơn giá</th>
            <th className={th}>Hiệu lực</th>
            <th className={th}>Trạng thái</th>
            <th className={th}>Ghi chú</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.id} className="border-t border-surface-container align-top">
              <td className={td}>{r.user_name}</td>
              <td className={td}>{r.teacher_type_label}</td>
              <td className={`${td} text-right font-mono whitespace-nowrap`}>
                {r.rate_unit === 'tuition' ? `${trimNumber(r.hourly_rate)}%` : vnd(r.hourly_rate)} {r.unit_label}
              </td>
              <td className={`${td} whitespace-nowrap`}>
                {r.effective_from}
                {r.end ? ` → ${r.end}` : ''}
              </td>
              <td className={td}>
                <Badge tone={statusTone(r.state_color)}>{r.state_label}</Badge>
              </td>
              <td className={td}>
                {r.note}
                <p className="text-xs text-on-surface-variant">
                  {r.creator} · {r.created_at}
                </p>
              </td>
            </tr>
          ))}
          {rows.length === 0 && (
            <tr>
              <td colSpan={6} className="py-4 text-center text-on-surface-variant">
                Chưa có phiên bản đơn giá nào.
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  )
}

function PersonalRateForm({
  teachers,
  types,
  today,
  teacherId,
  onTeacher,
  onDone,
}: {
  teachers: TeacherRow[]
  types: Option[]
  today: string
  teacherId: string
  onTeacher: (id: string) => void
  onDone: (msg: string) => void
}) {
  const [f, setF] = useState({
    rate: '',
    unit: 'session',
    type: '',
    from: today,
    note: '',
  })
  const save = useMutation({
    mutationFn: () =>
      payrollApi.addPersonalRate({
        user_id: teacherId,
        hourly_rate: numOrNull(f.rate),
        rate_unit: f.unit,
        teacher_type: f.type,
        effective_from: f.from,
        note: f.note,
      }),
    onSuccess: (r) => onDone(r.message),
  })
  const cur = teachers.find((t) => t.id === teacherId)
  return (
    <Card title="Thêm phiên bản đơn giá riêng">
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <Select label="Giáo viên" value={teacherId} onChange={(e) => onTeacher(e.target.value)}>
          <option value="">Chọn giáo viên</option>
          {teachers.map((t) => (
            <option key={t.id} value={t.id}>
              {t.name}
              {t.employee_code ? ` (${t.employee_code})` : ''}
            </option>
          ))}
        </Select>
        {cur?.current && (
          <p className="text-xs text-on-surface-variant">
            Đang áp dụng: {vnd(cur.current.rate)} {cur.current.unit_label} từ {cur.current.effective_from}
          </p>
        )}
        <div className="grid grid-cols-2 gap-3">
          <Field
            label={f.unit === 'tuition' ? '% học phí' : 'Đơn giá (VNĐ)'}
            type="number"
            step={f.unit === 'tuition' ? 0.1 : 1000}
            value={f.rate}
            onChange={(e) => setF({ ...f, rate: e.target.value })}
          />
          <Select label="Đơn vị" value={f.unit} onChange={(e) => setF({ ...f, unit: e.target.value })}>
            <option value="session">đ / buổi</option>
            <option value="hour">đ / giờ</option>
            <option value="tuition">% học phí</option>
          </Select>
          <Select label="Loại giáo viên" value={f.type} onChange={(e) => setF({ ...f, type: e.target.value })}>
            <option value="">Theo vai trò</option>
            {types.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
          <Field label="Hiệu lực từ" type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} />
        </div>
        <Textarea label="Ghi chú" value={f.note} onChange={(v) => setF({ ...f, note: v })} rows={2} />
        <Button type="submit" disabled={save.isPending}>
          Thêm đơn giá
        </Button>
      </form>
    </Card>
  )
}

function RankRatesDialog({ rows, onClose, onDone }: { rows: RankRate[]; onClose: () => void; onDone: (msg: string) => void }) {
  const [list, setList] = useState(
    rows.map((r) => ({
      rank_title: r.rank_title,
      criteria: r.criteria ?? '',
      communication: String(r.communication_rate),
      ielts: String(r.ielts_rate),
    })),
  )
  const save = useMutation({
    mutationFn: () =>
      payrollApi.saveRankRates(
        list.map(
          (r): RankRateInput => ({
            rank_title: r.rank_title,
            criteria: r.criteria || null,
            communication_rate: numOrNull(r.communication),
            ielts_rate: numOrNull(r.ielts),
          }),
        ),
      ),
    onSuccess: (r) => onDone(r.message),
  })
  const set = (i: number, patch: Partial<(typeof list)[number]>) => setList(list.map((r, j) => (j === i ? { ...r, ...patch } : r)))
  return (
    <Dialog title="Bảng đơn giá theo cấp bậc" onClose={onClose} wide>
      <div className="space-y-3">
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        {list.map((r, i) => (
          <div key={i} className="grid gap-2 rounded-lg border border-surface-container p-3 sm:grid-cols-[1fr_140px_140px_auto] sm:items-end">
            <Field label="Cấp bậc" value={r.rank_title} onChange={(e) => set(i, { rank_title: e.target.value })} />
            <Field label="Giao tiếp (đ)" type="number" value={r.communication} onChange={(e) => set(i, { communication: e.target.value })} />
            <Field label="IELTS (đ)" type="number" value={r.ielts} onChange={(e) => set(i, { ielts: e.target.value })} />
            <Button type="button" variant="danger" onClick={() => setList(list.filter((_, j) => j !== i))}>
              Xóa
            </Button>
            <div className="sm:col-span-4">
              <Textarea label="Tiêu chí" value={r.criteria} onChange={(v) => set(i, { criteria: v })} rows={1} />
            </div>
          </div>
        ))}
        <div className="flex justify-between gap-2">
          <Button
            type="button"
            variant="secondary"
            onClick={() =>
              setList([
                ...list,
                {
                  rank_title: '',
                  criteria: '',
                  communication: '',
                  ielts: '',
                },
              ])
            }
          >
            Thêm cấp bậc
          </Button>
          <Button type="button" disabled={save.isPending} onClick={() => save.mutate()}>
            Lưu bảng đơn giá
          </Button>
        </div>
      </div>
    </Dialog>
  )
}

export function TiersPage() {
  const qc = useQueryClient()
  const [asOf, setAsOf] = useState('')
  const [page, setPage] = useState(1)
  const [notice, setNotice] = useState('')
  const [editing, setEditing] = useState<TierRow | 'new' | null>(null)
  const q = useQuery({
    queryKey: ['payroll', 'tiers', asOf, page],
    queryFn: () => payrollApi.tiers(asOf, page),
    placeholderData: keepPreviousData,
  })
  const d = q.data
  const done = (msg: string) => {
    setNotice(msg)
    setEditing(null)
    qc.invalidateQueries({ queryKey: ['payroll', 'tiers'] })
  }
  const retire = useMutation({
    mutationFn: (id: string) => payrollApi.retireTier(id),
    onSuccess: (r) => done(r.message),
  })
  return (
    <div className="space-y-6">
      <Header
        title="Mốc hoa hồng / Thưởng"
        description="% hoa hồng tư vấn theo thứ tự HS chốt trong tháng (mốc có phiên bản hiệu lực) và bảng thưởng tái tục của giáo viên."
        actions={<Button onClick={() => setEditing('new')}>Thêm mốc hoa hồng</Button>}
      />
      {notice && <Alert tone="success">{notice}</Alert>}
      {(q.isError || retire.isError) && <Alert>{errorMessage(q.error ?? retire.error)}</Alert>}
      {d && (
        <>
          <Alert tone="info">
            Hoa hồng chỉ trả khi đủ gate kép: {d.gate_days} ngày từ ngày chốt và {d.gate_milestones} mốc chăm sóc tháng đầu; chưa đủ thì hoãn sang kỳ sau.
          </Alert>
          <Card title="Mốc đang hiệu lực">
            <div className="mb-3 max-w-xs">
              <Field label="Xem mốc tại ngày" type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} />
            </div>
            <TierTable rows={d.tiers} onEdit={setEditing} onRetire={(t) => window.confirm(`Ngừng áp dụng ${t.tier_name}?`) && retire.mutate(t.id)} />
          </Card>
          <Card title="Lịch sử phiên bản">
            <TierTable rows={d.history} />
            <Pager paging={d.paging} unit="phiên bản" onPage={setPage} />
          </Card>
          <RenewalCard key={q.dataUpdatedAt} rows={d.renewal_rows} beyond={d.renewal_beyond} onDone={done} />
        </>
      )}
      {editing && d && <TierDialog tier={editing === 'new' ? null : editing} tomorrow={d.tomorrow} onClose={() => setEditing(null)} onDone={done} />}
    </div>
  )
}

function TierTable({ rows, onEdit, onRetire }: { rows: TierRow[]; onEdit?: (t: TierRow) => void; onRetire?: (t: TierRow) => void }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[640px] text-left text-sm">
        <thead className="text-xs text-on-surface-variant">
          <tr>
            <th className={th}>Mốc</th>
            <th className={th}>Thứ tự HS chốt</th>
            <th className={`${th} text-right`}>% hoa hồng</th>
            <th className={th}>Hiệu lực</th>
            <th className={th}>Người tạo</th>
            {onEdit && <th />}
          </tr>
        </thead>
        <tbody>
          {rows.map((t) => (
            <tr key={t.id} className="border-t border-surface-container">
              <td className={td}>
                {t.tier_name} {t.current && <Badge tone="success">Đang áp dụng</Badge>}
              </td>
              <td className={td}>{t.range}</td>
              <td className={`${td} text-right font-semibold`}>{t.percent_label}</td>
              <td className={`${td} whitespace-nowrap`}>
                {t.effective_from} → {t.effective_to}
              </td>
              <td className={td}>{t.creator}</td>
              {onEdit && (
                <td className={`${td} text-right whitespace-nowrap`}>
                  <Button variant="secondary" className="h-8 px-2" onClick={() => onEdit(t)}>
                    Sửa
                  </Button>{' '}
                  <Button variant="danger" className="h-8 px-2" onClick={() => onRetire?.(t)}>
                    Ngừng
                  </Button>
                </td>
              )}
            </tr>
          ))}
          {rows.length === 0 && (
            <tr>
              <td colSpan={6} className="py-4 text-center text-on-surface-variant">
                Chưa có mốc hoa hồng.
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  )
}

function TierDialog({ tier, tomorrow, onClose, onDone }: { tier: TierRow | null; tomorrow: string; onClose: () => void; onDone: (m: string) => void }) {
  const [f, setF] = useState({
    name: tier?.tier_name ?? '',
    min: tier ? String(tier.min_students) : '',
    max: tier?.max_students != null ? String(tier.max_students) : '',
    percent: tier ? String(tier.new_sale_percent) : '',
    from: tier ? tomorrow : '',
  })
  const save = useMutation({
    mutationFn: () => {
      const input: TierInput = {
        tier_name: f.name,
        min_students: numOrNull(f.min),
        max_students: numOrNull(f.max),
        new_sale_percent: numOrNull(f.percent),
        effective_from: f.from,
      }
      return tier ? payrollApi.updateTier(tier.id, input) : payrollApi.createTier(input)
    },
    onSuccess: (r) => onDone(r.message),
  })
  return (
    <Dialog title={tier ? `Sửa ${tier.tier_name}` : 'Thêm mốc hoa hồng'} onClose={onClose}>
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {tier && <Alert tone="info">Sửa mốc tạo phiên bản mới từ ngày hiệu lực; các kỳ trước giữ nguyên mốc cũ.</Alert>}
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <Field label="Tên mốc" value={f.name} placeholder="Để trống: tự đặt theo khoảng HS" onChange={(e) => setF({ ...f, name: e.target.value })} />
        <div className="grid grid-cols-3 gap-3">
          <Field label="Từ HS thứ" type="number" min={0} value={f.min} onChange={(e) => setF({ ...f, min: e.target.value })} />
          <Field label="Đến HS thứ" type="number" min={0} value={f.max} placeholder="Không giới hạn" onChange={(e) => setF({ ...f, max: e.target.value })} />
          <Field label="% hoa hồng" type="number" step={0.1} value={f.percent} onChange={(e) => setF({ ...f, percent: e.target.value })} />
        </div>
        <Field label="Hiệu lực từ" type="date" value={f.from} hint="Để trống: từ hôm nay" onChange={(e) => setF({ ...f, from: e.target.value })} />
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

function RenewalCard({ rows, beyond, onDone }: { rows: RenewalRow[]; beyond: number; onDone: (m: string) => void }) {
  const [list, setList] = useState(renewalDraft(rows))
  const [b, setB] = useState(String(beyond))
  const save = useMutation({
    mutationFn: () => payrollApi.saveRenewal(renewalInput(list), numOrNull(b)),
    onSuccess: (r) => onDone(r.message),
  })
  return (
    <Card title="Thưởng tái tục theo số HS nghỉ">
      {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
      <RenewalEditor rows={list} onChange={setList} />
      <div className="mt-3 flex flex-wrap items-end gap-3">
        <div className="w-48">
          <Field label="% khi vượt bảng" type="number" step={0.1} value={b} onChange={(e) => setB(e.target.value)} />
        </div>
        <Button type="button" disabled={save.isPending} onClick={() => save.mutate()}>
          Lưu bảng thưởng
        </Button>
      </div>
    </Card>
  )
}
