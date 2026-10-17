// Tiêu chí KPI theo vai trò (Kpi/Criteria.vue): chọn vai trò → nhóm tiêu chí, chu kỳ chấm (tháng / quý), thưởng theo xếp loại;
// Thêm / Sửa trong hộp thoại (bấm dòng để sửa), Xóa có xác nhận. Học vụ có quỹ tiền KPI, vai trò khác chấm theo % đạt.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Checkbox, Dialog, Field, Select } from '../../components/ui'
import { kpiApi, type CriteriaPage as Data, type CriterionView } from './api'
import { criterionForm, criterionInput, gradeText, money, tierLabel, type CriterionForm } from './logic'

export function CriteriaPage() {
  const qc = useQueryClient()
  const [role, setRole] = useState('')
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const [editing, setEditing] = useState<CriterionView | 'new' | null>(null)
  const [deleting, setDeleting] = useState<CriterionView | null>(null)
  const page = useQuery({ queryKey: ['kpi', 'criteria', role], queryFn: () => kpiApi.criteria(role), placeholderData: keepPreviousData })
  const d = page.data
  const done = (msg: string) => {
    setNotice(msg)
    setError('')
    setEditing(null)
    setDeleting(null)
    qc.invalidateQueries({ queryKey: ['kpi'] })
  }
  const cycle = useMutation({
    mutationFn: (v: { months: number; fund?: number | null }) => kpiApi.setCycle(d!.role, v.months, v.fund),
    onSuccess: (r) => done(r.message),
    onError: (e) => setError(errorMessage(e)),
  })
  const [fundInput, setFundInput] = useState<string | null>(null)

  if (page.isError) return <Alert>{errorMessage(page.error)}</Alert>
  if (!d) return null
  const hasFund = d.fund !== null
  const matched = Math.abs(d.total_weight - 100) < 0.001
  const roleLabel = d.role_options.find((o) => o.value === d.role)?.label ?? ''
  const cycleLabel = d.cycle_options.find((o) => Number(o.value) === d.period_months)?.label

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Tiêu chí KPI</h1>
          <p className="text-sm text-on-surface-variant">
            Mỗi vai trò một bộ tiêu chí và một chu kỳ chấm (tháng hoặc quý). Tiêu chí đo bằng số lần hoặc tỉ lệ %, mức đạt theo các bậc tính điểm.
          </p>
        </div>
        {d.can_manage && <Button onClick={() => setEditing('new')}>Thêm tiêu chí</Button>}
      </div>
      {notice && <Alert tone="success">{notice}</Alert>}
      {error && <Alert>{error}</Alert>}

      <section className="flex flex-col gap-4 rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm sm:flex-row sm:items-end sm:justify-between">
        <div className="flex w-full flex-col gap-4 sm:max-w-2xl sm:flex-row">
          <div className="w-full sm:w-64">
            <Select label="Vai trò" value={d.role} onChange={(e) => setRole(e.target.value)}>
              {d.role_options.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          {d.can_manage && d.grades.length > 0 && !hasFund && (
            <div className="w-full sm:w-48">
              <Field
                label="Thưởng KPI tối đa/tháng (đ)"
                type="number"
                min={0}
                step={100000}
                value={fundInput ?? (d.grade_fund === null ? '' : String(d.grade_fund))}
                onChange={(e) => setFundInput(e.target.value)}
                onBlur={() => {
                  if (fundInput === null) return
                  cycle.mutate({ months: d.period_months, fund: fundInput === '' ? null : Number(fundInput) })
                  setFundInput(null)
                }}
              />
            </div>
          )}
          <div className="w-full sm:w-44">
            {d.can_manage ? (
              <Select label="Chu kỳ chấm" value={String(d.period_months)} onChange={(e) => cycle.mutate({ months: Number(e.target.value) })}>
                {d.cycle_options.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
            ) : (
              <p className="pt-7 text-sm">{cycleLabel}</p>
            )}
          </div>
        </div>
        <p className="text-sm text-on-surface-variant">
          Tổng trọng số đang áp dụng: <strong className={matched ? 'text-success' : 'text-warning'}>{d.total_weight_label}%</strong>
          {hasFund ? (
            <>
              {' '}
              · Quỹ KPI tháng <strong className="text-on-surface">{money(d.fund!)}</strong>
            </>
          ) : d.grade_fund ? (
            <>
              {' '}
              · Thưởng KPI tối đa <strong className="text-on-surface">{money(d.grade_fund)}</strong>/tháng × hệ số xếp loại
            </>
          ) : (
            ' · Vai trò này chưa có quỹ tiền KPI trong bảng lương, KPI tính theo % đạt'
          )}
        </p>
      </section>

      {(d.period_months > 1 || d.grades.length > 0) && (
        <p className="text-sm text-on-surface-variant">
          {d.period_months > 1 ? 'Phiếu theo quý, duyệt từ ngày cuối quý.' : 'Phiếu theo tháng, duyệt từ ngày cuối tháng.'}
          {d.grades.length > 0 && ` Xếp loại theo tổng % đạt: ${gradeText(d.grades)}.`}
        </p>
      )}
      {d.criteria_groups.length > 0 && !matched && (
        <Alert tone="info">
          Tổng trọng số các tiêu chí đang áp dụng của {roleLabel} là {d.total_weight_label}%, chưa bằng 100%. KPI vẫn tính theo tỉ lệ trọng số.
        </Alert>
      )}

      {d.criteria_groups.length === 0 ? (
        <div className="rounded-xl border border-surface-container-highest bg-surface p-8 text-center text-sm">
          <p className="font-medium">{roleLabel} chưa có tiêu chí KPI</p>
          <p className="text-on-surface-variant">Thêm tiêu chí để chấm KPI tháng cho vai trò này.</p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-surface-container-highest bg-surface">
          <table className="w-full min-w-[900px] text-left text-sm">
            <thead className="text-xs text-on-surface-variant">
              <tr>
                <th className="px-4 py-2 font-medium">Tiêu chí</th>
                <th className="px-4 py-2 text-right font-medium">Trọng số</th>
                {hasFund && <th className="px-4 py-2 text-right font-medium">Quỹ</th>}
                <th className="px-4 py-2 font-medium">Cách tính</th>
                <th className="px-4 py-2 font-medium">Trạng thái</th>
                {d.can_manage && (
                  <th className="px-4 py-2">
                    <span className="sr-only">Thao tác</span>
                  </th>
                )}
              </tr>
            </thead>
            <tbody>
              {d.criteria_groups.map((g) => (
                <CriteriaRows key={g.name} d={d} group={g} onEdit={setEditing} onDelete={setDeleting} />
              ))}
            </tbody>
          </table>
        </div>
      )}

      {editing && <CriterionDialog d={d} item={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onDone={done} />}
      {deleting && (
        <Dialog title="Xoá tiêu chí KPI" onClose={() => setDeleting(null)}>
          <DeleteBody item={deleting} onClose={() => setDeleting(null)} onDone={done} />
        </Dialog>
      )}
    </div>
  )
}

function CriteriaRows({
  d,
  group,
  onEdit,
  onDelete,
}: {
  d: Data
  group: Data['criteria_groups'][number]
  onEdit: (c: CriterionView) => void
  onDelete: (c: CriterionView) => void
}) {
  const hasFund = d.fund !== null
  return (
    <>
      <tr className="border-t border-surface-container bg-surface-container-low">
        <td colSpan={(hasFund ? 5 : 4) + (d.can_manage ? 1 : 0)} className="px-4 py-2 font-semibold">
          {group.name}
          <span className="ml-2 text-xs font-normal text-on-surface-variant">
            {group.count} tiêu chí · {group.active_weight}%{hasFund && group.active_fund !== null && ` · ${money(group.active_fund)}`}
          </span>
        </td>
      </tr>
      {group.items.map((c) => (
        <tr
          key={c.id}
          className={`border-t border-surface-container align-top ${d.can_manage ? 'cursor-pointer hover:bg-surface-container-low' : ''} ${c.is_active ? '' : 'opacity-60'}`}
          onClick={() => d.can_manage && onEdit(c)}
        >
          <td className="max-w-md min-w-[260px] px-4 py-2">
            <p className="font-medium">{c.name}</p>
            {c.description && <p className="line-clamp-2 text-xs text-on-surface-variant">{c.description}</p>}
            {c.source_pending && <p className="text-xs text-warning">{c.source_pending}</p>}
          </td>
          <td className="px-4 py-2 text-right font-mono whitespace-nowrap">{c.knockout ? 'Loại trừ' : `${c.weight}%`}</td>
          {hasFund && <td className="px-4 py-2 text-right font-mono font-semibold whitespace-nowrap text-primary">{money(c.fund_amount ?? 0)}</td>}
          <td className="max-w-xs min-w-[200px] px-4 py-2">
            {c.rule_label || '—'}
            {c.per_month && d.period_months > 1 && <p className="text-xs text-on-surface-variant">Tính từng tháng, lấy trung bình quý</p>}
            {c.allow_na && <p className="text-xs text-on-surface-variant">Tháng không phát sinh thì bỏ khỏi tổng</p>}
          </td>
          <td className="px-4 py-2">
            <Badge tone={c.is_active ? 'success' : 'neutral'}>{c.is_active ? 'Áp dụng' : 'Tạm tắt'}</Badge>
          </td>
          {d.can_manage && (
            <td className="px-4 py-2 text-right whitespace-nowrap" onClick={(e) => e.stopPropagation()}>
              <Button variant="secondary" className="h-8 px-2 text-xs" onClick={() => onEdit(c)} aria-label={`Sửa ${c.name}`}>
                Sửa
              </Button>{' '}
              <Button variant="danger" className="h-8 px-2 text-xs" onClick={() => onDelete(c)} aria-label={`Xóa ${c.name}`}>
                Xóa
              </Button>
            </td>
          )}
        </tr>
      ))}
    </>
  )
}

function DeleteBody({ item, onClose, onDone }: { item: CriterionView; onClose: () => void; onDone: (m: string) => void }) {
  const del = useMutation({ mutationFn: () => kpiApi.deleteCriterion(item.id), onSuccess: (r) => onDone(r.message) })
  return (
    <div className="space-y-4">
      <p className="text-sm">Xoá tiêu chí {item.name}?</p>
      {del.isError && <Alert>{errorMessage(del.error)}</Alert>}
      <div className="flex justify-end gap-2">
        <Button variant="secondary" onClick={onClose}>
          Hủy
        </Button>
        <Button variant="danger" disabled={del.isPending} onClick={() => del.mutate()}>
          Xóa
        </Button>
      </div>
    </div>
  )
}

function CriterionDialog({ d, item, onClose, onDone }: { d: Data; item: CriterionView | null; onClose: () => void; onDone: (m: string) => void }) {
  const [f, setF] = useState<CriterionForm>(() => criterionForm(item))
  const set = (patch: Partial<CriterionForm>) => setF((x) => ({ ...x, ...patch }))
  const save = useMutation({
    mutationFn: () => (item ? kpiApi.updateCriterion(item.id, criterionInput(f, null)) : kpiApi.createCriterion(criterionInput(f, d.role))),
    onSuccess: (r) => onDone(r.message),
  })
  const roleLabel = d.role_options.find((o) => o.value === d.role)?.label ?? ''
  const isRate = f.measure !== 'count'
  const isRateUp = f.measure === 'rate'
  const weightMoney = d.fund !== null ? Math.round((d.fund * (parseFloat(f.weight) || 0)) / 100) : null
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate()
  }
  return (
    <Dialog title={item ? 'Sửa tiêu chí KPI' : `Thêm tiêu chí KPI · ${roleLabel}`} onClose={onClose} wide>
      <form className="space-y-4" onSubmit={submit}>
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="grid gap-4 sm:grid-cols-2">
          <Select label="Nhóm" value={f.group_name} onChange={(e) => set({ group_name: e.target.value })}>
            <option value="">-- Chọn nhóm --</option>
            {d.groups.map((g) => (
              <option key={g} value={g}>
                {g}
              </option>
            ))}
          </Select>
          <Field label="Hoặc tạo nhóm mới" placeholder="vd: Học phí & dữ liệu" value={f.new_group} onChange={(e) => set({ new_group: e.target.value })} />
        </div>
        <Field label="Tên tiêu chí" required value={f.name} onChange={(e) => set({ name: e.target.value })} />
        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label={d.fund !== null ? 'Trọng số (% quỹ)' : 'Trọng số (điểm)'}
            type="number"
            step="0.25"
            min={0}
            max={100}
            required
            value={f.weight}
            onChange={(e) => set({ weight: e.target.value })}
            hint={weightMoney !== null ? `= ${money(weightMoney)} / tháng` : f.knockout ? 'Điều kiện loại trừ để 0' : undefined}
          />
          <Select label="Cách đo" value={f.measure} onChange={(e) => set({ measure: e.target.value })}>
            {d.measure_options.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
          {!isRate && (
            <Select label="Đơn vị đếm" value={f.unit} onChange={(e) => set({ unit: e.target.value })}>
              <option value="">-- Chọn đơn vị --</option>
              {d.unit_options.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          )}
        </div>
        {!isRate && (
          <Checkbox label="Điều kiện loại trừ: có từ 1 lần là mất toàn bộ KPI kỳ" checked={f.knockout} onChange={(e) => set({ knockout: e.target.checked })} />
        )}
        {isRateUp && <Checkbox label="Tính điểm thẳng theo tỉ lệ" checked={f.linear} onChange={(e) => set({ linear: e.target.checked })} />}
        {isRateUp && f.linear && (
          <Field
            label="Đủ điểm khi đạt (%)"
            type="number"
            min={0.01}
            max={1000}
            step="any"
            value={f.full_at}
            onChange={(e) => set({ full_at: e.target.value })}
            hint="vd 100%: đạt 80% được 80% điểm; 10%: tăng 5% được một nửa điểm"
          />
        )}
        {!(f.knockout && !isRate) && !(isRateUp && f.linear) && (
          <fieldset className="space-y-2 rounded-lg border border-outline-variant p-4">
            <legend className="px-1 text-sm font-semibold">Bậc tính điểm</legend>
            <p className="text-xs text-on-surface-variant">
              {isRateUp
                ? 'Từ ngưỡng % trở lên thì đạt số % điểm tương ứng; dưới bậc thấp nhất là 0%.'
                : isRate
                  ? 'Không quá tỉ lệ % thì đạt số % điểm tương ứng; vượt bậc cuối là 0%.'
                  : 'Không quá số lần thì đạt số % điểm tương ứng; vượt bậc cuối là 0%.'}
            </p>
            {f.tiers.map((t, i) => (
              <div key={i} className="flex items-end gap-2">
                <div className="flex-1">
                  <Field
                    label={i === 0 ? tierLabel(f.measure) : `${tierLabel(f.measure)} · bậc ${i + 1}`}
                    type="number"
                    min={0}
                    step="any"
                    value={t.at}
                    onChange={(e) => set({ tiers: f.tiers.map((x, j) => (j === i ? { ...x, at: e.target.value } : x)) })}
                  />
                </div>
                <div className="flex-1">
                  <Field
                    label={i === 0 ? 'Đạt (% điểm)' : `Đạt (% điểm) · bậc ${i + 1}`}
                    type="number"
                    min={0}
                    max={100}
                    step="any"
                    value={t.percent}
                    onChange={(e) => set({ tiers: f.tiers.map((x, j) => (j === i ? { ...x, percent: e.target.value } : x)) })}
                  />
                </div>
                <Button
                  type="button"
                  variant="secondary"
                  disabled={f.tiers.length === 1}
                  onClick={() => set({ tiers: f.tiers.filter((_, j) => j !== i) })}
                  aria-label="Bỏ bậc"
                >
                  Bỏ
                </Button>
              </div>
            ))}
            {f.tiers.length < 8 && (
              <Button type="button" variant="secondary" onClick={() => set({ tiers: [...f.tiers, { at: '', percent: '' }] })}>
                Thêm bậc
              </Button>
            )}
          </fieldset>
        )}
        {d.period_months > 1 && !isRate && !f.knockout && (
          <Checkbox
            label="Tính từng tháng rồi lấy trung bình quý (số liệu tự động)"
            checked={f.per_month}
            onChange={(e) => set({ per_month: e.target.checked })}
          />
        )}
        {!f.knockout && (
          <Checkbox
            label='Cho phép "Không phát sinh": kỳ không có việc để đánh giá thì bỏ mục khỏi tổng KPI'
            checked={f.allow_na}
            onChange={(e) => set({ allow_na: e.target.checked })}
          />
        )}
        <Select label="Số liệu" value={f.auto_source} onChange={(e) => set({ auto_source: e.target.value })}>
          {d.source_options.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
        <p className="-mt-2 text-xs text-on-surface-variant">
          Tự động: hệ thống tự đếm, người chấm không điền. Điền tay: người chấm điền số trên phiếu KPI tháng.
        </p>
        <label className="block space-y-1.5 text-sm font-medium">
          <span>Cách đếm</span>
          <textarea
            className="block w-full rounded-lg border border-outline-variant px-3 py-2 text-sm font-normal outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
            rows={3}
            placeholder="Đếm cái gì, lấy số liệu ở đâu"
            value={f.description}
            onChange={(e) => set({ description: e.target.value })}
          />
        </label>
        {item && <Checkbox label="Đang áp dụng" checked={f.is_active} onChange={(e) => set({ is_active: e.target.checked })} />}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            {item ? 'Lưu' : 'Thêm tiêu chí'}
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
