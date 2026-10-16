// Báo cáo tháng / quý Học thuật (tường thuật) và báo cáo tháng giáo viên (chấm công + giảng dạy theo lớp).
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Alert, Badge, Button, Checkbox, Dialog, Field, Select } from '../../components/ui'
import { navigate } from '../../router'
import { reportsApi, type AcademicMonthlyForm, type AcademicQuarterlyForm, type ClassValue, type TeacherMonthlyForm, type WeekOfMonth } from './api'
import { HistoryList, LegacyList, PageHeader, Panel, ReportTabs, ReviewState, TextArea } from './components'
import { formatDate, quarterPeriod, suggestLevels } from './logic'
import { QueryState, SaveResult, usePeriod } from './shared'

function PeriodSelect({ label, value, options, onChange }: { label: string; value: string; options: { value: string; label: string }[]; onChange: (v: string) => void }) {
  return (
    <div className="w-60">
      <Select label={label} value={value} onChange={(e) => onChange(e.target.value)}>
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </Select>
    </div>
  )
}

const monthlyPlaceholders: Record<string, string> = {
  test_syllabus_review: 'Nhập nội dung rà soát bài kiểm tra và chương trình học...',
  materials_transfers: 'Nhập nội dung chuẩn bị tài liệu và kế hoạch chuyển lớp...',
  overall: 'Nhập nhận xét tổng quan về hoạt động học thuật trong tháng...',
  teacher_notes: 'Nhập nhận xét cụ thể cho các giáo viên cần lưu ý...',
}

const quarterlyPlaceholders: Record<string, string> = {
  academic_work: 'Nhập chi tiết các hoạt động học thuật đã triển khai trong quý...',
  teacher_staffing: 'Nhập tình hình nhân sự, tuyển dụng, đào tạo giáo viên...',
  next_plan: 'Định hướng và mục tiêu công việc cho quý tiếp theo...',
  program_progress: 'Đánh giá tiến độ...',
  implementation_quality: 'Đánh giá chất lượng...',
  improvement_priorities: 'Các điểm cần khắc phục...',
}

function narrativeState(values: Record<string, string | null>) {
  return Object.fromEntries(Object.entries(values ?? {}).map(([k, v]) => [k, v ?? '']))
}

// ═════════════ Báo cáo tháng Học thuật ═════════════

export function AcademicMonthlyPage() {
  const [month, setMonth] = usePeriod('month')
  const q = useQuery({ queryKey: ['reports', 'academic-monthly', month], queryFn: () => reportsApi.academicMonthly(month || undefined) })
  return <QueryState isPending={q.isPending} error={q.error}>{() => <AcademicMonthlyView key={q.data!.month} form={q.data!} onPick={setMonth} />}</QueryState>
}

