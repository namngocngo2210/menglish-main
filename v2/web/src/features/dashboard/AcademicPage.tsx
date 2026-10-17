// Báo cáo & sự vụ lớp (AcademicDashboardController): hai màn giám sát chung một chỗ — /academic/dashboards[/reports]
// (URL cũ /academic/reports)
// "Báo cáo đào tạo" (tab ?tab=daily|weekly|monthly) và /academic/dashboards/incidents "Nhật ký sự vụ" (lọc cơ sở, lớp,
// mức độ, trạng thái). Báo cáo trực lớp, đề xuất giãn tiến độ / Big Test và ticket hỗ trợ chờ phân hệ nguồn.
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { errorMessage } from '../../api'
import { Alert, Badge, Button, Dialog, Icon, Select } from '../../components/ui'
import { navigate, usePath, useQueryParam } from '../../router'
import { dashboardApi, type DailyRow, type IncidentFilter, type RecordRow, type ReportsTab } from './api'
import { Empty, Link, Pending, StatCard } from './components'
import { formatDate } from './logic'

export const ACADEMIC_BASE = '/academic/dashboards'
const REPORTS_PATH = `${ACADEMIC_BASE}/reports`
const INCIDENTS_PATH = `${ACADEMIC_BASE}/incidents`

export function AcademicPage() {
  // /academic/incidents: URL cũ academic.incidents của Laravel, giữ cho liên kết đã lưu.
  const incidents = usePath().endsWith('/incidents')
  return (
    <div className="mx-auto max-w-7xl">
      <div className="mb-4 flex flex-wrap items-end justify-between gap-4">
        <div className="flex items-center gap-3">
          <Icon name="monitoring" className="text-[28px] text-primary" />
          <div>
            <h1 className="text-xl font-semibold">Báo cáo & sự vụ</h1>
            <p className="mt-0.5 text-sm text-on-surface-variant">Báo cáo đào tạo ngày / tuần / tháng và sự vụ của các lớp, các cơ sở.</p>
          </div>
        </div>
      </div>
      <SectionTabs incidents={incidents} />
      {incidents ? <IncidentsView /> : <ReportsView />}
    </div>
  )
}

function TabLink({ href, active, icon, children }: { href: string; active: boolean; icon?: string; children: string }) {
  return (
    <Link
      href={href}
      className={`-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm whitespace-nowrap ${
        active ? 'border-primary font-semibold text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface'
      }`}
    >
      {icon && <Icon name={icon} className="text-[18px]" />}
      {children}
    </Link>
  )
}

function SectionTabs({ incidents }: { incidents: boolean }) {
  return (
    <nav aria-label="Báo cáo & sự vụ" className="mb-6 flex gap-1 overflow-x-auto border-b border-surface-container-highest">
      <TabLink href={REPORTS_PATH} active={!incidents} icon="assessment">
        Báo cáo đào tạo
      </TabLink>
      <TabLink href={INCIDENTS_PATH} active={incidents} icon="report">
        Nhật ký sự vụ
      </TabLink>
    </nav>
  )
}

const REPORT_TABS: { key: ReportsTab; label: string }[] = [
  { key: 'daily', label: '1. Báo cáo ngày Học vụ' },
  { key: 'weekly', label: '2. Báo cáo tuần Học thuật' },
  { key: 'monthly', label: '3. Báo cáo tháng Giáo viên' },
]

type Viewing = { title: string; user: string | null; content: string | null }

