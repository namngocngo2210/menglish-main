// Danh sách đầu việc (tasks.index) và chi tiết công việc (tasks.show, /tasks/<id>).
// Chỉ "Của tôi" (việc mình được giao) và "Tôi giao" (việc mình giao), kể cả Admin; đổi trạng thái theo các bước máy chủ
// cho phép. Bấm tiêu đề → hộp xem nhanh; mở thẳng /tasks/<id> → trang đầy đủ.
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Dialog, Field, Select } from '../../components/ui'
import { can, isAdmin } from '../../nav'
import { navigate, usePath } from '../../router'
import { tasksApi, type ListParams, type Task, type Transition } from './api'
import { Chip, PageHeader, StatCard, StatusBadge, StatusForm, flash } from './components'
import { STATUS_OPTIONS, formatDate, formatDateTime, taskIdFromPath } from './logic'

export function TasksPage({ me }: { me: Me }) {
  const id = taskIdFromPath(usePath())
  return id ? <TaskDetailPage me={me} id={id} /> : <TaskListPage me={me} />
}

type Filters = { q: string; date_from: string; date_to: string; assignee_id: string; status: string }
const emptyFilters: Filters = { q: '', date_from: '', date_to: '', assignee_id: '', status: '' }

// Tab và trạng thái ban đầu đọc từ URL (vd. Tổng quan mở /tasks?tab=assigned&status=overdue như route Laravel).
function initialList() {
  const q = new URLSearchParams(window.location.search)
  const status = q.get('status') ?? ''
  return {
    tab: q.get('tab') === 'assigned' ? ('assigned' as const) : ('mine' as const),
    filters: { ...emptyFilters, status: STATUS_OPTIONS.some((o) => o.value === status) ? status : '' },
  }
}

