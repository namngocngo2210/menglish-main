// Nhận xét buổi học cho từng học sinh (TeacherPortalController::remarks): theo buổi (?session= hoặc ?date=, mặc định
// buổi hôm nay); "Lưu nháp" chưa hiện cho học viên, "Lưu nhận xét" hiện trên cổng học viên.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Card, Select } from '../../components/ui'
import { navigate, useQueryParam } from '../../router'
import { teacherApi, type RemarksPage as Page } from './api'
import { ClassTabs, Empty, Header, LoadError, Loading } from './components'
import { attLabel, REMARK_FIELDS } from './logic'

export function RemarksPage({ id }: { id: string }) {
  const session = useQueryParam('session')
  const date = useQueryParam('date')
  const [flash, setFlash] = useState('')
  const page = useQuery({
    queryKey: ['teacher-remarks', id, session, date],
    queryFn: () => teacherApi.remarks(id, { session: session || undefined, date: date || undefined }),
  })
  if (page.isPending) return <Loading />
  if (page.isError) return <LoadError title="Không mở được trang nhận xét" error={page.error} back={{ label: 'Về cổng giáo viên', path: '/teacher/home' }} />
  const p = page.data
  return (
    <>
      <Header
        title={`Nhận xét buổi học · ${p.classroom.name}`}
        description={p.session ? p.session.label : p.classroom.branch_name}
        actions={
          <Button variant="secondary" onClick={() => navigate('/teacher/home')}>
            Về cổng giáo viên
          </Button>
        }
      />
      <ClassTabs classId={id} active="remarks" sessionId={p.session?.id} />
      <div className="space-y-4">
        {p.recent_sessions.length > 0 && (
          <Card>
            <Select
              label="Buổi học"
              value={p.session?.id ?? ''}
              onChange={(e) => {
                setFlash('')
                navigate(`/teacher/classes/${id}/remarks?session=${e.target.value}`)
              }}
            >
              {!p.session && <option value="">Chọn buổi</option>}
              {p.recent_sessions.map((s) => (
                <option key={s.id} value={s.id} disabled={s.disabled}>
                  {s.label}
                </option>
              ))}
            </Select>
          </Card>
        )}
        {!p.session && <Alert tone="info">Lớp không có buổi học trong ngày này. Hãy chọn buổi cần nhận xét.</Alert>}
        {flash && <Alert tone="success">{flash}</Alert>}
        {p.block_reason && <Alert>{p.block_reason}</Alert>}
        {p.trial_guests.items.length > 0 && (
          <Card title={p.trial_guests.scope === 'past' ? 'Khách đã học thử buổi này' : 'Khách học thử buổi này'}>
            <ul className="space-y-1 text-sm">
              {p.trial_guests.items.map((g) => (
                <li key={g.id}>
                  <span className="font-medium">{g.name}</span>
                  {g.test_score && ` · Điểm test ${g.test_score}`} · {g.status_label}
                  {g.notes && <span className="text-on-surface-variant"> · {g.notes}</span>}
                </li>
              ))}
            </ul>
            <p className="mt-2 text-xs text-on-surface-variant">Nhận xét khách học thử ghi ở màn Khách học thử.</p>
          </Card>
        )}
        {p.session && <RemarksForm key={`${p.session.id}-${p.record_state}`} id={id} page={p} onSaved={setFlash} />}
      </div>
    </>
  )
}

function RemarksForm({ id, page, onSaved }: { id: string; page: Page; onSaved: (msg: string) => void }) {
  const qc = useQueryClient()
  const [remarks, setRemarks] = useState<Record<string, Record<string, string>>>(() =>
    Object.fromEntries(page.students.map((s) => [s.id, { ...s.remark }])),
  )
  const save = useMutation({
    mutationFn: (action: 'draft' | 'final') => teacherApi.saveRemarks(id, { session_id: page.session?.id ?? null, action, remarks }),
    onSuccess: (r) => {
      onSaved(r.message)
      qc.invalidateQueries({ queryKey: ['teacher-remarks', id] })
    },
  })
  const set = (sid: string, key: string, v: string) => setRemarks((cur) => ({ ...cur, [sid]: { ...cur[sid], [key]: v } }))
  return (
    <Card
      title={
        <span className="flex flex-wrap items-center gap-2">
          Học sinh ({page.students.length})
          {page.record_state === 'draft' && <Badge tone="warning">Bản nháp — chưa hiện cho học viên</Badge>}
          {page.record_state === 'saved' && <Badge tone="success">Đã lưu</Badge>}
        </span>
      }
    >
      {page.students.length === 0 ? (
        <Empty>Lớp chưa có học viên.</Empty>
      ) : (
        <div className="space-y-4">
          {page.students.map((s) => (
            <fieldset key={s.id} className={`rounded-lg border p-3 ${s.absent ? 'border-surface-container-highest opacity-75' : 'border-outline-variant'}`}>
              <legend className="flex items-center gap-2 px-1 text-sm font-semibold">
                {s.name}
                <Badge tone={s.absent ? 'error' : s.att_status === 'none' ? 'neutral' : 'success'}>{attLabel(s.att_status)}</Badge>
              </legend>
              <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                {REMARK_FIELDS.map((f) => (
                  <label key={f.key} className={`block text-xs ${f.key === 'comment' ? 'md:col-span-3' : ''}`}>
                    <span className="mb-1 block font-medium text-on-surface-variant">{f.label}</span>
                    {f.key === 'comment' ? (
                      <textarea
                        rows={2}
                        maxLength={2000}
                        aria-label={`${f.label} ${s.name}`}
                        className="block w-full rounded-lg border border-outline-variant px-2 py-1.5 text-sm"
                        value={remarks[s.id]?.[f.key] ?? ''}
                        onChange={(e) => set(s.id, f.key, e.target.value)}
                      />
                    ) : (
                      <input
                        maxLength={2000}
                        aria-label={`${f.label} ${s.name}`}
                        className="block h-9 w-full rounded-lg border border-outline-variant px-2 text-sm"
                        value={remarks[s.id]?.[f.key] ?? ''}
                        onChange={(e) => set(s.id, f.key, e.target.value)}
                      />
                    )}
                  </label>
                ))}
              </div>
            </fieldset>
          ))}
          {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
          <div className="flex flex-wrap justify-end gap-2">
            <Button variant="secondary" disabled={save.isPending || !!page.block_reason} onClick={() => save.mutate('draft')}>
              Lưu nháp
            </Button>
            <Button disabled={save.isPending || !!page.block_reason} onClick={() => save.mutate('final')}>
              {save.isPending ? 'Đang lưu…' : 'Lưu nhận xét'}
            </Button>
          </div>
        </div>
      )}
    </Card>
  )
}
