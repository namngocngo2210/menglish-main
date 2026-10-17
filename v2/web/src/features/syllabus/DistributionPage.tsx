// Duyệt & Phân phối đề Big Test (SyllabusController::bigTestDistribution): order đề (?order= mở chi tiết), đợt thi
// (gắn chặng, duyệt & phân phối), tạo đợt thi bản nháp.
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Dialog, Field, Icon, Select } from '../../components/ui'
import { useQueryParam } from '../../router'
import { syllabusApi, type BigTestRow, type DistributionPage as Page, type OrderDetail } from './api'
import {
  EmptyRow,
  ErrorText,
  Info,
  Link,
  NoticeBar,
  PageHeader,
  Pagination,
  setParam,
  StatusBadge,
  TableCard,
  tableCls,
  tbodyCls,
  td,
  TextArea,
  th,
  useAction,
} from './components'
import { timeAgo } from './logic'

export function PaperWarningText({ w }: { w: BigTestRow['paper_warning'] }) {
  if (!w) return null
  return (
    <p className={`flex items-center gap-1 text-xs ${w.level === 'overdue' ? 'text-error' : 'text-warning'}`}>
      <Icon name={w.level === 'overdue' ? 'error' : 'warning'} className="text-[16px]" />
      {w.label}
    </p>
  )
}

