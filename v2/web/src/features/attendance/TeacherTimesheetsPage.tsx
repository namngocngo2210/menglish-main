// Giờ dạy giáo viên (Payroll/Timesheets/Teachers.vue): ca dạy trong tháng theo phạm vi lớp. Người xem toàn bộ có lịch dạy
// theo ngày (xác nhận chấm công), lọc chi nhánh / lớp / giáo viên, chốt nhiều ca, duyệt / từ chối, chỉnh tay; giáo viên chỉ
// có payroll.view_own xem ca của mình. Chọn một giáo viên → thẻ giáo viên + buổi thiếu chấm công.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Checkbox, Dialog, Field } from '../../components/ui'
import { EmptyState, Pager, StatCard } from '../platform/parts'
import { attendanceApi, type ScheduleSession, type TimesheetFilter, type TimesheetRow } from './api'
import { dateLabel, manualUrl, PUNCH_TONE, selectableIds, shiftText, STATUS_TONE, statusText } from './logic'
import { AppLink, OptionSelect, TableCard, TD, TH } from './parts'

const EMPTY: TimesheetFilter = { month: '', status: '', type: '', search: '', branch_id: '', class_id: '', user_id: '', day: '', page: '' }

const SCHEDULE_STATE: Record<ScheduleSession['state'], { label: string; tone: 'success' | 'info' | 'warning' | 'neutral' }> = {
  valid: { label: 'Đã chấm công', tone: 'success' },
  checkin: { label: 'GV đã check-in', tone: 'info' },
  pending: { label: 'Chờ đối soát', tone: 'warning' },
  none: { label: 'Chưa chấm công', tone: 'neutral' },
}