function AcademicMonthlyView({ form, onPick }: { form: AcademicMonthlyForm; onPick: (m: string) => void }) {
  const qc = useQueryClient()
  const [values, setValues] = useState<Record<string, string>>(() => narrativeState(form.values))
  const [message, setMessage] = useState<string | null>(null)
  const [viewing, setViewing] = useState<WeekOfMonth | null>(null)
  const save = useMutation({
    mutationFn: () => reportsApi.saveAcademicMonthly({ month: form.month, narrative: values }),
    onSuccess: (r) => {
      setMessage(r.message)
      qc.invalidateQueries({ queryKey: ['reports'] })
    },
  })
  return (
    <div>
      <PageHeader title="Báo cáo tháng — Học thuật" actions={<PeriodSelect label="Chọn tháng" value={form.month} options={form.months} onChange={onPick} />} />
      <ReportTabs tabs={form.tabs} current="academic_monthly" />
      <div className="mb-6">
        <ReviewState review={form.review} />
      </div>
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_400px]">
        <form
          className="h-fit"
          onSubmit={(e) => {
            e.preventDefault()
            setMessage(null)
            save.mutate()
          }}
        >
          <Panel title="Tường thuật công việc">
            <SaveResult message={message} error={save.error} />
            {form.submitted_at && <Alert tone="info">Đã nộp báo cáo tháng này lúc {formatDate(form.submitted_at, 'H:i d/m/Y')}. Lưu lại để cập nhật.</Alert>}
            {form.fields.map((f) => (
              <TextArea
                key={f.value}
                label={f.label}
                rows={4}
                maxLength={form.max_field_length}
                placeholder={monthlyPlaceholders[f.value]}
                value={values[f.value] ?? ''}
                onChange={(v) => setValues({ ...values, [f.value]: v })}
              />
            ))}
            <div className="flex justify-end">
              <Button type="submit" disabled={save.isPending}>
                Lưu phần tường thuật
              </Button>
            </div>
          </Panel>
        </form>
        <div className="space-y-6">
          <Panel title="Báo cáo tuần trong tháng">
            <table className="w-full text-left text-sm">
              <thead className="text-xs text-on-surface-variant">
                <tr>
                  <th className="py-1 font-medium">Tuần</th>
                  <th className="py-1 font-medium">Khoảng ngày</th>
                  <th className="py-1 font-medium">PIC</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-surface-container-highest">
                {form.weekly_reports.map((w) => (
                  <tr key={w.week} className={w.content ? 'cursor-pointer hover:bg-surface-container-low' : ''} onClick={() => w.content && setViewing(w)}>
                    <td className="py-1.5 font-semibold">{w.label}</td>
                    <td className="py-1.5 font-mono text-on-surface-variant">{w.range}</td>
                    <td className="py-1.5">
                      {w.pic ?? (
                        <button
                          type="button"
                          className="text-xs text-on-surface-variant italic hover:text-primary"
                          onClick={(e) => {
                            e.stopPropagation()
                            navigate(`/reports/academic-weekly?week=${w.week}`)
                          }}
                        >
                          Chưa nộp
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Panel>
          <Panel title="Họp giáo viên">
            {form.meetings_note && <p className="text-xs text-on-surface-variant italic">Chưa có số liệu: {form.meetings_note}</p>}
            {form.meetings.length ? (
              <ul className="space-y-1 text-sm">
                {form.meetings.map((m) => (
                  <li key={m.id} className="flex items-center justify-between gap-2">
                    <span>
                      {formatDate(m.week_start)} · {m.teacher}
                    </span>
                    <Badge>{m.status_label}</Badge>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-on-surface-variant italic">Chưa ghi buổi họp giáo viên nào trong tháng này.</p>
            )}
          </Panel>
          <HistoryList items={form.history} current={form.month} title="Các tháng đã nộp" onPick={onPick} />
        </div>
      </div>
      {viewing && (
        <Dialog title={`${viewing.label} (${viewing.range}) · ${viewing.title ?? ''}`} onClose={() => setViewing(null)}>
          <p className="text-sm whitespace-pre-line">{viewing.content}</p>
          <div className="mt-4">
            <Button variant="secondary" onClick={() => navigate(`/reports/academic-weekly?week=${viewing.week}`)}>
              Mở báo cáo tuần
            </Button>
          </div>
        </Dialog>
      )}
    </div>
  )
}

// ═════════════ Báo cáo quý Học thuật ═════════════

export function AcademicQuarterlyPage() {
  const [quarter, setQuarter] = usePeriod('quarter')
  const q = useQuery({ queryKey: ['reports', 'academic-quarterly', quarter], queryFn: () => reportsApi.academicQuarterly(quarter || undefined) })
  return <QueryState isPending={q.isPending} error={q.error}>{() => <AcademicQuarterlyView key={q.data!.quarter} form={q.data!} onPick={setQuarter} />}</QueryState>
}

function AcademicQuarterlyView({ form, onPick }: { form: AcademicQuarterlyForm; onPick: (q: string) => void }) {
  const qc = useQueryClient()
  const [values, setValues] = useState<Record<string, string>>(() => narrativeState(form.values))
  const [message, setMessage] = useState<string | null>(null)
  const period = quarterPeriod(form.quarter)
  const save = useMutation({
    mutationFn: () => reportsApi.saveAcademicQuarterly({ quarter: form.quarter, narrative: values }),
    onSuccess: (r) => {
      setMessage(r.message)
      qc.invalidateQueries({ queryKey: ['reports', 'academic-quarterly'] })
    },
  })
  const startOptions = form.quarters.map((o) => {
    const p = quarterPeriod(o.value)
    return { value: o.value, label: `Tháng ${p.start}/${p.year}` }
  })
  return (
    <div>
      <PageHeader
        title="Báo cáo quý — Học thuật"
        meta="Hệ thống Quản trị Học thuật"
        actions={
          <div className="flex items-end gap-6">
            <PeriodSelect label="Tháng bắt đầu" value={form.quarter} options={startOptions} onChange={onPick} />
            <div>
              <p className="text-xs font-semibold text-on-surface-variant uppercase">Kỳ báo cáo</p>
              <p className="font-semibold">
                Tháng {period.start} - Tháng {period.end} / Năm {period.year}
              </p>
            </div>
          </div>
        }
      />
      <ReportTabs tabs={form.tabs} current="academic_quarterly" />
      <div className="mb-6">
        <ReviewState review={form.review} />
      </div>
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_340px]">
        <form
          className="space-y-6"
          onSubmit={(e) => {
            e.preventDefault()
            setMessage(null)
            save.mutate()
          }}
        >
          <SaveResult message={message} error={save.error} />
          {form.submitted_at && <Alert tone="info">Đã nộp báo cáo quý này lúc {formatDate(form.submitted_at, 'H:i d/m/Y')}. Lưu lại để cập nhật.</Alert>}
          {form.sections.map((g, i) => (
            <Panel key={g.title} title={g.title}>
              <div className={i > 0 ? 'grid grid-cols-1 gap-3 md:grid-cols-3' : 'space-y-3'}>
                {g.fields.map((f) => (
                  <TextArea
                    key={f.value}
                    label={f.label}
                    rows={i > 0 ? 3 : 4}
                    maxLength={form.max_field_length}
                    placeholder={quarterlyPlaceholders[f.value]}
                    value={values[f.value] ?? ''}
                    onChange={(v) => setValues({ ...values, [f.value]: v })}
                  />
                ))}
              </div>
            </Panel>
          ))}
          <div className="flex justify-end">
            <Button type="submit" disabled={save.isPending}>
              Lưu phần tường thuật
            </Button>
          </div>
        </form>
        <div className="space-y-6">
          <Panel title="Danh sách báo cáo tháng trong kỳ">
            <ul className="divide-y divide-surface-container-highest">
              {form.monthly_reports.map((m) => (
                <li key={m.month}>
                  <button type="button" className="block w-full py-2 text-left hover:bg-surface-container-low" onClick={() => navigate(`/reports/academic-monthly?month=${m.month}`)}>
                    <span className="block text-sm">{m.label}</span>
                    <span className={`block text-xs ${m.submitted ? 'text-success' : 'text-on-surface-variant'}`}>
                      {m.submitted ? `Đã nộp ${formatDate(m.updated_at)}` : 'Chưa nộp'}
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          </Panel>
          <HistoryList items={form.history} current={form.quarter} title="Các quý đã nộp" onPick={onPick} />
        </div>
      </div>
    </div>
  )
}

// ═════════════ Báo cáo tháng giáo viên ═════════════

export function TeacherMonthlyPage() {
  const [month, setMonth] = usePeriod('month')
  const q = useQuery({ queryKey: ['reports', 'teacher-monthly', month], queryFn: () => reportsApi.teacherMonthly(month || undefined) })
  return <QueryState isPending={q.isPending} error={q.error}>{() => <TeacherMonthlyView key={q.data!.month} form={q.data!} onPick={setMonth} />}</QueryState>
}

const pillOn: Record<string, string> = {
  success: 'border-success bg-success-container text-on-success-container',
  error: 'border-error bg-error-container text-on-error-container',
  info: 'border-info bg-info-container text-on-info-container',
}
// Đạt → xanh; chậm / thấp → đỏ; chuyên cần < 95% → xanh dương.
const toneOf = (field: string, opt: string) => (opt === 'on_track' || opt === 'high' ? 'success' : field === 'attendance_level' ? 'info' : 'error')

function TeacherMonthlyView({ form, onPick }: { form: TeacherMonthlyForm; onPick: (m: string) => void }) {
  const qc = useQueryClient()
  const lower = form.month_label.toLowerCase()
  const [attNote, setAttNote] = useState(form.attendance.note ?? '')
  const [general, setGeneral] = useState<Record<string, string>>(() => narrativeState(form.general))
  const [classes, setClasses] = useState<Record<string, ClassValue>>(() =>
    Object.fromEntries(
      form.classes.map((c) => {
        const saved = form.class_values[c.id] ?? {}
        return [c.id, { ...saved, ...suggestLevels(c, saved), sessions: saved.sessions ?? c.sessions_done, checks: { ...(saved.checks ?? {}) }, notes: { ...(saved.notes ?? {}) } }]
      }),
    ),
  )
  const [selected, setSelected] = useState(form.classes[0]?.id ?? '')
  const [message, setMessage] = useState<string | null>(null)
  const done = (r: { message: string }) => {
    setMessage(r.message)
    qc.invalidateQueries({ queryKey: ['reports', 'teacher-monthly'] })
  }
  const attendance = useMutation({ mutationFn: () => reportsApi.saveTeacherAttendance({ month: form.month, note: attNote || null }), onSuccess: done })
  const teaching = useMutation({
    mutationFn: (intent: 'draft' | 'submit') => reportsApi.saveTeacherMonthly({ month: form.month, intent, general, classes }),
    onSuccess: done,
  })
  const setClass = (id: string, patch: ClassValue) => setClasses({ ...classes, [id]: { ...classes[id], ...patch } })
  const c = form.classes.find((x) => x.id === selected)
  const v = c ? classes[c.id] : null
  const str = (k: string) => (v && typeof v[k] === 'string' ? (v[k] as string) : '')
  const checkRow = (key: string, label: string, note?: string) =>
    c &&
    v && (
      <div key={key} className="space-y-1 rounded-md p-2 hover:bg-surface-container-low">
        <Checkbox label={label} checked={!!v.checks?.[key]} onChange={(e) => setClass(c.id, { checks: { ...v.checks, [key]: e.target.checked } })} />
        {note && (
          <input
            className="ml-6 block w-[calc(100%-1.5rem)] rounded-lg border border-outline-variant px-2 py-1 text-sm"
            placeholder={note}
            aria-label={`${label}: ghi chú`}
            maxLength={1000}
            value={v.notes?.[key] ?? ''}
            onChange={(e) => setClass(c.id, { notes: { ...v.notes, [key]: e.target.value || null } })}
          />
        )}
      </div>
    )
  const choice = (key: string) => {
    const ch = form.choices.find((x) => x.key === key)
    if (!ch || !c) return null
    return (
      <div key={key}>
        <span className="mb-1 block text-xs text-on-surface-variant uppercase">{ch.label}</span>
        <div className="flex flex-wrap gap-2">
          {ch.options.map((o) => {
            const on = str(key) === o.value
            return (
              <label key={o.value} className={`cursor-pointer rounded-full border px-3 py-1 text-sm ${on ? pillOn[toneOf(key, o.value)] : 'border-outline-variant hover:bg-surface-container-low'}`}>
                <input type="radio" className="sr-only" name={`${c.id}-${key}`} checked={on} onChange={() => setClass(c.id, { [key]: o.value })} />
                {o.label}
              </label>
            )
          })}
        </div>
      </div>
    )
  }
  const classText = (key: string, rows = 2, placeholder?: string) => {
    const label = form.class_texts.find((t) => t.value === key)?.label ?? key
    return c && <TextArea key={key} label={label} rows={rows} maxLength={5000} placeholder={placeholder} value={str(key)} onChange={(t) => setClass(c.id, { [key]: t || null })} />
  }

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <PageHeader
        title="Báo cáo chung của giáo viên"
        meta="Hệ thống ghi nhận và quản lý công việc giảng dạy tháng"
        actions={<PeriodSelect label="Chọn tháng báo cáo" value={form.month} options={form.months} onChange={onPick} />}
      />
      <ReportTabs tabs={form.tabs} current="teacher_monthly" />
      <ReviewState review={form.review} />
      <SaveResult message={message} error={attendance.error ?? teaching.error} />

      <form
        onSubmit={(e) => {
          e.preventDefault()
          setMessage(null)
          attendance.mutate()
        }}
      >
        <Panel title="Báo cáo chấm công tháng">
          {form.attendance.submitted_at ? (
            <Badge tone="success">
              Đã nộp báo cáo chấm công {lower} lúc {formatDate(form.attendance.submitted_at, 'H:i d/m')}
            </Badge>
          ) : (
            <Badge tone="error">Chưa nộp báo cáo chấm công {lower}</Badge>
          )}
          <TextArea label="Nội dung mô tả (tùy chọn)" maxLength={5000} placeholder="Nhập ghi chú thêm về chấm công nếu có..." value={attNote} onChange={setAttNote} />
          <div className="flex justify-end">
            <Button type="submit" disabled={attendance.isPending}>
              Nộp báo cáo
            </Button>
          </div>
        </Panel>
      </form>

      <form
        onSubmit={(e) => {
          e.preventDefault()
          setMessage(null)
          const intent = (e.nativeEvent as SubmitEvent).submitter?.getAttribute('value') === 'draft' ? 'draft' : 'submit'
          teaching.mutate(intent)
        }}
      >
        <Panel title="Báo cáo giảng dạy hàng tháng">
          {form.status === 'submitted' ? (
            <Badge tone="success">
              Đã nộp báo cáo giảng dạy {lower} lúc {formatDate(form.submitted_at, 'H:i d/m')}. Lưu lại để cập nhật.
            </Badge>
          ) : form.status === 'draft' ? (
            <Badge tone="info">Đang lưu nháp, chưa nộp — hạn {form.due_label}</Badge>
          ) : (
            <Badge tone="error">
              Chưa nộp báo cáo giảng dạy {lower} — hạn {form.due_label}
            </Badge>
          )}
          <div className="grid grid-cols-1 gap-3 rounded-lg border border-surface-container-highest p-3 md:grid-cols-3">
            <Select label="Chọn lớp học" value={selected} onChange={(e) => setSelected(e.target.value)}>
              {!form.classes.length && <option value="">-- Chọn lớp học --</option>}
              {form.classes.map((x) => (
                <option key={x.id} value={x.id}>
                  {x.name}
                </option>
              ))}
            </Select>
            <Field label="Kỳ báo cáo" value={form.month_label} disabled readOnly />
            {c && v && (
              <Field
                label="Số buổi đã dạy"
                type="number"
                min={0}
                max={500}
                placeholder="Nhập số buổi..."
                value={v.sessions === null || v.sessions === undefined ? '' : String(v.sessions)}
                onChange={(e) => setClass(c.id, { sessions: e.target.value === '' ? null : e.target.value })}
              />
            )}
          </div>
          {!form.classes.length && (
            <p className="text-sm text-on-surface-variant italic">
              Không có lớp nào bạn dạy trong tháng này.{form.classes_note && ` (${form.classes_note})`}
            </p>
          )}

          {c && v && (
            <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
              <div className="space-y-4 xl:col-span-5">
                <section className="space-y-2 rounded-xl border border-surface-container-highest p-3">
                  <h3 className="border-b border-surface-container-highest pb-2 font-semibold">A. Học thuật</h3>
                  {choice('program_progress')}
                  {form.academic_checks.map((k) => checkRow(k.key, k.label, k.note))}
                  {classText('foreign_feedback')}
                  {classText('academic_note')}
                </section>
                <section className="space-y-2 rounded-xl border border-surface-container-highest p-3">
                  <h3 className="border-b border-surface-container-highest pb-2 font-semibold">B. Vận hành lớp</h3>
                  {form.operation_checks.map((k) => checkRow(k.key, k.label, k.note))}
                  {classText('operations_note')}
                </section>
              </div>
              <section className="space-y-3 rounded-xl border border-surface-container-highest p-3 xl:col-span-7">
                <h3 className="border-b border-surface-container-highest pb-2 font-semibold">C. Chất lượng lớp học</h3>
                <div className="grid grid-cols-1 gap-3 rounded-lg bg-surface-container-low p-3 sm:grid-cols-2">
                  {choice('attendance_level')}
                  {choice('homework_level')}
                </div>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">{form.quality_checks.map((k) => checkRow(k.key, k.label, k.note))}</div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  {classText('test_result', 1, 'VD: 85% học viên pass')}
                  {classText('attitude', 1, 'VD: Tích cực, hăng hái...')}
                </div>
                {classText('attention', 3, '- Nguyễn Văn A: Thường xuyên không làm bài tập về nhà...')}
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                  {classText('situation', 3, 'Mô tả vấn đề...')}
                  {classText('solution', 3, 'Cách xử lý của GV...')}
                </div>
                {classText('support_note', 2, 'Ghi chú yêu cầu hỗ trợ nếu cần...')}
              </section>
            </div>
          )}

          <section className="space-y-3 rounded-xl border border-surface-container-highest p-3">
            <h3 className="font-semibold">Nhận xét chung tháng (mọi lớp, tùy chọn)</h3>
            <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
              {form.general_fields.map((f) => (
                <TextArea key={f.value} label={f.label} rows={2} maxLength={10000} value={general[f.value] ?? ''} onChange={(t) => setGeneral({ ...general, [f.value]: t })} />
              ))}
            </div>
          </section>
          <div className="flex justify-end gap-3">
            {form.status !== 'submitted' && (
              <Button type="submit" variant="secondary" value="draft" disabled={teaching.isPending}>
                Lưu nháp
              </Button>
            )}
            <Button type="submit" value="submit" disabled={teaching.isPending}>
              Nộp báo cáo giảng dạy
            </Button>
          </div>
        </Panel>
      </form>

      <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
        <HistoryList items={form.history} current={form.month} title="Các tháng đã nộp" onPick={onPick} />
        <LegacyList items={form.legacy} />
      </div>
    </div>
  )
}
