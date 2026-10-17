import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card } from '../../components/ui'
import { placementApi, type GradingView, type ReviewRow, type RubricConfig } from './api'
import { LinkButton, PageHeader } from './components'
import { formatDateTime } from './logic'
import { initialForm, RubricScoreFields, type RubricForm } from './RubricScoreFields'
import { BASE } from './TestsPage'

// Chi tiết bài làm & chấm điểm (Laravel PlacementTests/Result).
export function GradingPage({ id }: { me: Me; id: string }) {
  const view = useQuery({ queryKey: ['placement', 'grading', id], queryFn: () => placementApi.grading(id) })
  const cfg = useQuery({ queryKey: ['placement', 'rubric'], queryFn: placementApi.rubric })
  if (view.isError || cfg.isError) return <Alert>{errorMessage(view.error ?? cfg.error)}</Alert>
  if (!view.data || !cfg.data) return <p className="text-sm text-on-surface-variant">Đang tải…</p>
  return <Grading key={view.data.submission.id + view.data.submission.status} view={view.data} cfg={cfg.data} />
}

const FILTERS = [
  { key: 'all', label: 'Tất cả' },
  { key: 'correct', label: 'Câu đúng' },
  { key: 'incorrect', label: 'Câu sai' },
  { key: 'listening', label: 'Listening' },
  { key: 'reading', label: 'Reading & Grammar' },
  { key: 'writing', label: 'Writing' },
  { key: 'speaking', label: 'Speaking' },
]

function matches(q: ReviewRow, f: string) {
  switch (f) {
    case 'correct':
      return q.is_correct
    case 'incorrect':
      return q.is_incorrect
    case 'reading':
      return q.skill === 'reading' || q.skill === 'grammar'
    case 'all':
      return true
    default:
      return q.skill === f
  }
}

function Grading({ view, cfg }: { view: GradingView; cfg: RubricConfig }) {
  const qc = useQueryClient()
  const s = view.submission
  const [form, setForm] = useState<RubricForm>(() => initialForm(cfg, view.rubric))
  const [filter, setFilter] = useState('all')
  const [flash, setFlash] = useState<string | null>(null)
  const grade = useMutation({
    mutationFn: (action: 'draft' | 'confirm') =>
      placementApi.grade(s.id, {
        grade_group: form.group,
        listening_score: form.scores.listening,
        reading_writing_score: form.scores.reading_writing,
        speaking_score: form.scores.speaking,
        chosen_class: form.chosen,
        listening_comment: form.comments.listening,
        reading_writing_comment: form.comments.reading_writing,
        speaking_comment: form.comments.speaking,
        teacher_comments: form.teacher_comments,
        action,
      }),
    onSuccess: (r) => {
      setFlash(r.message)
      qc.setQueryData(['placement', 'grading', s.id], { submission: r.submission, questions: r.questions, rubric: r.rubric })
      qc.invalidateQueries({ queryKey: ['placement', 'submissions'] })
      qc.invalidateQueries({ queryKey: ['placement', 'tests'] })
    },
  })
  const correct = view.questions.filter((q) => q.is_correct).length
  const incorrect = view.questions.filter((q) => q.is_incorrect).length

  return (
    <div className="space-y-6">
      <PageHeader
        title="Chi tiết bài làm & chấm điểm"
        back={BASE}
        badges={
          <>
            <Badge tone="info">{s.score_summary ?? 'Chưa có điểm'}</Badge>
            <Badge tone={s.is_pending ? 'neutral' : 'success'}>{s.is_pending ? 'Chờ chấm' : 'Đã chấm điểm'}</Badge>
          </>
        }
        description={
          <span className="font-mono text-xs">
            {s.test_title} · Thí sinh: <strong className="text-on-surface">{s.candidate_name}</strong> · SĐT: {s.candidate_phone}
          </span>
        }
        actions={
          <>
            <LinkButton href={`${BASE}/submissions/${s.id}/scorecard`}>Bảng điểm (Scorecard)</LinkButton>
            <LinkButton href={`${BASE}/rubric-guide`}>Thang điểm & hướng dẫn nhận xét</LinkButton>
          </>
        }
      />
      {flash && <Alert tone="success">{flash}</Alert>}
      {grade.isError && <Alert>{errorMessage(grade.error)}</Alert>}

      <Card
        title={
          <span className="flex flex-wrap items-center justify-between gap-2">
            Chấm điểm theo thang điểm khối lớp
            <span className="font-mono text-xs font-normal text-on-surface-variant">Nộp bài lúc: {formatDateTime(s.submitted_at)}</span>
          </span>
        }
      >
        <div className="space-y-4">
          {s.is_pending && (s.listening_score !== null || s.reading_score !== null) && (
            <Alert tone="info">
              Hệ thống đã tự chấm theo đáp án của đề (quy về thang của khối): Nghe {s.listening_score ?? '—'} · Đọc & Viết{' '}
              {s.reading_writing_score ?? s.reading_score ?? '—'}, kèm nhận xét gợi ý từng kỹ năng. Học vụ xem lại, sửa điểm / nhận xét nếu cần (bài viết tự luận
              chấm tay), nhập điểm Nói rồi bấm Xác nhận kết quả.
            </Alert>
          )}
          {(s.violation_count > 0 || s.auto_submitted) && (
            <Alert>
              <strong>
                Thí sinh rời khỏi bài thi {s.violation_count} lần{s.auto_submitted ? ' · bài bị tự động nộp' : ''}
              </strong>
              {s.violations.length > 0 && (
                <ul className="mt-1 list-inside list-disc">
                  {s.violations.map((v, i) => (
                    <li key={i}>
                      {v.at}: {v.label}
                    </li>
                  ))}
                </ul>
              )}
            </Alert>
          )}
          <div className="max-w-3xl">
            <RubricScoreFields cfg={cfg} form={form} onChange={setForm} />
          </div>
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-surface-container-highest pt-4">
            <span className="text-xs text-on-surface-variant">
              Khi xác nhận, hệ thống tính tổng điểm, tra lớp đề xuất và đồng bộ kết quả sang hồ sơ khách CRM.
            </span>
            <span className="flex gap-2">
              <Button variant="secondary" disabled={grade.isPending} onClick={() => grade.mutate('draft')}>
                Lưu bản nháp
              </Button>
              <Button disabled={grade.isPending} onClick={() => grade.mutate('confirm')}>
                Xác nhận kết quả
              </Button>
            </span>
          </div>
        </div>
      </Card>

      {(s.writing_content || s.speaking_self_rate) && (
        <Card title="Bài viết & tự đánh giá kỹ năng nói">
          {s.writing_content && (
            <div className="space-y-1">
              <p className="text-xs text-on-surface-variant">{s.writing_word_count} từ</p>
              <p className="rounded-lg bg-surface-container-low p-3 text-sm whitespace-pre-line">{s.writing_content}</p>
            </div>
          )}
          {s.speaking_self_rate && <p className="mt-3 text-sm">Tự đánh giá kỹ năng nói: {SELF_RATES[s.speaking_self_rate] ?? s.speaking_self_rate}</p>}
        </Card>
      )}

      <Card
        title={
          <span className="flex flex-wrap items-center justify-between gap-2">
            Đối chiếu đáp án từng câu
            <span className="text-xs font-normal text-on-surface-variant">
              Đúng {correct} · Sai {incorrect} · {view.questions.length} câu
            </span>
          </span>
        }
      >
        <div className="mb-4 flex flex-wrap gap-1">
          {FILTERS.map((f) => (
            <button
              key={f.key}
              type="button"
              aria-pressed={filter === f.key}
              onClick={() => setFilter(f.key)}
              className="rounded-full border border-surface-container-highest px-3 py-1 text-xs aria-pressed:border-primary aria-pressed:bg-primary aria-pressed:text-white"
            >
              {f.label}
            </button>
          ))}
        </div>
        <div className="space-y-3">
          {view.questions.filter((q) => matches(q, filter)).map((q) => (
            <ReviewItem key={q.number} q={q} />
          ))}
        </div>
      </Card>
    </div>
  )
}

