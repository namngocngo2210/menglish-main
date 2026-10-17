import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Alert, Button, Icon } from '../../components/ui'
import { portalApi, type FeedbackPage as Data } from './api'
import { Block, Pill, PortalFrame, QueryError, SubTabs, TextArea, useNotice, useStudentParam } from './components'
import { FEEDBACK_REQUIRED, feedbackFilled, portalHref, RATING_LABELS, submittedAt } from './logic'

// Gửi góp ý chặng học (Portal/Feedback.vue): mức hài lòng 1–5 sao, lĩnh vực góp ý, nội dung — cần ít nhất một mục
// (R-04). Mỗi học viên một bản mỗi chặng: gửi lại thì cập nhật, xóa để nhập lại.
export function FeedbackPage() {
  const sid = useStudentParam()
  const q = useQuery({ queryKey: ['portal', 'feedback', sid], queryFn: () => portalApi.feedback(sid) })
  const notice = useNotice()
  if (q.isError) return <QueryError error={q.error} />
  const d = q.data
  const student = d?.student ?? null
  return (
    <PortalFrame ctx={d} title="Feedback chặng" tab="survey">
      <SubTabs
        items={[
          { href: portalHref('/portal/survey', student?.id), label: 'Khảo sát định kỳ', icon: 'assignment', active: false },
          { href: portalHref('/portal/feedback', student?.id), label: 'Feedback chặng học', icon: 'rate_review', active: true },
        ]}
      />
      {notice.view}
      {d && student && <FeedbackForm key={`${student.id}-${d.last_feedback?.id ?? 'new'}`} d={d} studentId={student.id} notice={notice} />}
    </PortalFrame>
  )
}

