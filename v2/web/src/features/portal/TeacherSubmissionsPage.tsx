import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import type { Me } from '../../api'
import { Alert, Button, Icon, Select } from '../../components/ui'
import { can } from '../../nav'
import { navigate, useQueryParam } from '../../router'
import { PageHeader } from '../platform/parts'
import { portalApi, type TeacherItem } from './api'
import { Empty, Pill, PortalLink, QueryError, useNotice } from './components'
import { filled, initials, submittedAt, TEACHER_TABS } from './logic'

const href = (classId: string, type: string) => {
  const p = new URLSearchParams()
  if (classId) p.set('class_id', classId)
  if (type) p.set('type', type)
  return `/portal/teacher-submissions?${p.toString()}`
}

// Chấm bài nộp của lớp (Portal/TeacherSubmissions.vue): chọn lớp mình dạy / trợ giảng, lọc theo loại bài, chấm bản ghi
// âm (điểm bắt buộc) hoặc đánh dấu bài nộp đã xem.
export function TeacherSubmissionsPage({ me }: { me: Me }) {
  const classId = useQueryParam('class_id')
  const type = useQueryParam('type')
  const q = useQuery({ queryKey: ['portal', 'teacher', classId, type], queryFn: () => portalApi.teacherSubmissions(classId, type) })
  const notice = useNotice()
  if (q.isError) return <QueryError error={q.error} />
  const d = q.data
  const current = d?.current_class ?? null
  const tab = d?.active_tab ?? 'video'

  return (
    <>
      <PageHeader
        title="Bài nộp của lớp"
        description="Bài nộp và bản ghi âm của học viên trong lớp bạn phụ trách."
        actions={
          can(me, 'student.view') ? (
            <PortalLink href="/portal/student-homework" className="inline-flex h-10 items-center gap-1 rounded-lg border border-outline-variant px-4 text-sm font-medium hover:bg-surface-container-low">
              <Icon name="upload_file" className="text-[18px]" /> Xem giao diện Học sinh nộp bài
            </PortalLink>
          ) : null
        }
      />
      {notice.view}
      {d && (
        <div className="space-y-6">
          <div className="flex flex-col justify-between gap-4 rounded-2xl border border-surface-container-highest bg-surface p-5 shadow-sm sm:flex-row sm:items-end">
            <div>
              <h2 className="text-xl font-bold">Bài nộp của lớp</h2>
              <p className="mt-0.5 text-xs text-on-surface-variant">
                Lớp: <strong className="text-on-surface">{current?.name ?? 'Chưa có lớp'}</strong>
              </p>
            </div>
            <div className="w-full sm:w-72">
              <Select label="Chọn lớp dạy" value={current?.id ?? ''} onChange={(e) => navigate(href(e.target.value, tab))}>
                {d.classes.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name} ({c.code})
                  </option>
                ))}
              </Select>
            </div>
          </div>

          <div className="flex flex-wrap gap-2" role="tablist" aria-label="Loại bài nộp">
            {TEACHER_TABS.map((t) => (
              <PortalLink
                key={t.key}
                href={href(current?.id ?? '', t.key)}
                className={`flex items-center gap-1.5 rounded-full px-4 py-2 text-xs font-bold ${tab === t.key ? 'bg-primary text-white shadow-sm' : 'border border-surface-container-highest bg-surface text-on-surface-variant hover:bg-surface-container-low'}`}
              >
                <Icon name={t.icon} className="text-[16px]" /> {t.label}
              </PortalLink>
            ))}
          </div>
          {!d.ready && tab !== 'pronunciation' && (
            <Alert tone="info">Bài nộp loại này chờ phân hệ Cổng giáo viên chuyển sang hệ thống mới; tab Phát âm đã dùng được.</Alert>
          )}

          <section className="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface shadow-sm">
            <h3 className="flex items-center gap-2 border-b border-surface-container-highest bg-surface-container-low px-5 py-4 text-sm font-bold">
              <Icon name="check_circle" className="text-[20px] text-primary" /> Danh sách đã nộp bài ({d.submissions.length})
            </h3>
            <div className="divide-y divide-surface-container-highest">
              {d.submissions.map((s) => (
                <Row key={s.id} s={s} pronunciation={tab === 'pronunciation'} onDone={notice.ok} onFail={notice.fail} />
              ))}
            </div>
            {d.submissions.length === 0 && (
              <div className="p-4">
                <Empty icon="inbox" title={current ? 'Chưa có học viên nào nộp bài loại này.' : 'Bạn chưa được phân công lớp nào.'} />
              </div>
            )}
          </section>
        </div>
      )}
    </>
  )
}

function Row({ s, pronunciation, onDone, onFail }: { s: TeacherItem; pronunciation: boolean; onDone: (m: string) => void; onFail: (e: unknown) => void }) {
  const qc = useQueryClient()
  const [score, setScore] = useState('')
  const [feedback, setFeedback] = useState('')
  const m = useMutation({
    mutationFn: () => (pronunciation ? portalApi.reviewPronunciation : portalApi.reviewSubmission)(s.id, { score, feedback }),
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['portal', 'teacher'] })
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
            <Icon name="schedule" className="text-[14px]" /> nộp lúc {submittedAt(s.submitted_at)}
          </p>
          {s.notes && <p className="mt-1 text-xs text-on-surface-variant italic">"{s.notes}"</p>}
        </div>
      </div>
      {s.audio_url || pronunciation ? (
        <div className="flex-1 text-xs">
          <span className="block max-w-[220px] truncate font-bold">{s.unit_title ?? 'Bản ghi âm'}</span>
          {s.audio_url && <audio controls preload="none" className="mt-1 h-8 w-full max-w-[260px]" src={s.audio_url} />}
        </div>
      ) : (
        <div className="flex-1 text-xs">
          <span className="block max-w-[200px] truncate font-bold">
            {s.attachment_url ? (
              <a href={s.attachment_url} target="_blank" rel="noopener noreferrer" className="hover:underline">
                {s.attachment_name ?? 'Tệp đính kèm'}
              </a>
            ) : (
              'Không có tệp đính kèm'
            )}
          </span>
          <span className="font-bold text-primary uppercase">{s.homework_label ?? 'Bài nộp'}</span>
        </div>
      )}
      <div className="flex items-center gap-2">
        {s.status === 'reviewed' ? (
          <Pill tone="success">
            <Icon name="done_all" className="text-[14px]" /> {filled(s.score) ? `Đã chấm: ${s.score}` : 'Đã xem'}
          </Pill>
        ) : (
          <form
            onSubmit={(e) => {
              e.preventDefault()
              m.mutate()
            }}
            className="flex flex-wrap items-center gap-2"
          >
            <input
              value={score}
              onChange={(e) => setScore(e.target.value)}
              required={pronunciation}
              maxLength={20}
              placeholder={pronunciation ? 'Điểm /100' : 'Điểm (tùy chọn)'}
              aria-label={pronunciation ? 'Điểm phát âm' : 'Điểm'}
              className={`w-24 ${input}`}
            />
            <input value={feedback} onChange={(e) => setFeedback(e.target.value)} maxLength={1000} placeholder="Nhận xét" aria-label="Nhận xét" className={`w-40 ${input}`} />
            <Button type="submit" variant={pronunciation ? 'primary' : 'secondary'} className="h-9 px-3 text-xs" disabled={m.isPending}>
              {pronunciation ? 'Chấm' : 'Đánh dấu đã xem'}
            </Button>
          </form>
        )}
      </div>
    </div>
  )
}
