// Trang lớp (ClassManagementController@show): thanh bước vòng đời, việc tiếp theo, tab con Tổng quan / Học viên /
// Lịch & buổi học (gán GVNN) / Điểm danh & báo cáo buổi.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Checkbox, Dialog, Select } from '../../components/ui'
import { can } from '../../nav'
import { navigate } from '../../router'
import { classesApi, type ClassPage, type SessionRow } from './api'
import { Header, Loading, Pill, Stat, td, th } from './components'
import { takeFlash } from './flash'
import { formatDate, seatText, weekdayName } from './logic'

function initialTab() {
  return new URLSearchParams(window.location.search).get('tab') || 'overview'
}

export function ClassDetailPage({ me, id }: { me: Me; id: string }) {
  const qc = useQueryClient()
  const [tab, setTab] = useState(initialTab)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(() => {
    const m = takeFlash()
    return m ? { tone: 'success', text: m } : null
  })
  const page = useQuery({ queryKey: ['class', id, tab], queryFn: () => classesApi.show(id, tab), placeholderData: keepPreviousData })
  const remove = useMutation({
    mutationFn: () => classesApi.remove(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['classes'] })
      navigate('/classes')
    },
    onError: (e) => setNotice({ tone: 'error', text: errorMessage(e) }),
  })
  const open = (t: string) => {
    setTab(t)
    window.history.replaceState(null, '', `/classes/${id}?tab=${t}`)
  }

  if (page.isPending) return <Loading />
  if (page.isError) {
    return (
      <Card title="Không mở được lớp">
        <Alert>{errorMessage(page.error)}</Alert>
        <Button variant="secondary" className="mt-4" onClick={() => navigate('/classes')}>
          Về danh sách lớp
        </Button>
      </Card>
    )
  }
  const p = page.data
  const k = p.klass

  return (
    <>
      <Header
        title={
          <span className="flex flex-wrap items-center gap-2">
            {k.name} <span className="font-mono text-sm text-on-surface-variant">{k.code}</span>
            <Pill tone={k.status_meta.color}>{k.status_meta.label}</Pill>
          </span>
        }
        description={[k.program, k.level, k.branch].filter(Boolean).join(' · ')}
        actions={
          <>
            <Button variant="secondary" onClick={() => navigate('/classes')}>
              Danh sách lớp
            </Button>
            {p.can_record_attendance && <Button onClick={() => navigate(`/classes/${id}/attendance`)}>Điểm danh</Button>}
            {p.can_manage && can(me, 'work_task.view') && (
              <Button variant="secondary" onClick={() => navigate(`/schedule-config?class_id=${id}`)}>
                Lịch & TKB
              </Button>
            )}
            {p.can_manage && (
              <Button variant="secondary" onClick={() => navigate(`/classes/${id}/edit`)}>
                Sửa lớp
              </Button>
            )}
            {p.can_delete && (
              <Button
                variant="danger"
                disabled={remove.isPending}
                onClick={() => window.confirm(`Xóa lớp ${k.name}?`) && remove.mutate()}
              >
                Xóa
              </Button>
            )}
          </>
        }
      />
      <div className="space-y-4">
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
        <ol className="flex flex-wrap gap-2" aria-label="Vòng đời lớp">
          {p.steps.map((s, i) => (
            <li key={s.key}>
              <button
                type="button"
                title={s.hint ?? undefined}
                onClick={() => s.tab && open(s.tab)}
                className={`rounded-full border px-3 py-1 text-xs ${
                  s.state === 'done'
                    ? 'border-success/30 bg-success-container text-on-success-container'
                    : s.state === 'current'
                      ? 'border-primary bg-primary-light font-medium text-primary'
                      : 'border-outline-variant text-on-surface-variant'
                }`}
              >
                {i + 1}. {s.label}
              </button>
            </li>
          ))}
        </ol>
        {p.next_action && (
          <div className="flex flex-wrap items-center gap-2 text-sm">
            <span className="text-on-surface-variant">Việc tiếp theo:</span>
            <button type="button" onClick={() => p.next_action?.tab && open(p.next_action.tab)}>
              <Pill tone={p.next_action.tone}>{p.next_action.label}</Pill>
            </button>
          </div>
        )}
        <div role="tablist" aria-label="Trang lớp" className="flex gap-1 overflow-x-auto border-b border-surface-container-highest">
          {p.tabs.map((t) => (
            <button
              key={t.key}
              role="tab"
              type="button"
              aria-selected={tab === t.key}
              onClick={() => open(t.key)}
              className="border-b-2 border-transparent px-3 py-2 text-sm whitespace-nowrap text-on-surface-variant hover:text-on-surface aria-selected:border-primary aria-selected:font-medium aria-selected:text-primary"
            >
              {t.label}
            </button>
          ))}
        </div>
        {tab === 'overview' && <Overview p={p} />}
        {tab === 'students' && <Students p={p} />}
        {tab === 'schedule' && <Schedule p={p} onNotice={setNotice} />}
        {tab === 'attendance' && <Attendance p={p} />}
        {(tab === 'academic' || tab === 'incidents') && (
          <Card>
            <p className="text-sm text-on-surface-variant">
              {tab === 'academic'
                ? 'Học thuật (Big Test, giáo trình, báo cáo lớp) thuộc phân hệ Học thuật, chưa chuyển sang hệ thống mới.'
                : 'Sự vụ của lớp thuộc phân hệ Sự vụ, chưa chuyển sang hệ thống mới.'}
            </p>
          </Card>
        )}
      </div>
    </>
  )
}

