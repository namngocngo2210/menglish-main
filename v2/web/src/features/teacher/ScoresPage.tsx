// Nhập điểm mini test (TeacherPortalController::scores): mỗi lần một học sinh, 4 kỹ năng; điểm tổng = trung bình. Bài
// theo Unit của giáo trình lớp; lớp chưa gắn giáo trình thì nhập tên bài tự do. Dưới 7/10 tự vào danh sách bổ trợ.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Card, Field, Select } from '../../components/ui'
import { navigate, useQueryParam } from '../../router'
import { teacherApi, type ScoresPage as Page } from './api'
import { ClassTabs, Header, LoadError, Loading, Textarea, td, th } from './components'
import { overMax, scorePreview } from './logic'

function scoresHref(id: string, p: { unit_id?: string | null; name?: string | null; student_id?: string | null }) {
  const q = new URLSearchParams()
  if (p.unit_id) q.set('unit_id', p.unit_id)
  else if (p.name) q.set('name', p.name)
  if (p.student_id) q.set('student_id', p.student_id)
  const s = q.toString()
  return `/teacher/classes/${id}/scores${s ? `?${s}` : ''}`
}

export function ScoresPage({ id }: { id: string }) {
  const unitId = useQueryParam('unit_id')
  const name = useQueryParam('name')
  const studentId = useQueryParam('student_id')
  const [flash, setFlash] = useState('')
  const [saved, setSaved] = useState(0)
  const page = useQuery({
    queryKey: ['teacher-scores', id, unitId, name, studentId],
    queryFn: () => teacherApi.scores(id, { unit_id: unitId || undefined, name: name || undefined, student_id: studentId || undefined }),
  })
  if (page.isPending) return <Loading />
  if (page.isError) return <LoadError title="Không mở được trang nhập điểm" error={page.error} back={{ label: 'Về cổng giáo viên', path: '/teacher/home' }} />
  const p = page.data
  return (
    <>
      <Header
        title={`Nhập điểm mini test · ${p.classroom.name}`}
        description={`${p.classroom.code} · ${p.classroom.branch_name}`}
        actions={
          <Button variant="secondary" onClick={() => navigate('/teacher/home')}>
            Về cổng giáo viên
          </Button>
        }
      />
      <ClassTabs classId={id} active="scores" />
      <div className="space-y-4">
        {flash && <Alert tone="success">{flash}</Alert>}
        <TestPicker id={id} page={p} />
        <ScoreForm
          key={`${p.test_name}-${p.selected_student_id}-${saved}`}
          id={id}
          page={p}
          onSaved={(msg) => {
            setFlash(msg)
            setSaved((n) => n + 1)
          }}
        />
        <ScoreTable id={id} page={p} />
      </div>
    </>
  )
}

function TestPicker({ id, page }: { id: string; page: Page }) {
  const [name, setName] = useState(page.test_name ?? '')
  if (page.units.length > 0) {
    return (
      <Card>
        <Select label="Unit bài học" value={page.unit_id ?? ''} onChange={(e) => navigate(scoresHref(id, { unit_id: e.target.value }))}>
          <option value="">Chọn Unit</option>
          {page.units.map((u) => (
            <option key={u.value} value={u.value}>
              {u.label}
            </option>
          ))}
        </Select>
      </Card>
    )
  }
  return (
    <Card>
      <form
        className="flex flex-col gap-3 sm:flex-row sm:items-end"
        onSubmit={(e) => {
          e.preventDefault()
          navigate(scoresHref(id, { name: name.trim() }))
        }}
      >
        <div className="flex-1">
          <Field label="Tên bài kiểm tra" maxLength={255} value={name} onChange={(e) => setName(e.target.value)} hint="Lớp chưa gắn giáo trình: nhập tên bài tự do." />
        </div>
        <Button type="submit" variant="secondary">
          Mở bài
        </Button>
      </form>
    </Card>
  )
}

