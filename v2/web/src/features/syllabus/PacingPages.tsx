// Điều chỉnh tiến độ: GV xin giãn (SyllabusController::teacherAdjust) và Học thuật / Admin duyệt (adjustmentRequests, ?request=).
// Duyệt thêm buổi vào cuối lịch lớp qua phân hệ Lớp học (giãn tiến độ); từ chối bắt buộc lý do.
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Dialog, Field, Select } from '../../components/ui'
import { useQueryParam } from '../../router'
import { syllabusApi, type PacingDetail, type PacingRow, type TeacherAdjustPage as AdjustPage } from './api'
import {
  EmptyRow,
  ErrorText,
  Info,
  LinkButton,
  NoticeBar,
  PageHeader,
  Pagination,
  setParam,
  TableCard,
  tableCls,
  tbodyCls,
  td,
  TextArea,
  th,
  useAction,
} from './components'
import { fmtDateTime } from './logic'

const TONE: Record<PacingRow['status'], 'warning' | 'success' | 'error'> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'error',
}

function SlaBadge({ r, long }: { r: PacingRow; long?: boolean }) {
  if (r.status === 'pending') {
    return (
      <Badge tone={r.sla_overdue ? 'error' : 'success'}>
        {r.sla_overdue ? (long ? 'Quá hạn xử lý' : 'Quá hạn') : long ? 'Còn hạn xử lý' : 'Còn hạn'}
      </Badge>
    )
  }
  return <Badge tone={TONE[r.status]}>{r.status_label}</Badge>
}

export function TeacherAdjustPage() {
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState(false)
  const list = useQuery({
    queryKey: ['syllabus', 'teacher-adjust', page],
    queryFn: () => syllabusApi.teacherAdjust(page),
  })
  const done = useAction(async (m: string) => ({ message: m }))
  const d = list.data
  return (
    <>
      <PageHeader
        title="Xin điều chỉnh tiến độ"
        back="/syllabus/teacher-propose"
        description="Gửi yêu cầu điều chỉnh thời gian cho các lớp hoặc chặng học hiện tại."
        actions={
          <>
            {d?.can_review && (
              <LinkButton href="/syllabus/adjustment-requests" icon="rule">
                Duyệt yêu cầu tiến độ
              </LinkButton>
            )}
            <Button onClick={() => setOpen(true)}>Gửi yêu cầu</Button>
          </>
        }
      />
      <NoticeBar notice={done.notice} onClose={() => done.setNotice(null)} />
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      <TableCard
        header={
          <h2 className="flex items-center gap-2 font-semibold">
            Danh sách yêu cầu đã gửi <Badge>{d?.total ?? 0} yêu cầu</Badge>
          </h2>
        }
        footer={d && <Pagination page={d.page} pageSize={d.page_size} total={d.total} unit="yêu cầu" onPage={setPage} />}
      >
        <table className={`${tableCls} min-w-[640px]`}>
          <thead>
            <tr>
              <th className={th}>Ngày gửi</th>
              <th className={th}>Lớp / Chặng</th>
              <th className={th}>Lý do</th>
              <th className={`${th} text-center`}>Số buổi thêm</th>
              <th className={th}>Trạng thái</th>
            </tr>
          </thead>
          <tbody className={tbodyCls}>
            {d?.requests.map((r) => (
              <tr key={r.id}>
                <td className={`${td} font-mono text-xs whitespace-nowrap text-on-surface-variant`}>{fmtDateTime(r.created_at)}</td>
                <td className={`${td} font-medium`}>{r.class_stage_label}</td>
                <td className={`${td} max-w-[240px]`}>
                  <p className="truncate" title={r.reason}>
                    {r.reason}
                  </p>
                  {r.status === 'approved' && r.applied_note && <p className="text-xs text-success">{r.applied_note}</p>}
                </td>
                <td className={`${td} text-center font-semibold`}>{r.extra_sessions || 0}</td>
                <td className={`${td} whitespace-nowrap`}>
                  <Badge tone={TONE[r.status] ?? 'neutral'}>{r.status_label}</Badge>
                  {r.status === 'rejected' && r.rejection_reason && (
                    <p className="mt-1 max-w-[200px] text-xs whitespace-normal text-error">{r.rejection_reason}</p>
                  )}
                </td>
              </tr>
            ))}
            {d && !d.requests.length && <EmptyRow colSpan={5} icon="speed" title="Bạn chưa gửi yêu cầu xin điều chỉnh tiến độ nào" />}
          </tbody>
        </table>
      </TableCard>
      {open && d && (
        <RequestDialog
          page={d}
          onClose={() => setOpen(false)}
          onDone={(m) => {
            setOpen(false)
            done.run(m)
          }}
        />
      )}
    </>
  )
}