export function TeacherTimesheetsPage() {
  const qc = useQueryClient()
  const [f, setF] = useState<TimesheetFilter>(EMPTY)
  const [selected, setSelected] = useState<string[]>([])
  const [notice, setNotice] = useState('')
  const [rejecting, setRejecting] = useState<TimesheetRow | null>(null)
  const [adjusting, setAdjusting] = useState<TimesheetRow | null>(null)
  const set = (k: keyof TimesheetFilter, v: string) => {
    setSelected([])
    setF((x) => ({ ...x, [k]: v, page: k === 'page' ? v : '' }))
  }
  const page = useQuery({ queryKey: ['attendance', 'teachers', f], queryFn: () => attendanceApi.teachers(f), placeholderData: keepPreviousData })
  const done = (r: { message: string }) => {
    setNotice(r.message)
    setSelected([])
    qc.invalidateQueries({ queryKey: ['attendance', 'teachers'] })
  }
  const review = useMutation({ mutationFn: (id: string) => attendanceApi.review(id, 'valid'), onSuccess: done })
  const bulk = useMutation({ mutationFn: () => attendanceApi.bulkReview(selected), onSuccess: done })
  const confirm = useMutation({ mutationFn: attendanceApi.confirm, onSuccess: done })
  const d = page.data
  const err = page.error ?? review.error ?? bulk.error ?? confirm.error
  const selectable = d ? selectableIds(d.timesheets) : []
  const allChecked = selectable.length > 0 && selectable.every((id) => selected.includes(id))

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <h1 className="text-xl font-semibold">Giờ dạy giáo viên</h1>
        {d?.period && <Badge tone={d.period.locked ? 'error' : 'info'}>Kỳ lương {d.month_label}: {d.period.status_label}</Badge>}
      </div>
      {notice && <Alert tone="success">{notice}</Alert>}
      {err && <Alert>{errorMessage(err)}</Alert>}
      {d?.period_locked && <Alert tone="info">Kỳ lương tháng {d.month_label} đã khóa, không thể duyệt hoặc chỉnh ca dạy trong tháng này.</Alert>}

      {d && (
        <div className="grid gap-3 rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
          <Field label="Tháng" type="month" value={f.month || d.month} onChange={(e) => set('month', e.target.value)} />
          <OptionSelect label="Trạng thái" value={f.status} all="Tất cả trạng thái" options={d.statuses} onChange={(v) => set('status', v)} />
          <OptionSelect label="Loại ca" value={f.type} all="Tất cả loại ca" options={d.types} onChange={(v) => set('type', v)} />
          {d.can_view_all && (
            <>
              <OptionSelect label="Chi nhánh" value={f.branch_id} all="Tất cả chi nhánh" options={d.filter_branches} onChange={(v) => set('branch_id', v)} />
              <OptionSelect label="Lớp" value={f.class_id} all="Tất cả lớp" options={d.filter_classes} onChange={(v) => set('class_id', v)} />
              <OptionSelect label="Giáo viên" value={f.user_id} all="Tất cả giáo viên" options={d.filter_teachers} onChange={(v) => set('user_id', v)} />
              <Field label="Tìm" placeholder="Tên giáo viên, mã lớp" value={f.search} onChange={(e) => set('search', e.target.value)} />
            </>
          )}
        </div>
      )}

      {d && d.sub_pending_count > 0 && (
        <Alert tone="info">Có {d.sub_pending_count} buổi dạy thay đang chờ duyệt — ca dạy thay chỉ tính công sau khi được duyệt.</Alert>
      )}

      {d?.schedule_day && (
        <section className="space-y-2">
          <div className="flex flex-wrap items-end justify-between gap-3">
            <h2 className="text-base font-semibold">
              Lịch dạy {d.schedule_day.weekday}, {dateLabel(d.schedule_day.date)}
            </h2>
            <div className="w-48">
              <Field label="Ngày" type="date" value={f.day || d.schedule_day.date} onChange={(e) => set('day', e.target.value)} />
            </div>
          </div>
          <TableCard>
            <thead className="bg-surface-container-low">
              <tr>
                <th className={TH}>Lớp</th>
                <th className={TH}>Giờ</th>
                <th className={TH}>Giáo viên</th>
                <th className={TH}>Chấm công</th>
                <th className={`${TH} text-right`}>Thao tác</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-surface-container-highest">
              {d.schedule_sessions.map((s) => (
                <tr key={s.id}>
                  <td className={TD}>
                    <div className="font-medium">{s.class_code}</div>
                    <div className="text-xs text-on-surface-variant">{s.class_name}</div>
                  </td>
                  <td className={`${TD} tabular-nums`}>
                    {s.start_time} – {s.end_time}
                  </td>
                  <td className={TD}>{s.teacher_name ?? 'Chưa phân công'}</td>
                  <td className={TD}>
                    <Badge tone={SCHEDULE_STATE[s.state].tone}>{SCHEDULE_STATE[s.state].label}</Badge>
                    {s.state_note && <div className="text-xs text-on-surface-variant">{s.state_note}</div>}
                  </td>
                  <td className={`${TD} text-right`}>
                    {s.can_confirm && (
                      <Button className="h-8 px-3 text-xs" disabled={confirm.isPending} onClick={() => confirm.mutate(s.id)}>
                        Xác nhận chấm công
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
              {d.schedule_sessions.length === 0 && (
                <tr>
                  <td colSpan={5}>
                    <EmptyState icon="event_busy" title="Không có buổi học nào trong ngày" />
                  </td>
                </tr>
              )}
            </tbody>
          </TableCard>
        </section>
      )}

      {d?.teacher && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm">
          <div>
            <div className="font-semibold">{d.teacher.name}</div>
            <div className="text-sm text-on-surface-variant">
              {d.teacher.employee_code}
              {d.teacher.branch_name && ` · ${d.teacher.branch_name}`}
            </div>
          </div>
          {d.missing_sessions.length > 0 && <Badge tone="warning">{d.missing_sessions.length} buổi thiếu chấm công</Badge>}
        </div>
      )}

      {d && (
        <div className="grid gap-3 sm:grid-cols-3">
          <StatCard label="Chờ đối soát" value={d.summary.pending_review} icon="pending_actions" tone="warning" />
          <StatCard label="Hợp lệ" value={d.summary.valid} icon="task_alt" tone="success" />
          <StatCard label="Không hợp lệ" value={d.summary.invalid} icon="block" tone="error" />
        </div>
      )}

      {d && (
        <section className="space-y-2">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-base font-semibold">Ca dạy tháng {d.month_label}</h2>
            {d.can_review && selectable.length > 0 && (
              <Button disabled={selected.length === 0 || bulk.isPending} onClick={() => bulk.mutate()}>
                Chốt bảng công ({selected.length})
              </Button>
            )}
          </div>
          <TableCard footer={<Pager page={d.meta.page} perPage={d.meta.per_page} total={d.meta.total} unit="ca dạy" onPage={(p) => set('page', String(p))} />}>
            <thead className="bg-surface-container-low">
              <tr>
                {d.can_review && (
                  <th className={TH}>
                    <input
                      type="checkbox"
                      aria-label="Chọn tất cả ca chờ đối soát"
                      className="size-4 accent-primary"
                      disabled={selectable.length === 0}
                      checked={allChecked}
                      onChange={() => setSelected(allChecked ? [] : selectable)}
                    />
                  </th>
                )}
                <th className={TH}>Ngày</th>
                <th className={TH}>Ca học</th>
                <th className={TH}>Lớp</th>
                {d.can_view_all && <th className={TH}>Giáo viên</th>}
                <th className={TH}>Vào / Ra</th>
                <th className={TH}>Giờ</th>
                <th className={TH}>Trạng thái</th>
                {(d.can_review || d.can_adjust) && <th className={`${TH} text-right`}>Thao tác</th>}
              </tr>
            </thead>
            <tbody className="divide-y divide-surface-container-highest">
              {d.timesheets.map((t) => {
                const pick = selectable.includes(t.id)
                return (
                  <tr key={t.id}>
                    {d.can_review && (
                      <td className={TD}>
                        {pick && (
                          <input
                            type="checkbox"
                            aria-label={`Chọn ca ${dateLabel(t.teaching_date)} ${t.class_label}`}
                            className="size-4 accent-primary"
                            checked={selected.includes(t.id)}
                            onChange={(e) => setSelected((x) => (e.target.checked ? [...x, t.id] : x.filter((id) => id !== t.id)))}
                          />
                        )}
                      </td>
                    )}
                    <td className={`${TD} whitespace-nowrap`}>{dateLabel(t.teaching_date)}</td>
                    <td className={TD}>
                      <div>{shiftText(t)}</div>
                      <div className="text-xs text-on-surface-variant">
                        {t.type_label} · {t.source_label}
                      </div>
                    </td>
                    <td className={TD}>{t.class_label}</td>
                    {d.can_view_all && <td className={TD}>{t.teacher_name}</td>}
                    <td className={`${TD} tabular-nums`}>
                      <div>
                        {t.checkin_time ?? '—'} – {t.display_checkout ?? '—'}
                      </div>
                      <Badge tone={PUNCH_TONE[t.punch_state]}>{t.punch_state_label}</Badge>
                      {t.adjustment_short && (
                        <div className="text-xs text-on-surface-variant" title={t.adjustment_reason ?? undefined}>
                          {t.adjuster_name ? `${t.adjuster_name}: ` : ''}
                          {t.adjustment_short}
                        </div>
                      )}
                      {t.late_label && <div className="text-xs text-warning">{t.late_label}</div>}
                    </td>
                    <td className={TD}>{t.hours}</td>
                    <td className={TD}>
                      <Badge tone={STATUS_TONE[t.status]}>{statusText(t)}</Badge>
                      {t.reviewer_name && <div className="text-xs text-on-surface-variant">{t.reviewer_name}</div>}
                      {t.rejection_short && <div className="text-xs text-error">{t.rejection_short}</div>}
                      {t.notes_short && <div className="text-xs text-on-surface-variant">{t.notes_short}</div>}
                      {t.locked && <div className="text-xs text-on-surface-variant">Đã khóa kỳ lương</div>}
                    </td>
                    {(d.can_review || d.can_adjust) && (
                      <td className={`${TD} text-right whitespace-nowrap`}>
                        {!t.locked && d.can_review && t.status !== 'valid' && (
                          <Button className="mr-1 h-8 px-3 text-xs" aria-label={`Duyệt ${t.reject_label}`} disabled={review.isPending} onClick={() => review.mutate(t.id)}>
                            Duyệt
                          </Button>
                        )}
                        {!t.locked && d.can_review && t.status !== 'invalid' && (
                          <Button variant="danger" className="mr-1 h-8 px-3 text-xs" aria-label={`Từ chối ${t.reject_label}`} onClick={() => setRejecting(t)}>
                            Từ chối
                          </Button>
                        )}
                        {!t.locked && d.can_adjust && (
                          <Button variant="secondary" className="h-8 px-3 text-xs" aria-label={`Chỉnh tay ${t.edit_label}`} onClick={() => setAdjusting(t)}>
                            Chỉnh tay
                          </Button>
                        )}
                      </td>
                    )}
                  </tr>
                )
              })}
              {d.timesheets.length === 0 && (
                <tr>
                  <td colSpan={9}>
                    <EmptyState icon="event_note" title="Chưa có ca dạy nào trong tháng" />
                  </td>
                </tr>
              )}
            </tbody>
          </TableCard>
        </section>
      )}

      {d?.teacher && d.missing_sessions.length > 0 && (
        <section className="space-y-2">
          <h2 className="text-base font-semibold">Buổi thiếu chấm công</h2>
          <TableCard>
            <thead className="bg-surface-container-low">
              <tr>
                <th className={TH}>Ngày</th>
                <th className={TH}>Giờ</th>
                <th className={TH}>Lớp</th>
                <th className={`${TH} text-right`}>Thao tác</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-surface-container-highest">
              {d.missing_sessions.map((s) => (
                <tr key={s.id}>
                  <td className={TD}>{dateLabel(s.date)}</td>
                  <td className={`${TD} tabular-nums`}>
                    {s.start_time} – {s.end_time}
                  </td>
                  <td className={TD}>{s.class_label}</td>
                  <td className={`${TD} text-right`}>
                    {d.can_adjust && d.teacher && <AppLink to={manualUrl(d.teacher.id, s)}>Chấm công tay</AppLink>}
                  </td>
                </tr>
              ))}
            </tbody>
          </TableCard>
        </section>
      )}

      {rejecting && (
        <RejectDialog
          row={rejecting}
          onClose={() => setRejecting(null)}
          onDone={(r) => {
            setRejecting(null)
            done(r)
          }}
        />
      )}
      {adjusting && (
        <AdjustDialog
          row={adjusting}
          onClose={() => setAdjusting(null)}
          onDone={(r) => {
            setAdjusting(null)
            done(r)
          }}
        />
      )}
    </div>
  )
}

const TEXTAREA = 'block w-full rounded-lg border border-outline-variant px-3 py-2 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/25'

function RejectDialog({ row, onClose, onDone }: { row: TimesheetRow; onClose: () => void; onDone: (r: { message: string }) => void }) {
  const [reason, setReason] = useState('')
  const reject = useMutation({ mutationFn: () => attendanceApi.review(row.id, 'invalid', reason), onSuccess: onDone })
  return (
    <Dialog title="Từ chối ca dạy" onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          reject.mutate()
        }}
      >
        <p className="text-sm">
          {row.teacher_name} · {row.class_label} · {dateLabel(row.teaching_date)}
        </p>
        <div className="space-y-1.5">
          <label htmlFor="ts-reject" className="block text-sm font-medium">
            Lý do từ chối ca dạy
          </label>
          <textarea id="ts-reject" required maxLength={1000} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} className={TEXTAREA} />
        </div>
        {reject.isError && <Alert>{errorMessage(reject.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" variant="danger" disabled={reject.isPending}>
            Xác nhận
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function AdjustDialog({ row, onClose, onDone }: { row: TimesheetRow; onClose: () => void; onDone: (r: { message: string }) => void }) {
  const [timeIn, setTimeIn] = useState(row.checkin_time ?? '')
  const [timeOut, setTimeOut] = useState(row.checkout_time ?? row.display_checkout ?? '')
  const [reason, setReason] = useState('')
  const [late, setLate] = useState(String(row.late_minutes))
  const [early, setEarly] = useState(String(row.early_leave_minutes))
  const [notified, setNotified] = useState(row.late_notified)
  const adjust = useMutation({
    mutationFn: () =>
      attendanceApi.adjust(row.id, {
        time_in: timeIn,
        time_out: timeOut,
        adjustment_reason: reason,
        late_minutes: late.trim() === '' ? null : Number(late),
        early_leave_minutes: early.trim() === '' ? null : Number(early),
        late_notified: notified,
      }),
    onSuccess: onDone,
  })
  return (
    <Dialog title="Chỉnh giờ ca dạy" onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          adjust.mutate()
        }}
      >
        <p className="text-sm">
          {row.teacher_name} · {row.class_label} · {dateLabel(row.teaching_date)}
        </p>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Giờ vào" type="time" required value={timeIn} onChange={(e) => setTimeIn(e.target.value)} />
          <Field label="Giờ ra" type="time" required value={timeOut} onChange={(e) => setTimeOut(e.target.value)} />
          <Field label="Đi muộn (phút)" type="number" min={0} max={600} value={late} onChange={(e) => setLate(e.target.value)} />
          <Field label="Về sớm (phút)" type="number" min={0} max={600} value={early} onChange={(e) => setEarly(e.target.value)} />
        </div>
        <Checkbox label="Có báo trước đi muộn / về sớm" checked={notified} onChange={(e) => setNotified(e.target.checked)} />
        <div className="space-y-1.5">
          <label htmlFor="ts-adjust" className="block text-sm font-medium">
            Lý do điều chỉnh
          </label>
          <textarea id="ts-adjust" required minLength={5} maxLength={500} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} className={TEXTAREA} />
        </div>
        {adjust.isError && <Alert>{errorMessage(adjust.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={adjust.isPending}>
            Lưu điều chỉnh
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
