// Chấm bài nộp của lớp (StudentPortalController::teacherSubmissions / markSubmission, Portal/TeacherSubmissions.vue): chọn
// lớp mình dạy / trợ giảng, lọc theo loại bài + tab "Phát âm"; chấm bản ghi âm (điểm bắt buộc) hoặc đánh dấu bài nộp đã
// xem (điểm tùy chọn). Bài đã chấm chỉ hiện nhãn "Đã chấm: …" / "Đã xem" như Laravel.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Icon, Select } from '../../components/ui'
import { can } from '../../nav'
import { navigate, useQueryParam } from '../../router'
import { teacherApi, type Submission } from './api'
import { Header, LinkButton, LoadError, Loading } from './components'
import { initials, reviewedLabel, SUBMISSION_TAB_ICONS, submissionsHref } from './logic'

export function SubmissionsPage({ me }: { me?: Me }) {
  const classId = useQueryParam('class_id')
  const type = useQueryParam('type')
  const [flash, setFlash] = useState<{ ok: boolean; text: string } | null>(null)
  const page = useQuery({
    queryKey: ['teacher-submissions', classId, type],
    queryFn: () => teacherApi.submissions({ class_id: classId || undefined, type: type || undefined }),
  })
  if (page.isPending) return <Loading />
  if (page.isError) return <LoadError title="Không mở được màn chấm bài" error={page.error} back={{ label: 'Về lớp đầu tiên', path: '/teacher/submissions' }} />
  const p = page.data
  const current = p.current_class
  const pron = p.active_tab === 'pronunciation'
  const go = (to: string) => {
    setFlash(null)
    navigate(to)
  }
  return (
    <>
      <Header
        title="Bài nộp của lớp"
        description="Bài nộp và bản ghi âm của học viên trong lớp bạn phụ trách."
        actions={
          me && can(me, 'student.view') ? (
            <LinkButton to="/portal/student-homework" icon="upload_file">
              Xem giao diện Học sinh nộp bài
            </LinkButton>
          ) : undefined
        }
      />
      <div className="mx-auto max-w-5xl space-y-6">
        <div className="flex flex-col justify-between gap-4 rounded-2xl border border-surface-container-highest bg-surface p-5 shadow-sm sm:flex-row sm:items-end">
          <div>
            <h2 className="text-xl font-bold text-on-surface">Bài nộp của lớp</h2>
            <p className="mt-0.5 text-xs text-on-surface-variant">
              Lớp: <strong className="text-on-surface">{current?.name ?? 'Chưa có lớp'}</strong>
            </p>
          </div>
          <div className="w-full sm:w-72">
            <Select label="Chọn lớp dạy" value={current?.id ?? ''} onChange={(e) => go(submissionsHref(e.target.value, p.active_tab))}>
              {p.classes.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name} ({c.code})
                </option>
              ))}
            </Select>
          </div>
        </div>

        <nav className="flex flex-wrap gap-2" aria-label="Loại bài nộp">
          {p.tabs.map((t) => (
            <button
              key={t.value}
              type="button"
              aria-pressed={t.value === p.active_tab}
              onClick={() => go(submissionsHref(current?.id, t.value))}
              className={`flex items-center gap-1.5 rounded-full px-4 py-2 text-xs font-bold ${
                t.value === p.active_tab
                  ? 'bg-primary text-white shadow-sm'
                  : 'border border-surface-container-highest bg-surface text-on-surface-variant hover:bg-surface-container-low'
              }`}
            >
              <Icon name={SUBMISSION_TAB_ICONS[t.value] ?? 'assignment'} className="text-[16px]" /> {t.label}
            </button>
          ))}
        </nav>
        {flash && <Alert tone={flash.ok ? 'success' : 'error'}>{flash.text}</Alert>}
        {pron && p.pronunciation_pending && (
          <Alert tone="info">Bài phát âm: chờ phân hệ Cổng Phụ huynh & Học sinh chuyển sang hệ thống mới.</Alert>
        )}

        <section className="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface shadow-sm">
          <h3 className="flex items-center gap-2 border-b border-surface-container-highest bg-surface-container-low px-5 py-4 text-sm font-bold">
            <Icon name="check_circle" className="text-[20px] text-primary" /> Danh sách đã nộp bài ({p.submissions.length})
          </h3>
          <div className="divide-y divide-surface-container-highest">
            {p.submissions.map((s) => (
              <Row
                key={s.id}
                s={s}
                pronunciation={pron}
                onDone={(text) => setFlash({ ok: true, text })}
                onFail={(e) => setFlash({ ok: false, text: errorMessage(e) })}
              />
            ))}
          </div>
          {p.submissions.length === 0 && (
            <div className="flex flex-col items-center gap-2 p-8 text-center text-sm text-on-surface-variant">
              <Icon name="inbox" className="text-3xl" />
              {current ? 'Chưa có học viên nào nộp bài loại này.' : 'Bạn chưa được phân công lớp nào.'}
            </div>
          )}
        </section>
      </div>
    </>
  )
}