function Overview({ p }: { p: ClassPage }) {
  const k = p.klass
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat label="Sĩ số" value={seatText(p.seat.occupied, p.seat.capacity)} />
        <Stat label="Ngưỡng khai giảng" value={p.seat.not_started && p.seat.needed > 0 ? `${p.seat.min} (thiếu ${p.seat.needed})` : p.seat.min} />
        <Stat label="Buổi đã học" value={`${p.session_progress.done}/${p.session_progress.total}`} />
        <Stat label="Còn chỗ" value={p.seat.left ?? 'Không giới hạn'} />
      </div>
      <Card title="Thông tin lớp">
        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[10rem_1fr]">
          <dt className="text-on-surface-variant">Giáo viên</dt>
          <dd>{k.teacher ?? '—'}</dd>
          <dt className="text-on-surface-variant">GVNN mặc định</dt>
          <dd>{k.foreign_teacher ?? '—'}</dd>
          {p.upcoming_foreign_teachers && p.upcoming_foreign_teachers.length > 0 && (
            <>
              <dt className="text-on-surface-variant">GVNN 7 ngày tới</dt>
              <dd>{p.upcoming_foreign_teachers.join(', ')}</dd>
            </>
          )}
          <dt className="text-on-surface-variant">Phòng học</dt>
          <dd>{k.room ?? '—'}</dd>
          <dt className="text-on-surface-variant">Lịch học</dt>
          <dd>{k.schedule_text || 'Chưa cấu hình'}</dd>
          <dt className="text-on-surface-variant">Thời gian</dt>
          <dd>
            {formatDate(k.start_date)} – {formatDate(k.end_date)}
          </dd>
          <dt className="text-on-surface-variant">Buổi tiếp theo</dt>
          <dd>
            {p.next_session
              ? `${formatDate(p.next_session.date)} ${p.next_session.start}–${p.next_session.end}${p.next_session.room ? ` · ${p.next_session.room}` : ''}`
              : '—'}
          </dd>
        </dl>
      </Card>
    </div>
  )
}