function RequestDialog({ page, onClose, onDone }: { page: AdjustPage; onClose: () => void; onDone: (m: string) => void }) {
  const [f, setF] = useState({ class_id: '', reason: '', sessions: '' })
  const save = useAction(
    () =>
      syllabusApi.createPacing({
        class_id: f.class_id,
        reason: f.reason,
        extra_sessions: f.sessions ? Number(f.sessions) : null,
      }),
    (m) => onDone(m.message),
  )
  return (
    <Dialog title="Gửi yêu cầu điều chỉnh tiến độ" onClose={onClose}>
      {!page.classes.length ? (
        <div className="space-y-1 py-4 text-center text-sm">
          <p className="font-semibold">Không có chặng học nào đang mở</p>
          <p className="text-on-surface-variant">Bạn hiện không có chặng học nào đang mở để gửi yêu cầu.</p>
        </div>
      ) : (
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault()
            save.run()
          }}
        >
          <Select label="Lớp học / Chặng học" required value={f.class_id} onChange={(e) => setF({ ...f, class_id: e.target.value })}>
            <option value="" disabled>
              Chọn lớp/chặng cần xin giãn
            </option>
            {page.classes.map((c) => (
              <option key={c.value} value={c.value}>
                {c.label}
              </option>
            ))}
          </Select>
          <TextArea
            label="Lý do xin điều chỉnh"
            required
            rows={4}
            placeholder="Vui lòng ghi rõ lý do (VD: Học sinh chưa nắm vững kiến thức, cháy giáo án do mất điện...)"
            value={f.reason}
            onChange={(e) => setF({ ...f, reason: e.target.value })}
          />
          <div className="space-y-1.5">
            <p className="text-sm font-medium">
              Số buổi cần thêm <span className="text-error">*</span>
            </p>
            <div className="grid grid-cols-2 gap-3" role="radiogroup">
              {['1', '2'].map((n) => (
                <label
                  key={n}
                  className={`flex cursor-pointer items-center justify-center rounded-lg border px-3 py-3 text-sm font-medium ${f.sessions === n ? 'border-primary bg-primary-light text-primary' : 'border-outline-variant'}`}
                >
                  <input
                    type="radio"
                    name="extra_sessions"
                    value={n}
                    checked={f.sessions === n}
                    onChange={() => setF({ ...f, sessions: n })}
                    className="sr-only"
                  />
                  {n} buổi
                </label>
              ))}
            </div>
            <p className="text-xs text-on-surface-variant">
              Khi được duyệt, hệ thống thêm đúng số buổi này vào cuối lịch học của lớp (theo TKB, bỏ qua ngày nghỉ).
            </p>
          </div>
          <Alert tone="info">
            <strong>Quy định SLA:</strong> Yêu cầu được Ban Học thuật xem xét và phản hồi trong vòng {page.sla_days} ngày.
          </Alert>
          <ErrorText error={save.error} />
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={onClose}>
              Hủy
            </Button>
            <Button type="submit" disabled={save.pending}>
              Gửi yêu cầu
            </Button>
          </div>
        </form>
      )}
    </Dialog>
  )
}

