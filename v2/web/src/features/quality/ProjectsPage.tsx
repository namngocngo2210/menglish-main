// Dự án soạn sách & chương trình (AcademicProjects/Index.vue, Form.vue, Show.vue). /academic-projects: danh sách (người
// quản lý thấy mọi dự án, thành viên chỉ thấy dự án mình tham gia); /academic-projects/<id>: chi tiết — tiến độ, mốc &
// deadline, báo cáo tiến độ (link sản phẩm, khó khăn, phản hồi), họp thống nhất, thành viên, biên bản trễ deadline.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent, type ReactNode } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Checkbox, Dialog, Field, Icon, Select } from '../../components/ui'
import { navigate, usePath } from '../../router'
import { EmptyState, PageHeader, Pager, StatCard } from '../platform/parts'
import { projectsApi, type Milestone, type Option, type ProgressUpdate, type ProjectDetail, type ProjectFilter } from './api'
import { ConfirmDialog, FilterSelect, Panel, ProgressBar, Table, Textarea } from './components'
import {
  DEADLINE_BADGE,
  MILESTONE_TONES,
  PENALTY_TONES,
  badgeTone,
  daysLeft,
  deadlineHint,
  filterStaff,
  formatDate,
  formatDateTime,
  isOpenStatus,
  linkHost,
  milestoneForm,
  money,
  projectForm,
  shortDateTime,
  targetOf,
  toMilestoneInput,
  toProjectInput,
  toggleMember,
  updatableMilestones,
} from './logic'

// Đường dẫn /academic-projects/<id> → id (bỏ qua /academic-projects/report).
export function projectIdOf(path: string): string | null {
  const m = /^\/academic-projects\/([^/]+)\/?$/.exec(path)
  return m && m[1] !== 'report' ? m[1] : null
}

export function ProjectsPage({ me }: { me: Me }) {
  const id = projectIdOf(usePath())
  return id ? <ProjectDetailPage key={id} id={id} me={me} /> : <ProjectListPage />
}