export function DistributionPage() {
  const orderId = useQueryParam('order')
  const [draft, setDraft] = useState({ order_search: '' })
  const [filters, setFilters] = useState({
    order_status: 'pending',
    order_search: '',
    orders_page: 1,
    tests_page: 1,
  })
  const list = useQuery({
    queryKey: ['syllabus', 'distribution', filters, orderId],
    queryFn: () =>
      syllabusApi.distribution({
        ...filters,
        order: orderId || undefined,
      }),
  })
  const [creating, setCreating] = useState(false)
  const [staging, setStaging] = useState<BigTestRow | null>(null)
  const done = useAction(async (m: string) => ({ message: m }))
  const approve = useAction(syllabusApi.approveBigTest)
  const d = list.data
  const notice = done.notice ?? approve.notice

  return (
    <>
      <PageHeader
        title="Duyệt & Phân phối đề Big Test"
        description="Quản lý yêu cầu ra đề từ giáo viên và phân phối tài liệu kiểm tra."
        actions={
          d?.can_manage && (
            <Button className="gap-1.5" onClick={() => setCreating(true)}>
              <Icon name="add_circle" className="text-[18px]" />
              Tạo đợt Big Test mới
            </Button>
          )
        }
      />
      <NoticeBar
        notice={notice}
        onClose={() => {
          done.setNotice(null)
          approve.setNotice(null)
        }}
      />
      <ErrorText error={approve.error} />
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      <div className="space-y-6">
        <TableCard
          header={
            <>
              <h2 className="flex items-center gap-2 font-semibold">
                <Icon name="pending_actions" className="text-primary" />
                Yêu cầu chờ duyệt <Badge tone="info">{d?.pending_orders ?? 0}</Badge>
              </h2>
              <form
                className="flex flex-wrap items-end gap-2"
                onSubmit={(e) => {
                  e.preventDefault()
                  setFilters({
                    ...filters,
                    order_search: draft.order_search,
                    orders_page: 1,
                  })
                }}
              >
                <Field
                  label="Tìm lớp"
                  placeholder="Tìm tên lớp..."
                  value={draft.order_search}
                  onChange={(e) =>
                    setDraft({
                      order_search: e.target.value,
                    })
                  }
                />
                <Select
                  label="Trạng thái order"
                  value={filters.order_status}
                  onChange={(e) =>
                    setFilters({
                      ...filters,
                      order_status: e.target.value,
                      orders_page: 1,
                    })
                  }
                >
                  <option value="">Tất cả</option>
                  {d?.order_status_options.map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              </form>
            </>
          }
          footer={
            d && (
              <Pagination
                page={d.orders_page.page}
                pageSize={d.orders_page.page_size}
                total={d.orders_page.total}
                unit="order"
                onPage={(p) => setFilters({ ...filters, orders_page: p })}
              />
            )
          }
        >
          <table className={`${tableCls} min-w-[880px]`}>
            <thead>
              <tr>
                <th className={th}>Lớp</th>
                <th className={th}>Chặng · Loại đề</th>
                <th className={th}>Giáo viên</th>
                <th className={th}>Ngày thi</th>
                <th className={th}>Hạn xử lý</th>
                <th className={th}>Trạng thái</th>
                <th className={th}>
                  <span className="sr-only">Thao tác</span>
                </th>
              </tr>
            </thead>
            <tbody className={tbodyCls}>
              {d?.orders.map((o) => (
                <tr
                  key={o.id}
                  className={`cursor-pointer hover:bg-surface-container-low ${d.selected_order?.id === o.id ? 'bg-primary-light' : ''}`}
                  onClick={() => setParam('order', o.id)}
                >
                  <td className={td}>
                    <p className="font-semibold">{o.class_label}</p>
                    <p className="text-xs text-on-surface-variant">Order {timeAgo(o.ordered_at)}</p>
                  </td>
                  <td className={td}>{o.stage_type}</td>
                  <td className={td}>{o.teacher ?? '—'}</td>
                  <td className={`${td} font-mono text-xs whitespace-nowrap`}>{o.exam_date || 'chưa chốt'}</td>
                  <td className={`${td} font-mono text-xs whitespace-nowrap ${o.overdue ? 'font-semibold text-error' : ''}`}>{o.due_date || '—'}</td>
                  <td className={`${td} whitespace-nowrap`}>
                    {o.overdue ? (
                      <Badge tone="error">Trễ hạn</Badge>
                    ) : o.sla_warning ? (
                      <Badge tone="warning">Cảnh báo SLA</Badge>
                    ) : (
                      <StatusBadge color={o.status_color}>{o.status_label}</StatusBadge>
                    )}
                  </td>
                  <td className={`${td} text-right`}>
                    <Button variant="secondary" className="h-8 px-3">
                      Xem
                    </Button>
                  </td>
                </tr>
              ))}
              {d && !d.orders.length && <EmptyRow colSpan={7} title="Không có order đề nào" />}
            </tbody>
          </table>
        </TableCard>

        <TableCard
          header={
            <h2 className="flex items-center gap-2 font-semibold">
              <Icon name="event_note" className="text-primary" />
              Đợt thi Big Test
            </h2>
          }
          footer={
            d && (
              <Pagination
                page={d.tests_page.page}
                pageSize={d.tests_page.page_size}
                total={d.tests_page.total}
                unit="đợt thi"
                onPage={(p) => setFilters({ ...filters, tests_page: p })}
              />
            )
          }
        >
          <table className={`${tableCls} min-w-[980px]`}>
            <thead>
              <tr>
                <th className={th}>Mã kỳ thi</th>
                <th className={th}>Tên kỳ thi</th>
                <th className={th}>Lớp thi / Chặng</th>
                <th className={th}>Thời gian &amp; Địa điểm</th>
                <th className={th}>Giám thị</th>
                <th className={th}>Mật mã thi</th>
                <th className={`${th} text-right`}>Thao tác</th>
              </tr>
            </thead>
            <tbody className={tbodyCls}>
              {d?.big_tests.map((bt) => (
                <tr key={bt.id}>
                  <td className={`${td} font-mono font-bold`}>{bt.code}</td>
                  <td className={`${td} font-semibold`}>
                    {bt.title}
                    <PaperWarningText w={bt.paper_warning} />
                    <div className="flex flex-wrap gap-2 text-xs font-normal">
                      {bt.content_url && (
                        <a href={bt.content_url} target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
                          Link đề
                        </a>
                      )}
                      {bt.speaking_url && (
                        <a href={bt.speaking_url} target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
                          Phần Speaking
                        </a>
                      )}
                    </div>
                  </td>
                  <td className={td}>
                    <span className="font-semibold text-primary">{bt.class_name}</span>
                    <span className={`block text-xs ${bt.stage_label ? 'text-on-surface-variant' : 'text-warning'}`}>
                      {bt.stage_label ?? 'Chưa gắn chặng'}
                    </span>
                  </td>
                  <td className={td}>
                    <div>{bt.scheduled_at || '—'}</div>
                    <div className="text-xs text-on-surface-variant">{bt.room}</div>
                  </td>
                  <td className={td}>{bt.proctor ?? '—'}</td>
                  <td className={`${td} font-mono font-bold text-success`}>{bt.passcode}</td>
                  <td className={`${td} text-right`}>
                    <div className="flex items-center justify-end gap-2 whitespace-nowrap">
                      {d.can_review && bt.class_id && (
                        <Button variant="secondary" className="h-8 px-3" onClick={() => setStaging(bt)}>
                          Gắn chặng
                        </Button>
                      )}
                      {!bt.is_distributed ? (
                        d.can_approve ? (
                          <Button variant="secondary" className="h-8 px-3" disabled={approve.pending} onClick={() => approve.run(bt.id)}>
                            Duyệt &amp; phân phối
                          </Button>
                        ) : (
                          <Badge tone="warning">Chờ duyệt đề</Badge>
                        )
                      ) : (
                        <Badge tone="success">Đã phân phối</Badge>
                      )}
                      <Link href={`/syllabus/big-tests/results/${bt.id}`} className="text-sm font-medium text-primary hover:underline">
                        Bảng điểm
                      </Link>
                    </div>
                  </td>
                </tr>
              ))}
              {d && !d.big_tests.length && <EmptyRow colSpan={7} icon="event_busy" title="Chưa có kỳ thi Big Test nào" />}
            </tbody>
          </table>
        </TableCard>
      </div>

      {creating && d && (
        <CreateDialog
          page={d}
          onClose={() => setCreating(false)}
          onDone={(m) => {
            setCreating(false)
            done.run(m)
          }}
        />
      )}
      {staging && (
        <StageDialog
          bt={staging}
          onClose={() => setStaging(null)}
          onDone={(m) => {
            setStaging(null)
            done.run(m)
          }}
        />
      )}
      {d?.selected_order && (
        <OrderDialog
          o={d.selected_order}
          page={d}
          onDone={(m) => {
            done.run(m)
            setParam('order', null)
          }}
        />
      )}
    </>
  )
}

type DialogProps = { onClose: () => void; onDone: (m: string) => void }

function CreateDialog({ page, onClose, onDone }: DialogProps & { page: Page }) {
  const [f, setF] = useState({
    title: '',
    class_id: '',
    test_type: 'midterm',
    scheduled_at: page.default_scheduled_at,
    room: '',
  })
  const save = useAction(
    () => syllabusApi.createBigTest(f),
    (m) => onDone(m.message),
  )
  return (
    <Dialog title="Tạo đợt thi Big Test (bản nháp)" onClose={onClose}>
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          save.run()
        }}
      >
        <Field
          label="Tên đợt thi"
          required
          placeholder="Final Big Test #09 (Cuối khóa)"
          value={f.title}
          onChange={(e) => setF({ ...f, title: e.target.value })}
        />
        <Select label="Lớp thi" required value={f.class_id} onChange={(e) => setF({ ...f, class_id: e.target.value })}>
          <option value="">-- Chọn lớp --</option>
          {page.classes.map((c) => (
            <option key={c.value} value={c.value}>
              {c.label}
            </option>
          ))}
        </Select>
        <Select label="Loại kỳ thi" value={f.test_type} onChange={(e) => setF({ ...f, test_type: e.target.value })}>
          <option value="midterm">Giữa kỳ (Mid-term)</option>
          <option value="final">Cuối khóa (Final)</option>
        </Select>
        <Field
          label="Thời gian thi"
          type="datetime-local"
          required
          value={f.scheduled_at}
          onChange={(e) => setF({ ...f, scheduled_at: e.target.value })}
        />
        <Field label="Phòng thi" required placeholder="VD: Phòng Lab 201" value={f.room} onChange={(e) => setF({ ...f, room: e.target.value })} />
        <p className="text-xs text-on-surface-variant">
          Đợt thi tự gắn với <strong>chặng đang mở</strong> của lớp (Big Test cuối chặng). Khi kết quả của cả lớp được duyệt và gửi phụ huynh, chặng
          đóng và chặng kế tiếp tự mở.
        </p>
        <ErrorText error={save.error} />
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.pending}>
            Lưu bản nháp
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function StageDialog({ bt, onClose, onDone }: DialogProps & { bt: BigTestRow }) {
  const [stage, setStage] = useState(bt.stage_id)
  const save = useAction(
    () => syllabusApi.assignStage(bt.id, stage),
    (m) => onDone(m.message),
  )
  return (
    <Dialog title="Gắn chặng cho đợt Big Test" onClose={onClose}>
      <div className="space-y-3">
        <p className="text-sm text-on-surface-variant">
          Đợt thi:{' '}
          <strong>
            {bt.code} · {bt.class_name}
          </strong>
          . Big Test cuối chặng: khi kết quả được duyệt và gửi đủ phụ huynh, chặng đang mở tương ứng của lớp sẽ đóng và chặng kế tiếp tự mở.
        </p>
        <Select label="Chặng" value={stage} onChange={(e) => setStage(e.target.value)}>
          <option value="">— Không gắn chặng —</option>
          {bt.stage_options?.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
        <ErrorText error={save.error} />
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button disabled={save.pending} onClick={() => save.run()}>
            Lưu
          </Button>
        </div>
      </div>
    </Dialog>
  )
}

function OrderDialog({ o, page, onDone }: { o: OrderDetail; page: Page; onDone: (m: string) => void }) {
  const [rejecting, setRejecting] = useState(false)
  const [reason, setReason] = useState('')
  const [mode, setMode] = useState<'create' | 'link'>('create')
  const [f, setF] = useState({
    test_link: '',
    speaking_link: '',
    scheduled_at: o.default_scheduled_at,
    room: o.default_room ?? '',
    big_test_id: '',
  })
  const act = useAction(
    () =>
      rejecting
        ? syllabusApi.rejectOrder(o.id, reason)
        : syllabusApi.approveOrder(o.id, {
            test_link: f.test_link,
            speaking_link: f.speaking_link,
            big_test_id: o.is_big && mode === 'link' ? f.big_test_id : '',
            scheduled_at: o.is_big && mode === 'create' ? f.scheduled_at : '',
            room: o.is_big && mode === 'create' ? f.room : '',
          }),
    (m) => onDone(m.message),
  )
  return (
    <Dialog title={`Order đề ${o.code}`} onClose={() => setParam('order', null)} wide>
      <div className="space-y-4 text-sm">
        <div className="space-y-2">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <span className="rounded bg-surface-container-high px-2 py-0.5 text-xs text-on-surface-variant">CLASS ID: {o.class_code || '—'}</span>
            <StatusBadge color={o.status_color}>{o.status_label}</StatusBadge>
          </div>
          <h3 className="text-lg font-semibold">{o.class_label}</h3>
          <p className="text-on-surface-variant">{o.summary}</p>
          <dl className="grid grid-cols-1 gap-3 rounded-lg border border-outline-variant bg-surface-container-low p-3 sm:grid-cols-3">
            <Info label="Ngày thi dự kiến">{o.exam_date || '—'}</Info>
            <Info label="Giáo viên">{o.teacher ?? '—'}</Info>
            <Info label="Hạn xử lý">
              <span className={o.overdue ? 'font-semibold text-error' : ''}>{o.due_date || '—'}</span>
            </Info>
          </dl>
        </div>
        <div>
          <p className="mb-1 text-xs font-medium text-on-surface-variant uppercase">Yêu cầu từ Giáo viên</p>
          <p className="rounded-lg border-l-4 border-primary bg-surface-container-low p-3 whitespace-pre-line italic">
            {o.note ? `"${o.note}"` : 'Không có ghi chú.'}
          </p>
        </div>
        {o.status === 'approved' && (
          <Alert tone="success">
            <strong>
              Đã duyệt bởi {o.reviewer} lúc {o.reviewed_at}
            </strong>
            <span className="mt-1 flex flex-wrap gap-3">
              {page.can_review && o.test_link && (
                <a href={o.test_link} target="_blank" rel="noopener noreferrer" className="font-semibold text-primary underline">
                  Link đề
                </a>
              )}
              {o.speaking_link ? (
                <a href={o.speaking_link} target="_blank" rel="noopener noreferrer" className="font-semibold text-primary underline">
                  Phần Speaking
                </a>
              ) : (
                !page.can_review && <span>Học thuật chưa gửi link phần Speaking.</span>
              )}
            </span>
            {o.big_test && (
              <span className="mt-1 block">
                Đợt thi: <strong>{o.big_test.code}</strong> · {o.big_test.scheduled_at} · {o.big_test.room}
              </span>
            )}
          </Alert>
        )}
        {o.status === 'rejected' && (
          <Alert>
            <strong>
              Đã từ chối bởi {o.reviewer} lúc {o.reviewed_at}
            </strong>
            <br />
            Lý do: {o.rejection_reason}
          </Alert>
        )}
        {o.reviewing && (
          <div className="space-y-3">
            <p className="text-xs font-medium text-on-surface-variant uppercase">Phân phối đề</p>
            {rejecting ? (
              <TextArea label="Lý do từ chối" required rows={3} autoFocus value={reason} onChange={(e) => setReason(e.target.value)} />
            ) : (
              <>
                <Field
                  label="Link đề Big Test (Folder lớp)"
                  type="url"
                  required
                  placeholder="Dán link Google Drive hoặc OneDrive tại đây..."
                  value={f.test_link}
                  onChange={(e) =>
                    setF({
                      ...f,
                      test_link: e.target.value,
                    })
                  }
                />
                <Field
                  label="Link phần Speaking (GV xem sau khi phân phối)"
                  type="url"
                  placeholder="Link riêng phần Speaking (tùy chọn)..."
                  value={f.speaking_link}
                  onChange={(e) =>
                    setF({
                      ...f,
                      speaking_link: e.target.value,
                    })
                  }
                />
                {o.is_big && (
                  <div className="space-y-3 rounded-lg border border-outline-variant p-3">
                    <p className="font-semibold">Đợt Big Test</p>
                    <div className="flex flex-wrap gap-4">
                      <label className="flex items-center gap-1">
                        <input type="radio" checked={mode === 'create'} onChange={() => setMode('create')} /> Tạo đợt thi mới
                      </label>
                      {o.class_tests.length > 0 && (
                        <label className="flex items-center gap-1">
                          <input type="radio" checked={mode === 'link'} onChange={() => setMode('link')} /> Gắn đợt thi có sẵn
                        </label>
                      )}
                    </div>
                    {mode === 'create' ? (
                      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Field
                          label="Ngày giờ thi"
                          type="datetime-local"
                          value={f.scheduled_at}
                          onChange={(e) =>
                            setF({
                              ...f,
                              scheduled_at: e.target.value,
                            })
                          }
                        />
                        <Field
                          label="Phòng thi"
                          placeholder="VD: Phòng Lab 201"
                          value={f.room}
                          onChange={(e) =>
                            setF({
                              ...f,
                              room: e.target.value,
                            })
                          }
                        />
                        <p className="text-xs text-on-surface-variant sm:col-span-2">
                          Hệ thống tạo đợt Big Test gắn <strong>chặng đang mở</strong> của lớp và phân phối luôn để giáo viên nhập kết quả.
                        </p>
                      </div>
                    ) : (
                      <Select
                        label="Gắn vào đợt thi"
                        value={f.big_test_id}
                        onChange={(e) =>
                          setF({
                            ...f,
                            big_test_id: e.target.value,
                          })
                        }
                      >
                        <option value="">-- Chọn đợt thi --</option>
                        {o.class_tests.map((t) => (
                          <option key={t.value} value={t.value}>
                            {t.label}
                          </option>
                        ))}
                      </Select>
                    )}
                  </div>
                )}
              </>
            )}
            {(o.overdue || o.sla_warning) && (
              <Alert>
                <strong>{o.overdue ? 'Cảnh báo: Phân phối trễ hạn SLA' : 'Cảnh báo: Sắp hết hạn SLA'}</strong>
                <br />
                Hệ thống ghi nhận cần hoàn thành phân phối trước {page.lead_days} ngày so với lịch thi (Hạn chót: {o.due_date}). Vui lòng ưu tiên xử
                lý ngay.
              </Alert>
            )}
            <ErrorText error={act.error} />
            <div className="flex flex-wrap items-center justify-between gap-2">
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
                    Từ chối yêu cầu
                  </Button>
                  <span className="flex gap-2">
                    <Button variant="secondary" disabled={!f.test_link} onClick={() => window.open(f.test_link, '_blank', 'noopener')}>
                      Xem trước tệp
                    </Button>
                    <Button disabled={act.pending} onClick={() => act.run()}>
                      Phê duyệt &amp; Phân phối
                    </Button>
                  </span>
                </>
              )}
            </div>
          </div>
        )}
      </div>
    </Dialog>
  )
}