function FeedbackForm({ d, studentId, notice }: { d: Data; studentId: string; notice: ReturnType<typeof useNotice> }) {
  const qc = useQueryClient()
  const last = d.last_feedback
  const [rating, setRating] = useState(last?.rating ?? 0)
  const [academic, setAcademic] = useState(last?.fb_hoc_thuat ?? false)
  const [teacher, setTeacher] = useState(last?.fb_giao_vien ?? false)
  const [other, setOther] = useState(last?.fb_khac ?? false)
  const [content, setContent] = useState(last?.content ?? '')
  const [missing, setMissing] = useState(false)
  useEffect(() => setMissing(false), [rating, academic, teacher, other, content])
  const body = { student_id: studentId, rating, fb_hoc_thuat: academic, fb_giao_vien: teacher, fb_khac: other, content }
  const save = useMutation({
    mutationFn: () => portalApi.submitFeedback(body),
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['portal'] })
      notice.ok(r.message)
    },
    onError: notice.fail,
  })
  const del = useMutation({
    mutationFn: () => portalApi.deleteFeedback(last!.id),
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['portal'] })
      notice.ok(r.message)
    },
    onError: notice.fail,
  })

  return (
    <div className="space-y-4">
      {last && notice.notice?.tone !== 'success' && (
        <Alert tone="info">Bạn đã gửi đánh giá trước đó ({submittedAt(last.submitted_at)}). Có thể thay đổi số sao hoặc nội dung rồi bấm "Cập nhật feedback".</Alert>
      )}
      <Block>
        <div className="mb-1.5 flex items-center justify-between">
          <span className="text-xs font-bold tracking-wider text-on-surface-variant uppercase">Chặng học đang mở thu thập</span>
          <Pill tone="success">Đang mở thu thập</Pill>
        </div>
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-sm font-bold">{d.stage_name}</h3>
            <p className="mt-0.5 text-xs text-on-surface-variant">
              Lớp: <span className="font-bold text-on-surface">{d.class_name}</span>
            </p>
          </div>
          <Icon name="lock" className="text-[18px] text-on-surface-variant" />
        </div>
      </Block>

      <form
        onSubmit={(e) => {
          e.preventDefault()
          if (!feedbackFilled(body)) {
            setMissing(true)
            return
          }
          save.mutate()
        }}
        className="space-y-4"
      >
        <Block>
          <div className="mb-2 flex items-center justify-between">
            <span className="text-xs font-bold">
              1. Mức độ hài lòng chung <span className="font-normal text-on-surface-variant">(Tùy chọn)</span>
            </span>
            <span className="text-xs font-bold text-primary">{RATING_LABELS[rating]}</span>
          </div>
          <div className="flex items-center justify-between px-1">
            {[1, 2, 3, 4, 5].map((s) => (
              <button
                key={s}
                type="button"
                onClick={() => setRating(s)}
                aria-label={`${s} sao`}
                aria-pressed={s <= rating}
                className="flex flex-col items-center gap-1 rounded-xl p-2 hover:bg-primary/10"
              >
                <Icon name="star" fill={s <= rating} className={`text-3xl ${s <= rating ? 'text-warning' : 'text-on-surface-variant'}`} />
                <span className={`font-mono text-xs font-bold ${s <= rating ? 'text-warning' : 'text-on-surface-variant'}`}>{s}</span>
              </button>
            ))}
          </div>
        </Block>

        <Block>
          <div className="mb-2.5 flex items-center justify-between text-xs font-bold">
            <span>2. Lĩnh vực cần góp ý</span>
            <span className="font-normal text-on-surface-variant">(Tùy chọn)</span>
          </div>
          <div className="grid grid-cols-3 gap-2">
            {(
              [
                ['Học thuật', academic, setAcademic],
                ['Giáo viên', teacher, setTeacher],
                ['Khác', other, setOther],
              ] as const
            ).map(([label, value, set]) => (
              <label
                key={label}
                className={`flex cursor-pointer flex-col items-center justify-center rounded-xl border p-2.5 ${value ? 'border-primary bg-primary/10' : 'border-surface-container-highest'}`}
              >
                <input type="checkbox" checked={value} onChange={(e) => set(e.target.checked)} className="mb-1 accent-primary" />
                <span className="text-xs font-semibold text-on-surface-variant select-none">{label}</span>
              </label>
            ))}
          </div>
        </Block>

        <Block>
          <TextArea
            label="3. Nội dung feedback chi tiết (Tùy chọn)"
            value={content}
            onChange={setContent}
            rows={4}
            maxLength={5000}
            placeholder="Chia sẻ cảm nhận của phụ huynh/học sinh về giáo trình, phương pháp giảng dạy hoặc điểm cần hỗ trợ thêm..."
          />
          <p className="mt-1 text-xs text-on-surface-variant">Ý kiến chân thực giúp trung tâm nâng cao chất lượng dạy học.</p>
        </Block>

        {missing && <Alert>{FEEDBACK_REQUIRED}</Alert>}
        {d.can_write && (
          <div>
            <Button type="submit" className="w-full" disabled={save.isPending}>
              <Icon name="send" className="mr-1 text-[18px]" /> {last ? 'Cập nhật feedback' : 'Gửi feedback'}
            </Button>
            <p className="mt-2 text-center text-xs text-on-surface-variant">Sau khi gửi, bạn vẫn có thể chỉnh sửa lại trong thời gian đợt thu thập còn mở.</p>
          </div>
        )}
      </form>

      {last && d.can_write && (
        <Button variant="danger" className="w-full" disabled={del.isPending} onClick={() => window.confirm('Xóa bản feedback này?') && del.mutate()}>
          <Icon name="delete_sweep" className="mr-1 text-[18px]" /> Xóa phản hồi đã lưu &amp; nhập lại
        </Button>
      )}

      {d.history.length > 1 && (
        <Block title="Các chặng đã góp ý" icon="history">
          <ul className="divide-y divide-surface-container-highest text-xs">
            {d.history.map((h) => (
              <li key={h.id} className="flex items-center justify-between gap-2 py-2">
                <span>
                  <span className="font-semibold">{h.stage_name}</span>
                  {h.class_name && <span className="text-on-surface-variant"> · {h.class_name}</span>}
                </span>
                <span className="text-on-surface-variant">{h.rating > 0 ? `${h.rating} ★` : RATING_LABELS[0]}</span>
              </li>
            ))}
          </ul>
        </Block>
      )}
    </div>
  )
}
