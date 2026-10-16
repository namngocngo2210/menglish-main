// Điểm danh một buổi (TeacherPortalController::attendance): GV điểm danh trong khung ±N giờ, Học vụ / Quản lý điểm danh
// thay hoặc điểm danh bù buổi đã qua; "Vắng" / "Vắng có phép" bắt buộc ghi lý do.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Button, Card, Select } from '../../components/ui'
import { navigate } from '../../router'
import { classesApi, type AttendanceRecord, type AttendanceSheet } from './api'
import { Header, Loading, td, th } from './components'
import { missingNotes } from './logic'

export function AttendancePage({ id }: { id: string }) {
  const [session, setSession] = useState(() => new URLSearchParams(window.location.search).get('session') ?? '')
  const sheet = useQuery({ queryKey: ['class-attendance', id, session], queryFn: () => classesApi.attendance(id, { session: session || undefined }) })
  if (sheet.isPending) return <Loading />
  if (sheet.isError) {
    return (
      <Card title="Không mở được trang điểm danh">
        <Alert>{errorMessage(sheet.error)}</Alert>
        <Button variant="secondary" className="mt-4" onClick={() => navigate(`/classes/${id}`)}>
          Về trang lớp
        </Button>
      </Card>
    )
  }
  return (
    <Sheet
      key={sheet.data.session?.id ?? 'none'}
      id={id}
      sheet={sheet.data}
      onSession={(s) => {
        setSession(s)
        window.history.replaceState(null, '', `/classes/${id}/attendance${s ? `?session=${s}` : ''}`)
      }}
    />
  )
}

function Sheet({ id, sheet, onSession }: { id: string; sheet: AttendanceSheet; onSession: (s: string) => void }) {
  const qc = useQueryClient()
  const [records, setRecords] = useState<AttendanceRecord[]>(() =>
    sheet.students.map((s) => ({ student_id: s.id, status: s.status || 'present', note: s.note ?? '' })),
  )
  const [done, setDone] = useState('')
  const missing = missingNotes(records)
  const blocked = sheet.block_reason || sheet.window_reason
  const save = useMutation({
    mutationFn: () => classesApi.saveAttendance(id, { session_id: sheet.session?.id, records }),
    onSuccess: (r) => {
      setDone(r.message)
      qc.invalidateQueries({ queryKey: ['class', id] })
      qc.invalidateQueries({ queryKey: ['class-attendance', id] })
    },
  })
  const update = (i: number, patch: Partial<AttendanceRecord>) => setRecords((cur) => cur.map((r, j) => (j === i ? { ...r, ...patch } : r)))

  return (
    <>
      <Header
        title={`Điểm danh · ${sheet.classroom.name}`}
        description={
          sheet.session
            ? `${sheet.session.date} · ${sheet.session.time}${sheet.session.room_label ? ` · ${sheet.session.room_label}` : ''} · ${sheet.classroom.branch_name}`
            : sheet.classroom.branch_name
        }
        actions={
          <Button variant="secondary" onClick={() => navigate(`/classes/${id}?tab=attendance`)}>
            Về trang lớp
          </Button>
        }
      />
      <div className="space-y-4">
        {sheet.recent_sessions.length > 0 && (
          <Card>
            <Select label="Buổi học" value={sheet.session?.id ?? ''} onChange={(e) => onSession(e.target.value)}>
              {!sheet.session && <option value="">Chọn buổi</option>}
              {sheet.recent_sessions.map((s) => (
                <option key={s.id} value={s.id} disabled={s.disabled}>
                  {s.label}
                </option>
              ))}
            </Select>
          </Card>
        )}
        {!sheet.session && <Alert tone="info">Lớp không có buổi học hôm nay. Chọn buổi cần điểm danh.</Alert>}
        {sheet.on_behalf && sheet.on_behalf_name && (
          <Alert tone="info">Bạn đang điểm danh thay cho giáo viên {sheet.on_behalf_name}.</Alert>
        )}
        {blocked && <Alert>{blocked}</Alert>}
        {done && <Alert tone="success">{done}</Alert>}
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        {sheet.session && (
          <Card title={`Học viên (${sheet.students.length})`}>
            {sheet.students.length === 0 ? (
              <p className="text-sm text-on-surface-variant">Lớp chưa có học viên để điểm danh.</p>
            ) : (
              <form
                onSubmit={(e) => {
                  e.preventDefault()
                  setDone('')
                  save.mutate()
                }}
              >
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[640px] text-left text-sm">
                    <thead className="text-xs text-on-surface-variant">
                      <tr>
                        <th className={th}>Học viên</th>
                        <th className={th}>Trạng thái</th>
                        <th className={th}>Ghi chú / lý do</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-surface-container-highest">
                      {sheet.students.map((s, i) => {
                        const r = records[i]
                        const needNote = (r.status === 'absent' || r.status === 'excused') && r.note.trim() === ''
                        return (
                          <tr key={s.id}>
                            <td className={td}>
                              <p className="font-medium">{s.name}</p>
                              <p className="font-mono text-xs text-on-surface-variant">
                                {s.code}
                                {s.linked && ' · lớp liên kết'}
                                {s.recorder_name && ` · ghi bởi ${s.recorder_name}`}
                              </p>
                            </td>
                            <td className={td}>
                              <div className="flex flex-wrap gap-1" role="radiogroup" aria-label={`Trạng thái ${s.name}`}>
                                {sheet.statuses.map((o) => (
                                  <button
                                    key={o.value}
                                    type="button"
                                    role="radio"
                                    aria-checked={r.status === o.value}
                                    onClick={() => update(i, { status: o.value })}
                                    className={`rounded-lg border px-2 py-1 text-xs ${
                                      r.status === o.value ? 'border-primary bg-primary text-white' : 'border-outline-variant hover:bg-surface-container-low'
                                    }`}
                                  >
                                    {o.label}
                                  </button>
                                ))}
                              </div>
                            </td>
                            <td className={td}>
                              <input
                                aria-label={`Ghi chú ${s.name}`}
                                className={`block h-9 w-full rounded-lg border px-2 text-sm ${needNote ? 'border-error' : 'border-outline-variant'}`}
                                value={r.note}
                                maxLength={500}
                                placeholder={needNote ? 'Bắt buộc ghi lý do' : ''}
                                onChange={(e) => update(i, { note: e.target.value })}
                              />
                            </td>
                          </tr>
                        )
                      })}
                    </tbody>
                  </table>
                </div>
                <div className="mt-4 flex flex-wrap items-center justify-end gap-3">
                  {missing > 0 && <span className="text-sm text-error">Còn {missing} học viên nghỉ chưa ghi lý do.</span>}
                  <Button type="submit" disabled={save.isPending || Boolean(blocked) || missing > 0}>
                    Lưu điểm danh
                  </Button>
                </div>
              </form>
            )}
          </Card>
        )}
      </div>
    </>
  )
}