function TaskListPage({ me }: { me: Me }) {
  const [initial] = useState(initialList)
  const [tab, setTab] = useState<'mine' | 'assigned'>(initial.tab)
  const [draft, setDraft] = useState<Filters>(initial.filters)
  const [filters, setFilters] = useState<Filters>(initial.filters)
  const [page, setPage] = useState(1)
  const [notice, setNotice] = useState(flash.take)
  const [detail, setDetail] = useState<string | null>(null)
  const [statusChange, setStatusChange] = useState<{ task: Task; next: Transition } | null>(null)
  const params: ListParams = { tab, page, ...filters }
  const list = useQuery({ queryKey: ['tasks', 'list', params], queryFn: () => tasksApi.list(params), placeholderData: keepPreviousData })
  const data = list.data
  const pages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1
  const canCreate = can(me, 'work_task.create', 'work_task.request')

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setFilters(draft)
    setPage(1)
  }

  return (
    <>
      <PageHeader
        title="Danh sách đầu việc"
        description="Quản lý, phân công và theo dõi tiến độ công việc — giao việc hai chiều."
        actions={
          <>
            {isAdmin(me) && (
              <Button variant="secondary" onClick={() => navigate('/tasks/approvals')}>
                Chờ xác nhận
                {data && data.counts.approvals > 0 && (
                  <span className="ml-2 rounded-full bg-error px-1.5 text-xs text-white">{data.counts.approvals}</span>
                )}
              </Button>
            )}
            {can(me, 'work_task.assign', 'work_task.approve') && (
              <Button variant="secondary" onClick={() => navigate('/portal/ta-tasks')}>
                Nhiệm vụ của TA
              </Button>
            )}
            {can(me, 'work_task.assign') && (
              <Button variant="secondary" onClick={() => navigate('/tasks/ta-assign')}>
                Giao việc cho Trợ giảng
              </Button>
            )}
            {canCreate && (
              <Button onClick={() => navigate('/tasks/new')}>
                {can(me, 'work_task.create') ? 'Tạo đầu việc' : 'Đề xuất việc cho Admin / Học vụ'}
              </Button>
            )}
          </>
        }
      />

      {notice && (
        <div className="mb-4">
          <Alert tone="success">{notice}</Alert>
        </div>
      )}

      {data && (
        <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
          <StatCard label="Việc của tôi" value={data.counts.mine} tone="primary" />
          <StatCard label="Việc tôi giao" value={data.counts.assigned} />
          <StatCard label="Chờ xác nhận" value={data.counts.pending} tone="warning" />
          <StatCard label="Quá hạn" value={data.counts.overdue} tone="error" />
        </div>
      )}

      <Card>
        <form className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_9.5rem_9.5rem_12rem_11rem_auto] lg:items-end" onSubmit={submit}>
          <Field label="Tìm kiếm" placeholder="Tìm công việc, nhân sự..." value={draft.q} onChange={(e) => setDraft({ ...draft, q: e.target.value })} />
          <Field label="Hạn từ" type="date" value={draft.date_from} onChange={(e) => setDraft({ ...draft, date_from: e.target.value })} />
          <Field label="Hạn đến" type="date" value={draft.date_to} onChange={(e) => setDraft({ ...draft, date_to: e.target.value })} />
          <Select label="Nhân viên" value={draft.assignee_id} onChange={(e) => setDraft({ ...draft, assignee_id: e.target.value })}>
            <option value="">Mọi nhân viên</option>
            {data?.assignees.map((u) => (
              <option key={u.id} value={u.id}>
                {u.label}
              </option>
            ))}
          </Select>
          <Select label="Trạng thái" value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
            <option value="">Mọi trạng thái</option>
            {STATUS_OPTIONS.map((s) => (
              <option key={s.value} value={s.value}>
                {s.label}
              </option>
            ))}
          </Select>
          <div className="flex gap-2">
            <Button type="submit" variant="secondary">
              Lọc
            </Button>
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                setDraft(emptyFilters)
                setFilters(emptyFilters)
                setPage(1)
              }}
            >
              Xóa lọc
            </Button>
          </div>
        </form>

        <div role="tablist" className="mb-2 flex gap-1 border-b border-surface-container-highest">
          {(
            [
              ['mine', 'Của tôi', data?.counts.mine],
              ['assigned', 'Tôi giao', data?.counts.assigned],
            ] as const
          ).map(([key, label, count]) => (
            <button
              key={key}
              type="button"
              role="tab"
              aria-selected={tab === key}
              onClick={() => {
                setTab(key)
                setPage(1)
              }}
              className="-mb-px border-b-2 border-transparent px-3 py-2 text-sm text-on-surface-variant aria-selected:border-primary aria-selected:font-medium aria-selected:text-primary"
            >
              {label}
              {count !== undefined && <span className="ml-1.5 rounded-full bg-surface-container-high px-1.5 text-xs">{count}</span>}
            </button>
          ))}
        </div>

        {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
        {data && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[880px] text-left text-sm">
              <thead className="text-xs text-on-surface-variant">
                <tr>
                  <th className="py-2 pr-4 font-medium">Tiêu đề</th>
                  <th className="py-2 pr-4 font-medium">Loại</th>
                  <th className="py-2 pr-4 font-medium">Người nhận</th>
                  <th className="py-2 pr-4 font-medium">Hạn hoàn thành</th>
                  <th className="py-2 pr-4 font-medium">Trạng thái</th>
                  <th className="py-2 text-right font-medium">Thao tác</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-surface-container-highest">
                {data.tasks.map((t) => (
                  <tr key={t.id} className={`${t.status === 'overdue' ? 'bg-error-container/20' : ''} ${t.status === 'canceled' ? 'opacity-60' : ''}`}>
                    <td className="max-w-[360px] py-2 pr-4 align-top">
                      <a
                        href={`/tasks/${t.id}`}
                        onClick={(e) => {
                          if (e.metaKey || e.ctrlKey || e.shiftKey) return
                          e.preventDefault()
                          setDetail(t.id)
                        }}
                        className={`font-semibold text-on-surface hover:text-primary ${t.status === 'canceled' ? 'line-through' : ''}`}
                      >
                        {t.title}
                      </a>
                      {t.description && <p className="line-clamp-1 text-xs text-on-surface-variant">{t.description}</p>}
                      {(t.class_label || t.lesson_session || t.slot_label) && (
                        <div className="mt-1 flex flex-wrap gap-1">
                          {(t.class_label || t.lesson_session) && <Chip tone="primary">{t.class_label ?? t.lesson_session}</Chip>}
                          {t.slot_label && <Chip>{t.slot_label}</Chip>}
                        </div>
                      )}
                      {t.blocked_reason && <p className="mt-1 text-xs text-error">Lý do: {t.blocked_reason}</p>}
                      {t.handed_back_from && <p className="mt-1 text-xs text-on-surface-variant">Chuyển lại từ {t.handed_back_from} (bị chặn)</p>}
                    </td>
                    <td className="py-2 pr-4 align-top">
                      <Chip tone={t.task_type === 'recurring' ? 'primary' : 'neutral'}>{t.type_label}</Chip>
                    </td>
                    <td className="py-2 pr-4 align-top">
                      <div className="font-medium">{t.assignee ?? 'Chưa phân công'}</div>
                      {tab !== 'mine' && <div className="text-xs text-on-surface-variant">Giao bởi {t.creator}</div>}
                    </td>
                    <td className={`py-2 pr-4 align-top whitespace-nowrap ${t.status === 'overdue' ? 'font-semibold text-error' : ''}`}>
                      {formatDate(t.due_date)}
                      {t.due_time && <span className="block text-xs text-on-surface-variant">{t.due_time}</span>}
                    </td>
                    <td className="py-2 pr-4 align-top">
                      <StatusBadge status={t.status} label={t.status_label} />
                    </td>
                    <td className="py-2 text-right align-top">
                      <ActionMenu task={t} onPick={(next) => setStatusChange({ task: t, next })} />
                    </td>
                  </tr>
                ))}
                {data.tasks.length === 0 && (
                  <tr>
                    <td colSpan={6} className="py-8 text-center text-on-surface-variant">
                      Không tìm thấy công việc nào phù hợp
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}
        {data && pages > 1 && (
          <div className="mt-4 flex items-center gap-3 text-sm">
            <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
              Trước
            </Button>
            <span>
              Trang {Math.min(page, pages)}/{pages} · {data.total} công việc
            </span>
            <Button variant="secondary" disabled={page >= pages} onClick={() => setPage(page + 1)}>
              Sau
            </Button>
          </div>
        )}
      </Card>

      {detail && (
        <Dialog title="Chi tiết công việc" onClose={() => setDetail(null)}>
          <TaskDetail
            id={detail}
            onDone={(msg) => {
              setNotice(msg)
              setDetail(null)
            }}
          />
          <div className="mt-4 flex justify-end">
            <Button variant="secondary" onClick={() => navigate(`/tasks/${detail}`)}>
              Mở trang đầy đủ
            </Button>
          </div>
        </Dialog>
      )}
      {statusChange && (
        <Dialog title="Thay đổi trạng thái" onClose={() => setStatusChange(null)}>
          <div className="mb-3 text-sm">
            <p className="text-xs text-on-surface-variant">Công việc</p>
            <p className="font-semibold">{statusChange.task.title}</p>
          </div>
          <StatusForm
            task={statusChange.task}
            creatorIsMe={statusChange.task.creator_id === me.user.id}
            initial={statusChange.next.value}
            onDone={(msg) => {
              setNotice(msg)
              setStatusChange(null)
            }}
          />
        </Dialog>
      )}
    </>
  )
}

// Menu "Thao tác" của một dòng: các bước máy chủ cho phép; "Hủy công việc" màu đỏ, đứng cuối.
function ActionMenu({ task, onPick }: { task: Task; onPick: (t: Transition) => void }) {
  const [open, setOpen] = useState(false)
  return (
    <div className="relative inline-block text-left">
      <Button variant="secondary" className="h-8 px-3" aria-haspopup="menu" aria-expanded={open} onClick={() => setOpen(!open)}>
        Thao tác
      </Button>
      {open && (
        <div role="menu" className="absolute right-0 z-10 mt-1 w-56 rounded-lg border border-surface-container-highest bg-surface py-1 shadow-lg" onMouseLeave={() => setOpen(false)}>
          {task.allowed.map((a) => (
            <button
              key={a.value}
              type="button"
              role="menuitem"
              onClick={() => {
                setOpen(false)
                onPick(a)
              }}
              className={`block w-full px-3 py-1.5 text-left text-sm hover:bg-surface-container-low ${a.value === 'canceled' ? 'mt-1 border-t border-surface-container-highest text-error' : ''}`}
            >
              {a.label}
            </button>
          ))}
          {task.allowed.length === 0 && <p className="px-3 py-1.5 text-xs text-on-surface-variant">Không có thao tác khả dụng</p>}
        </div>
      )}
    </div>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs text-on-surface-variant">{label}</dt>
      <dd className="text-sm break-words text-on-surface">{value}</dd>
    </div>
  )
}

// Nội dung chi tiết công việc (dùng cho hộp xem nhanh và trang đầy đủ).
export function TaskDetail({ id, onDone }: { id: string; onDone: (message: string) => void }) {
  const q = useQuery({ queryKey: ['tasks', 'detail', id], queryFn: () => tasksApi.get(id) })
  if (q.isPending) return <p className="text-sm text-on-surface-variant">Đang tải…</p>
  if (q.isError) return <Alert>{errorMessage(q.error)}</Alert>
  const t = q.data
  return (
    <div className="space-y-4" data-testid="task-detail">
      <div>
        <p className="text-xs text-on-surface-variant">Công việc #{t.no}</p>
        <h2 className="text-lg font-semibold">{t.title}</h2>
        <div className="mt-1 flex flex-wrap items-center gap-2">
          <StatusBadge status={t.status} label={t.status_label} />
          {t.slot_label && <Chip>{t.slot_label}</Chip>}
        </div>
      </div>
      <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <Row label="Người nhận" value={t.assignee ?? 'Chưa phân công'} />
        <Row label="Người giao" value={t.creator || '—'} />
        {t.handed_back_at && <Row label="Chuyển lại từ" value={`${t.handed_back_from ?? '—'} · ${formatDateTime(t.handed_back_at)}`} />}
        <Row label="Hạn hoàn thành" value={formatDate(t.due_date) + (t.due_time ? ` · ${t.due_time}` : '')} />
        <Row label="Loại" value={t.type_label} />
        <Row label="Chi nhánh" value={t.branch ?? '—'} />
        {t.class_label && <Row label="Lớp" value={t.class_label} />}
        {!t.class_label && t.lesson_session && <Row label="Buổi học" value={t.lesson_session} />}
        {t.confirmed_by && <Row label="Xác nhận bởi" value={`${t.confirmed_by}${t.confirmed_at ? ' · ' + formatDateTime(t.confirmed_at) : ''}`} />}
      </dl>
      {t.description && (
        <div className="rounded-lg bg-surface-container-low p-3">
          <p className="mb-1 text-xs font-medium text-on-surface-variant uppercase">Mô tả</p>
          <p className="text-sm whitespace-pre-line">{t.description}</p>
        </div>
      )}
      {t.blocked_reason && (
        <Alert>
          <strong>Lý do bị chặn:</strong> <span className="whitespace-pre-line">{t.blocked_reason}</span>
        </Alert>
      )}
      {t.rejection_reason && (
        <Alert tone="info">
          <strong>Lý do hủy / trả về:</strong> <span className="whitespace-pre-line">{t.rejection_reason}</span>
        </Alert>
      )}
      {t.completion_note && (
        <Alert tone="info">
          <strong>Ghi chú / kết quả:</strong> <span className="whitespace-pre-line">{t.completion_note}</span>
        </Alert>
      )}
      {t.completion_proof_url && (
        <a href={t.completion_proof_url} target="_blank" rel="noopener noreferrer" className="block truncate text-sm text-primary hover:underline">
          Ảnh minh chứng: {t.completion_proof_url}
        </a>
      )}
      <StatusForm key={t.version} task={t} creatorIsMe={t.creator_is_me ?? false} onDone={onDone} />
    </div>
  )
}

function TaskDetailPage({ id }: { me: Me; id: string }) {
  const [notice, setNotice] = useState('')
  return (
    <div className="mx-auto max-w-2xl space-y-4">
      <Button variant="secondary" onClick={() => navigate('/tasks')}>
        ← Danh sách đầu việc
      </Button>
      {notice && <Alert tone="success">{notice}</Alert>}
      <Card>
        <TaskDetail id={id} onDone={setNotice} />
      </Card>
    </div>
  )
}

