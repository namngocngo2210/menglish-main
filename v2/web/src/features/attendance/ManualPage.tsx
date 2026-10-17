// Chấm công đơn lẻ (Payroll/Timesheets/Manual.vue): chấm công tay một ca dạy cho giáo viên / trợ giảng — tìm nhân sự, chọn
// chi nhánh → lớp, ngày dạy, giờ vào / ra, đi muộn / về sớm, lý do. Kỳ lương đã khóa thì cảnh báo và khóa nút lưu.
// Mở từ "Buổi thiếu chấm công" thì các ô được điền sẵn qua tham số URL.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Button, Card, Checkbox, Field, Select } from '../../components/ui'
import { attendanceApi, type ManualForm } from './api'
import { classesOfBranch, filterTeachers, lockedRangeFor, manualDefaults, toManualInput, type ManualState } from './logic'
import { AppLink } from './parts'

export function ManualPage() {
  const form = useQuery({ queryKey: ['attendance', 'manual'], queryFn: attendanceApi.manualForm })
  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-semibold">Chấm công đơn lẻ</h1>
        <AppLink to="/payroll/timesheets/teachers">Về Giờ dạy giáo viên</AppLink>
      </div>
      {form.isError && <Alert>{errorMessage(form.error)}</Alert>}
      {form.data && <ManualFormCard form={form.data} />}
    </div>
  )
}

function ManualFormCard({ form }: { form: ManualForm }) {
  const qc = useQueryClient()
  const [s, setS] = useState<ManualState>(() => manualDefaults(form, new URLSearchParams(window.location.search)))
  const [q, setQ] = useState('')
  const [notice, setNotice] = useState('')
  const set = <K extends keyof ManualState>(k: K, v: ManualState[K]) => setS((x) => ({ ...x, [k]: v }))
  const save = useMutation({
    mutationFn: () => attendanceApi.storeManual(toManualInput(s)),
    onSuccess: (r) => {
      setNotice(r.message)
      setS((x) => ({ ...manualDefaults(form, new URLSearchParams()), branch_id: x.branch_id, teaching_date: x.teaching_date }))
      qc.invalidateQueries({ queryKey: ['attendance', 'teachers'] })
    },
  })
  const teachers = filterTeachers(form.teachers, q, s.user_id)
  const classes = classesOfBranch(form.classes, s.branch_id)
  const locked = lockedRangeFor(form.locked_ranges, s.teaching_date)

  return (
    <Card>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          setNotice('')
          save.mutate()
        }}
      >
        {notice && <Alert tone="success">{notice}</Alert>}
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Tìm nhân sự" placeholder="Tên, mã nhân viên, email" value={q} onChange={(e) => setQ(e.target.value)} />
          <Select label="Nhân sự được chấm công" required value={s.user_id} onChange={(e) => set('user_id', e.target.value)}>
            <option value="">— Chọn nhân sự —</option>
            {teachers.map((t) => (
              <option key={t.id} value={t.id}>
                {t.label}
              </option>
            ))}
          </Select>
          <Select
            label="Chi nhánh"
            value={s.branch_id}
            onChange={(e) => {
              const b = e.target.value
              setS((x) => ({ ...x, branch_id: b, class_id: classesOfBranch(form.classes, b).some((c) => c.id === x.class_id) ? x.class_id : '' }))
            }}
          >
            <option value="">Tất cả chi nhánh</option>
            {form.branches.map((b) => (
              <option key={b.value} value={b.value}>
                {b.label}
              </option>
            ))}
          </Select>
          <Select label="Lớp" required value={s.class_id} onChange={(e) => set('class_id', e.target.value)}>
            <option value="">— Chọn lớp —</option>
            {classes.map((c) => (
              <option key={c.id} value={c.id}>
                {c.label}
              </option>
            ))}
          </Select>
          <Field label="Ngày dạy" type="date" required max={form.today} value={s.teaching_date} onChange={(e) => set('teaching_date', e.target.value)} />
          <div className="grid grid-cols-2 gap-3">
            <Field label="Giờ vào" type="time" required value={s.time_in} onChange={(e) => set('time_in', e.target.value)} />
            <Field label="Giờ ra" type="time" required value={s.time_out} onChange={(e) => set('time_out', e.target.value)} />
          </div>
          <Field label="Đi muộn (phút)" type="number" min={0} max={600} value={s.late_minutes} onChange={(e) => set('late_minutes', e.target.value)} />
          <Field label="Về sớm (phút)" type="number" min={0} max={600} value={s.early_leave_minutes} onChange={(e) => set('early_leave_minutes', e.target.value)} />
        </div>
        <Checkbox label="Có báo trước đi muộn / về sớm" checked={s.late_notified} onChange={(e) => set('late_notified', e.target.checked)} />
        <details className="rounded-lg border border-surface-container-highest p-3 text-sm">
          <summary className="cursor-pointer font-medium">Tùy chọn khác (loại ca, đơn giá giờ)</summary>
          <div className="mt-3 grid gap-3 sm:grid-cols-2">
            <Select label="Loại ca" value={s.type} onChange={(e) => set('type', e.target.value)}>
              {form.types.map((t) => (
                <option key={t.value} value={t.value}>
                  {t.label}
                </option>
              ))}
            </Select>
            <Field
              label="Đơn giá giờ (đ)"
              type="number"
              min={1000}
              step={1000}
              placeholder="Theo hợp đồng"
              hint="Bỏ trống để tính theo đơn giá của nhân sự."
              value={s.hourly_rate}
              onChange={(e) => set('hourly_rate', e.target.value)}
            />
          </div>
        </details>
        <div className="space-y-1.5">
          <label htmlFor="manual-notes" className="block text-sm font-medium">
            Lý do chấm công tay
          </label>
          <textarea
            id="manual-notes"
            required
            minLength={5}
            maxLength={500}
            rows={3}
            value={s.notes}
            onChange={(e) => set('notes', e.target.value)}
            className="block w-full rounded-lg border border-outline-variant px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
          />
        </div>
        {locked && <Alert>Ngày dạy thuộc {locked.title} đã khóa — không thể chấm công tay cho ngày này.</Alert>}
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="flex justify-end">
          <Button type="submit" disabled={!!locked || save.isPending}>
            {save.isPending ? 'Đang lưu…' : 'Lưu chấm công'}
          </Button>
        </div>
      </form>
    </Card>
  )
}
