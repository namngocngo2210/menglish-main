// Tổng quan (Laravel Dashboard.vue): bảng điều hành Admin / Quản lý / Học thuật; vai trò khác: lời chào + "Việc của
// bạn" (lịch hẹn 7 ngày tới, việc cần xử lý, việc của tôi trong tuần); băng "Báo cáo ngày" cho người nộp báo cáo vận
// hành ngày; chất lượng giảng dạy theo tháng.
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Icon } from '../../components/ui'
import { navigate } from '../../router'
import { dashboardApi, type Agenda, type DailyReport, type MyTasks, type Queue, type RoleDashboard } from './api'
import { Empty, Link, Panel, PanelLink, Pending, StatCard } from './components'
import { bannerOf, formatCount, formatDate, formatHourDay, kindClass, statGridClass, welcomeOf } from './logic'
import { TeachingQuality } from './TeachingQuality'

// Việc quá hạn của bảng điều hành mở danh sách "Tôi giao" lọc Quá hạn (tasks.index tab=assigned status=overdue).
const OVERDUE_TASKS_URL = '/tasks?tab=assigned&status=overdue'

function readMonth() {
  return new URLSearchParams(window.location.search).get('month') ?? ''
}

export function DashboardPage({ me }: { me: Me }) {
  const [month, setMonth] = useState(readMonth)
  const q = useQuery({ queryKey: ['dashboard', month], queryFn: () => dashboardApi.overview(month), placeholderData: keepPreviousData })
  const pickMonth = (m: string) => {
    setMonth(m)
    const url = new URL(window.location.href)
    url.searchParams.set('month', m)
    window.history.replaceState(null, '', url.pathname + url.search)
  }
  const data = q.data
  return (
    <div className="mx-auto max-w-7xl">
      <div className="mb-6 flex items-center gap-3">
        <Icon name="dashboard" className="text-[28px] text-primary" />
        <div>
          <h1 className="text-xl font-semibold">Tổng quan</h1>
          {data?.is_operations && <p className="mt-0.5 text-sm text-on-surface-variant">Bảng điều hành toàn trung tâm</p>}
        </div>
      </div>
      {q.error ? (
        <Alert>{errorMessage(q.error)}</Alert>
      ) : !data ? (
        <p className="text-sm text-on-surface-variant">Đang tải…</p>
      ) : (
        <div className="space-y-6">
          {!data.is_operations && <WelcomeBlock me={me} />}
          {data.daily_report && <DailyReportBanner report={data.daily_report} />}
          {/* Giáo viên / trợ giảng: chất lượng giảng dạy lên đầu; vai trò khác: sau bảng điều hành / việc của bạn. */}
          {data.teaching?.mine && <TeachingQuality teaching={data.teaching} onMonth={pickMonth} />}
          {data.role_dashboard?.type === 'personal' ? (
            <MyWork dashboard={data.role_dashboard} />
          ) : (
            data.role_dashboard && <RoleWidgets dashboard={data.role_dashboard} />
          )}
          {data.teaching && !data.teaching.mine && <TeachingQuality teaching={data.teaching} onMonth={pickMonth} />}
        </div>
      )}
    </div>
  )
}

function WelcomeBlock({ me }: { me: Me }) {
  const w = welcomeOf(me)
  return (
    <div className="flex flex-col gap-3 rounded-2xl border border-surface-container-highest bg-surface p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h2 className="text-lg font-bold text-on-surface">Xin chào, {w.name}!</h2>
        <p className="mt-1 text-xs text-on-surface-variant">
          Vai trò: <span className="font-semibold text-primary">{w.roles}</span> · Chi nhánh: {w.branch}
        </p>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        {w.salaryUrl && (
          <Button variant="secondary" onClick={() => navigate(w.salaryUrl!)}>
            <Icon name="payments" className="mr-1.5 text-[18px]" /> Lương của tôi
          </Button>
        )}
        {w.tasksUrl && (
          <Button variant="secondary" onClick={() => navigate(w.tasksUrl!)}>
            <Icon name="checklist" className="mr-1.5 text-[18px]" /> Nhiệm vụ hôm nay
          </Button>
        )}
      </div>
    </div>
  )
}

