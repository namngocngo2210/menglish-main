import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Select } from '../../components/ui'
import { can } from '../../nav'
import { placementApi, type FeedbackInput, type TrialFilter, type TrialRow, type TrialStatus } from './api'
import { Empty, PageHeader, Pagination, Tabs, td, th, Textarea } from './components'
import { formatDate, formatDateTime } from './logic'

const STATUS_TONE: Record<TrialStatus, 'info' | 'success' | 'error' | 'neutral'> = {
  scheduled: 'info',
  attended: 'success',
  no_show: 'error',
  cancelled: 'neutral',
}
const STATUSES: { value: TrialStatus; label: string }[] = [
  { value: 'scheduled', label: 'Đã hẹn' },
  { value: 'attended', label: 'Đã học thử' },
  { value: 'no_show', label: 'Vắng mặt' },
  { value: 'cancelled', label: 'Đã hủy' },
]
const SCOPES = [
  { value: 'upcoming' as const, label: 'Hôm nay & sắp tới' },
  { value: 'past' as const, label: 'Đã diễn ra' },
]
const REMARK_FIELDS = [
  { key: 'grammar', label: 'Thực hành ngữ pháp', placeholder: 'Khá' },
  { key: 'attitude', label: 'Tinh thần học tập', placeholder: 'Hăng hái' },
  { key: 'result', label: 'Kết quả', placeholder: 'Đạt mục tiêu' },
]

function SessionCell({ b }: { b: TrialRow }) {
  return (
    <>
      <div className="font-semibold">{b.class_name || 'Lớp học'}</div>
      <div className="text-on-surface-variant">
        {formatDate(b.date)} · {b.start || '—'}–{b.end || '—'}
      </div>
      <div className="text-on-surface-subtle">{[b.course_name, b.branch_name].filter(Boolean).join(' · ')}</div>
    </>
  )
}

function FeedbackView({ b }: { b: TrialRow }) {
  if (!b.feedback_at) return <span className="text-on-surface-subtle italic">Chưa có nhận xét</span>
  return (
    <div className="space-y-0.5 text-on-surface-variant">
      {b.rating && (
        <div>
          <span className="font-semibold text-on-surface">{b.rating}/5</span>
          {b.remarks_summary && ` · ${b.remarks_summary}`}
        </div>
      )}
      <div>{b.feedback || '—'}</div>
      <div className="text-on-surface-subtle">
        {b.feedback_by} · {formatDateTime(b.feedback_at)}
      </div>
    </div>
  )
}

