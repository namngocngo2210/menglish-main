// Đánh giá dự giờ học thuật (ClassQuality/Academic.vue): theo tháng, mỗi lớp có học trong tháng một dòng — % chuyên cần,
// % đạt yêu cầu, đã dự giờ chưa. Bấm dòng mở hộp thoại đánh giá: "Đã dự giờ" → ngày, người dự giờ, 6 tiêu chí nhận xét,
// ghi chú / hành động. % chuyên cần gợi ý từ điểm danh của lớp trong tháng.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Checkbox, Dialog, Field, Icon, Select } from '../../components/ui'
import { EmptyState, PageHeader, Pager, StatCard } from '../platform/parts'
import { qualityApi, type AcademicClass, type AcademicPage as Data } from './api'
import { FilterSelect, Panel, Table, Textarea } from './components'
import { academicForm, dayMonth, rate, toAcademicInput } from './logic'

const STATUS_OPTIONS = [
  { value: 'observed', label: 'Đã dự giờ' },
  { value: 'pending', label: 'Chưa dự giờ' },
]

export function AcademicPage(_: { me: Me }) {
  const qc = useQueryClient()
  const [filter, setFilter] = useState({ month: '', status: '', search: '', page: 1 })
  const [q, setQ] = useState('')
  const [editing, setEditing] = useState<AcademicClass | null>(null)
  const [notice, setNotice] = useState('')
  const page = useQuery({
    queryKey: ['class-quality', 'academic', filter],
    queryFn: () => qualityApi.academic(filter),
    placeholderData: keepPreviousData,
  })
  const d = page.data
  const monthLabel = d ? (d.months.find((m) => m.value === d.month)?.label ?? d.month) : ''
  const done = (msg: string) => {
    setNotice(msg)
    setEditing(null)
    qc.invalidateQueries({ queryKey: ['class-quality'] })
  }
  const pending = d ? Math.max(0, d.stats.total - d.stats.observed) : 0

  return (
    <div className="space-y-6">
      <PageHeader title="Đánh giá dự giờ" description="Ghi nhận đánh giá định kỳ và chuyên môn giảng dạy theo tháng cho từng lớp học." />
      {notice && <Alert tone="success">{notice}</Alert>}
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}

      {d && (
        <>
          <div className="grid gap-4 sm:grid-cols-3">
            <StatCard label="Lớp có học trong tháng" value={d.stats.total} icon="co_present" />
            <StatCard label="Đã dự giờ" value={d.stats.observed} icon="task_alt" tone="success" />
            <StatCard label="Chưa dự giờ" value={pending} icon="event_busy" tone={pending > 0 ? 'warning' : 'neutral'} />
          </div>
          <form
            className="grid gap-3 sm:grid-cols-[1fr_200px_200px_auto] sm:items-end"
            onSubmit={(e) => {
              e.preventDefault()
              setFilter({ ...filter, search: q, page: 1 })
            }}
          >
            <Field label="Tìm kiếm" placeholder="Tìm mã / tên lớp..." value={q} onChange={(e) => setQ(e.target.value)} />
            <FilterSelect label="Tháng" options={d.months} value={d.month} onChange={(e) => setFilter({ ...filter, month: e.target.value, page: 1 })} />
            <FilterSelect
              label="Dự giờ"
              all="Tất cả lớp"
              options={STATUS_OPTIONS}
              value={filter.status}
              onChange={(e) => setFilter({ ...filter, status: e.target.value, page: 1 })}
            />
            <Button type="submit" variant="secondary">
              Lọc
            </Button>
          </form>
          <Panel>
            <Table
              minWidth={900}
              head={
                <tr>
                  <th>Lớp</th>
                  <th>Giáo viên</th>
                  <th className="text-right">% chuyên cần</th>
                  <th className="text-right">% đạt yêu cầu</th>
                  <th>Dự giờ {monthLabel.toLowerCase()}</th>
                  <th className="text-right">Thao tác</th>
                </tr>
              }
            >
              {d.classes.map((c) => (
                <tr key={c.id} className="cursor-pointer hover:bg-surface-container-low" onClick={() => setEditing(c)}>
                  <td>
                    <span className="font-semibold">{c.code}</span>
                    <span className="block text-xs text-on-surface-variant">
                      {c.name}
                      {c.course && ` · ${c.course}`}
                    </span>
                  </td>
                  <td>{c.teacher ?? '—'}</td>
                  <td className="text-right font-mono">
                    {c.evaluation?.attendance_rate !== null && c.evaluation?.attendance_rate !== undefined ? (
                      rate(c.evaluation.attendance_rate)
                    ) : c.suggested_attendance !== null ? (
                      <span className="text-on-surface-variant" title="Tính từ điểm danh trong tháng">
                        {rate(c.suggested_attendance)}
                      </span>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td className="text-right font-mono">{rate(c.evaluation?.pass_rate)}</td>
                  <td>
                    {c.evaluation?.observed ? (
                      <>
                        <Badge tone="success">Đã dự giờ {dayMonth(c.evaluation.observed_on)}</Badge>
                        <span className="block text-xs text-on-surface-variant">{c.evaluation.observer}</span>
                      </>
                    ) : (
                      <Badge tone="warning">Chưa dự giờ</Badge>
                    )}
                  </td>
                  <td className="text-right whitespace-nowrap" onClick={(e) => e.stopPropagation()}>
                    <Button variant="secondary" className="h-8 px-2" onClick={() => setEditing(c)}>
                      {d.can_record ? 'Đánh giá' : 'Xem'}
                    </Button>
                  </td>
                </tr>
              ))}
              {d.classes.length === 0 && (
                <tr>
                  <td colSpan={6}>
                    <EmptyState icon="co_present" title="Không có lớp nào học trong tháng này" />
                  </td>
                </tr>
              )}
            </Table>
            <Pager page={d.meta.page} perPage={d.meta.per_page} total={d.meta.total} unit="lớp" onPage={(p) => setFilter({ ...filter, page: p })} />
          </Panel>
        </>
      )}

      {editing && d && <EvaluationDialog c={editing} d={d} monthLabel={monthLabel} onClose={() => setEditing(null)} onDone={done} />}
    </div>
  )
}

function EvaluationDialog({ c, d, monthLabel, onClose, onDone }: { c: AcademicClass; d: Data; monthLabel: string; onClose: () => void; onDone: (msg: string) => void }) {
  const [f, setF] = useState(() => academicForm(c, d.current_user_id))
  const save = useMutation({ mutationFn: () => qualityApi.saveAcademic(toAcademicInput(c.id, d.month, f)), onSuccess: (r) => onDone(r.message) })
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate()
  }
  const heading = (icon: string, text: string) => (
    <h3 className="flex items-center gap-1 font-semibold">
      <Icon name={icon} className="text-[18px] text-primary" />
      {text}
    </h3>
  )
  return (
    <Dialog title={`Đánh giá dự giờ — ${c.code}`} onClose={onClose} wide>
      <form className="space-y-6" onSubmit={submit}>
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <fieldset disabled={!d.can_record} className="space-y-6">
          <section className="space-y-4">
            {heading('info', 'Thông tin cơ bản')}
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <div className="text-sm font-medium">Lớp</div>
                <p className="py-2 font-semibold">
                  {c.code} · {c.name}
                  {c.teacher && ` (GV: ${c.teacher})`}
                </p>
              </div>
              <div>
                <div className="text-sm font-medium">Tháng</div>
                <p className="py-2 font-semibold">{monthLabel}</p>
              </div>
              <Field
                label="Tỉ lệ % chuyên cần học sinh"
                type="number"
                step="0.1"
                min={0}
                max={100}
                value={f.attendance_rate}
                onChange={(e) => setF({ ...f, attendance_rate: e.target.value })}
                hint={c.suggested_attendance !== null ? `Theo điểm danh trong tháng: ${rate(c.suggested_attendance)}` : 'Chưa có dữ liệu điểm danh trong tháng'}
              />
              <Field
                label="Tỉ lệ % học sinh đạt yêu cầu"
                type="number"
                step="0.1"
                min={0}
                max={100}
                value={f.pass_rate}
                onChange={(e) => setF({ ...f, pass_rate: e.target.value })}
              />
            </div>
          </section>
          <section className="space-y-4">
            <div className="flex items-center justify-between gap-4">
              {heading('fact_check', 'Chi tiết đánh giá')}
              <Checkbox label="Đã dự giờ" checked={f.observed} onChange={(e) => setF({ ...f, observed: e.target.checked })} />
            </div>
            {f.observed ? (
              <>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Field label="Ngày dự giờ" type="date" required value={f.observed_on} onChange={(e) => setF({ ...f, observed_on: e.target.value })} />
                  <Select label="Người dự giờ" required value={f.observer_id} onChange={(e) => setF({ ...f, observer_id: e.target.value })}>
                    <option value="">-- Chọn người dự giờ --</option>
                    {d.observers.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </Select>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                  {d.criteria.map((k, i) => (
                    <Textarea
                      key={k.key}
                      label={`${i + 1}. ${k.label}`}
                      value={f.criteria[k.key] ?? ''}
                      onChange={(e) => setF({ ...f, criteria: { ...f.criteria, [k.key]: e.target.value } })}
                    />
                  ))}
                </div>
                <Textarea
                  label="Ghi chú / Hành động"
                  value={f.action_notes}
                  onChange={(e) => setF({ ...f, action_notes: e.target.value })}
                  placeholder="Kế hoạch theo dõi tiếp theo, đề xuất khắc phục hoặc giải pháp cải thiện chuyên môn..."
                />
              </>
            ) : (
              <div className="rounded-xl border border-dashed border-outline-variant p-6 text-center text-on-surface-variant">
                <Icon name="event_busy" className="text-[32px]" />
                <p className="mt-1 text-sm">Lớp học này chưa thực hiện dự giờ trong tháng đã chọn.</p>
                <p className="text-xs">Bật “Đã dự giờ” để ghi nhận thông tin và đánh giá chi tiết.</p>
              </div>
            )}
          </section>
        </fieldset>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {d.can_record ? 'Hủy bỏ' : 'Đóng'}
          </Button>
          {d.can_record && (
            <Button type="submit" disabled={save.isPending}>
              Lưu
            </Button>
          )}
        </div>
      </form>
    </Dialog>
  )
}
