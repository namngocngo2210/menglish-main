import { useQuery } from '@tanstack/react-query'
import { errorMessage } from '../../api'
import { Alert, Button } from '../../components/ui'
import { placementApi, type SubmissionDetail } from './api'
import { Link } from './components'
import { SKILL_LABELS, SKILLS } from './logic'
import { BASE } from './TestsPage'

const LEVELS = ['PRE STARTERS', 'STARTERS', 'MOVERS', 'FLYERS', 'KET', 'PET', 'IELTS FOUNDATION', 'IELTS ACADEMIC']

// Level theo lớp xếp (thang điểm khối lớp), không quy đổi từ điểm trung bình.
export function activeLevel(s: Pick<SubmissionDetail, 'final_class' | 'is_pending'>): string | null {
  const c = s.final_class.toUpperCase()
  if (!c || s.is_pending) return null
  if (c.includes('PRE STARTERS')) return 'PRE STARTERS'
  if (c.includes('STARTERS')) return 'STARTERS'
  if (c.includes('MOVERS') || c.includes('FAM 2')) return 'MOVERS'
  for (const l of ['FLYERS', 'KET', 'PET', 'IELTS FOUNDATION']) if (c.includes(l)) return l
  return c.includes('IELTS') ? 'IELTS ACADEMIC' : null
}

function score(v: number | null) {
  return v === null ? '—' : String(Math.round(v * 10) / 10)
}

// Bản đánh giá năng lực tiếng Anh (Laravel portal-scorecard), in được. Trong v2 chỉ người đăng nhập có quyền mới xem.
export function ScorecardPage({ id }: { id: string }) {
  const q = useQuery({ queryKey: ['placement', 'scorecard', id], queryFn: () => placementApi.scorecard(id) })
  if (q.isError) return <Alert>{errorMessage(q.error)}</Alert>
  if (!q.data) return <p className="text-sm text-on-surface-variant">Đang tải…</p>
  const s = q.data
  const level = activeLevel(s)
  const rw = s.reading_writing_score ?? s.reading_score
  const values = { listening: s.listening_score, reading_writing: rw, speaking: s.speaking_score }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between print:hidden">
        <Link href={BASE} className="text-sm text-on-surface-variant hover:text-primary">
          ← Quay lại
        </Link>
        <Button onClick={() => window.print()}>In bản đánh giá</Button>
      </div>
      <main className="mx-auto max-w-4xl space-y-6 rounded-2xl border border-surface-container-highest bg-surface p-6 shadow-sm sm:p-10 print:border-0 print:shadow-none">
        <div className="flex flex-col items-center justify-between gap-4 border-b-2 border-brand pb-5 sm:flex-row">
          <div className="flex items-center gap-3">
            <img src="/images/menglish-logo.png" alt="MEnglish" className="h-16 w-auto object-contain" />
            <div>
              <div className="text-xl font-bold">MEnglish</div>
              <div className="text-xs font-semibold text-primary italic">We change - We lead</div>
              <div className="text-xs text-on-surface-variant">Hệ thống Anh ngữ MEnglish</div>
            </div>
          </div>
          <div className="space-y-1 text-center sm:text-right">
            <h1 className="text-xl font-bold text-primary uppercase">Bản đánh giá năng lực tiếng Anh</h1>
            <div className="font-mono text-xs text-on-surface-subtle">
              Mã bài thi: {s.test_code} · Ngày thi: {new Date(s.submitted_at).toLocaleDateString('vi-VN')}
            </div>
          </div>
        </div>

        <section className="grid gap-3 rounded-xl border border-brand/25 bg-primary-light p-4 text-sm sm:grid-cols-3">
          <div>
            <span className="text-xs text-on-surface-variant">Họ tên:</span>
            <div className="font-semibold">{s.candidate_name}</div>
          </div>
          <div>
            <span className="text-xs text-on-surface-variant">ĐT liên hệ:</span>
            <div className="font-mono font-semibold text-primary">{s.candidate_phone}</div>
          </div>
          <div>
            <span className="text-xs text-on-surface-variant">Email:</span>
            <div>{s.candidate_email || '—'}</div>
          </div>
        </section>

        {s.is_pending && (
          <Alert tone="info">
            Bài làm đã được ghi nhận và <strong>đang chờ Học vụ chấm</strong> phần Viết / Nói. Điểm tổng, trình độ và khóa học đề xuất sẽ được cập nhật sau khi chấm.
          </Alert>
        )}

        <section className="space-y-4 rounded-xl border-2 border-surface-container-highest p-5">
          <div className="flex flex-wrap items-center justify-between gap-2 border-b border-surface-container-highest pb-2">
            <h2 className="text-sm font-bold uppercase">Kết quả đánh giá năng lực</h2>
            <span className="font-mono text-xs text-on-surface-variant">{s.grade_group_label}</span>
          </div>
          <div className="flex flex-wrap gap-1">
            {LEVELS.map((l) => (
              <span key={l} className={`rounded-full px-2.5 py-1 text-xs font-semibold ${l === level ? 'bg-brand text-white' : 'bg-surface-container-low text-on-surface-variant'}`}>
                {l}
              </span>
            ))}
          </div>
          <table className="w-full border-collapse text-sm">
            <thead>
              <tr className="bg-sidebar text-left text-xs text-white">
                <th className="p-2">Kỹ năng</th>
                <th className="p-2 text-center">Điểm</th>
                <th className="p-2">Nhận xét</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-surface-container-highest">
              {SKILLS.map((k) => (
                <tr key={k} className="align-top">
                  <td className="p-2 font-semibold whitespace-nowrap">{SKILL_LABELS[k]}</td>
                  <td className="p-2 text-center font-mono whitespace-nowrap">
                    {score(values[k])}
                    {s.has_rubric && <span className="text-on-surface-subtle"> / {s.max_scores[k]}</span>}
                  </td>
                  <td className="p-2 text-xs leading-relaxed text-on-surface-variant">{s.comments[k] || '—'}</td>
                </tr>
              ))}
              <tr>
                <td className="p-2 font-bold">Tổng điểm</td>
                <td className="p-2 text-center font-mono font-bold text-primary">
                  {score(s.total_score ?? s.overall_score)}
                  {s.has_rubric && <span className="font-normal text-on-surface-subtle"> / {SKILLS.reduce((t, k) => t + s.max_scores[k], 0)}</span>}
                </td>
                <td className="p-2 text-sm">
                  Lớp đề xuất: <strong className="text-primary">{s.final_class || '—'}</strong>
                </td>
              </tr>
            </tbody>
          </table>
        </section>

        {s.teacher_comments && (
          <section className="rounded-xl border border-surface-container-highest p-4 text-sm">
            <h2 className="mb-1 text-xs font-bold uppercase">Nhận xét của giáo viên</h2>
            <p className="whitespace-pre-line">{s.teacher_comments}</p>
          </section>
        )}
        <p className="text-right text-xs text-on-surface-subtle">
          {s.grader_name && `Người chấm: ${s.grader_name}`}
          {s.graded_at && ` · ${new Date(s.graded_at).toLocaleDateString('vi-VN')}`}
        </p>
      </main>
    </div>
  )
}