const SELF_RATES: Record<string, string> = {
  beginner: 'Mới bắt đầu / Mất gốc (A1)',
  intermediate: 'Trung bình (A2 - B1)',
  advanced: 'Nâng cao (B2 - C1)',
}

function ReviewItem({ q }: { q: ReviewRow }) {
  return (
    <div
      className={`space-y-2 rounded-xl border p-3 text-sm ${q.is_correct ? 'border-success/30' : q.is_incorrect ? 'border-error/30' : 'border-surface-container-highest'}`}
    >
      <div className="flex flex-wrap items-center gap-2">
        <span className="rounded-md bg-sidebar px-2 py-0.5 font-mono text-xs text-white">Câu {q.pdf_number ?? q.number}</span>
        <Badge tone="info">{q.skill}</Badge>
        {q.is_correct && <Badge tone="success">Đúng</Badge>}
        {q.is_incorrect && <Badge tone="error">Sai</Badge>}
        <span className="ml-auto font-mono text-xs text-on-surface-variant">{q.points} điểm</span>
      </div>
      {q.title && <p className="font-medium whitespace-pre-line">{q.title}</p>}
      {q.passage && <p className="text-xs whitespace-pre-line text-on-surface-variant italic">“{q.passage}”</p>}
      {q.options.length > 0 ? (
        <div className="grid gap-1.5 sm:grid-cols-2">
          {q.options.map((o) => (
            <div
              key={o.key}
              className={`rounded-lg border px-2 py-1.5 text-xs ${o.is_correct ? 'border-success/40 bg-success-container' : o.is_chosen ? 'border-error/40 bg-error-container' : 'border-surface-container-highest'}`}
            >
              <span className="font-mono font-semibold">{o.key}.</span> {o.text}
              {o.is_chosen && <span className="ml-1 font-semibold">(thí sinh chọn)</span>}
            </div>
          ))}
        </div>
      ) : (
        <p className="text-xs">
          Thí sinh trả lời: <span className="font-semibold">{q.candidate_answer || '—'}</span>
          {q.correct_answer && (
            <>
              {' '}
              · Đáp án: <span className="font-mono font-semibold">{q.correct_answer}</span>
            </>
          )}
        </p>
      )}
      {q.explanation && <p className="text-xs text-on-surface-variant">Giải thích: {q.explanation}</p>}
      {q.rubric_note && <p className="text-xs text-on-surface-variant">Hướng dẫn chấm: {q.rubric_note}</p>}
      {q.cue_points && <p className="font-mono text-xs whitespace-pre-line text-on-surface-variant">{q.cue_points}</p>}
    </div>
  )
}
