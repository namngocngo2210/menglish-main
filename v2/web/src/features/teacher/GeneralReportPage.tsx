// Báo cáo giảng dạy tháng của chính giáo viên (TeacherPortalController::generalReport): giờ dạy / phiên chấm công,
// lượt điểm danh đã ghi, điểm mini test đã nhập, lớp phụ trách, chi tiết chấm công.
import { useQuery } from '@tanstack/react-query'
import { Alert, Badge, Card, Field } from '../../components/ui'
import { navigate, useQueryParam } from '../../router'
import { teacherApi } from './api'
import { Empty, Header, LoadError, Loading, td, th } from './components'

const TS_STATUS: Record<string, { label: string; tone: 'success' | 'warning' | 'error' | 'neutral' }> = {
  valid: { label: 'Hợp lệ', tone: 'success' },
  pending_review: { label: 'Chờ duyệt', tone: 'warning' },
  invalid: { label: 'Không hợp lệ', tone: 'error' },
  rejected: { label: 'Bị từ chối', tone: 'error' },
}

const TYPE_LABEL: Record<string, string> = { regular: 'Chính khóa', makeup: 'Học bù', support: 'Phụ đạo' }

export function GeneralReportPage() {
  const period = useQueryParam('period')
  const report = useQuery({ queryKey: ['teacher-report', period], queryFn: () => teacherApi.generalReport(period || undefined) })
  if (report.isPending) return <Loading />
  if (report.isError) return <LoadError title="Không mở được báo cáo giảng dạy" error={report.error} />
  const r = report.data
  const stats = [
    { label: 'Tổng giờ dạy', value: r.stats.hours, hint: `${r.stats.sessions} phiên` },
    { label: 'Giờ đã đối soát', value: r.stats.valid_hours, hint: `${r.stats.valid_sessions} phiên hợp lệ` },
    { label: 'Lượt điểm danh đã ghi', value: String(r.stats.attendance_marked) },
    { label: 'Điểm mini test đã nhập', value: String(r.stats.scores_entered) },
  ]
  return (
    <>
      <Header
        title={`Báo cáo giảng dạy tháng ${String(r.month).padStart(2, '0')}/${r.year}`}
        actions={
          <div className="w-44">
            <Field label="Tháng" type="month" value={r.period} onChange={(e) => e.target.value && navigate(`/teacher/general-report?period=${e.target.value}`)} />
          </div>
        }
      />
      <div className="space-y-4">
        {r.work_pending && <Alert tone="info">Giờ dạy / chấm công: {r.work_pending}.</Alert>}
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {stats.map((s) => (
            <div key={s.label} className="rounded-lg border border-surface-container-highest bg-surface px-4 py-3">
              <p className="text-xs text-on-surface-variant">{s.label}</p>
              <p className="mt-1 text-lg font-semibold">{s.value}</p>
              {s.hint && !r.work_pending && <p className="text-xs text-on-surface-variant">{s.hint}</p>}
            </div>
          ))}
        </div>
        <Card title={`Lớp phụ trách (${r.my_classes.length})`}>
          {r.my_classes.length === 0 ? (
            <Empty>Bạn chưa là giáo viên chính / GVNN của lớp nào.</Empty>
          ) : (
            <ul className="divide-y divide-surface-container-highest text-sm">
              {r.my_classes.map((c) => (
                <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                  <div>
                    <p className="font-medium">{c.name}</p>
                    <p className="text-xs text-on-surface-variant">
                      {c.code}
                      {c.course && ` · ${c.course}`}
                    </p>
                  </div>
                  <div className="flex gap-1">
                    {c.is_main && <Badge tone="info">GV chính</Badge>}
                    {c.is_foreign && <Badge tone="info">GVNN</Badge>}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
        <Card title="Chi tiết chấm công">
          {r.timesheets.length === 0 ? (
            <Empty>{r.work_pending ? 'Chưa có dữ liệu chấm công trên hệ thống mới.' : 'Không có phiên chấm công trong tháng.'}</Empty>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[520px] text-left text-sm">
                <thead className="text-xs text-on-surface-variant">
                  <tr>
                    <th className={th}>Ngày</th>
                    <th className={th}>Lớp</th>
                    <th className={th}>Loại</th>
                    <th className={th}>Giờ</th>
                    <th className={th}>Trạng thái</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-surface-container-highest">
                  {r.timesheets.map((t) => {
                    const st = TS_STATUS[t.status] ?? { label: t.status, tone: 'neutral' as const }
                    return (
                      <tr key={t.id}>
                        <td className={td}>{t.date}</td>
                        <td className={td}>{t.class_name}</td>
                        <td className={td}>{TYPE_LABEL[t.type] ?? t.type}</td>
                        <td className={td}>{t.hours}</td>
                        <td className={td}>
                          <Badge tone={st.tone}>{st.label}</Badge>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      </div>
    </>
  )
}
