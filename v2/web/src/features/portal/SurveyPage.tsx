import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Button, Dialog, Icon } from '../../components/ui'
import { portalApi, type SurveyItem } from './api'
import { Empty, Pill, PortalFrame, QueryError, SubTabs, TextArea, useNotice, useStudentParam } from './components'
import { portalHref, submittedAt } from './logic'

// Khảo sát 5 sao (Portal/Survey.vue): đợt khảo sát đang mở do Học vụ tạo — bấm một khảo sát để chấm sao và gửi ý
// kiến; khảo sát đã gửi (xóa để làm lại). Đợt khảo sát thuộc phân hệ Khảo sát: chưa gắn thì danh sách trống.
export function SurveyPage() {
  const sid = useStudentParam()
  const q = useQuery({ queryKey: ['portal', 'surveys', sid], queryFn: () => portalApi.surveys(sid) })
  const qc = useQueryClient()
  const notice = useNotice()
  const [selected, setSelected] = useState<SurveyItem | null>(null)
  const del = useMutation({
    mutationFn: portalApi.deleteSurvey,
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['portal'] })
      notice.ok(r.message)
    },
    onError: notice.fail,
  })
  if (q.isError) return <QueryError error={q.error} />
  const d = q.data
  const student = d?.student ?? null

  return (
    <PortalFrame ctx={d} title="Khảo sát" tab="survey">
      <SubTabs
        items={[
          { href: portalHref('/portal/survey', student?.id), label: 'Khảo sát định kỳ', icon: 'assignment', active: true },
          { href: portalHref('/portal/feedback', student?.id), label: 'Feedback chặng học', icon: 'rate_review', active: false },
        ]}
      />
      {notice.view}
      {d && (
        <div className="space-y-4">
          <div>
            <h2 className="text-xl font-bold">Khảo sát &amp; Đánh giá</h2>
            <p className="mt-0.5 text-xs text-on-surface-variant">Hãy chia sẻ ý kiến của bạn để chúng tôi nâng cao chất lượng dịch vụ đào tạo.</p>
          </div>
          {!d.ready && <Alert tone="info">Đợt khảo sát chờ phân hệ Khảo sát chuyển sang hệ thống mới.</Alert>}

          <section className="space-y-2">
            <h3 className="text-xs font-bold tracking-wider text-on-surface-variant uppercase">Khảo sát đang mở</h3>
            {d.surveys.length > 0 && <p className="text-xs text-on-surface-variant">Bấm vào một khảo sát để gửi phản hồi.</p>}
            {d.surveys.map((s) => (
              <button
                key={s.id}
                type="button"
                disabled={!d.can_write}
                onClick={() => setSelected(s)}
                className="flex w-full items-start justify-between gap-2 rounded-xl border border-surface-container-highest bg-surface p-3.5 text-left shadow-sm hover:bg-primary/5 disabled:cursor-default"
              >
                <span>
                  <span className="mb-1 block text-xs font-bold text-on-surface">{s.title}</span>
                  <span className={`flex items-center gap-1 text-xs ${s.is_urgent ? 'font-semibold text-error' : 'text-on-surface-variant'}`}>
                    <Icon name="event" className="text-[13px]" /> {s.status_text}
                  </span>
                </span>
                <Icon name="chevron_right" className="text-lg text-on-surface-variant" />
              </button>
            ))}
            {d.surveys.length === 0 && (
              <p className="rounded-xl border border-dashed border-surface-container-highest p-3.5 text-center text-xs text-on-surface-variant">
                Hiện chưa có khảo sát nào đang mở.
              </p>
            )}
          </section>

          {d.past_surveys.length > 0 && (
            <section className="space-y-2">
              <h3 className="text-xs font-bold tracking-wider text-on-surface-variant uppercase">Khảo sát đã gửi</h3>
              {d.past_surveys.map((p) => (
                <div key={p.id} className="space-y-1.5 rounded-xl border border-surface-container-highest bg-surface p-3 text-xs shadow-sm">
                  <div className="flex items-center justify-between gap-2">
                    <strong>{p.title}</strong>
                    <span className="flex items-center gap-1.5">
                      <Pill tone="success">{p.rating} ★</Pill>
                      {d.can_write && (
                        <button
                          type="button"
                          title="Xóa khảo sát"
                          aria-label="Xóa khảo sát"
                          onClick={() => window.confirm('Xóa bài khảo sát này?') && del.mutate(p.id)}
                          className="inline-flex size-7 items-center justify-center rounded-lg text-error hover:bg-error-container"
                        >
                          <Icon name="delete" className="text-[16px]" />
                        </button>
                      )}
                    </span>
                  </div>
                  <p className="text-on-surface-variant italic">"{p.feedback}"</p>
                  <span className="block font-mono text-on-surface-variant">{submittedAt(p.submitted_at)}</span>
                </div>
              ))}
            </section>
          )}
          {d.surveys.length === 0 && d.past_surveys.length === 0 && d.ready && <Empty icon="poll" title="Chưa có khảo sát nào." />}
        </div>
      )}
      {selected && student && (
        <ResponseDialog
          studentId={student.id}
          survey={selected}
          onClose={() => setSelected(null)}
          onDone={(m) => {
            setSelected(null)
            notice.ok(m)
          }}
        />
      )}
    </PortalFrame>
  )
}

function ResponseDialog({ studentId, survey, onClose, onDone }: { studentId: string; survey: SurveyItem; onClose: () => void; onDone: (m: string) => void }) {
  const qc = useQueryClient()
  const [rating, setRating] = useState(5)
  const [feedback, setFeedback] = useState('')
  const m = useMutation({
    mutationFn: () => portalApi.submitSurvey({ student_id: studentId, survey_id: survey.id, rating, feedback }),
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['portal'] })
      onDone(r.message)
    },
  })
  return (
    <Dialog title="Phản hồi khảo sát" onClose={onClose}>
      <form
        onSubmit={(e) => {
          e.preventDefault()
          m.mutate()
        }}
        className="space-y-3"
      >
        <p className="text-xs text-on-surface-variant">
          Đang phản hồi cho: <span className="font-bold text-primary">{survey.title}</span>
        </p>
        {m.isError && <Alert>{errorMessage(m.error)}</Alert>}
        <fieldset>
          <legend className="mb-1.5 block text-xs font-bold tracking-wider text-on-surface-variant uppercase">Mức độ hài lòng chung</legend>
          <div className="flex items-center gap-2 rounded-xl border border-surface-container-highest p-2.5">
            {[1, 2, 3, 4, 5].map((s) => (
              <label key={s} className="flex flex-1 cursor-pointer flex-col items-center gap-1">
                <input type="radio" name="rating" checked={rating === s} onChange={() => setRating(s)} className="accent-primary" />
                <span className="text-xs font-bold text-on-surface-variant">{s} ★</span>
              </label>
            ))}
          </div>
        </fieldset>
        <TextArea label="Ý kiến của bạn" value={feedback} onChange={setFeedback} rows={4} placeholder="Vui lòng nhập chi tiết phản hồi của bạn tại đây..." />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={m.isPending}>
            Gửi phản hồi khảo sát
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