// "Lịch học thử" (Laravel classes.trial-booking): buổi học thử của khách trong phạm vi CRM. Đặt / hủy trong hồ sơ khách.
export function TrialSchedulePage({ me }: { me: Me }) {
  const qc = useQueryClient()
  const [filter, setFilter] = useState<TrialFilter>({ scope: 'upcoming', status: '', page: 1 })
  const list = useQuery({ queryKey: ['placement', 'trials', filter], queryFn: () => placementApi.trialSchedule(filter) })
  const cancel = useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) => placementApi.cancelTrial(id, reason),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['placement', 'trials'] }),
  })
  return (
    <div className="space-y-4">
      <PageHeader
        title="Lịch học thử"
        description="Các buổi học thử đã đặt cho khách tuyển sinh. Đặt hoặc hủy học thử trong hồ sơ khách (CRM); giáo viên buổi đó thấy khách và nhận xét vào hồ sơ."
      />
      <Tabs value={filter.scope ?? 'upcoming'} onChange={(scope) => setFilter({ ...filter, scope, page: 1 })} items={SCOPES} />
      <div className="max-w-xs">
        <Select label="Trạng thái" value={filter.status} onChange={(e) => setFilter({ ...filter, status: e.target.value, page: 1 })}>
          <option value="">Tất cả trạng thái</option>
          {STATUSES.map((s) => (
            <option key={s.value} value={s.value}>
              {s.label}
            </option>
          ))}
        </Select>
      </div>
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {cancel.isError && <Alert>{errorMessage(cancel.error)}</Alert>}
      <Card>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[960px] text-left text-xs">
            <thead className="text-on-surface-variant">
              <tr>
                <th className={th}>Buổi học</th>
                <th className={th}>Khách học thử</th>
                <th className={th}>Trạng thái</th>
                <th className={th}>Nhận xét của giáo viên</th>
                <th className={`${th} text-right`}>Thao tác</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-surface-container-highest">
              {list.data?.bookings.map((b) => (
                <tr key={b.id}>
                  <td className={td}>
                    <SessionCell b={b} />
                  </td>
                  <td className={td}>
                    <div className="font-semibold">{b.customer_name || '—'}</div>
                    {b.parent_name && <div className="text-on-surface-variant">PH: {b.parent_name}</div>}
                    <div className="text-on-surface-variant">
                      {b.stage_label || '—'} · Test: {b.test_score || 'Chưa test'}
                    </div>
                    <div className="text-on-surface-subtle">
                      Phụ trách: {b.assigned_name || '—'} · Đặt bởi: {b.booked_by || '—'}
                    </div>
                  </td>
                  <td className={td}>
                    <Badge tone={STATUS_TONE[b.status]}>{b.status_label}</Badge>
                  </td>
                  <td className={td}>
                    <FeedbackView b={b} />
                  </td>
                  <td className={`${td} pr-0 text-right`}>
                    {b.status === 'scheduled' && can(me, 'lead.stage_forward') && (
                      <Button
                        variant="danger"
                        className="h-8"
                        disabled={cancel.isPending}
                        onClick={() => {
                          const reason = window.prompt('Lý do hủy buổi học thử:')
                          if (reason !== null) cancel.mutate({ id: b.id, reason })
                        }}
                      >
                        Hủy
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
              {list.data && list.data.bookings.length === 0 && (
                <Empty colSpan={5}>{filter.scope === 'past' ? 'Chưa có buổi học thử nào đã diễn ra.' : 'Chưa có lịch học thử sắp tới.'}</Empty>
              )}
            </tbody>
          </table>
        </div>
        {list.data && (
          <Pagination page={list.data.page} perPage={list.data.per_page} total={list.data.total} unit="buổi" onPage={(page) => setFilter({ ...filter, page })} />
        )}
      </Card>
    </div>
  )
}

// "Nhận xét học thử" (Cổng Giáo viên): khách học thử trên buổi dạy của giáo viên; điểm danh + nhận xét từ ngày học.
export function TeacherTrialsPage() {
  const [filter, setFilter] = useState<TrialFilter>({ scope: 'upcoming', page: 1 })
  const [flash, setFlash] = useState<string | null>(null)
  const list = useQuery({ queryKey: ['placement', 'teacher-trials', filter], queryFn: () => placementApi.teacherTrials(filter) })
  return (
    <div className="space-y-4">
      <PageHeader
        title="Nhận xét học thử"
        description="Khách học thử (chưa chốt) trên buổi dạy của bạn. Nhận xét như học sinh chính thức — lưu vào hồ sơ khách tuyển sinh để Học vụ / tư vấn viên theo dõi."
      />
      {flash && <Alert tone="success">{flash}</Alert>}
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      <Tabs value={filter.scope ?? 'upcoming'} onChange={(scope) => setFilter({ scope, page: 1 })} items={SCOPES} />
      <Card>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[960px] text-left text-xs">
            <thead className="text-on-surface-variant">
              <tr>
                <th className={th}>Buổi học</th>
                <th className={th}>Khách học thử</th>
                <th className={th}>Trạng thái</th>
                <th className={th}>Nhận xét của giáo viên</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-surface-container-highest">
              {list.data?.bookings.map((b) => (
                <tr key={b.id}>
                  <td className={`${td} w-48`}>
                    <SessionCell b={b} />
                  </td>
                  <td className={`${td} w-52`}>
                    <div className="font-semibold">{b.customer_name || '—'}</div>
                    {b.parent_name && <div className="text-on-surface-variant">PH: {b.parent_name}</div>}
                    <div className="text-on-surface-variant">Kết quả test: {b.test_score || 'Chưa test'}</div>
                    {b.notes && <div className="mt-1 text-on-surface-variant">Ghi chú của Học vụ: {b.notes}</div>}
                  </td>
                  <td className={td}>
                    <Badge tone={STATUS_TONE[b.status]}>{b.status_label}</Badge>
                  </td>
                  <td className={td}>
                    {b.feedback_at && (
                      <div className="mb-2">
                        <FeedbackView b={b} />
                      </div>
                    )}
                    {b.status === 'cancelled' ? null : b.started ? (
                      <FeedbackForm b={b} onSaved={setFlash} />
                    ) : (
                      <p className="text-on-surface-subtle italic">Nhận xét được mở từ ngày học thử.</p>
                    )}
                  </td>
                </tr>
              ))}
              {list.data && list.data.bookings.length === 0 && (
                <Empty colSpan={4}>
                  {filter.scope === 'past' ? 'Chưa có buổi học thử nào đã diễn ra.' : 'Không có khách học thử sắp tới trên buổi dạy của bạn.'}
                </Empty>
              )}
            </tbody>
          </table>
        </div>
        {list.data && (
          <Pagination page={list.data.page} perPage={list.data.per_page} total={list.data.total} unit="buổi" onPage={(page) => setFilter({ ...filter, page })} />
        )}
      </Card>
    </div>
  )
}

function FeedbackForm({ b, onSaved }: { b: TrialRow; onSaved: (msg: string) => void }) {
  const qc = useQueryClient()
  const [form, setForm] = useState<FeedbackInput>({
    status: b.status === 'no_show' ? 'no_show' : 'attended',
    rating: b.rating ?? 4,
    remarks: { ...b.remarks },
    feedback: b.feedback ?? '',
  })
  const save = useMutation({
    mutationFn: () => placementApi.trialFeedback(b.id, form),
    onSuccess: (r) => {
      onSaved(r.message)
      qc.invalidateQueries({ queryKey: ['placement', 'teacher-trials'] })
    },
  })
  return (
    <form
      className="space-y-2"
      onSubmit={(e) => {
        e.preventDefault()
        save.mutate()
      }}
    >
      {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
      <div className="flex flex-wrap gap-2">
        <select
          aria-label="Trạng thái"
          className="h-8 rounded-lg border border-outline-variant bg-surface px-2 text-xs"
          value={form.status}
          onChange={(e) => setForm({ ...form, status: e.target.value as FeedbackInput['status'] })}
        >
          <option value="attended">Có mặt (đã học thử)</option>
          <option value="no_show">Vắng mặt</option>
        </select>
        <select
          aria-label="Mức độ phù hợp với lớp"
          className="h-8 rounded-lg border border-outline-variant bg-surface px-2 text-xs"
          value={form.rating ?? ''}
          onChange={(e) => setForm({ ...form, rating: Number(e.target.value) })}
        >
          {[5, 4, 3, 2, 1].map((r) => (
            <option key={r} value={r}>
              {r}/5
            </option>
          ))}
        </select>
      </div>
      <div className="grid grid-cols-3 gap-2">
        {REMARK_FIELDS.map((f) => (
          <label key={f.key} className="block">
            <span className="text-xs font-semibold text-on-surface-variant uppercase">{f.label}</span>
            <input
              maxLength={255}
              placeholder={f.placeholder}
              value={form.remarks[f.key] ?? ''}
              onChange={(e) => setForm({ ...form, remarks: { ...form.remarks, [f.key]: e.target.value } })}
              className="h-8 w-full rounded-lg border border-outline-variant bg-surface px-2 text-xs"
            />
          </label>
        ))}
      </div>
      <Textarea
        aria-label="Nhận xét chi tiết"
        rows={2}
        maxLength={3000}
        placeholder="Nhận xét chi tiết: mức độ phù hợp với lớp, tương tác, đề xuất..."
        value={form.feedback}
        onChange={(e) => setForm({ ...form, feedback: e.target.value })}
      />
      <Button type="submit" className="h-8" disabled={save.isPending}>
        Lưu nhận xét
      </Button>
    </form>
  )
}