function ProjectListPage() {
  const [filter, setFilter] = useState<ProjectFilter>({ search: '', type: '', status: '', owner_id: '', page: 1 })
  const [q, setQ] = useState('')
  const [creating, setCreating] = useState(false)
  const page = useQuery({
    queryKey: ['academic-projects', 'list', filter],
    queryFn: () => projectsApi.list(filter),
    placeholderData: keepPreviousData,
  })
  const d = page.data
  const createButton = d?.can_manage && (
    <Button onClick={() => setCreating(true)}>
      <Icon name="add" className="mr-1 text-[18px]" /> Tạo dự án
    </Button>
  )

  return (
    <div className="space-y-6">
      <PageHeader
        title="Dự án soạn sách & chương trình"
        description="Kế hoạch, mốc deadline, khối lượng hoàn thành và báo cáo tiến độ của từng dự án học thuật."
        actions={createButton}
      />
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {d && (
        <>
          <form
            className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_180px_180px_220px_auto] lg:items-end"
            onSubmit={(e) => {
              e.preventDefault()
              setFilter({ ...filter, search: q, page: 1 })
            }}
          >
            <Field label="Tìm kiếm" placeholder="Tìm mã, tên dự án…" value={q} onChange={(e) => setQ(e.target.value)} />
            <FilterSelect label="Loại" all="Tất cả loại" options={d.types} value={filter.type} onChange={(e) => setFilter({ ...filter, type: e.target.value, page: 1 })} />
            <FilterSelect
              label="Trạng thái"
              all="Tất cả trạng thái"
              options={d.statuses}
              value={filter.status}
              onChange={(e) => setFilter({ ...filter, status: e.target.value, page: 1 })}
            />
            <FilterSelect
              label="Phụ trách"
              all="Tất cả người phụ trách"
              options={d.owners}
              value={filter.owner_id}
              onChange={(e) => setFilter({ ...filter, owner_id: e.target.value, page: 1 })}
            />
            <Button type="submit" variant="secondary">
              Lọc
            </Button>
          </form>
          <Panel>
            <Table
              minWidth={980}
              head={
                <tr>
                  <th>Dự án</th>
                  <th>Phụ trách</th>
                  <th>Thời gian</th>
                  <th>Tiến độ</th>
                  <th>Mốc tiếp theo</th>
                  <th>Trạng thái</th>
                </tr>
              }
            >
              {d.data.map((p) => (
                <tr key={p.id} className="cursor-pointer hover:bg-surface-container-low" onClick={() => navigate(`/academic-projects/${p.id}`)}>
                  <td>
                    <a
                      href={`/academic-projects/${p.id}`}
                      className="font-semibold hover:text-primary"
                      onClick={(e) => {
                        e.preventDefault()
                        e.stopPropagation()
                        navigate(`/academic-projects/${p.id}`)
                      }}
                    >
                      {p.name}
                    </a>
                    <span className="block text-xs text-on-surface-variant">
                      <span className="font-mono">{p.code}</span> · {p.type_label} · {p.members_count} thành viên
                    </span>
                  </td>
                  <td>{p.owner ?? '—'}</td>
                  <td className="font-mono text-xs whitespace-nowrap">
                    {formatDate(p.start_date)} → {formatDate(p.deadline)}
                  </td>
                  <td>
                    <ProgressBar value={p.progress} overdue={p.milestones_overdue > 0} label={`${p.progress}% · ${p.milestones_done}/${p.milestones_total} mốc`} />
                    {p.milestones_overdue > 0 && <span className="mt-0.5 block text-xs font-semibold text-error">{p.milestones_overdue} mốc trễ hạn</span>}
                  </td>
                  <td>
                    {p.next_milestone ? (
                      <>
                        <span className="block max-w-[220px] truncate">{p.next_milestone.title}</span>
                        <span className="font-mono text-xs text-on-surface-variant">Hạn {formatDate(p.next_milestone.due_date)}</span>
                      </>
                    ) : (
                      <span className="text-on-surface-subtle">—</span>
                    )}
                  </td>
                  <td>
                    <Badge tone={badgeTone(p.status_color)}>{p.status_label}</Badge>
                  </td>
                </tr>
              ))}
              {d.data.length === 0 && (
                <tr>
                  <td colSpan={6}>
                    <EmptyState
                      icon="auto_stories"
                      title="Chưa có dự án nào"
                      description={d.can_manage ? 'Tạo dự án sau buổi họp thống nhất, rồi thêm mốc và chốt tiến độ.' : 'Bạn chưa tham gia dự án học thuật nào.'}
                    />
                  </td>
                </tr>
              )}
            </Table>
            <Pager page={d.meta.page} perPage={d.meta.per_page} total={d.meta.total} unit="dự án" onPage={(p) => setFilter({ ...filter, page: p })} />
          </Panel>
        </>
      )}
      {creating && d && (
        <ProjectDialog
          project={null}
          types={d.types}
          staff={d.owners}
          onClose={() => setCreating(false)}
          onCreated={(newId) => navigate(`/academic-projects/${newId}`)}
          onDone={() => setCreating(false)}
        />
      )}
    </div>
  )
}

