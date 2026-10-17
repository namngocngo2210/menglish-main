// Chặng đang dạy & Order Test (SyllabusController::teachingStages) và Order đề test của một lớp (teacher.order-test).
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Button, Field, Icon } from '../../components/ui'
import { usePath } from '../../router'
import { syllabusApi, type TeachingStage } from './api'
import {
  Empty,
  EmptyRow,
  ErrorText,
  LinkButton,
  NoticeBar,
  PageHeader,
  StatusBadge,
  TableCard,
  tableCls,
  tbodyCls,
  td,
  TextArea,
  th,
  useAction,
} from './components'

export function TeachingStagesPage() {
  const page = useQuery({
    queryKey: ['syllabus', 'teaching-stages'],
    queryFn: syllabusApi.teachingStages,
  })
  const done = useAction(async (m: string) => ({ message: m }))
  const d = page.data
  return (
    <>
      <PageHeader
        title="Chặng đang dạy & Order Test"
        description="Quản lý các chặng học, lịch dự kiến Big Test và yêu cầu đề thi cho học viên."
        actions={
          d?.month && (
            <span className="inline-flex items-center gap-1.5 rounded-lg border border-outline-variant px-3 py-2 text-sm">
              <Icon name="calendar_today" className="text-[18px] text-primary" />
              {d.month}
            </span>
          )
        }
      />
      <NoticeBar notice={done.notice} onClose={() => done.setNotice(null)} />
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {d && !d.assignments.length && (
        <Empty
          icon="inventory_2"
          title="Chưa được giao chặng nào"
          hint="Hiện tại bạn chưa có chặng học nào đang mở. Vui lòng liên hệ Quản lý chuyên môn nếu có sai sót."
        />
      )}
      {d && d.assignments.length > 0 && (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          {d.assignments.map((a) => (
            <StageCard key={a.id} a={a} today={d.today} onDone={(m) => done.run(m)} />
          ))}
        </div>
      )}
    </>
  )
}

function StageCard({ a, today, onDone }: { a: TeachingStage; today: string; onDone: (m: string) => void }) {
  const [date, setDate] = useState(a.expected_date)
  const save = useAction(
    () => syllabusApi.setExpectedDate(a.id, date),
    (m) => onDone(m.message),
  )
  const orderHref = `/teacher/order-test/${a.class_id}`
  return (
    <article className="flex flex-col gap-3 rounded-xl border border-outline-variant bg-surface p-4 shadow-sm">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <span className="inline-flex rounded-md bg-primary-light px-2 py-0.5 text-xs font-medium text-primary">{a.stage_label}</span>
          <h3 className="mt-1 text-base font-semibold">{a.class_label}</h3>
        </div>
        <Icon name="school" className="text-primary" />
      </div>
      <div className="space-y-1 text-sm text-on-surface-variant">
        <p>Bắt đầu: {a.start_date || '—'}</p>
        <p className="flex items-center gap-1">
          <Icon name={a.is_assistant ? 'supervisor_account' : 'person'} className="text-[18px]" />
          {a.role_label}
        </p>
      </div>
      {a.exam === 'approved' && (
        <p className="flex items-center gap-1 rounded-lg bg-success-container px-3 py-2 text-sm font-medium text-on-success-container">
          <Icon name="check_circle" className="text-[18px]" />
          Đã có đề
        </p>
      )}
      {a.exam === 'pending' && (
        <p className="flex items-center gap-1 rounded-lg bg-warning-container px-3 py-2 text-sm font-medium text-warning">
          <Icon name="pending" className="text-[18px]" />
          Đã order - Chờ HT duyệt
        </p>
      )}
      <div className="space-y-2 rounded-lg border border-outline-variant bg-surface-container-low p-3 text-sm">
        <p>
          Ngày dự kiến Big Test: <strong className={a.date ? '' : 'text-error'}>{a.date || 'Chưa đặt lịch'}</strong>
          {a.big_test_code && <span className="text-xs text-on-surface-variant"> (đợt thi {a.big_test_code})</span>}
        </p>
        {a.can_set_date && (
          <form
            className="flex items-end gap-2"
            onSubmit={(e) => {
              e.preventDefault()
              save.run()
            }}
          >
            <div className="flex-1">
              <Field
                label={a.expected_date ? 'Sửa ngày' : 'Chọn ngày'}
                type="date"
                required
                min={today}
                value={date}
                onChange={(e) => setDate(e.target.value)}
              />
            </div>
            <Button type="submit" disabled={save.pending}>
              {a.expected_date ? 'Cập nhật' : 'Lưu'}
            </Button>
          </form>
        )}
        <ErrorText error={save.error} />
      </div>
      <div className="mt-auto">
        {a.order_status === 'pending' || a.order_status === 'approved' ? (
          <LinkButton href={orderHref} className="w-full">
            Chi tiết
          </LinkButton>
        ) : (
          a.can_order && (
            <>
              {a.order_status === 'rejected' && <p className="mb-2 text-xs text-error">Order trước bị từ chối: {a.order_rejection}</p>}
              <LinkButton href={orderHref} variant="primary" icon="assignment_add" className="w-full">
                Order đề Big Test
              </LinkButton>
            </>
          )
        )}
      </div>
    </article>
  )
}