// Đường dẫn cũ /portal/teacher-submissions (route portal.teacher.submissions của Laravel) → /teacher/submissions, giữ
// ?class_id= / ?type=.
export function LegacySubmissionsRedirect() {
  useEffect(() => {
    window.history.replaceState(null, '', '/teacher/submissions' + window.location.search)
    window.dispatchEvent(new PopStateEvent('popstate'))
  }, [])
  return <Loading />
}

function Row({ s, pronunciation, onDone, onFail }: { s: Submission; pronunciation: boolean; onDone: (m: string) => void; onFail: (e: unknown) => void }) {
  const qc = useQueryClient()
  const [score, setScore] = useState('')
  const [feedback, setFeedback] = useState('')
  const mark = useMutation({
    mutationFn: () => teacherApi.mark(s.id, { score, feedback }),
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['teacher-submissions'] })
      onDone(r.message)
    },
    onError: onFail,
  })
  const input = 'h-9 rounded-lg border border-outline-variant bg-surface px-2 text-xs outline-none focus:border-primary'
  return (
    <div className="flex flex-col justify-between gap-4 p-4 md:flex-row md:items-center">
      <div className="flex flex-1 items-center gap-3">
        <span className="flex size-12 shrink-0 items-center justify-center rounded-full border border-primary/20 bg-primary/10 text-sm font-bold text-primary">
          {initials(s.student_name)}
        </span>
        <div>
          <h4 className="text-xs font-bold">{s.student_name || 'Học viên'}</h4>
          <p className="mt-0.5 flex items-center gap-1 font-mono text-xs text-on-surface-variant">
            <Icon name="schedule" className="text-[14px]" /> nộp lúc {s.submitted_at}
          </p>
          {s.notes && <p className="mt-1 text-xs text-on-surface-variant italic">"{s.notes}"</p>}
        </div>
      </div>
      {s.audio_url ? (
        <div className="flex-1 text-xs">
          <span className="block max-w-[220px] truncate font-bold">{s.unit_title || 'Bản ghi âm'}</span>
          <audio controls preload="none" className="mt-1 h-8 w-full max-w-[260px]" src={s.audio_url} />
        </div>
      ) : (
        <div className="flex flex-1 items-center gap-3">
          <span className="flex h-12 w-16 shrink-0 items-center justify-center rounded-lg bg-on-surface text-surface shadow-sm">
            <Icon name="play_arrow" className="text-[20px]" />
          </span>
          <div className="text-xs">
            <span className="block max-w-[180px] truncate font-bold">
              {s.attachment_url ? (
                <a href={s.attachment_url} target="_blank" rel="noopener noreferrer" className="hover:underline">
                  {s.attachment_name || 'Tệp đính kèm'}
                </a>
              ) : (
                'Không có tệp đính kèm'
              )}
            </span>
            <span className="font-bold text-primary uppercase">{s.homework_label || 'Bài nộp'}</span>
          </div>
        </div>
      )}
      <div className="flex items-center gap-2">
        {s.status === 'reviewed' ? (
          <span className="inline-flex items-center gap-1 rounded-full bg-success-container px-2.5 py-1 text-xs font-medium text-on-success-container">
            <Icon name="done_all" className="text-[14px]" /> {reviewedLabel(s.score)}
          </span>
        ) : (
          <form
            onSubmit={(e) => {
              e.preventDefault()
              mark.mutate()
            }}
            className="flex flex-wrap items-center gap-2"
          >
            {!pronunciation && (
              <span className="rounded-full bg-surface-container-low px-2.5 py-1 text-xs font-medium text-on-surface-variant">Đã nộp</span>
            )}
            <input
              value={score}
              onChange={(e) => setScore(e.target.value)}
              required={pronunciation}
              maxLength={20}
              placeholder={pronunciation ? 'Điểm /100' : 'Điểm (tùy chọn)'}
              aria-label={pronunciation ? 'Điểm phát âm' : 'Điểm'}
              className={`w-24 ${input}`}
            />
            <input
              value={feedback}
              onChange={(e) => setFeedback(e.target.value)}
              maxLength={1000}
              placeholder="Nhận xét"
              aria-label="Nhận xét"
              className={`${pronunciation ? 'w-40' : 'w-36'} ${input}`}
            />
            <Button type="submit" variant={pronunciation ? 'primary' : 'secondary'} className="h-9 px-3 text-xs" disabled={mark.isPending}>
              {pronunciation ? (
                'Chấm'
              ) : (
                <span className="inline-flex items-center gap-1">
                  <Icon name="visibility" className="text-[16px]" /> Đánh dấu đã xem
                </span>
              )}
            </Button>
          </form>
        )}
      </div>
    </div>
  )
}
