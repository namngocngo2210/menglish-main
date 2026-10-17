import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Dialog, Field } from '../../components/ui'
import { can } from '../../nav'
import { placementApi, type Invitation, type IssuedInvitation, type Question } from './api'
import { Empty, LinkButton, PageHeader, StatCard, td, th, Textarea } from './components'
import { formatDateTime, QUESTION_SKILLS, QUESTION_TYPES } from './logic'
import { BASE, SubmissionsTable } from './TestsPage'

const STATUS: Record<Invitation['status'], { label: string; tone: 'success' | 'neutral' | 'error' | 'info' }> = {
  active: { label: 'Đang dùng', tone: 'success' },
  expired: { label: 'Hết hạn', tone: 'neutral' },
  revoked: { label: 'Đã thu hồi', tone: 'error' },
  used_up: { label: 'Đã dùng hết lượt', tone: 'info' },
}

export function publicURL(path: string) {
  return window.location.origin + path
}

export function TestDetailPage({ me, id }: { me: Me; id: string }) {
  const qc = useQueryClient()
  const test = useQuery({ queryKey: ['placement', 'test', id], queryFn: () => placementApi.test(id) })
  const subs = useQuery({ queryKey: ['placement', 'submissions', id], queryFn: () => placementApi.submissions(id) })
  const canLink = can(me, 'placement_test.distribute', 'placement_test.send')
  const invitations = useQuery({ queryKey: ['placement', 'invitations', id], queryFn: () => placementApi.invitations(id) })
  const [linkOpen, setLinkOpen] = useState(false)
  const [issued, setIssued] = useState<IssuedInvitation | null>(null)
  const revoke = useMutation({
    mutationFn: (inv: Invitation) => placementApi.revokeInvitation(inv.id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['placement', 'invitations', id] }),
  })
  const toggle = useMutation({
    mutationFn: () => placementApi.toggleTest(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['placement'] }),
  })

  if (test.isError) return <Alert>{errorMessage(test.error)}</Alert>
  if (!test.data) return <p className="text-sm text-on-surface-variant">Đang tải…</p>
  const t = test.data

  return (
    <div className="space-y-6">
      <PageHeader
        title={t.title}
        back={BASE}
        badges={
          <>
            <span className="rounded-md bg-surface-container-low px-2 py-0.5 font-mono text-xs font-semibold">{t.code}</span>
            {t.is_preset ? <Badge>Đề mẫu hệ thống (Khóa sửa)</Badge> : <Badge tone="info">Đề tạo tay (Tùy biến)</Badge>}
            {t.is_active ? <Badge tone="success">Hoạt động</Badge> : <Badge>Ẩn</Badge>}
          </>
        }
        actions={
          <>
            {canLink && t.is_active && (
              <Button variant="secondary" onClick={() => setLinkOpen(true)}>
                Tạo link làm bài
              </Button>
            )}
            {can(me, 'placement_test.update') && (
              <Button variant="secondary" disabled={toggle.isPending} onClick={() => toggle.mutate()}>
                {t.is_active ? 'Ẩn đề' : 'Kích hoạt'}
              </Button>
            )}
            {!t.is_preset && can(me, 'placement_test.update') && (
              <LinkButton variant="primary" href={`${BASE}/${t.id}/edit`}>
                Sửa đề
              </LinkButton>
            )}
          </>
        }
      />
      {toggle.isError && <Alert>{errorMessage(toggle.error)}</Alert>}
      {issued && (
        <Alert tone="success">
          Đã tạo link làm bài (chỉ hiện một lần — hãy sao chép ngay):{' '}
          <code className="font-mono break-all">{publicURL(issued.path)}</code>{' '}
          <button type="button" className="font-semibold underline" onClick={() => navigator.clipboard?.writeText(publicURL(issued.path))}>
            Sao chép
          </button>
        </Alert>
      )}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <StatCard label="Cấp độ mục tiêu" value={t.target_level || '—'} />
        <StatCard label="Thời lượng làm bài" value={`${t.duration_minutes} phút`} />
        <StatCard label="Tổng số câu hỏi" value={`${t.questions_count} câu`} tone="primary" />
        <StatCard label="Lượt thí sinh đã thi" value={`${subs.data?.submissions.length ?? 0} lượt`} tone="success" hint={t.grade_group_label} />
      </div>

      <Card title="Link làm bài">
        <p className="mb-3 text-xs text-on-surface-variant">
          Mỗi link có hạn dùng và thu hồi được; thí sinh mở link là làm bài, không cần tài khoản. Ẩn đề thì mọi link của đề ngừng hoạt động.
        </p>
        {invitations.isError && <Alert>{errorMessage(invitations.error)}</Alert>}
        {revoke.isError && <Alert>{errorMessage(revoke.error)}</Alert>}
        <div className="overflow-x-auto">
          <table className="w-full min-w-[760px] text-left text-sm">
            <thead className="text-xs text-on-surface-variant">
              <tr>
                <th className={th}>Loại</th>
                <th className={th}>Ghi chú</th>
                <th className={th}>Hạn dùng</th>
                <th className={`${th} text-center`}>Đã nộp</th>
                <th className={th}>Trạng thái</th>
                <th className={th}>Người tạo</th>
                <th />
              </tr>
            </thead>
            <tbody className="divide-y divide-surface-container-highest">
              {invitations.data?.invitations.map((inv) => (
                <tr key={inv.id}>
                  <td className={td}>{inv.kind === 'lead' ? `Riêng: ${inv.lead_name || inv.candidate_name}` : 'Link chung'}</td>
                  <td className={`${td} text-on-surface-variant`}>{inv.note || '—'}</td>
                  <td className={`${td} whitespace-nowrap`}>{formatDateTime(inv.expires_at)}</td>
                  <td className={`${td} text-center font-mono`}>
                    {inv.submission_count}
                    {inv.max_submissions ? `/${inv.max_submissions}` : ''}
                  </td>
                  <td className={td}>
                    <Badge tone={STATUS[inv.status].tone}>{STATUS[inv.status].label}</Badge>
                    {inv.opened_at && <div className="text-xs text-on-surface-subtle">Mở lúc {formatDateTime(inv.opened_at)}</div>}
                  </td>
                  <td className={`${td} text-on-surface-variant`}>
                    {inv.created_by_name || '—'}
                    <div className="text-xs">{formatDateTime(inv.created_at)}</div>
                  </td>
                  <td className={`${td} pr-0 text-right`}>
                    {inv.status === 'active' && can(me, inv.kind === 'lead' ? 'entrance_test.send' : 'placement_test.distribute', 'placement_test.send') && (
                      <Button variant="danger" disabled={revoke.isPending} onClick={() => window.confirm('Thu hồi link này? Thí sinh sẽ không mở được nữa.') && revoke.mutate(inv)}>
                        Thu hồi
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
              {invitations.data && invitations.data.invitations.length === 0 && <Empty colSpan={7}>Chưa có link làm bài nào.</Empty>}
            </tbody>
          </table>
        </div>
      </Card>

      <Card title={`Danh sách thí sinh đã thi bộ đề này (${subs.data?.submissions.length ?? 0})`}>
        {subs.isError && <Alert>{errorMessage(subs.error)}</Alert>}
        <SubmissionsTable me={me} rows={subs.data?.submissions ?? []} filtered />
      </Card>

      {t.pdf_path && (
        <Card title="Đề PDF (thí sinh xem khi làm bài)">
          {t.audio_url && <audio controls className="mb-3 h-9 w-full" src={t.audio_url} />}
          <a href={t.pdf_url} target="_blank" rel="noopener noreferrer" className="text-sm font-semibold text-primary underline">
            Mở file PDF
          </a>
        </Card>
      )}

      <Card title="Chi tiết toàn bộ câu hỏi & đáp án chuẩn">
        <div className="space-y-4">
          {t.questions.map((q, i) => (
            <QuestionView key={q.id || i} q={q} index={i} />
          ))}
          {t.questions.length === 0 && <p className="text-sm text-on-surface-variant">Đề thi chưa có câu hỏi nào.</p>}
        </div>
      </Card>

      {linkOpen && (
        <LinkDialog
          testId={id}
          onClose={() => setLinkOpen(false)}
          onIssued={(r) => {
            setIssued(r)
            setLinkOpen(false)
            qc.invalidateQueries({ queryKey: ['placement', 'invitations', id] })
          }}
        />
      )}
    </div>
  )
}

function QuestionView({ q, index }: { q: Question; index: number }) {
  return (
    <div className="space-y-2 rounded-xl border border-surface-container-highest bg-surface-container-low/40 p-4 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <span className="rounded-md bg-sidebar px-2 py-0.5 font-mono text-xs font-semibold text-white">Câu {q.number || index + 1}</span>
          <Badge tone="info">{QUESTION_SKILLS.find((s) => s.value === q.skill)?.label ?? q.skill}</Badge>
          <span className="text-xs text-on-surface-subtle">{QUESTION_TYPES.find((t) => t.value === q.type)?.label ?? q.type}</span>
        </div>
        <span className="font-mono text-xs text-on-surface-variant">{q.points ?? 1} điểm</span>
      </div>
      {q.title && <p className="font-medium whitespace-pre-line">{q.title}</p>}
      {q.passage && <p className="rounded-lg border border-info/20 bg-info-container/40 p-3 text-xs whitespace-pre-line italic">“{q.passage}”</p>}
      {q.audio_url && <audio controls preload="none" className="h-8 w-full" src={q.audio_url} />}
      {q.image_url && <img src={q.image_url} alt="Minh họa câu hỏi" className="mx-auto max-h-56 rounded-lg object-contain" />}
      {!!q.options?.length && (
        <div className="grid gap-2 sm:grid-cols-2">
          {q.options.map((o) => {
            const right = String(o.key) === String(q.correct_answer)
            return (
              <div key={o.key} className={`flex items-center gap-2 rounded-lg border p-2 text-xs ${right ? 'border-success/40 bg-success-container' : 'border-surface-container-highest bg-surface'}`}>
                <span className="font-mono font-semibold">{o.key}</span>
                <span>{o.text}</span>
                {right && <span className="ml-auto font-semibold text-success">Đúng</span>}
              </div>
            )
          })}
        </div>
      )}
      {q.correct_answer && (
        <p className="rounded-lg border border-warning/30 bg-warning-container/50 p-2 text-xs">
          <strong>Đáp án chuẩn:</strong> <span className="font-mono font-semibold">{q.correct_answer}</span>
          {q.explanation && <span className="ml-2 text-on-surface-variant italic">({q.explanation})</span>}
        </p>
      )}
      {q.cue_points && (
        <p className="rounded-lg border border-error/20 bg-error-container/40 p-2 font-mono text-xs whitespace-pre-line">
          <strong>Gợi ý phỏng vấn Speaking:</strong>
          {'\n'}
          {q.cue_points}
        </p>
      )}
      {q.teacher_note && <p className="text-xs text-on-surface-variant">Ghi chú giáo viên: {q.teacher_note}</p>}
    </div>
  )
}

function LinkDialog({ testId, onClose, onIssued }: { testId: string; onClose: () => void; onIssued: (r: IssuedInvitation) => void }) {
  const [form, setForm] = useState({ expires_in_days: '30', max_submissions: '', note: '' })
  const create = useMutation({
    mutationFn: () =>
      placementApi.createInvitation(testId, {
        kind: 'public',
        expires_in_days: form.expires_in_days ? Number(form.expires_in_days) : undefined,
        max_submissions: form.max_submissions ? Number(form.max_submissions) : undefined,
        note: form.note,
      }),
    onSuccess: onIssued,
  })
  return (
    <Dialog title="Tạo link làm bài" onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          create.mutate()
        }}
      >
        {create.isError && <Alert>{errorMessage(create.error)}</Alert>}
        <p className="text-xs text-on-surface-variant">
          Link chung để gửi qua Zalo / hội thảo. Link riêng cho từng khách tạo trong hồ sơ khách CRM (tự điền sẵn thông tin khách).
        </p>
        <Field label="Hạn dùng (ngày)" type="number" min={1} max={90} value={form.expires_in_days} onChange={(e) => setForm({ ...form, expires_in_days: e.target.value })} />
        <Field
          label="Số lần nộp tối đa"
          type="number"
          min={1}
          max={1000}
          value={form.max_submissions}
          onChange={(e) => setForm({ ...form, max_submissions: e.target.value })}
          hint="Để trống = không giới hạn."
        />
        <Textarea label="Ghi chú" rows={2} maxLength={500} value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} placeholder="VD: Hội thảo tháng 10" />
        <Button type="submit" disabled={create.isPending}>
          Tạo link
        </Button>
      </form>
    </Dialog>
  )
}