function ReportsView() {
  const tabParam = useQueryParam('tab')
  const tab: ReportsTab = tabParam === 'weekly' || tabParam === 'monthly' ? tabParam : 'daily'
  const q = useQuery({ queryKey: ['academic-dashboard', 'reports', tab], queryFn: () => dashboardApi.reports(tab), placeholderData: keepPreviousData })
  const [viewing, setViewing] = useState<Viewing | null>(null)
  if (q.error) return <Alert>{errorMessage(q.error)}</Alert>
  const d = q.data
  if (!d) return <p className="text-sm text-on-surface-variant">Đang tải…</p>
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Lớp học đang chạy" value={`${d.active_classes} / ${d.total_classes}`} tone="primary" icon="school" />
        <StatCard
          label="Tỷ lệ chuyên cần ngày"
          value={d.attendance_rate_today === null ? 'Chưa có dữ liệu' : `${d.attendance_rate_today.toLocaleString('vi-VN')}%`}
          tone={d.attendance_rate_today === null ? 'default' : 'success'}
          icon="how_to_reg"
        />
        <StatCard label="Báo cáo ngày hôm nay" value={d.total_daily_reports_today} tone="secondary" icon="assignment_turned_in" />
        <StatCard label="Đề xuất điều chỉnh tiến độ chờ duyệt" value={null} icon="pending_actions" pending={d.weekly_stats.pending} />
      </div>

      <div className="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface shadow-sm">
        <div className="flex flex-col justify-between gap-2 px-4 pt-2 sm:flex-row sm:items-center sm:px-5">
          <nav aria-label="Loại báo cáo" className="flex flex-1 gap-1 overflow-x-auto border-b border-surface-container-highest">
            {REPORT_TABS.map((t) => (
              <TabLink key={t.key} href={`${REPORTS_PATH}?tab=${t.key}`} active={tab === t.key}>
                {t.label}
              </TabLink>
            ))}
          </nav>
          <span className="text-xs font-medium text-on-surface-variant">Hôm nay: {d.today}</span>
        </div>

        {tab === 'daily' && (
          <div className="space-y-6 p-6">
            <Heading title="Báo cáo Vận hành Ngày của Khối Học vụ" sub="Vận hành lớp, tuyển sinh / học viên, truyền thông và task follow-up Học vụ nộp mỗi ngày" />
            <DailyTable rows={d.staff_daily_reports} onOpen={(r) => setViewing({ title: r.title || 'Báo cáo ngày', user: r.user, content: r.content })} />
            <Heading title="Báo cáo trực lớp theo buổi" sub="Sĩ số, nội dung bài học và học viên cần bổ trợ do giáo viên / trợ giảng nộp sau từng buổi" />
            <Pending>{d.class_reports_pending}</Pending>
          </div>
        )}
        {tab === 'weekly' && (
          <div className="space-y-6 p-6">
            <Heading title="Báo cáo Tiến độ Tuần của Khối Học thuật" sub="Kiểm soát tiến độ syllabus, kết quả dự giờ, kỳ thi Big Test định kỳ" />
            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
              <MiniBox title="Điều chỉnh tiến độ Syllabus">
                <Pending>{d.weekly_stats.pending}</Pending>
              </MiniBox>
              <MiniBox title="Khảo thí & Big Test">
                <Pending>{d.weekly_stats.pending}</Pending>
              </MiniBox>
              <MiniBox title="Lớp đang chạy">
                <div className="flex items-center justify-between text-xs">
                  <span>Đang học / Tổng số lớp:</span>
                  <span className="font-bold text-on-surface">
                    {d.active_classes} / {d.total_classes}
                  </span>
                </div>
              </MiniBox>
            </div>
            <RecordTable records={d.weekly_reports} empty="Chưa có báo cáo tuần nào của Học thuật." onOpen={setViewing} />
            <Heading title="Báo cáo tuần theo mục KPI của Học vụ" sub="Số lần phát sinh theo từng mục KPI và chỉ số quy mô trong tuần" />
            <RecordTable records={d.weekly_kpi_reports} empty="Chưa có báo cáo tuần KPI nào." onOpen={setViewing} />
          </div>
        )}
        {tab === 'monthly' && (
          <div className="space-y-6 p-6">
            <Heading title="Báo cáo tháng / quý" sub="Báo cáo tháng của giáo viên, báo cáo tháng và quý của Học thuật" />
            <RecordTable records={d.monthly_reports} empty="Chưa có báo cáo tháng nào." onOpen={setViewing} />
          </div>
        )}
      </div>

      {viewing && (
        <Dialog title={`${viewing.title} · ${viewing.user ?? ''}`} onClose={() => setViewing(null)} wide>
          <p className="text-sm whitespace-pre-line text-on-surface" data-report-content>
            {viewing.content || 'Không có nội dung.'}
          </p>
          <div className="mt-4 flex justify-end">
            <Button variant="secondary" onClick={() => setViewing(null)}>
              Đóng
            </Button>
          </div>
        </Dialog>
      )}
    </div>
  )
}

function Heading({ title, sub }: { title: string; sub: string }) {
  return (
    <div>
      <h3 className="text-sm font-bold text-on-surface">{title}</h3>
      <p className="text-xs text-on-surface-variant">{sub}</p>
    </div>
  )
}

function MiniBox({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="space-y-2 rounded-xl border border-surface-container-highest bg-surface-container-low p-4">
      <span className="text-xs font-bold text-on-surface-variant uppercase">{title}</span>
      {children}
    </div>
  )
}

const th = 'px-3 py-2 text-left text-xs font-medium text-on-surface-variant'
const td = 'px-3 py-2 align-top'
const rowCls = 'cursor-pointer border-b border-surface-container-highest/60 last:border-0 hover:bg-surface-container-low'