export function DailyReportBanner({ report }: { report: DailyReport }) {
  const b = bannerOf(report)
  return (
    <section
      data-dashboard-daily-report
      className={`flex flex-col gap-3 rounded-2xl border p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between ${
        b.attention ? 'border-warning/40 bg-warning-container/40' : 'border-surface-container-highest bg-surface'
      }`}
    >
      <div className="flex min-w-0 items-start gap-3">
        <Icon name={b.attention ? 'assignment_late' : 'assignment_turned_in'} className={`mt-0.5 ${b.attention ? 'text-warning' : 'text-success'}`} />
        <div className="min-w-0 space-y-1 text-sm">
          <p className="font-bold text-on-surface">Báo cáo ngày</p>
          {b.missing.length > 0 && (
            <p className="text-on-surface">
              Chưa nộp:{' '}
              {b.missing.map((d, i) => (
                <span key={d.href}>
                  <Link href={d.href} className="font-semibold text-primary underline">
                    {d.label}
                  </Link>
                  {i < b.missing.length - 1 && ', '}
                </span>
              ))}
              {b.more > 0 && ` và ${b.more} ngày khác`}
              {b.deadline && <span className="text-on-surface-variant"> · hạn {b.deadline}</span>}
            </p>
          )}
          {b.returned.map((r) => (
            <p key={r.href} className="text-on-surface">
              Admin yêu cầu bổ sung báo cáo{' '}
              <Link href={r.href} className="font-semibold text-primary underline">
                {r.label}
              </Link>
              {r.note && `: ${r.note}`}
            </p>
          ))}
          {b.allDone && <p className="text-on-surface-variant">Đã nộp đủ các ngày trước.</p>}
          <p className="text-on-surface-variant">Hôm nay: {b.today}</p>
        </div>
      </div>
      <Button onClick={() => navigate(b.action.href)} className="shrink-0">
        <Icon name="edit_note" className="mr-1.5 text-[18px]" /> {b.action.label}
      </Button>
    </section>
  )
}

function Header({ dashboard }: { dashboard: RoleDashboard }) {
  return (
    <div>
      <h2 className="text-lg font-semibold text-on-surface">{dashboard.title}</h2>
      <p className="text-xs text-on-surface-variant">
        Phạm vi: {dashboard.scope} · cập nhật {dashboard.updated_at}
      </p>
    </div>
  )
}

function Stats({ dashboard, personal }: { dashboard: RoleDashboard; personal: boolean }) {
  if (!dashboard.stats.length) return null
  return (
    <div className={`grid grid-cols-1 gap-4 sm:grid-cols-2 ${statGridClass(dashboard.stats.length, personal)}`}>
      {dashboard.stats.map((s) => {
        const card = <StatCard label={s.label} value={s.value} icon={s.icon} tone={s.tone} hint={s.hint} pending={s.pending} />
        return s.href ? (
          <Link key={s.label} href={s.href} className="block rounded-xl transition hover:shadow-md">
            {card}
          </Link>
        ) : (
          <div key={s.label}>{card}</div>
        )
      })}
    </div>
  )
}

function QueueIcon({ queue }: { queue: Queue }) {
  const hot = (queue.value ?? 0) > 0
  return (
    <span
      className={`flex size-9 shrink-0 items-center justify-center rounded-full ${hot ? 'bg-warning-container text-warning' : 'bg-surface-container-low text-on-surface-variant'}`}
    >
      <Icon name={queue.icon} className="text-[20px]" />
    </span>
  )
}

// Một hàng chờ: có số liệu thì là liên kết; chờ phân hệ thì hiện lý do, không bấm được.
function QueueRow({ queue, className, children }: { queue: Queue; className: string; children: ReactNode }) {
  return queue.href && !queue.pending ? (
    <Link href={queue.href} className={`${className} hover:bg-surface-container-low`}>
      {children}
    </Link>
  ) : (
    <div className={className}>{children}</div>
  )
}

function queueValue(q: Queue) {
  return q.pending ? '—' : formatCount(q.value)
}

// "Việc của bạn" (MyWork.vue): số liệu tuần / tháng, lịch hẹn 7 ngày tới, đầu việc cần xử lý, việc của tôi trong tuần.
function MyWork({ dashboard }: { dashboard: RoleDashboard }) {
  const agenda = dashboard.agenda
  return (
    <section className="space-y-4" data-role-dashboard="personal">
      <Header dashboard={dashboard} />
      <Stats dashboard={dashboard} personal />
      {/* Không có nguồn lịch hẹn (vd. Kế toán): Cần xử lý + Việc của tôi chia đôi chiều ngang */}
      <div className={`grid grid-cols-1 gap-4 ${agenda ? 'lg:grid-cols-3' : 'lg:grid-cols-2'}`}>
        {agenda && <AgendaCard agenda={agenda} className="lg:col-span-2" />}
        <div className={agenda ? 'space-y-4' : 'contents'}>
          {dashboard.queues.length > 0 && (
            <Panel title="Cần xử lý" data-role-queues>
              {dashboard.queues.map((q) => (
                <QueueRow key={q.label} queue={q} className="flex items-center gap-3 border-b border-surface-container-highest/60 px-4 py-2.5 last:border-0">
                  <QueueIcon queue={q} />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm text-on-surface">{q.label}</span>
                    {(q.pending || q.hint) && <span className="block truncate text-xs text-on-surface-variant">{q.pending ?? q.hint}</span>}
                  </span>
                  <span className={`shrink-0 text-lg font-semibold tabular-nums ${(q.value ?? 0) > 0 ? 'text-warning' : 'text-on-surface-variant'}`}>
                    {queueValue(q)}
                  </span>
                </QueueRow>
              ))}
            </Panel>
          )}
          {dashboard.my_tasks && <MyTasksCard tasks={dashboard.my_tasks} />}
        </div>
      </div>
    </section>
  )
}