// /teacher/order-test/<classId>
export function OrderTestPage() {
  const classId = usePath().split('/')[3] ?? ''
  const page = useQuery({
    queryKey: ['syllabus', 'order-test', classId],
    queryFn: () => syllabusApi.orderTest(classId),
    enabled: !!classId,
  })
  const [f, setF] = useState<{
    test_type: 'big' | 'mini'
    exam_date: string | null
    note: string
  }>({ test_type: 'big', exam_date: null, note: '' })
  const save = useAction(
    () =>
      syllabusApi.submitOrderTest(classId, {
        test_type: f.test_type,
        exam_date: f.exam_date ?? page.data?.open_assignment?.expected_big_test_date ?? '',
        note: f.note,
      }),
    () => setF({ test_type: 'big', exam_date: null, note: '' }),
  )
  const d = page.data
  if (!classId) return <Alert>Thiếu mã lớp.</Alert>
  const examDate = f.exam_date ?? d?.open_assignment?.expected_big_test_date ?? ''
  return (
    <>
      <PageHeader
        title={`Order đề test — ${d?.classroom.name ?? ''}`}
        back="/syllabus/teaching-stages"
        description={`${d?.classroom.program ? d.classroom.program + ' · ' : ''}Gửi yêu cầu cấp đề Mini/Big Test cho chặng đang dạy tới Ban Học thuật`}
      />
      <NoticeBar notice={save.notice} onClose={() => save.setNotice(null)} />
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {d && (
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <section className="space-y-3 rounded-xl border border-outline-variant bg-surface p-4 shadow-sm">
              <h2 className="flex items-center gap-2 font-semibold">
                <Icon name="flag" className="text-primary" />
                Chặng đang dạy
              </h2>
              {d.open_assignment ? (
                <div className="space-y-1 rounded-lg border border-primary/30 bg-primary-light p-3 text-sm">
                  <p className="font-semibold">{d.open_assignment.label}</p>
                  <p className="text-on-surface-variant">Bắt đầu: {d.open_assignment.started_at}</p>
                  <p className="text-xs text-on-surface-variant">{d.open_assignment.assigned_chapters}</p>
                </div>
              ) : (
                <div className="py-4 text-center text-sm">
                  <p className="font-medium">Chưa được giao chặng nào</p>
                  <p className="text-on-surface-variant">
                    Hiện tại lớp chưa có chặng học nào đang mở. Vui lòng liên hệ Quản lý chuyên môn nếu có sai sót.
                  </p>
                </div>
              )}
              {d.closed_assignments.length > 0 && (
                <div>
                  <p className="mb-1 text-xs font-medium text-on-surface-variant uppercase">Chặng đã học</p>
                  <ul className="space-y-1 text-sm text-on-surface-variant">
                    {d.closed_assignments.map((c) => (
                      <li key={c.id} className="flex items-center gap-1">
                        <Icon name="check_circle" className="text-[16px] text-success" />
                        {c.label} · đóng {c.closed_at}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </section>
            <form
              className="space-y-3 rounded-xl border border-outline-variant bg-surface p-4 shadow-sm"
              onSubmit={(e) => {
                e.preventDefault()
                save.run()
              }}
            >
              <h2 className="flex items-center gap-2 font-semibold">
                <Icon name="assignment_add" className="text-primary" />
                Order đề Big Test
              </h2>
              <div className="space-y-1.5">
                <p className="text-sm font-medium">Chặng</p>
                <p className="rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-sm font-semibold">
                  {d.open_assignment ? d.open_assignment.label : 'Lớp chưa mở chặng'}
                </p>
              </div>
              <div className="space-y-1.5">
                <p className="text-sm font-medium">
                  Loại đề <span className="text-error">*</span>
                </p>
                <div className="grid grid-cols-2 gap-2" role="radiogroup">
                  {(
                    [
                      ['big', 'Big Test (cuối chặng)'],
                      ['mini', 'Mini Test'],
                    ] as const
                  ).map(([v, l]) => (
                    <label
                      key={v}
                      className="flex cursor-pointer items-center gap-2 rounded-lg border border-outline-variant px-3 py-2 text-sm font-semibold"
                    >
                      <input
                        type="radio"
                        name="test_type"
                        checked={f.test_type === v}
                        onChange={() => setF({ ...f, test_type: v })}
                        className="accent-primary"
                      />
                      {l}
                    </label>
                  ))}
                </div>
              </div>
              <Field
                label="Ngày thi dự kiến"
                type="date"
                min={d.today}
                value={examDate}
                onChange={(e) => setF({ ...f, exam_date: e.target.value })}
                hint={`Hạn xử lý của Học thuật = ngày thi − ${d.lead_days} ngày (để trống: trong ${d.lead_days} ngày).`}
              />
              <TextArea
                label="Ghi chú cho Học thuật"
                rows={3}
                placeholder="VD: đề trọng tâm Listening Part 1-2, độ khó vừa phải..."
                value={f.note}
                onChange={(e) => setF({ ...f, note: e.target.value })}
              />
              <ErrorText error={save.error} />
              <Button type="submit" className="w-full" disabled={!d.open_assignment || save.pending}>
                Gửi yêu cầu tới Ban Học thuật
              </Button>
            </form>
          </div>
          <TableCard
            header={
              <h2 className="flex items-center gap-2 font-semibold">
                <Icon name="history" className="text-primary" />
                Yêu cầu đã gửi cho lớp này
              </h2>
            }
          >
            <table className={`${tableCls} min-w-[760px]`}>
              <thead>
                <tr>
                  <th className={th}>Thời gian</th>
                  <th className={th}>Loại đề</th>
                  <th className={th}>Chặng</th>
                  <th className={th}>Ngày thi / Hạn xử lý</th>
                  <th className={th}>Ghi chú</th>
                  <th className={th}>Trạng thái</th>
                </tr>
              </thead>
              <tbody className={tbodyCls}>
                {d.requests.map((r) => (
                  <tr key={r.id}>
                    <td className={`${td} font-mono text-xs`}>{r.created_at}</td>
                    <td className={`${td} font-semibold`}>{r.type_label}</td>
                    <td className={`${td} font-semibold`}>{r.stage_label}</td>
                    <td className={`${td} text-on-surface-variant`}>
                      <div>Thi: {r.exam_date || '—'}</div>
                      <div className="text-xs">Hạn: {r.due_date || '—'}</div>
                    </td>
                    <td className={`${td} text-on-surface-variant`}>{r.note || '—'}</td>
                    <td className={`${td} space-y-1`}>
                      <StatusBadge color={r.status_color}>{r.status_label}</StatusBadge>
                      {r.status === 'approved' ? (
                        <>
                          {r.test_link && (
                            <a
                              href={r.test_link}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="block text-xs font-semibold text-primary hover:underline"
                            >
                              Mở link đề
                            </a>
                          )}
                          {r.speaking_link ? (
                            <a
                              href={r.speaking_link}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="block text-xs font-semibold text-primary hover:underline"
                            >
                              Mở phần Speaking
                            </a>
                          ) : (
                            <p className="text-xs text-on-surface-variant">Chưa có link phần Speaking</p>
                          )}
                        </>
                      ) : (
                        r.rejection_reason && <p className="text-xs text-error">Lý do: {r.rejection_reason}</p>
                      )}
                    </td>
                  </tr>
                ))}
                {!d.requests.length && <EmptyRow colSpan={6} icon="assignment" title="Chưa gửi yêu cầu đề test nào cho lớp này" />}
              </tbody>
            </table>
          </TableCard>
        </div>
      )}
    </>
  )
}