function Students({ p }: { p: ClassPage }) {
  const rows = p.students ?? []
  return (
    <Card title={`Học viên (${rows.length})`}>
      {rows.length === 0 ? (
        <p className="text-sm text-on-surface-variant">
          Lớp chưa có học viên. Xếp lớp ở{' '}
          <button type="button" className="text-primary hover:underline" onClick={() => navigate('/students/enrollments')}>
            Tiếp nhận & Xếp lớp
          </button>
          .
        </p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-left text-sm">
            <thead className="text-xs text-on-surface-variant">
              <tr>
                <th className={th}>Học viên</th>
                <th className={th}>Ngày sinh</th>
                <th className={th}>Phụ huynh</th>
                <th className={th}>Trạng thái</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-surface-container-highest">
              {rows.map((s) => (
                <tr key={s.id} className="cursor-pointer hover:bg-surface-container-low" onClick={() => navigate(`/students/${s.id}`)}>
                  <td className={td}>
                    <p className="font-medium">{s.name}</p>
                    <p className="font-mono text-xs text-on-surface-variant">
                      {s.code}
                      {!s.is_main && ' · liên kết lớp khác'}
                    </p>
                  </td>
                  <td className={td}>{formatDate(s.dob)}</td>
                  <td className={td}>
                    {s.parent_name ?? '—'}
                    {s.phone && <p className="text-xs text-on-surface-variant">{s.phone}</p>}
                  </td>
                  <td className={td}>{s.status_label}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  )
}

function Schedule({ p, onNotice }: { p: ClassPage; onNotice: (n: { tone: 'success' | 'error'; text: string }) => void }) {
  const rows = p.sessions ?? []
  const [picked, setPicked] = useState<string[]>([])
  const [dialog, setDialog] = useState(false)
  const editable = rows.filter((r) => r.editable)
  return (
    <Card title={`Lịch & buổi học (${rows.length} buổi, ${p.upcoming_count} sắp tới)`}>
      {p.can_manage && editable.length > 0 && (
        <div className="mb-3 flex flex-wrap items-center gap-2 text-sm">
          <Button variant="secondary" disabled={picked.length === 0} onClick={() => setDialog(true)}>
            Gán GVNN cho {picked.length} buổi
          </Button>
          <button type="button" className="text-primary hover:underline" onClick={() => setPicked(editable.map((r) => r.id))}>
            Chọn mọi buổi sắp tới
          </button>
        </div>
      )}
      {rows.length === 0 ? (
        <p className="text-sm text-on-surface-variant">Lớp chưa có buổi học. Cấu hình lịch tuần ở Lịch & TKB lớp.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[720px] text-left text-sm">
            <thead className="text-xs text-on-surface-variant">
              <tr>
                {p.can_manage && <th className={th}></th>}
                <th className={th}>Ngày</th>
                <th className={th}>Giờ</th>
                <th className={th}>Phòng</th>
                <th className={th}>Giáo viên</th>
                <th className={th}>GVNN</th>
                <th className={th}>Ghi chú</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-surface-container-highest">
              {rows.map((s) => (
                <SessionLine key={s.id} s={s} manage={p.can_manage} picked={picked} setPicked={setPicked} />
              ))}
            </tbody>
          </table>
        </div>
      )}
      {dialog && (
        <ForeignDialog
          p={p}
          ids={picked}
          onClose={() => setDialog(false)}
          onDone={(text) => {
            setDialog(false)
            setPicked([])
            onNotice({ tone: 'success', text })
          }}
        />
      )}
    </Card>
  )
}

function SessionLine({ s, manage, picked, setPicked }: { s: SessionRow; manage: boolean; picked: string[]; setPicked: (v: string[]) => void }) {
  const on = picked.includes(s.id)
  return (
    <tr className={s.cancelled ? 'text-on-surface-variant line-through' : s.is_today ? 'bg-primary-light/40' : ''}>
      {manage && (
        <td className={td}>
          {s.editable && (
            <input
              type="checkbox"
              aria-label={`Chọn buổi ${formatDate(s.date)}`}
              className="size-4 accent-primary"
              checked={on}
              onChange={() => setPicked(on ? picked.filter((x) => x !== s.id) : [...picked, s.id])}
            />
          )}
        </td>
      )}
      <td className={`${td} whitespace-nowrap`}>
        {weekdayName(s.weekday)}, {formatDate(s.date)}
      </td>
      <td className={`${td} font-mono`}>
        {s.start}–{s.end}
      </td>
      <td className={td}>{s.room || '—'}</td>
      <td className={td}>
        {s.teacher || '—'}
        {s.assistants && <p className="text-xs text-on-surface-variant">TG: {s.assistants}</p>}
      </td>
      <td className={td}>{s.foreign_teacher ?? '—'}</td>
      <td className={td}>
        {s.type === 'makeup' && 'Học bù'}
        {s.type === 'support' && 'Phụ đạo'}
        {s.holiday && ` Nghỉ lễ: ${s.holiday}`}
      </td>
    </tr>
  )
}

function ForeignDialog({ p, ids, onClose, onDone }: { p: ClassPage; ids: string[]; onClose: () => void; onDone: (t: string) => void }) {
  const qc = useQueryClient()
  const [teacher, setTeacher] = useState('')
  const [setDefault, setSetDefault] = useState(false)
  const save = useMutation({
    mutationFn: () => classesApi.assignForeign(p.klass.id, { foreign_teacher_id: teacher, session_ids: ids, set_default: setDefault }),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['class', p.klass.id] })
      onDone(r.message)
    },
  })
  return (
    <Dialog title="Gán GVNN cho buổi học" onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <p className="text-sm text-on-surface-variant">{ids.length} buổi đã chọn (chỉ buổi sắp tới, chưa điểm danh).</p>
        <Select label="GVNN" value={teacher} onChange={(e) => setTeacher(e.target.value)}>
          <option value="">Chọn GVNN</option>
          <option value="none">Không có GVNN (gỡ)</option>
          {(p.foreign_teacher_options ?? []).map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
        {teacher && teacher !== 'none' && (
          <Checkbox label="Đặt làm GVNN mặc định của lớp" checked={setDefault} onChange={(e) => setSetDefault(e.target.checked)} />
        )}
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

function Attendance({ p }: { p: ClassPage }) {
  const rows = p.attendance_rows ?? []
  const st = p.attendance_stats
  return (
    <div className="space-y-4">
      {st && (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
          <Stat label="Buổi đã qua" value={st.past} />
          <Stat label="Đã điểm danh" value={st.done} />
          <Stat label="Chưa điểm danh" value={st.missing} tone={st.missing ? 'error' : undefined} />
        </div>
      )}
      <Card title="Điểm danh theo buổi">
        {rows.length === 0 ? (
          <p className="text-sm text-on-surface-variant">Chưa có buổi học.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[560px] text-left text-sm">
              <thead className="text-xs text-on-surface-variant">
                <tr>
                  <th className={th}>Buổi</th>
                  <th className={th}>Trạng thái</th>
                  <th className={th}>Số học viên đã điểm danh</th>
                  <th className={th}></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-surface-container-highest">
                {rows.map((r) => (
                  <tr key={r.id}>
                    <td className={`${td} whitespace-nowrap`}>
                      {formatDate(r.date)} <span className="font-mono">{r.start}–{r.end}</span>
                    </td>
                    <td className={td}>
                      <Pill tone={r.state.color}>{r.state.label}</Pill>
                    </td>
                    <td className={`${td} font-mono`}>{r.attendances_count}</td>
                    <td className={`${td} text-right`}>
                      {p.can_record_attendance && !r.cancelled && (
                        <button
                          type="button"
                          className="text-primary hover:underline"
                          onClick={() => navigate(`/classes/${p.klass.id}/attendance?session=${r.id}`)}
                        >
                          Mở điểm danh
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  )
}