function ScoreForm({ id, page, onSaved }: { id: string; page: Page; onSaved: (msg: string) => void }) {
  const qc = useQueryClient()
  const [studentId, setStudentId] = useState(page.selected_student_id ?? '')
  const [testDate, setTestDate] = useState(page.test_date)
  const [maxScore, setMaxScore] = useState(page.current.max_score)
  const [skills, setSkills] = useState<Record<string, string>>(page.current.skills)
  const [note, setNote] = useState(page.current.note ?? '')
  const keys = page.skills.map((s) => s.key)
  const total = scorePreview(skills, keys)
  const save = useMutation({
    mutationFn: () =>
      teacherApi.saveScore(id, {
        unit_id: page.unit_id,
        name: page.test_name ?? '',
        student_id: studentId || null,
        test_date: testDate,
        max_score: maxScore,
        skills,
        note,
      }),
    onSuccess: (r) => {
      onSaved(r.message)
      qc.invalidateQueries({ queryKey: ['teacher-scores', id] })
      navigate(scoresHref(id, { unit_id: r.unit_id, name: r.name }))
    },
  })
  return (
    <Card title={page.test_name ? `Nhập điểm: ${page.test_name}` : 'Nhập điểm'}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          <Select label="Học sinh" value={studentId} onChange={(e) => setStudentId(e.target.value)}>
            <option value="">Chọn học sinh</option>
            {page.students.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
                {s.has_score ? ' (đã có điểm)' : ''}
              </option>
            ))}
          </Select>
          <Field label="Ngày kiểm tra" type="date" value={testDate} onChange={(e) => setTestDate(e.target.value)} />
          <Field label="Điểm tối đa" inputMode="decimal" value={maxScore} onChange={(e) => setMaxScore(e.target.value)} />
        </div>
        <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
          {page.skills.map((s) => (
            <Field
              key={s.key}
              label={s.label}
              inputMode="decimal"
              value={skills[s.key] ?? ''}
              onChange={(e) => setSkills((cur) => ({ ...cur, [s.key]: e.target.value }))}
            />
          ))}
        </div>
        <p className="text-sm">
          Điểm tổng (trung bình 4 kỹ năng): <span className="font-semibold">{total ? `${total}/${maxScore || '10'}` : '—'}</span>
        </p>
        {overMax(skills, maxScore) && <Alert>Điểm kỹ năng không được vượt quá điểm tối đa ({maxScore || '10'}).</Alert>}
        <Textarea label="Ghi chú" rows={2} maxLength={1000} value={note} onChange={(e) => setNote(e.target.value)} />
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="flex justify-end">
          <Button type="submit" disabled={save.isPending || !page.test_name}>
            {save.isPending ? 'Đang lưu…' : 'Lưu điểm'}
          </Button>
        </div>
      </form>
    </Card>
  )
}

function ScoreTable({ id, page }: { id: string; page: Page }) {
  return (
    <Card title={`Điểm cả lớp (${page.scored_count}/${page.students.length} học sinh đã có điểm)`}>
      {page.students.length === 0 ? (
        <p className="text-sm text-on-surface-variant">Lớp chưa có học viên.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[560px] text-left text-sm">
            <thead className="text-xs text-on-surface-variant">
              <tr>
                <th className={th}>Học sinh</th>
                {page.skills.map((s) => (
                  <th key={s.key} className={th}>
                    {s.label}
                  </th>
                ))}
                <th className={th}>Tổng</th>
                <th className={th} />
              </tr>
            </thead>
            <tbody className="divide-y divide-surface-container-highest">
              {page.students.map((s) => (
                <tr key={s.id}>
                  <td className={td}>{s.name}</td>
                  {page.skills.map((k) => (
                    <td key={k.key} className={td}>
                      {s.skills[k.key]}
                    </td>
                  ))}
                  <td className={td}>
                    {s.total ?? '—'} {s.low && <Badge tone="error">Dưới 7/10</Badge>}
                  </td>
                  <td className={td}>
                    <Button
                      variant="secondary"
                      className="h-8 px-3 text-xs"
                      disabled={!page.test_name}
                      onClick={() => navigate(scoresHref(id, { unit_id: page.unit_id, name: page.test_name, student_id: s.id }))}
                    >
                      {s.has_score ? 'Sửa' : 'Nhập'}
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  )
}
