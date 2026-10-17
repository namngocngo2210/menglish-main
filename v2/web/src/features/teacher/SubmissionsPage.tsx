// Chấm bài nộp của lớp (StudentPortalController::teacherSubmissions / markSubmission): chọn lớp, tab theo hạng mục bài
// tập + "Phát âm"; chấm điểm (chữ, tối đa 20 ký tự) và phản hồi.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Card, Dialog, Field, Select } from '../../components/ui'
import { navigate, useQueryParam } from '../../router'
import { teacherApi, type Submission } from './api'
import { Empty, Header, LoadError, Loading, Textarea } from './components'

export function SubmissionsPage() {
  const classId = useQueryParam('class_id')
  const type = useQueryParam('type')
  const [flash, setFlash] = useState('')
  const [marking, setMarking] = useState<Submission | null>(null)
  const page = useQuery({
    queryKey: ['teacher-submissions', classId, type],
    queryFn: () => teacherApi.submissions({ class_id: classId || undefined, type: type || undefined }),
  })
  if (page.isPending) return <Loading />
  if (page.isError) return <LoadError title="Không mở được màn chấm bài" error={page.error} back={{ label: 'Về danh sách lớp', path: '/teacher/submissions' }} />
  const p = page.data
  const href = (c: string | undefined, t: string) => {
    const q = new URLSearchParams()
    if (c) q.set('class_id', c)
    if (t) q.set('type', t)
    return `/teacher/submissions?${q.toString()}`
  }
  const pron = p.active_tab === 'pronunciation'
  return (
    <>
      <Header title="Chấm & đánh giá bài nộp" description={p.current_class ? `Lớp ${p.current_class.name}` : undefined} />
      <div className="space-y-4">
        {p.classes.length === 0 ? (
          <Card>
            <Empty>Bạn chưa phụ trách lớp nào.</Empty>
          </Card>
        ) : (
          <Card>
            <Select
              label="Lớp"
              value={p.current_class?.id ?? ''}
              onChange={(e) => {
                setFlash('')
                navigate(href(e.target.value, p.active_tab))
              }}
            >
              {p.classes.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                  {c.code ? ` (${c.code})` : ''}
                </option>
              ))}
            </Select>
          </Card>
        )}
        <nav className="flex flex-wrap gap-2" aria-label="Loại bài nộp">
          {p.tabs.map((t) => (
            <button
              key={t.value}
              type="button"
              aria-pressed={t.value === p.active_tab}
              onClick={() => {
                setFlash('')
                navigate(href(p.current_class?.id, t.value))
              }}
              className={`rounded-full border px-3 py-1 text-xs font-medium ${
                t.value === p.active_tab ? 'border-primary bg-primary text-white' : 'border-outline-variant text-on-surface-variant hover:bg-surface-container-low'
              }`}
            >
              {t.label}
            </button>
          ))}
        </nav>
        {flash && <Alert tone="success">{flash}</Alert>}
        {pron && p.pronunciation_pending && (
          <Alert tone="info">Bài phát âm: chờ phân hệ Cổng Phụ huynh & Học sinh chuyển sang hệ thống mới.</Alert>
        )}
        <Card title={`Bài nộp (${p.submissions.length})`}>
          {p.submissions.length === 0 ? (
            <Empty>Chưa có bài nộp nào.</Empty>
          ) : (
            <ul className="divide-y divide-surface-container-highest">
              {p.submissions.map((s) => (
                <li key={s.id} className="flex flex-col gap-2 py-3 text-sm sm:flex-row sm:items-start sm:justify-between">
                  <div className="min-w-0 space-y-1">
                    <p className="flex flex-wrap items-center gap-2 font-semibold">
                      {s.student_name}
                      <Badge tone={s.status === 'reviewed' ? 'success' : 'warning'}>{s.status === 'reviewed' ? 'Đã chấm' : 'Chờ chấm'}</Badge>
                      {s.score && <Badge tone="info">Điểm: {s.score}</Badge>}
                    </p>
                    <p className="text-xs text-on-surface-variant">
                      {s.homework_label || s.unit_title} · Nộp lúc {s.submitted_at}
                    </p>
                    {s.notes && <p className="whitespace-pre-line">{s.notes}</p>}
                    {s.attachment_url && (
                      <a href={s.attachment_url} target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
                        {s.attachment_name || 'Xem bài làm'}
                      </a>
                    )}
                    {s.audio_url && <audio controls preload="none" src={s.audio_url} className="h-8 max-w-full" />}
                    {s.feedback && <p className="text-xs text-on-surface-variant">Phản hồi: {s.feedback}</p>}
                  </div>
                  <Button variant="secondary" className="h-8 shrink-0 px-3 text-xs" onClick={() => setMarking(s)}>
                    {s.status === 'reviewed' ? 'Chấm lại' : 'Chấm bài'}
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
      {marking && (
        <MarkDialog
          sub={marking}
          pronunciation={pron}
          onClose={() => setMarking(null)}
          onDone={(msg) => {
            setFlash(msg)
            setMarking(null)
          }}
        />
      )}
    </>
  )
}

function MarkDialog({
  sub,
  pronunciation,
  onClose,
  onDone,
}: {
  sub: Submission
  pronunciation: boolean
  onClose: () => void
  onDone: (msg: string) => void
}) {
  const qc = useQueryClient()
  const [score, setScore] = useState(sub.score)
  const [feedback, setFeedback] = useState(sub.feedback)
  const mark = useMutation({
    mutationFn: () => teacherApi.mark(sub.id, { score, feedback }),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['teacher-submissions'] })
      onDone(r.message)
    },
  })
  return (
    <Dialog title={`Chấm bài · ${sub.student_name}`} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          mark.mutate()
        }}
      >
        <Field
          label={pronunciation ? 'Điểm (thang 100, bắt buộc)' : 'Điểm'}
          maxLength={20}
          required={pronunciation}
          value={score}
          onChange={(e) => setScore(e.target.value)}
          placeholder="Ví dụ: 9/10"
        />
        <Textarea label="Phản hồi cho học sinh" rows={4} maxLength={1000} value={feedback} onChange={(e) => setFeedback(e.target.value)} />
        {mark.isError && <Alert>{errorMessage(mark.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={mark.isPending}>
            {mark.isPending ? 'Đang lưu…' : 'Lưu điểm & phản hồi'}
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