function ProjectDialog({
  project,
  types,
  staff,
  onClose,
  onDone,
  onCreated,
}: {
  project: ProjectDetail['project'] | null
  types: Option[]
  staff: Option[]
  onClose: () => void
  onDone: (msg: string) => void
  onCreated?: (id: string) => void
}) {
  const qc = useQueryClient()
  const [f, setF] = useState(() => projectForm(project))
  const [search, setSearch] = useState('')
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value })
  const shown = filterStaff(staff, search)
  const save = useMutation({
    mutationFn: async () => {
      if (project) return { ...(await projectsApi.update(project.id, toProjectInput(f))), id: project.id }
      return projectsApi.create(toProjectInput(f))
    },
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['academic-projects'] })
      if (!project && onCreated) onCreated(r.id)
      onDone(r.message)
    },
  })
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate()
  }
  return (
    <Dialog title={project ? `Sửa dự án ${project.code}` : 'Tạo dự án học thuật'} onClose={onClose} wide>
      <p className="mb-4 text-xs text-on-surface-variant">Sau khi tạo, thêm các mốc (deadline, khối lượng, người nhận) rồi bấm “Chốt tiến độ”.</p>
      <form className="space-y-6" onSubmit={submit}>
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <section className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="sm:col-span-2">
              <Field label="Tên dự án" required maxLength={255} value={f.name} onChange={set('name')} placeholder="VD: Soạn sách Tiếng Anh lớp 6" />
            </div>
            <FilterSelect label="Loại dự án" required options={types} value={f.type} onChange={set('type')} />
          </div>
          <div className="grid gap-4 sm:grid-cols-3">
            <FilterSelect label="Người phụ trách" required all="-- Chọn người phụ trách --" options={staff} value={f.owner_id} onChange={set('owner_id')} />
            <Field label="Ngày bắt đầu" type="date" value={f.start_date} onChange={set('start_date')} />
            <Field label="Deadline dự án" type="date" value={f.deadline} onChange={set('deadline')} />
          </div>
          <Textarea label="Mục tiêu / phạm vi" rows={2} value={f.description} onChange={set('description')} placeholder="VD: 12 unit, mỗi unit 8 trang, kèm audio và đáp án" />
        </section>
        <section className="space-y-2">
          <div className="flex flex-wrap items-end justify-between gap-2">
            <div>
              <h3 className="font-semibold">Thành viên tham gia</h3>
              <p className="text-xs text-on-surface-variant">Đã chọn {f.member_ids.length} người. Người phụ trách và người nhận mốc tự được thêm.</p>
            </div>
            <input
              className="block h-10 w-full rounded-lg border border-outline-variant px-3 text-sm outline-none focus:border-primary sm:w-56"
              placeholder="Gõ để tìm…"
              aria-label="Tìm thành viên"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          <div className="grid max-h-56 gap-1 overflow-y-auto rounded-lg border border-outline-variant p-2 sm:grid-cols-2">
            {shown.map((s) => (
              <Checkbox key={s.value} label={s.label} checked={f.member_ids.includes(s.value)} onChange={() => setF({ ...f, member_ids: toggleMember(f.member_ids, s.value) })} />
            ))}
            {shown.length === 0 && <p className="col-span-full py-2 text-center text-xs text-on-surface-variant">Không tìm thấy</p>}
          </div>
        </section>
        <section className="space-y-4">
          <h3 className="font-semibold">Họp thống nhất</h3>
          <Textarea
            label="Nội dung đã thống nhất"
            rows={4}
            value={f.kickoff_notes}
            onChange={set('kickoff_notes')}
            placeholder="Phân công, chuẩn biên soạn, cách nghiệm thu, lịch họp định kỳ…"
          />
          <Field label="Link biên bản họp" type="url" value={f.kickoff_link} onChange={set('kickoff_link')} placeholder="https://drive.google.com/…" />
        </section>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            {project ? 'Lưu dự án' : 'Tạo dự án'}
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

type Act = { title: string; body: ReactNode; confirm: string; danger?: boolean; run: () => Promise<{ message: string }>; after?: () => void }

function ProjectDetailPage({ id }: { id: string; me: Me }) {
  const qc = useQueryClient()
  const [notice, setNotice] = useState('')
  const [editing, setEditing] = useState(false)
  const [milestone, setMilestone] = useState<Milestone | 'new' | null>(null)
  const [updating, setUpdating] = useState<Milestone | 'any' | null>(null)
  const [responding, setResponding] = useState<ProgressUpdate | null>(null)
  const [act, setAct] = useState<Act | null>(null)
  const detail = useQuery({ queryKey: ['academic-projects', 'detail', id], queryFn: () => projectsApi.get(id) })
  const resume = useMutation({ mutationFn: () => projectsApi.status(id, 'resume'), onSuccess: (r) => done(r.message) })
  const d = detail.data
  const done = (msg: string) => {
    setNotice(msg)
    setEditing(false)
    setMilestone(null)
    setUpdating(null)
    setResponding(null)
    setAct(null)
    qc.invalidateQueries({ queryKey: ['academic-projects'] })
  }
  const back = (
    <a
      href="/academic-projects"
      className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary"
      onClick={(e) => {
        e.preventDefault()
        navigate('/academic-projects')
      }}
    >
      <Icon name="arrow_back" className="text-[18px]" /> Dự án soạn sách & chương trình
    </a>
  )
  if (!d) {
    return (
      <div className="space-y-4">
        {back}
        {detail.isError && <Alert>{errorMessage(detail.error)}</Alert>}
      </div>
    )
  }

  const p = d.project
  const open = isOpenStatus(p.status)
  const days = daysLeft(p.deadline, new Date())
  const status = (action: string, title: string, body: string, confirm: string, danger = false) =>
    setAct({ title, body, confirm, danger, run: () => projectsApi.status(id, action) })

  return (
    <div className="space-y-6">
      {back}
      <PageHeader
        title={p.name}
        actions={
          <>
            {d.can_update && (
              <Button onClick={() => setUpdating('any')}>
                <Icon name="edit_note" className="mr-1 text-[18px]" /> Cập nhật tiến độ
              </Button>
            )}
            {d.can_manage && (
              <>
                {p.status === 'planning' && (
                  <Button
                    variant="secondary"
                    onClick={() =>
                      setAct({
                        title: 'Chốt tiến độ',
                        body: 'Chốt tiến độ dự án? Từ lúc chốt, mốc chưa hoàn thành khi quá deadline sẽ tự lập biên bản chờ giải trình.',
                        confirm: 'Chốt tiến độ',
                        run: () => projectsApi.lock(id),
                      })
                    }
                  >
                    <Icon name="lock_clock" className="mr-1 text-[18px]" /> Chốt tiến độ
                  </Button>
                )}
                <Button variant="secondary" onClick={() => setEditing(true)}>
                  <Icon name="edit" className="mr-1 text-[18px]" /> Sửa dự án
                </Button>
              </>
            )}
          </>
        }
      />
      {d.can_manage && open && (
        <div className="-mt-4 flex flex-wrap justify-end gap-2">
          {p.status === 'active' && (
            <Button variant="secondary" className="h-9" onClick={() => status('pause', 'Tạm dừng dự án', 'Tạm dừng dự án? Khi tạm dừng, mốc không bị tính trễ.', 'Tạm dừng')}>
              <Icon name="pause_circle" className="mr-1 text-[18px]" /> Tạm dừng
            </Button>
          )}
          {p.status === 'paused' && (
            <Button variant="secondary" className="h-9" disabled={resume.isPending} onClick={() => resume.mutate()}>
              <Icon name="play_circle" className="mr-1 text-[18px]" /> Tiếp tục thực hiện
            </Button>
          )}
          {(p.status === 'active' || p.status === 'paused') && (
            <Button
              variant="secondary"
              className="h-9"
              onClick={() => status('complete', 'Hoàn thành dự án', 'Đóng dự án: hoàn thành? Thành viên sẽ không cập nhật tiến độ được nữa.', 'Hoàn thành')}
            >
              <Icon name="task_alt" className="mr-1 text-[18px]" /> Hoàn thành dự án
            </Button>
          )}
          <Button variant="danger" className="h-9" onClick={() => status('cancel', 'Hủy dự án', 'Hủy dự án này?', 'Hủy dự án', true)}>
            <Icon name="block" className="mr-1 text-[18px]" /> Hủy dự án
          </Button>
          <Button
            variant="danger"
            className="h-9"
            onClick={() =>
              setAct({
                title: 'Xóa dự án',
                body: `Xóa dự án ${p.code}? Dữ liệu được giữ trong thùng rác (xóa mềm).`,
                confirm: 'Xóa',
                danger: true,
                run: () => projectsApi.remove(id),
                after: () => navigate('/academic-projects'),
              })
            }
          >
            <Icon name="delete" className="mr-1 text-[18px]" /> Xóa dự án
          </Button>
        </div>
      )}

      <div className="flex flex-wrap items-center gap-2">
        <Badge tone={badgeTone(p.status_color)}>{p.status_label}</Badge>
        <Badge>{p.type_label}</Badge>
        <span className="font-mono text-sm text-on-surface-variant">{p.code}</span>
      </div>

      {notice && <Alert tone="success">{notice}</Alert>}
      {resume.isError && <Alert>{errorMessage(resume.error)}</Alert>}
      {p.status === 'planning' && (
        <Alert tone="info">
          <strong>Đang lên kế hoạch.</strong> Sau buổi họp thống nhất, thêm đủ các mốc (deadline, khối lượng, người nhận) rồi bấm “Chốt tiến độ”. Mốc chỉ bị tính trễ
          sau khi chốt.
        </Alert>
      )}
      {p.status === 'active' && d.sla_enabled && (
        <Alert tone="info">
          Đã chốt tiến độ {formatDateTime(p.plan_locked_at)}
          {p.locker && ` (${p.locker})`}. Mốc chưa hoàn thành sau 23:59 ngày deadline
          {d.grace_hours > 0 && ` + ${d.grace_hours} giờ ân hạn`} sẽ tự lập biên bản chờ giải trình cho người nhận mốc.
        </Alert>
      )}
      {p.status === 'paused' && <Alert tone="info">Dự án đang tạm dừng: mốc không bị tính trễ cho đến khi tiếp tục.</Alert>}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Tiến độ dự án" value={`${p.progress}%`} icon="donut_large" tone="primary" />
        <StatCard label="Mốc hoàn thành" value={`${p.milestones_done}/${p.milestones_total}`} icon="flag" tone="success" />
        <StatCard label="Mốc trễ hạn" value={p.milestones_overdue} icon="alarm" tone={p.milestones_overdue ? 'error' : 'neutral'} />
        <StatCard
          label="Deadline dự án"
          value={
            <>
              {formatDate(p.deadline)}
              {deadlineHint(days, open) && <span className="block text-xs font-normal text-on-surface-variant">{deadlineHint(days, open)}</span>}
            </>
          }
          icon="event"
          tone={days !== null && days < 0 && open ? 'error' : 'neutral'}
        />
      </div>

      <MilestonesSection
        d={d}
        open={open}
        onAdd={() => setMilestone('new')}
        onEdit={setMilestone}
        onUpdate={setUpdating}
        onDelete={(m) =>
          setAct({ title: 'Xóa mốc', body: `Xóa mốc “${m.title}”?`, confirm: 'Xóa', danger: true, run: () => projectsApi.deleteMilestone(m.id) })
        }
      />

      <div className="grid gap-6 xl:grid-cols-3">
        <div className="xl:col-span-2">
          <Panel title="Báo cáo tiến độ" actions={<span className="text-xs text-on-surface-variant">{d.updates.length} lần cập nhật</span>}>
            <ol className="divide-y divide-surface-container-highest">
              {d.updates.map((u) => (
                <li key={u.id} className="space-y-2 px-4 py-4">
                  <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span className="font-semibold">{u.user ?? '—'}</span>
                    <span className="font-mono text-xs text-on-surface-variant">{formatDateTime(u.created_at)}</span>
                    {u.milestone && <Badge>{u.milestone}</Badge>}
                    {u.quantity && <Badge tone="info">Đã xong {u.quantity}</Badge>}
                    {u.marks_complete && <Badge tone="success">Báo hoàn thành mốc</Badge>}
                  </div>
                  <p className="text-sm whitespace-pre-line">{u.content}</p>
                  {u.links.length > 0 && (
                    <ul className="flex flex-wrap gap-2">
                      {u.links.map((link) => (
                        <li key={link}>
                          <a
                            href={link}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-1 rounded-full bg-surface-container-low px-2 py-0.5 text-xs text-primary hover:underline"
                          >
                            <Icon name="link" className="text-[16px]" />
                            {linkHost(link)}
                          </a>
                        </li>
                      ))}
                    </ul>
                  )}
                  {u.difficulties && (
                    <div className="rounded-lg bg-warning-container px-3 py-2">
                      <p className="text-xs font-semibold text-warning uppercase">Khó khăn / cần hỗ trợ</p>
                      <p className="text-sm whitespace-pre-line">{u.difficulties}</p>
                    </div>
                  )}
                  {u.response ? (
                    <div className="rounded-lg bg-info-container px-3 py-2">
                      <p className="text-xs font-semibold text-on-info-container uppercase">
                        Phản hồi · {u.responder} · {shortDateTime(u.responded_at)}
                      </p>
                      <p className="text-sm whitespace-pre-line">{u.response}</p>
                    </div>
                  ) : (
                    d.can_respond && (
                      <Button variant="secondary" className="h-8 px-2" onClick={() => setResponding(u)}>
                        <Icon name="reply" className="mr-1 text-[16px]" /> Phản hồi
                      </Button>
                    )
                  )}
                </li>
              ))}
              {d.updates.length === 0 && (
                <li>
                  <EmptyState
                    icon="edit_note"
                    title="Chưa có cập nhật tiến độ"
                    description="Thành viên bấm “Cập nhật tiến độ” để báo việc đã làm, khối lượng xong, link sản phẩm và khó khăn."
                  />
                </li>
              )}
            </ol>
          </Panel>
        </div>
        <aside className="space-y-6">
          <section className="space-y-4 rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm">
            <h2 className="text-base font-semibold">Thông tin dự án</h2>
            <dl className="grid grid-cols-2 gap-4 text-sm">
              <Info label="Người phụ trách">
                <span className="font-semibold">{p.owner ?? '—'}</span>
              </Info>
              <Info label="Người tạo">{p.creator ?? '—'}</Info>
              <Info label="Bắt đầu">
                <span className="font-mono">{formatDate(p.start_date)}</span>
              </Info>
              <Info label="Deadline">
                <span className="font-mono">{formatDate(p.deadline)}</span>
              </Info>
            </dl>
            {p.description && (
              <div>
                <p className="text-xs text-on-surface-variant">Mục tiêu / phạm vi</p>
                <p className="text-sm whitespace-pre-line">{p.description}</p>
              </div>
            )}
            <div>
              <p className="mb-1 text-xs text-on-surface-variant">Thành viên ({p.members.length})</p>
              <ul className="flex flex-wrap gap-1">
                {p.members.map((m) => (
                  <li key={m.id} className="rounded-full bg-surface-container-low px-2 py-0.5 text-xs">
                    {m.name}
                  </li>
                ))}
              </ul>
            </div>
          </section>
          <section className="space-y-2 rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm">
            <h2 className="text-base font-semibold">Họp thống nhất</h2>
            {p.kickoff_notes ? (
              <p className="text-sm whitespace-pre-line">{p.kickoff_notes}</p>
            ) : (
              <p className="text-xs text-on-surface-variant">Chưa ghi nội dung họp.</p>
            )}
            {p.kickoff_link && (
              <a href={p.kickoff_link} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-sm text-primary hover:underline">
                <Icon name="description" className="text-[18px]" />
                Biên bản họp
              </a>
            )}
          </section>
          <section className="space-y-2 rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm">
            <h2 className="text-base font-semibold">Biên bản trễ deadline</h2>
            {d.penalties.length > 0 ? (
              <ul className="divide-y divide-surface-container-highest">
                {d.penalties.map((x) => (
                  <li key={x.id} className="py-2 text-sm">
                    <a href={x.url} className="font-mono font-semibold text-primary hover:underline">
                      {x.code}
                    </a>{' '}
                    <Badge tone={PENALTY_TONES[x.status] ?? 'neutral'}>{x.status_label}</Badge>
                    <span className="block text-xs text-on-surface-variant">
                      {x.user} · mốc “{x.milestone}”{x.amount > 0 && ` · ${money(x.amount)}`}
                    </span>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-xs text-on-surface-variant">
                {d.penalties_pending ? 'Biên bản trễ deadline sẽ hiện khi module Lương & Phạt được gộp.' : 'Chưa có biên bản nào.'}
              </p>
            )}
          </section>
        </aside>
      </div>

      {editing && <ProjectDialog project={p} types={d.types} staff={d.staff} onClose={() => setEditing(false)} onDone={done} />}
      {milestone && <MilestoneDialog projectId={id} m={milestone === 'new' ? null : milestone} d={d} onClose={() => setMilestone(null)} onDone={done} />}
      {updating && <UpdateDialog projectId={id} first={updating === 'any' ? null : updating} d={d} onClose={() => setUpdating(null)} onDone={done} />}
      {responding && <RespondDialog u={responding} onClose={() => setResponding(null)} onDone={done} />}
      {act && (
        <ConfirmDialog
          title={act.title}
          confirm={act.confirm}
          danger={act.danger}
          action={act.run}
          onClose={() => setAct(null)}
          onDone={(msg) => {
            done(msg)
            act.after?.()
          }}
        >
          {act.body}
        </ConfirmDialog>
      )}
    </div>
  )
}

function Info({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-xs text-on-surface-variant">{label}</dt>
      <dd>{children}</dd>
    </div>
  )
}

function MilestonesSection({
  d,
  open,
  onAdd,
  onEdit,
  onUpdate,
  onDelete,
}: {
  d: ProjectDetail
  open: boolean
  onAdd: () => void
  onEdit: (m: Milestone) => void
  onUpdate: (m: Milestone) => void
  onDelete: (m: Milestone) => void
}) {
  const manage = d.can_manage && open
  const canUpdate = (m: Milestone) => d.can_update && m.can_update && m.status !== 'done'
  return (
    <Panel
      title="Mốc tiến độ & deadline"
      actions={
        manage && (
          <Button variant="secondary" className="h-9" onClick={onAdd}>
            <Icon name="add" className="mr-1 text-[18px]" /> Thêm mốc
          </Button>
        )
      }
    >
      <Table
        minWidth={860}
        head={
          <tr>
            <th>Mốc</th>
            <th>Người nhận</th>
            <th>Deadline</th>
            <th>Khối lượng</th>
            <th>Trạng thái</th>
            <th className="text-right">Thao tác</th>
          </tr>
        }
      >
        {d.milestones.map((m) => (
          <tr key={m.id} className={m.mine ? 'bg-primary-light/60' : ''}>
            <td className="max-w-[360px] min-w-[220px]">
              <span className="font-semibold">{m.title}</span>
              {m.description && <span className="line-clamp-2 block text-xs text-on-surface-variant">{m.description}</span>}
            </td>
            <td>
              {m.assignee ?? '—'}
              {m.mine && <span className="block text-xs font-semibold text-primary">Việc của bạn</span>}
            </td>
            <td className="whitespace-nowrap">
              <span className="font-mono">{formatDate(m.due_date)}</span>
              {m.deadline_state && (
                <span className="mt-0.5 block">
                  <Badge tone={DEADLINE_BADGE[m.deadline_state].tone}>{DEADLINE_BADGE[m.deadline_state].label}</Badge>
                </span>
              )}
            </td>
            <td>
              <ProgressBar value={m.progress} overdue={m.deadline_state === 'overdue'} label={m.quantity_label} />
            </td>
            <td>
              <Badge tone={MILESTONE_TONES[m.status] ?? 'neutral'}>{m.status_label}</Badge>
              {m.completed_at && <span className="mt-0.5 block font-mono text-xs text-on-surface-variant">{shortDateTime(m.completed_at)}</span>}
            </td>
            <td className="text-right whitespace-nowrap">
              <span className="inline-flex gap-1">
                {canUpdate(m) && (
                  <Button variant="secondary" className="h-8 px-2" onClick={() => onUpdate(m)}>
                    Cập nhật
                  </Button>
                )}
                {manage && (
                  <>
                    <Button variant="secondary" className="h-8 px-2" aria-label={`Sửa mốc ${m.title}`} onClick={() => onEdit(m)}>
                      <Icon name="edit" className="text-[18px]" />
                    </Button>
                    <Button variant="danger" className="h-8 px-2" aria-label={`Xóa mốc ${m.title}`} onClick={() => onDelete(m)}>
                      <Icon name="delete" className="text-[18px]" />
                    </Button>
                  </>
                )}
              </span>
            </td>
          </tr>
        ))}
        {d.milestones.length === 0 && (
          <tr>
            <td colSpan={6}>
              <EmptyState
                icon="flag"
                title="Chưa có mốc nào"
                description={d.can_manage ? 'Chia dự án thành các mốc: deadline, khối lượng (unit, trang…) và người nhận.' : 'Người quản lý dự án chưa thêm mốc.'}
              />
            </td>
          </tr>
        )}
      </Table>
    </Panel>
  )
}

function MilestoneDialog({ projectId, m, d, onClose, onDone }: { projectId: string; m: Milestone | null; d: ProjectDetail; onClose: () => void; onDone: (msg: string) => void }) {
  const [f, setF] = useState(() => milestoneForm(m))
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value })
  const save = useMutation({
    mutationFn: () => (m ? projectsApi.updateMilestone(m.id, toMilestoneInput(f, true)) : projectsApi.createMilestone(projectId, toMilestoneInput(f, false))),
    onSuccess: (r) => onDone(r.message),
  })
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate()
  }
  return (
    <Dialog title={m ? 'Sửa mốc' : 'Thêm mốc'} onClose={onClose} wide>
      <form className="space-y-4" onSubmit={submit}>
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <Field label="Tên mốc" required maxLength={255} value={f.title} onChange={set('title')} placeholder="VD: Hoàn thành bản thảo Unit 1–4" />
        <div className="grid gap-4 sm:grid-cols-2">
          <FilterSelect label="Người nhận" all="-- Chọn người nhận --" options={d.staff} value={f.assignee_id} onChange={set('assignee_id')} />
          {m && <FilterSelect label="Trạng thái" options={d.milestone_statuses} value={f.status} onChange={set('status')} />}
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Bắt đầu" type="date" value={f.start_date} onChange={set('start_date')} />
          <Field label="Deadline" type="date" required value={f.due_date} onChange={set('due_date')} />
        </div>
        <div className="grid grid-cols-2 gap-4">
          <Field label="Khối lượng cần làm" type="number" step="0.01" min="0.01" required value={f.target_quantity} onChange={set('target_quantity')} />
          <Field label="Đơn vị" required maxLength={30} value={f.unit} onChange={set('unit')} placeholder="unit, trang, bài…" />
        </div>
        <Textarea label="Yêu cầu / tiêu chí nghiệm thu" value={f.description} onChange={set('description')} />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            Lưu mốc
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function UpdateDialog({ projectId, first, d, onClose, onDone }: { projectId: string; first: Milestone | null; d: ProjectDetail; onClose: () => void; onDone: (msg: string) => void }) {
  const updatable = updatableMilestones(d.milestones)
  const initial = first ?? (updatable.length === 1 ? updatable[0] : null)
  const [f, setF] = useState({
    milestone_id: initial?.id ?? '',
    quantity_done: initial ? String(initial.done_quantity) : '',
    content: '',
    difficulties: '',
    links_text: '',
    marks_complete: false,
  })
  const chosen = d.milestones.find((m) => m.id === f.milestone_id)
  const set = (k: 'quantity_done' | 'content' | 'difficulties' | 'links_text') => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value })
  const save = useMutation({
    mutationFn: () => projectsApi.postUpdate(projectId, { ...f, marks_complete: chosen ? f.marks_complete : false }),
    onSuccess: (r) => onDone(r.message),
  })
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate()
  }
  return (
    <Dialog title="Cập nhật tiến độ" onClose={onClose} wide>
      <form className="space-y-4" onSubmit={submit}>
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <Select
          label="Mốc"
          value={f.milestone_id}
          onChange={(e) => {
            const m = d.milestones.find((x) => x.id === e.target.value)
            setF({ ...f, milestone_id: e.target.value, quantity_done: m ? String(m.done_quantity) : '', marks_complete: false })
          }}
        >
          <option value="">Báo cáo chung (không gắn mốc)</option>
          {updatable.map((m) => (
            <option key={m.id} value={m.id}>
              {m.title} · hạn {formatDate(m.due_date)}
            </option>
          ))}
        </Select>
        {chosen && (
          <div className="grid gap-4 sm:grid-cols-2">
            <Field
              label="Khối lượng đã xong đến nay"
              type="number"
              step="0.01"
              min="0"
              value={f.quantity_done}
              onChange={set('quantity_done')}
              hint={`Cần làm ${targetOf(chosen.quantity_label)}`}
            />
            <div className="flex items-end pb-2">
              <Checkbox label="Đã hoàn thành mốc này" checked={f.marks_complete} onChange={(e) => setF({ ...f, marks_complete: e.target.checked })} />
            </div>
          </div>
        )}
        <Textarea label="Đã làm được gì" required value={f.content} onChange={set('content')} placeholder="VD: Xong bản thảo Unit 3, đang soạn bài tập Unit 4" />
        <Textarea
          label="Link sản phẩm đã làm"
          rows={2}
          value={f.links_text}
          onChange={set('links_text')}
          hint="Mỗi dòng một link (Google Drive, Docs…), tối đa 5"
          placeholder="https://drive.google.com/…"
        />
        <Textarea label="Khó khăn / cần hỗ trợ" rows={2} value={f.difficulties} onChange={set('difficulties')} hint="Để trống nếu không có" />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            <Icon name="send" className="mr-1 text-[18px]" /> Gửi cập nhật
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function RespondDialog({ u, onClose, onDone }: { u: ProgressUpdate; onClose: () => void; onDone: (msg: string) => void }) {
  const [text, setText] = useState('')
  const save = useMutation({ mutationFn: () => projectsApi.respond(u.id, text), onSuccess: (r) => onDone(r.message) })
  return (
    <Dialog title="Phản hồi cập nhật" onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <p className="text-xs text-on-surface-variant">
          {u.user} · {formatDateTime(u.created_at)}
        </p>
        {u.difficulties && <p className="rounded-lg bg-warning-container px-3 py-2 text-sm whitespace-pre-line">{u.difficulties}</p>}
        <Textarea label="Phản hồi / hướng xử lý" required value={text} onChange={(e) => setText(e.target.value)} />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            <Icon name="send" className="mr-1 text-[18px]" /> Gửi phản hồi
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