function DailyTable({ rows, onOpen }: { rows: DailyRow[]; onOpen: (r: DailyRow) => void }) {
  return (
    <div className="overflow-x-auto rounded-xl border border-surface-container-highest" data-staff-daily>
      <table className="w-full text-sm">
        <thead className="border-b border-surface-container-highest">
          <tr>
            {['Ngày', 'Học vụ', 'Lớp thực tế / kế hoạch', 'GV / TG', 'Tuyển sinh', 'Cập nhật'].map((h) => (
              <th key={h} className={th}>
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.id} className={rowCls} tabIndex={0} onClick={() => onOpen(r)} onKeyDown={(e) => e.key === 'Enter' && onOpen(r)}>
              <td className={`${td} font-mono text-xs`}>{formatDate(r.date)}</td>
              <td className={`${td} font-semibold`}>{r.user ?? 'Chưa cập nhật'}</td>
              {r.structured ? (
                <>
                  <td className={td}>{r.classes ?? '—'}</td>
                  <td className={td}>{r.staff ?? '—'}</td>
                  <td className={td}>
                    {r.enrollment ?? '—'}{' '}
                    {r.has_task && <Badge tone="info">Có task</Badge>}
                  </td>
                </>
              ) : (
                <td colSpan={3} className={`${td} text-on-surface-variant`}>
                  {r.title} (dạng chữ)
                </td>
              )}
              <td className={`${td} font-mono text-xs text-on-surface-variant`}>{r.updated_at}</td>
            </tr>
          ))}
          {!rows.length && (
            <tr>
              <td colSpan={6}>
                <Empty title="Chưa có báo cáo ngày nào của Học vụ." />
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  )
}

function RecordTable({ records, empty, onOpen }: { records: RecordRow[]; empty: string; onOpen: (v: Viewing) => void }) {
  const open = (r: RecordRow) => onOpen({ title: r.title, user: r.user, content: r.content })
  return (
    <div className="overflow-x-auto rounded-xl border border-surface-container-highest">
      <table className="w-full text-sm">
        <thead className="border-b border-surface-container-highest">
          <tr>
            {['Báo cáo', 'Người gửi', 'Loại', 'Thời gian'].map((h) => (
              <th key={h} className={th}>
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {records.map((r) => (
            <tr key={r.id} className={rowCls} tabIndex={0} onClick={() => open(r)} onKeyDown={(e) => e.key === 'Enter' && open(r)}>
              <td className={td}>
                <span className="font-bold">{r.title}</span>
                {r.record_code && <span className="block font-mono text-xs text-on-surface-variant">{r.record_code}</span>}
              </td>
              <td className={`${td} font-semibold`}>{r.user ?? 'Chưa cập nhật'}</td>
              <td className={td}>{r.status_label}</td>
              <td className={`${td} font-mono text-xs text-on-surface-variant`}>{r.created_at}</td>
            </tr>
          ))}
          {!records.length && (
            <tr>
              <td colSpan={4}>
                <Empty title={empty} />
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  )
}

const SEVERITY_OPTIONS = [
  { value: 'all', label: 'Mọi mức độ' },
  { value: 'urgent', label: 'Khẩn cấp' },
  { value: 'high', label: 'Cao' },
  { value: 'medium', label: 'Trung bình' },
  { value: 'low', label: 'Thấp' },
]
const STATUS_OPTIONS = [
  { value: 'all', label: 'Mọi trạng thái' },
  { value: 'open', label: 'Đang xử lý' },
  { value: 'resolved', label: 'Đã giải quyết' },
]
const PRIORITY_TONE: Record<string, 'error' | 'warning' | 'info'> = { urgent: 'error', high: 'warning', medium: 'info' }
const JOURNAL_STATUS: Record<string, { label: string; tone: 'success' | 'info' | 'warning' }> = {
  resolved: { label: 'Đã xử lý', tone: 'success' },
  following: { label: 'Đang theo dõi', tone: 'info' },
}

function readFilter(): IncidentFilter {
  const q = new URLSearchParams(window.location.search)
  return { severity: q.get('severity') || 'all', status: q.get('status') || 'all', branch_id: q.get('branch_id') ?? '', class_id: q.get('class_id') ?? '' }
}

function IncidentsView() {
  const [filter, setFilter] = useState(readFilter)
  const q = useQuery({ queryKey: ['academic-dashboard', 'incidents', filter], queryFn: () => dashboardApi.incidents(filter), placeholderData: keepPreviousData })
  const change = (key: keyof IncidentFilter, value: string) => {
    const next = { ...filter, [key]: value }
    setFilter(next)
    const params = new URLSearchParams(Object.entries(next).filter(([, v]) => v && v !== 'all'))
    const search = params.toString()
    window.history.replaceState(null, '', INCIDENTS_PATH + (search ? `?${search}` : ''))
  }
  if (q.error) return <Alert>{errorMessage(q.error)}</Alert>
  const d = q.data
  if (!d) return <p className="text-sm text-on-surface-variant">Đang tải…</p>
  return (
    <div className="space-y-6">
      {d.can_submit_journal && (
        <div className="flex justify-end">
          <Button onClick={() => navigate('/reports/journal')}>
            <Icon name="add" className="mr-1.5 text-[18px]" /> Ghi sự vụ
          </Button>
        </div>
      )}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Tổng sự vụ ghi nhận" value={`${d.total_incidents} vụ`} icon="auto_stories" />
        <StatCard label="Khẩn cấp cần xử lý" value={`${d.urgent_count} vụ khẩn cấp`} tone="error" icon="emergency" />
        <StatCard label="Đang theo dõi / Xử lý" value={`${d.open_count} vụ`} tone="warning" icon="hourglass_top" />
        <StatCard label="Đã giải quyết" value={`${d.resolved_count} vụ`} tone="success" icon="check_circle" />
      </div>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Lọc sự vụ">
        <Select label="Cơ sở" value={filter.branch_id} onChange={(e) => change('branch_id', e.target.value)}>
          <option value="">Tất cả cơ sở</option>
          {d.branches.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
        <Select label="Lớp" value={filter.class_id} onChange={(e) => change('class_id', e.target.value)}>
          <option value="">Mọi lớp</option>
          {d.classes.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
        <Select label="Mức độ" value={filter.severity} onChange={(e) => change('severity', e.target.value)}>
          {SEVERITY_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
        <Select label="Trạng thái" value={filter.status} onChange={(e) => change('status', e.target.value)}>
          {STATUS_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
      </div>

      <div className="overflow-x-auto rounded-xl border border-surface-container-highest bg-surface shadow-sm">
        <div className="space-y-1 border-b border-surface-container-highest px-4 py-3">
          <h3 className="text-sm font-bold text-on-surface">Nhật ký sự vụ các lớp và cơ sở</h3>
          <p className="text-xs text-on-surface-variant">Theo dõi, giao quyền xử lý và ghi nhận giải pháp khắc phục</p>
          <Pending>Ticket hỗ trợ khẩn / cao: {d.tickets_pending}</Pending>
        </div>
        <table className="w-full text-sm">
          <thead className="border-b border-surface-container-highest">
            <tr>
              {['Mã / Cơ sở', 'Phân loại sự vụ', 'Chi tiết sự vụ phát sinh', 'Mức độ', 'Người phụ trách & Biện pháp', 'Trạng thái'].map((h) => (
                <th key={h} className={th}>
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {d.journals.map((j) => {
              const st = JOURNAL_STATUS[j.status] ?? { label: 'Mới', tone: 'warning' as const }
              return (
                <tr
                  key={j.id}
                  className={`border-b border-surface-container-highest/60 last:border-0 ${j.severity === 'urgent' && j.status !== 'resolved' ? 'bg-error/5' : ''}`}
                >
                  <td className={td}>
                    <span className="font-mono text-xs font-bold">Sự vụ #{j.id.slice(0, 8)}</span>
                    <span className="block text-xs text-on-surface-variant">{j.branch ?? 'Chưa cập nhật'}</span>
                    {j.class_id && (
                      <Link href={`/classes/${j.class_id}`} className="block text-xs font-semibold text-primary hover:underline">
                        Lớp {j.class_code}
                      </Link>
                    )}
                  </td>
                  <td className={td}>
                    <Badge tone="warning">Nhật ký sự vụ</Badge>
                  </td>
                  <td className={`${td} max-w-sm`}>
                    <p className="font-semibold">{j.title}</p>
                    {j.content && <p className="line-clamp-1 text-xs text-on-surface-variant">{j.content}</p>}
                  </td>
                  <td className={td}>
                    <Badge tone={(j.priority && PRIORITY_TONE[j.priority]) || 'neutral'}>{j.severity_label}</Badge>
                  </td>
                  <td className={td}>
                    <span className="font-bold">{j.user ?? 'Chưa cập nhật'}</span>
                    {j.followup && <span className="line-clamp-1 block text-xs text-on-surface-variant">{j.followup}</span>}
                  </td>
                  <td className={td}>
                    <Badge tone={st.tone}>{st.label}</Badge>
                  </td>
                </tr>
              )
            })}
            {!d.journals.length && (
              <tr>
                <td colSpan={6}>
                  <Empty title="Chưa có sự vụ nào phù hợp bộ lọc." />
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
