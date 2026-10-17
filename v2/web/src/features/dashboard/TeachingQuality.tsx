// Chất lượng giảng dạy theo tháng (TeachingQuality.vue): chọn tháng; giáo viên / trợ giảng thấy số liệu của mình + biểu đồ
// theo lớp; người theo dõi giáo viên thấy biểu đồ + bảng theo giáo viên; Học thuật / Admin / Quản lý thêm báo cáo tháng
// của giáo viên và order học thuật. Chấm công (ngày công, đi muộn, phép, vi phạm) chờ phân hệ Chấm công & KPI.
import { useState } from 'react'
import { Badge, Button, Dialog, Icon } from '../../components/ui'
import { navigate } from '../../router'
import type { Teaching, TeacherReport, WorkStats } from './api'
import { BarList, Empty, Panel, PanelLink, Pending, StatCard } from './components'
import { classBars, formatDate, formatPercent, formatScore, reportSummary, supportCount, teamBars } from './logic'

export function TeachingQuality({ teaching, onMonth }: { teaching: Teaching; onMonth: (m: string) => void }) {
  const [opened, setOpened] = useState<TeacherReport | null>(null)
  const { mine, team } = teaching
  return (
    <section className="space-y-4" data-teaching-quality>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold text-on-surface">Chất lượng giảng dạy</h2>
          <p className="text-xs text-on-surface-variant">Tổng hợp từ điểm danh, bài về nhà, điểm mini test và chấm công trong tháng</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <label className="flex items-center gap-2 text-sm text-on-surface-variant">
            Tháng
            <select
              aria-label="Chọn tháng"
              value={teaching.month}
              onChange={(e) => onMonth(e.target.value)}
              className="h-9 rounded-lg border border-outline-variant bg-surface px-2 text-sm text-on-surface outline-none focus:border-primary focus:ring-2 focus:ring-primary/25"
            >
              {teaching.months.map((m) => (
                <option key={m.value} value={m.value}>
                  {m.label}
                </option>
              ))}
            </select>
          </label>
          {teaching.report_url && (
            <Button variant="secondary" onClick={() => navigate(teaching.report_url!)}>
              <Icon name="edit_note" className="mr-1.5 text-[18px]" /> Viết báo cáo tháng
            </Button>
          )}
        </div>
      </div>

      {teaching.pending.length > 0 && (
        <div className="space-y-1">
          {teaching.pending.map((p) => (
            <Pending key={p}>{p}</Pending>
          ))}
        </div>
      )}

      {mine && (
        <>
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4" data-teaching-mine>
            <WorkCards work={mine.work} />
            <StatCard label="Lớp đang giữ" value={mine.totals.classes} icon="co_present" />
            <StatCard label="Học sinh đang dạy" value={mine.totals.students} icon="groups" />
            <StatCard label="HS mới / nghỉ trong tháng" value={`${mine.totals.new} / ${mine.totals.dropped}`} icon="swap_vert" />
            <StatCard label="Điểm TB tháng" value={formatScore(mine.totals.score)} icon="grade" tone="secondary" hint="Mini test, thang 10" />
          </div>
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <BarList title="Chuyên cần theo lớp" rows={classBars(mine.classes, 'attendance')} max={100} unit="%" />
            <BarList title="Làm bài về nhà theo lớp" rows={classBars(mine.classes, 'homework')} max={100} unit="%" />
            <BarList title="Điểm trung bình theo lớp" rows={classBars(mine.classes, 'score')} max={10} />
          </div>
        </>
      )}

      {team && (
        <>
          {mine && <h3 className="pt-2 text-sm font-semibold text-on-surface">Theo giáo viên</h3>}
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <BarList title="Chuyên cần" rows={teamBars(team.rows, 'attendance')} max={100} unit="%" />
            <BarList title="Làm bài về nhà" rows={teamBars(team.rows, 'homework')} max={100} unit="%" />
            <BarList title="Điểm trung bình" rows={teamBars(team.rows, 'score')} max={10} />
          </div>
          <div className="overflow-x-auto rounded-xl border border-surface-container-highest bg-surface shadow-sm" data-teaching-team>
            {team.rows.length ? (
              <table className="w-full text-sm">
                <thead className="border-b border-surface-container-highest text-xs text-on-surface-variant">
                  <tr>
                    <th className="px-4 py-2 text-left font-medium">Giáo viên</th>
                    {['Lớp', 'HS', 'Mới / nghỉ', 'Chuyên cần', 'BTVN', 'Điểm TB', 'Ngày công', 'Đi muộn', 'Phép', 'Vi phạm'].map((h) => (
                      <th key={h} className="px-3 py-2 text-right font-medium whitespace-nowrap">
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {team.rows.map((r) => (
                    <tr key={r.id} className="border-b border-surface-container-highest/60 last:border-0">
                      <td className="px-4 py-2 font-semibold">{r.name}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{r.classes}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{r.students}</td>
                      <td className="px-3 py-2 text-right tabular-nums">
                        {r.new} / {r.dropped}
                      </td>
                      <td className="px-3 py-2 text-right tabular-nums">{formatPercent(r.attendance)}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{formatPercent(r.homework)}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{formatScore(r.score)}</td>
                      <WorkCells work={r.work} />
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <Empty icon="co_present" title="Chưa có lớp đang chạy có giáo viên chính trong phạm vi của bạn" />
            )}
          </div>
        </>
      )}

      {teaching.teacher_reports && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
          <Panel
            title="Báo cáo tháng của giáo viên"
            className="lg:col-span-2"
            data-teacher-reports
            action={
              <span className="text-xs text-on-surface-variant">
                {teaching.teacher_reports.filter((r) => r.submitted).length}/{teaching.teacher_reports.length} đã nộp
              </span>
            }
          >
            {teaching.teacher_reports.map((r) => {
              const summary = reportSummary(r)
              const need = supportCount(r)
              return (
                <button
                  key={r.id}
                  type="button"
                  disabled={!r.submitted}
                  onClick={() => setOpened(r)}
                  className="flex w-full items-start justify-between gap-3 border-b border-surface-container-highest/60 px-4 py-2.5 text-left last:border-0 enabled:hover:bg-surface-container-low"
                >
                  <span className="min-w-0">
                    <span className="block text-sm font-semibold text-on-surface">{r.teacher}</span>
                    {summary ? (
                      <span className="block truncate text-xs text-on-surface-variant">{summary}</span>
                    ) : (
                      <span className="block text-xs text-on-surface-variant italic">Chưa nộp báo cáo tháng</span>
                    )}
                  </span>
                  {need > 0 && <Badge tone="warning">{need} lớp cần hỗ trợ</Badge>}
                </button>
              )
            })}
            {!teaching.teacher_reports.length && <Empty icon="summarize" title="Chưa có giáo viên trong phạm vi của bạn" />}
          </Panel>
          <Panel
            title="Order học thuật"
            data-academic-orders
            action={teaching.orders_url ? <PanelLink href={teaching.orders_url}>Xem tất cả</PanelLink> : undefined}
          >
            {(teaching.academic_orders ?? []).map((o) => (
              <div key={o.id} className="border-b border-surface-container-highest/60 px-4 py-2.5 last:border-0">
                <p className="truncate text-sm text-on-surface" title={o.title}>
                  {o.title}
                </p>
                <p className="text-xs text-on-surface-variant">
                  {o.teacher ?? '—'} · {o.class ?? 'Không gắn lớp'} · {o.status_label}
                </p>
              </div>
            ))}
            {!teaching.academic_orders?.length && <Empty icon="inventory" title="Không có order học thuật trong tháng" />}
          </Panel>
        </div>
      )}

      {opened && <TeacherReportDialog report={opened} onClose={() => setOpened(null)} />}
    </section>
  )
}

const WORK_PENDING = 'Chờ phân hệ Chấm công & KPI'

function WorkCards({ work }: { work: WorkStats | null }) {
  const pending = work ? null : WORK_PENDING
  return (
    <>
      <StatCard label="Ngày công" value={work?.work_days} icon="event_available" tone="primary" pending={pending} />
      <StatCard label="Buổi đi muộn" value={work?.late} icon="schedule" tone={work && work.late > 0 ? 'warning' : 'default'} pending={pending} />
      <StatCard label="Ngày nghỉ phép" value={work?.leave_days} icon="beach_access" pending={pending} />
      <StatCard label="Lỗi vi phạm" value={work?.violations} icon="gavel" tone={work && work.violations > 0 ? 'error' : 'default'} pending={pending} />
    </>
  )
}

function WorkCells({ work }: { work: WorkStats | null }) {
  const cell = 'px-3 py-2 text-right tabular-nums'
  if (!work) {
    return (
      <td colSpan={4} className={`${cell} text-xs text-on-surface-variant italic`} title={WORK_PENDING}>
        Chờ phân hệ
      </td>
    )
  }
  return (
    <>
      <td className={cell}>{work.work_days}</td>
      <td className={`${cell} ${work.late > 0 ? 'text-warning' : ''}`}>{work.late}</td>
      <td className={cell}>{work.leave_days}</td>
      <td className={`${cell} ${work.violations > 0 ? 'text-error' : ''}`}>{work.violations}</td>
    </>
  )
}

function TeacherReportDialog({ report, onClose }: { report: TeacherReport; onClose: () => void }) {
  const general: [string, string | null][] = [
    ['Tiến độ giảng dạy', report.progress],
    ['Khó khăn', report.difficulties],
    ['Đề xuất', report.proposals],
  ]
  const notes = (c: TeacherReport['classes'][number]): [string, string | null][] => [
    ['Vấn đề phát sinh', c.situation],
    ['Học sinh cần chú ý', c.attention],
    ['Giải pháp', c.solution],
    ['Cần hỗ trợ', c.support_note],
  ]
  return (
    <Dialog title={`Báo cáo tháng · ${report.teacher}`} onClose={onClose} wide>
      <div className="space-y-4 text-sm text-on-surface">
        {report.updated_at && <p className="text-xs text-on-surface-variant">Cập nhật {formatDate(report.updated_at, true)}</p>}
        <dl className="space-y-2">
          {general.map(([label, text]) => (
            <div key={label}>
              <dt className="text-xs font-semibold text-on-surface-variant">{label}</dt>
              <dd className="whitespace-pre-line">{text || '—'}</dd>
            </div>
          ))}
        </dl>
        {report.classes.map((c) => (
          <div key={c.class} className="rounded-lg border border-surface-container-highest p-3">
            <p className="flex items-center justify-between gap-2 font-semibold">
              {c.class}
              {c.need_support && <Badge tone="warning">Cần hỗ trợ</Badge>}
            </p>
            {notes(c)
              .filter(([, text]) => text)
              .map(([label, text]) => (
                <p key={label} className="mt-1 text-xs whitespace-pre-line">
                  <span className="font-semibold">{label}:</span> {text}
                </p>
              ))}
          </div>
        ))}
        <div className="flex justify-end">
          <Button variant="secondary" onClick={onClose}>
            Đóng
          </Button>
        </div>
      </div>
    </Dialog>
  )
}