// Bảng điều hành Admin / Quản lý / Học thuật (RoleWidgets.vue).
function RoleWidgets({ dashboard }: { dashboard: RoleDashboard }) {
  const n = dashboard.queues.length
  return (
    <section className="space-y-4" data-role-dashboard={dashboard.type}>
      <Header dashboard={dashboard} />
      <Stats dashboard={dashboard} personal={false} />
      {n > 0 && (
        <Panel title="Hàng chờ cần xử lý">
          <div
            data-role-queues
            className={`grid grid-cols-1 divide-y divide-surface-container-highest/60 sm:grid-cols-2 sm:divide-y-0 ${
              n >= 5 ? 'xl:grid-cols-5' : n >= 4 ? 'xl:grid-cols-4' : 'xl:grid-cols-3'
            }`}
          >
            {dashboard.queues.map((q) => (
              <QueueRow key={q.label} queue={q} className="flex items-center gap-3 px-4 py-3">
                <QueueIcon queue={q} />
                <span className="min-w-0">
                  <span className={`block text-lg font-semibold tabular-nums ${(q.value ?? 0) > 0 ? 'text-warning' : 'text-on-surface'}`}>{queueValue(q)}</span>
                  <span className="block truncate text-xs text-on-surface-variant">{q.label}</span>
                  {(q.pending || q.hint) && <span className="block truncate text-xs text-on-surface-subtle">{q.pending ?? q.hint}</span>}
                </span>
              </QueueRow>
            ))}
          </div>
        </Panel>
      )}
      {(dashboard.type === 'admin' || dashboard.type === 'manager') && (
        <Panel title="Việc quá hạn cần xử lý" action={<PanelLink href={OVERDUE_TASKS_URL}>Xem danh sách</PanelLink>}>
          {(dashboard.overdue_tasks ?? []).map((t) => (
            <div key={t.id} className="flex items-center justify-between gap-3 border-b border-surface-container-highest/60 px-4 py-2.5 last:border-0">
              <div className="min-w-0">
                <Link href={`/tasks/${t.id}`} className="block truncate text-sm text-on-surface hover:text-primary" title={t.title}>
                  {t.title}
                </Link>
                <p className="text-xs text-on-surface-variant">{t.assignee ?? 'Chưa phân công'}</p>
              </div>
              <span className="shrink-0 rounded-full bg-error-container px-2 py-0.5 text-xs text-error">Hạn {formatDate(t.due_date) || '—'}</span>
            </div>
          ))}
          {!dashboard.overdue_tasks?.length && <Empty icon="task_alt" title="Không có việc quá hạn" />}
        </Panel>
      )}
      {dashboard.type === 'academic' && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <Panel title="Đề xuất chờ duyệt" action={<PanelLink href="/syllabus/adjustment-requests">Duyệt đề xuất</PanelLink>}>
            {dashboard.academic_pending ? (
              <div className="px-4 py-4">
                <Pending>{dashboard.academic_pending}</Pending>
              </div>
            ) : (
              <>
                {(dashboard.pending_adjustments ?? []).map((r) => (
                  <div key={r.id} className="border-b border-surface-container-highest/60 px-4 py-2.5 last:border-0">
                    <p className="text-sm text-on-surface">
                      {r.class ?? 'Lớp'} — {r.teacher ?? 'Giáo viên'}
                    </p>
                    <p className="truncate text-xs text-on-surface-variant">{r.reason}</p>
                  </div>
                ))}
                {!dashboard.pending_adjustments?.length && <Empty icon="inbox" title="Không có đề xuất chờ duyệt" />}
              </>
            )}
          </Panel>
          <Panel title="Big Test sắp tới" action={<PanelLink href="/syllabus/big-tests/schedules">Lịch Big Test</PanelLink>}>
            {dashboard.academic_pending ? (
              <div className="px-4 py-4">
                <Pending>{dashboard.academic_pending}</Pending>
              </div>
            ) : (
              <>
                {(dashboard.upcoming_big_tests ?? []).map((t) => (
                  <div key={t.id} className="flex items-center justify-between gap-3 border-b border-surface-container-highest/60 px-4 py-2.5 last:border-0">
                    <div className="min-w-0">
                      <p className="truncate text-sm text-on-surface">{t.title}</p>
                      <p className="text-xs text-on-surface-variant">{t.class ?? '—'}</p>
                    </div>
                    <span className="shrink-0 text-xs text-on-surface-variant">{formatHourDay(t.scheduled_at)}</span>
                  </div>
                ))}
                {!dashboard.upcoming_big_tests?.length && <Empty icon="event_available" title="Không có Big Test trong 14 ngày tới" />}
              </>
            )}
          </Panel>
        </div>
      )}
      {/* Học thuật: thêm lịch hẹn 7 ngày tới + việc của tôi trong tuần */}
      {dashboard.agenda && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
          <AgendaCard agenda={dashboard.agenda} className="lg:col-span-2" />
          {dashboard.my_tasks && <MyTasksCard tasks={dashboard.my_tasks} />}
        </div>
      )}
    </section>
  )
}

