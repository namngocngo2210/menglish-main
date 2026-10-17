// Nhắc lịch Big Test (SyllabusController::bigTestSchedules): chặng sắp thi chưa duyệt đề; đợt thi đã tạo (nhắc lịch học viên).
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Icon } from '../../components/ui'
import { syllabusApi } from './api'
import {
  confirmThen,
  EmptyRow,
  ErrorText,
  Link,
  LinkButton,
  NoticeBar,
  PageHeader,
  Pagination,
  StatCard,
  TableCard,
  tableCls,
  tbodyCls,
  td,
  th,
  useAction,
} from './components'
import { PaperWarningText } from './DistributionPage'
import { daysLeftClass } from './logic'

export function SchedulesPage() {
  const [page, setPage] = useState(1)
  const list = useQuery({
    queryKey: ['syllabus', 'schedules', page],
    queryFn: () => syllabusApi.schedules(page),
  })
  const remind = useAction(syllabusApi.remind)
  const d = list.data
  return (
    <>
      <PageHeader
        title="Nhắc lịch Big Test"
        back="/syllabus/documents"
        description="Danh sách các chặng học sắp đến hạn thi Big Test (trong vòng 7 ngày) chưa được duyệt đề thi."
        actions={
          <LinkButton href="/syllabus/teaching-stages" icon="event_note">
            Lịch dự kiến theo lớp
          </LinkButton>
        }
      />
      <NoticeBar notice={remind.notice} onClose={() => remind.setNotice(null)} />
      <ErrorText error={remind.error} />
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {d && (
        <div className="space-y-6">
          <div className="grid max-w-3xl grid-cols-1 gap-4 sm:grid-cols-2">
            <StatCard label="Tổng số chặng" value={d.upcoming.length} tone="primary" />
            <StatCard label="Khẩn cấp (1-2 ngày)" value={d.urgent} tone="error" />
          </div>
          <TableCard
            header={
              <div>
                <h2 className="flex items-center gap-2 font-semibold">
                  <Icon name="notification_important" className="text-primary" />
                  Chặng sắp thi, chưa duyệt đề
                </h2>
                <p className="text-xs text-on-surface-variant">
                  Giáo viên lớp được tự động nhắc trước 7 ngày (đợt thi đã tạo); ngày thi lấy theo đợt Big Test của chặng, hoặc ngày dự kiến GV đặt /
                  ngày thi trong order.
                </p>
              </div>
            }
          >
            <table className={`${tableCls} min-w-[720px]`}>
              <thead>
                <tr>
                  <th className={th}>Mã chặng</th>
                  <th className={th}>Lớp / Chặng</th>
                  <th className={th}>Ngày thi dự kiến</th>
                  <th className={th}>Trạng thái đề</th>
                  <th className={`${th} text-right`}>Số ngày còn lại</th>
                </tr>
              </thead>
              <tbody className={tbodyCls}>
                {d.upcoming.map((r) => (
                  <tr key={r.id}>
                    <td className={`${td} font-mono font-semibold`}>{r.code}</td>
                    <td className={td}>
                      <p className="font-medium">{r.class_name}</p>
                      <p className="text-xs text-on-surface-variant">{r.stage_label}</p>
                    </td>
                    <td className={`${td} font-mono`}>{r.date}</td>
                    <td className={td}>
                      <Badge tone="error">{r.exam === 'pending' ? 'Chưa duyệt đề' : 'Chưa order đề'}</Badge>
                    </td>
                    <td className={`${td} text-right`}>
                      <span className={`text-lg font-semibold ${daysLeftClass(r.days_left)}`}>{r.days_left}</span>
                      <span className="text-xs text-on-surface-variant"> ngày</span>
                    </td>
                  </tr>
                ))}
                {!d.upcoming.length && (
                  <EmptyRow colSpan={5} icon="task_alt" title="Tất cả đều ổn!" hint="Không có chặng học nào sắp tới hạn chưa duyệt đề." />
                )}
              </tbody>
            </table>
          </TableCard>
          <TableCard
            header={
              <h2 className="flex items-center gap-2 font-semibold">
                <Icon name="event_note" className="text-primary" />
                Đợt thi Big Test đã tạo
              </h2>
            }
            footer={<Pagination page={d.page.page} pageSize={d.page.page_size} total={d.page.total} unit="đợt thi" onPage={setPage} />}
          >
            <table className={`${tableCls} min-w-[980px]`}>
              <thead>
                <tr>
                  <th className={th}>Mã đợt thi</th>
                  <th className={th}>Lớp thi</th>
                  <th className={th}>Tên bài thi</th>
                  <th className={th}>Ngày &amp; Giờ thi</th>
                  <th className={th}>Phòng thi &amp; Cơ sở</th>
                  <th className={th}>Giám thị coi thi</th>
                  <th className={th}>Mật mã thi</th>
                  <th className={`${th} text-right`}>Thao tác</th>
                </tr>
              </thead>
              <tbody className={tbodyCls}>
                {d.big_tests.map((bt) => (
                  <tr key={bt.id}>
                    <td className={`${td} font-mono font-semibold`}>{bt.code}</td>
                    <td className={`${td} font-semibold`}>{bt.class_name}</td>
                    <td className={`${td} text-primary`}>
                      {bt.title}
                      <PaperWarningText w={bt.paper_warning} />
                    </td>
                    <td className={`${td} font-mono text-on-surface-variant`}>{bt.scheduled_at || '—'}</td>
                    <td className={td}>{bt.place}</td>
                    <td className={td}>{bt.proctor ?? '—'}</td>
                    <td className={`${td} font-mono font-bold text-success`}>{bt.passcode}</td>
                    <td className={`${td} text-right`}>
                      <div className="flex items-center justify-end gap-2 whitespace-nowrap">
                        {d.can_manage && (
                          <Button
                            variant="secondary"
                            className="h-8 px-3"
                            title="Gửi thông báo nhắc lịch vào Cổng PH/HS"
                            disabled={remind.pending}
                            onClick={() =>
                              confirmThen(`Gửi nhắc lịch ${bt.title} tới toàn bộ học viên của lớp ${bt.class_name ?? ''}?`, () => remind.run(bt.id))
                            }
                          >
                            Nhắc lịch
                          </Button>
                        )}
                        <Link href={`/syllabus/big-tests/results/${bt.id}`} className="text-sm font-medium text-primary hover:underline">
                          Xem điểm
                        </Link>
                      </div>
                    </td>
                  </tr>
                ))}
                {!d.big_tests.length && <EmptyRow colSpan={8} icon="event_busy" title="Chưa có lịch thi Big Test nào" />}
              </tbody>
            </table>
          </TableCard>
        </div>
      )}
    </>
  )
}