export function AdjustmentRequestsPage() {
  const selectedId = useQueryParam('request')
  const [filters, setFilters] = useState({ status: '', page: 1 })
  const list = useQuery({
    queryKey: ['syllabus', 'adjustment-requests', filters, selectedId],
    queryFn: () =>
      syllabusApi.adjustmentRequests({
        ...filters,
        request: selectedId || undefined,
      }),
  })
  const done = useAction(async (m: string) => ({ message: m }))
  const d = list.data
  return (
    <>
      <PageHeader
        title="Duyệt yêu cầu xin điều chỉnh tiến độ"
        back="/syllabus/documents"
        description="Quản lý các yêu cầu giãn tiến độ từ giáo viên. Duyệt sẽ thêm buổi vào cuối lịch của lớp; từ chối bắt buộc nhập lý do."
        actions={
          <>
            <Select label="Lọc" value={filters.status || d?.status || ''} onChange={(e) => setFilters({ status: e.target.value, page: 1 })}>
              {d?.status_options.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
            <LinkButton href="/syllabus/teacher-adjust" icon="speed">
              Gửi yêu cầu mới
            </LinkButton>
          </>
        }
      />
      <NoticeBar notice={done.notice} onClose={() => done.setNotice(null)} />
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      <TableCard
        header={
          <h2 className="flex items-center gap-2 font-semibold">
            {d?.list_title} <Badge tone="info">{d?.total ?? 0} yêu cầu</Badge>
          </h2>
        }
        footer={
          d && <Pagination page={d.page} pageSize={d.page_size} total={d.total} unit="yêu cầu" onPage={(page) => setFilters({ ...filters, page })} />
        }
      >
        <table className={`${tableCls} min-w-[760px]`}>
          <thead>
            <tr>
              <th className={th}>Giáo viên</th>
              <th className={th}>Lớp / Chặng học</th>
              <th className={`${th} text-right`}>Xin thêm</th>
              <th className={th}>Lý do</th>
              <th className={th}>Ngày gửi</th>
              <th className={th}>Trạng thái</th>
              <th className={th}>
                <span className="sr-only">Thao tác</span>
              </th>
            </tr>
          </thead>
          <tbody className={tbodyCls}>
            {d?.requests.map((r) => (
              <tr
                key={r.id}
                className={`cursor-pointer hover:bg-surface-container-low ${d.selected?.id === r.id ? 'bg-primary-light' : ''}`}
                onClick={() => setParam('request', r.id)}
              >
                <td className={`${td} font-semibold`}>{r.teacher || '—'}</td>
                <td className={td}>{r.class_stage_label}</td>
                <td className={`${td} text-right font-semibold whitespace-nowrap`}>{r.extra_sessions || 0} buổi</td>
                <td className={`${td} max-w-[280px]`}>
                  <p className="line-clamp-1 text-on-surface-variant" title={r.reason}>
                    {r.reason}
                  </p>
                </td>
                <td className={`${td} font-mono text-xs whitespace-nowrap`}>{fmtDateTime(r.created_at)}</td>
                <td className={`${td} whitespace-nowrap`}>
                  <SlaBadge r={r} />
                </td>
                <td className={`${td} text-right`}>
                  <Button variant="secondary" className="h-8 px-3">
                    Xem
                  </Button>
                </td>
              </tr>
            ))}
            {d && !d.requests.length && <EmptyRow colSpan={7} title="Không có yêu cầu nào" />}
          </tbody>
        </table>
      </TableCard>
      {d?.selected && (
        <ReviewDialog
          r={d.selected}
          canReview={d.can_review}
          max={d.max_extra_sessions}
          onDone={(m) => {
            done.run(m)
            setParam('request', null)
          }}
        />
      )}
    </>
  )
}

function ReviewDialog({ r, canReview, max, onDone }: { r: PacingDetail; canReview: boolean; max: number; onDone: (m: string) => void }) {
  const [rejecting, setRejecting] = useState(false)
  const [reason, setReason] = useState('')
  const [extra, setExtra] = useState(String(r.extra_sessions ?? 0))
  const act = useAction(
    () => (rejecting ? syllabusApi.rejectPacing(r.id, reason) : syllabusApi.approvePacing(r.id, extra === '' ? null : Number(extra))),
    (m) => onDone(m.message),
  )
  return (
    <Dialog title="Chi tiết yêu cầu điều chỉnh tiến độ" onClose={() => setParam('request', null)} wide>
      <div className="space-y-4 text-sm">
        <div className="flex items-center justify-between gap-3">
          <div>
            <p className="text-base font-semibold">{r.teacher || '—'}</p>
            <p className="text-xs text-on-surface-variant">{r.teacher_code || 'Giáo viên'}</p>
          </div>
          <SlaBadge r={r} long />
        </div>
        <dl className="grid grid-cols-1 gap-3 rounded-lg border border-outline-variant bg-surface-container-low p-3 sm:grid-cols-3">
          <Info label="Lớp / Chặng học" className="sm:col-span-3">
            {r.class_stage_label}
          </Info>
          <Info label="Ngày gửi yêu cầu">{fmtDateTime(r.created_at)}</Info>
          <Info label="Số buổi xin thêm">+{r.extra_sessions || 0} buổi</Info>
          <Info label="Kết thúc lớp hiện tại">{r.class_end_date || '—'}</Info>
        </dl>
        <div>
          <p className="mb-1 text-xs font-medium text-on-surface-variant uppercase">Lý do xin giãn tiến độ</p>
          <div className="rounded-lg border border-outline-variant p-3">
            <p className="whitespace-pre-line">{r.reason}</p>
            {r.request_type && <p className="mt-1 text-xs text-on-surface-variant">{r.request_type}</p>}
          </div>
        </div>
        {r.status === 'approved' && (
          <Alert tone="success">
            <strong>Đã duyệt bởi {r.reviewed_by}</strong>
            <br />
            {r.applied_note}
          </Alert>
        )}
        {r.status === 'rejected' && (
          <Alert>
            <strong>Đã từ chối bởi {r.reviewed_by}</strong>
            <br />
            Lý do: {r.rejection_reason || '—'}
          </Alert>
        )}
        {r.status === 'pending' && canReview && (
          <>
            {rejecting ? (
              <div className="rounded-lg border border-error/30 bg-error-container/30 p-3">
                <TextArea
                  label="Lý do từ chối (Bắt buộc)"
                  rows={3}
                  autoFocus
                  placeholder="Nhập lý do chi tiết để phản hồi lại giáo viên..."
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                />
              </div>
            ) : (
              <Field
                label="Số buổi thêm vào lịch khi duyệt"
                type="number"
                min={0}
                max={max}
                value={extra}
                onChange={(e) => setExtra(e.target.value)}
                hint="Buổi mới nối tiếp sau buổi cuối của lớp, theo TKB, bỏ qua ngày nghỉ lễ; 0 = chỉ ghi nhận, không đổi lịch."
              />
            )}
            <ErrorText error={act.error} />
            <div className="flex flex-wrap justify-end gap-2">
              {rejecting ? (
                <>
                  <Button variant="secondary" onClick={() => setRejecting(false)}>
                    Quay lại
                  </Button>
                  <Button variant="danger" disabled={act.pending} onClick={() => act.run()}>
                    Xác nhận từ chối
                  </Button>
                </>
              ) : (
                <>
                  <Button variant="danger" onClick={() => setRejecting(true)}>
                    Từ chối
                  </Button>
                  <Button disabled={act.pending} onClick={() => act.run()}>
                    Phê duyệt
                  </Button>
                </>
              )}
            </div>
          </>
        )}
      </div>
    </Dialog>
  )
}