// Lịch hẹn 7 ngày tới (AgendaCard.vue): nhóm theo ngày, xếp theo giờ; lịch đã qua làm mờ.
function AgendaCard({ agenda, className = '' }: { agenda: Agenda; className?: string }) {
  return (
    <Panel
      title="Lịch hẹn 7 ngày tới"
      className={className}
      data-agenda
      action={
        <span className="text-xs text-on-surface-variant">
          {agenda.range} · {formatCount(agenda.total)} lịch
        </span>
      }
    >
      {agenda.days.map((day) => (
        <div key={day.date} className="border-b border-surface-container-highest/60 last:border-0">
          <p className="bg-surface-container-low px-4 py-1 text-xs font-semibold tracking-wide text-on-surface-variant uppercase">{day.label}</p>
          {day.items.map((item, i) => {
            const body = (
              <>
                <span className="w-12 shrink-0 font-mono text-xs text-on-surface">{item.time ?? '—'}</span>
                <span className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold ${kindClass(item.kind)}`}>{item.kind_label}</span>
                <span className="min-w-0">
                  <span className="block truncate text-sm text-on-surface">{item.title}</span>
                  {item.subtitle && <span className="block truncate text-xs text-on-surface-variant">{item.subtitle}</span>}
                </span>
              </>
            )
            const cls = `flex items-center gap-3 px-4 py-2 ${item.past ? 'opacity-60' : ''}`
            return item.href ? (
              <Link key={day.date + i} href={item.href} className={`${cls} hover:bg-surface-container-low`}>
                {body}
              </Link>
            ) : (
              <div key={day.date + i} className={cls}>
                {body}
              </div>
            )
          })}
        </div>
      ))}
      {agenda.more > 0 && <p className="px-4 py-2 text-xs text-on-surface-variant">+{formatCount(agenda.more)} lịch nữa</p>}
      {!agenda.days.length && <Empty icon="event_available" title="Không có lịch hẹn trong 7 ngày tới" />}
      {agenda.pending.length > 0 && (
        <div className="space-y-1 border-t border-surface-container-highest px-4 py-2">
          {agenda.pending.map((p) => (
            <Pending key={p}>{p}</Pending>
          ))}
        </div>
      )}
    </Panel>
  )
}

// Việc được giao cho tôi chưa xong, hạn trong tuần này hoặc đã quá hạn (MyTasksCard.vue).
function MyTasksCard({ tasks }: { tasks: MyTasks }) {
  if (!tasks.url) return null
  const url = tasks.url
  return (
    <Panel title="Việc của tôi trong tuần" data-my-tasks action={<PanelLink href={url}>Xem tất cả ({formatCount(tasks.total)})</PanelLink>}>
      {tasks.items.map((t) => (
        <Link
          key={t.id}
          href={`/tasks/${t.id}`}
          className="flex items-center justify-between gap-3 border-b border-surface-container-highest/60 px-4 py-2.5 last:border-0 hover:bg-surface-container-low"
        >
          <span className="min-w-0">
            <span className="block truncate text-sm text-on-surface" title={t.title}>
              {t.title}
            </span>
            <span className="block text-xs text-on-surface-variant">{t.status_label}</span>
          </span>
          <span className={`shrink-0 rounded-full px-2 py-0.5 text-xs ${t.overdue ? 'bg-error-container text-error' : 'bg-surface-container-low text-on-surface-variant'}`}>
            {t.due ? `Hạn ${t.due}` : 'Không hạn'}
          </span>
        </Link>
      ))}
      {!tasks.items.length && <Empty icon="task_alt" title="Không có việc đến hạn tuần này" />}
    </Panel>
  )
}
