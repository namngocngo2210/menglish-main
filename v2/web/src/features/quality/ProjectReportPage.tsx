// Báo cáo dự án học thuật (AcademicProjects/Report.vue — Báo cáo → Đào tạo & Chất lượng lớp): số liệu tháng, tiến độ từng
// dự án, theo thành viên (mốc đúng hạn / trễ, số lần cập nhật, biên bản), khó khăn và biên bản trễ deadline trong tháng.
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge } from '../../components/ui'
import { navigate } from '../../router'
import { EmptyState, PageHeader, StatCard } from '../platform/parts'
import { projectsApi } from './api'
import { FilterSelect, Panel, ProgressBar, Table } from './components'
import { badgeTone, formatDate, money, shortDateTime } from './logic'

function ProjectLink({ id, children }: { id: string; children: ReactNode }) {
  return (
    <a
      href={`/academic-projects/${id}`}
      className="font-semibold hover:text-primary"
      onClick={(e) => {
        e.preventDefault()
        navigate(`/academic-projects/${id}`)
      }}
    >
      {children}
    </a>
  )
}

export function ProjectReportPage(_: { me: Me }) {
  const [filter, setFilter] = useState({ month: '', status: '' })
  const page = useQuery({
    queryKey: ['academic-projects', 'report', filter],
    queryFn: () => projectsApi.report(filter.month, filter.status),
    placeholderData: keepPreviousData,
  })
  const d = page.data
  const num = (n: number, tone = '') => <td className={`text-right font-mono ${n ? tone : ''}`}>{n}</td>

  return (
    <div className="space-y-6">
      <PageHeader title="Báo cáo dự án học thuật" description="Tiến độ soạn sách / chương trình, khối lượng hoàn thành, khó khăn và trễ deadline." />
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {d && (
        <>
          <div className="grid gap-3 sm:grid-cols-[220px_280px]">
            <FilterSelect label="Tháng" options={d.months} value={d.month} onChange={(e) => setFilter({ ...filter, month: e.target.value })} />
            <FilterSelect
              label="Trạng thái"
              all="Dự án đang mở + đóng trong tháng"
              options={d.statuses}
              value={d.status}
              onChange={(e) => setFilter({ ...filter, status: e.target.value })}
            />
          </div>
          <div className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
            <StatCard label="Dự án đang thực hiện" value={d.stats.active} icon="auto_stories" tone="primary" />
            <StatCard label="Mốc đến hạn trong tháng" value={d.stats.due_in_month} icon="event" />
            <StatCard label="Mốc xong trong tháng" value={d.stats.done_in_month} icon="task_alt" tone="success" />
            <StatCard label="Mốc đang trễ hạn" value={d.stats.overdue} icon="alarm" tone={d.stats.overdue ? 'error' : 'neutral'} />
            <StatCard label="Khó khăn chưa phản hồi" value={d.stats.open_issues} icon="support" tone={d.stats.open_issues ? 'warning' : 'neutral'} />
            <StatCard label="Biên bản trễ deadline" value={d.stats.penalties_in_month} icon="gavel" tone={d.stats.penalties_in_month ? 'error' : 'neutral'} />
          </div>
          {d.penalties_pending && <Alert tone="info">Số biên bản trễ deadline sẽ có khi module Lương & Phạt được gộp.</Alert>}

          <Panel title="Tiến độ từng dự án">
            <Table
              minWidth={960}
              head={
                <tr>
                  <th>Dự án</th>
                  <th>Phụ trách</th>
                  <th>Deadline</th>
                  <th>Tiến độ</th>
                  <th className="text-right">Mốc trễ</th>
                  <th>Cập nhật gần nhất</th>
                  <th className="text-right">Khó khăn mở</th>
                  <th>Trạng thái</th>
                </tr>
              }
            >
              {d.projects.map((p) => (
                <tr key={p.id}>
                  <td>
                    <ProjectLink id={p.id}>{p.name}</ProjectLink>
                    <span className="block font-mono text-xs text-on-surface-variant">
                      {p.code} · {p.type_label}
                    </span>
                  </td>
                  <td>{p.owner ?? '—'}</td>
                  <td className="font-mono whitespace-nowrap">{formatDate(p.deadline)}</td>
                  <td>
                    <ProgressBar value={p.progress} overdue={p.milestones_overdue > 0} label={`${p.progress}% · ${p.milestones_done}/${p.milestones_total} mốc`} />
                  </td>
                  {num(p.milestones_overdue, 'font-semibold text-error')}
                  <td>
                    {p.last_update ? (
                      <>
                        <span className="block font-mono text-xs">{shortDateTime(p.last_update.at)}</span>
                        <span className="text-xs text-on-surface-variant">{p.last_update.user}</span>
                      </>
                    ) : (
                      <span className="text-on-surface-subtle">Chưa có</span>
                    )}
                  </td>
                  {num(p.open_issues, 'font-semibold text-warning')}
                  <td>
                    <Badge tone={badgeTone(p.status_color)}>{p.status_label}</Badge>
                  </td>
                </tr>
              ))}
              {d.projects.length === 0 && (
                <tr>
                  <td colSpan={8}>
                    <EmptyState icon="auto_stories" title="Không có dự án nào" />
                  </td>
                </tr>
              )}
            </Table>
          </Panel>

          <div className="grid gap-6 2xl:grid-cols-2">
            <Panel title="Theo thành viên">
              <Table
                minWidth={620}
                head={
                  <tr>
                    <th>Thành viên</th>
                    <th className="text-right">Mốc được giao</th>
                    <th className="text-right">Xong đúng hạn</th>
                    <th className="text-right">Xong trễ</th>
                    <th className="text-right">Đang trễ</th>
                    <th className="text-right">Cập nhật / tháng</th>
                    <th className="text-right">Biên bản</th>
                  </tr>
                }
              >
                {d.members.map((m) => (
                  <tr key={m.id ?? m.name}>
                    <td className="font-semibold whitespace-nowrap">{m.name}</td>
                    {num(m.assigned)}
                    {num(m.done_on_time, 'text-success')}
                    {num(m.done_late)}
                    {num(m.overdue, 'font-semibold text-error')}
                    {num(m.updates_in_month)}
                    {num(m.penalties)}
                  </tr>
                ))}
                {d.members.length === 0 && (
                  <tr>
                    <td colSpan={7}>
                      <EmptyState icon="group" title="Chưa có mốc nào được giao" />
                    </td>
                  </tr>
                )}
              </Table>
            </Panel>
            <Panel title="Khó khăn thành viên báo">
              <ul className="divide-y divide-surface-container-highest">
                {d.issues.map((i) => (
                  <li key={i.id} className="px-4 py-3">
                    <div className="flex flex-wrap items-center gap-2">
                      <ProjectLink id={i.project_id}>{i.project ?? '—'}</ProjectLink>
                      <span className="text-xs text-on-surface-variant">
                        {i.user} · {shortDateTime(i.created_at)}
                      </span>
                      <Badge tone={i.responded ? 'success' : 'warning'}>{i.responded ? 'Đã phản hồi' : 'Chưa phản hồi'}</Badge>
                    </div>
                    <p className="mt-1 line-clamp-3 text-sm whitespace-pre-line">{i.difficulties}</p>
                  </li>
                ))}
                {d.issues.length === 0 && (
                  <li>
                    <EmptyState icon="sentiment_satisfied" title="Chưa có khó khăn nào được báo" />
                  </li>
                )}
              </ul>
            </Panel>
          </div>

          {d.penalties.length > 0 && (
            <Panel title="Biên bản trễ deadline trong tháng">
              <Table
                minWidth={620}
                head={
                  <tr>
                    <th>Biên bản</th>
                    <th>Người nhận</th>
                    <th>Mốc</th>
                    <th>Trạng thái</th>
                    <th className="text-right">Mức phạt</th>
                  </tr>
                }
              >
                {d.penalties.map((p) => (
                  <tr key={p.id}>
                    <td>
                      <a href={p.url} className="font-mono font-semibold text-primary hover:underline">
                        {p.code}
                      </a>
                    </td>
                    <td>{p.user}</td>
                    <td>{p.milestone}</td>
                    <td>{p.status_label}</td>
                    <td className="text-right font-mono">{p.amount ? money(p.amount) : '—'}</td>
                  </tr>
                ))}
              </Table>
            </Panel>
          )}
        </>
      )}
    </div>
  )
}
