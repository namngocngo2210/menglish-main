import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent, type MouseEvent, type ReactNode } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Checkbox, Field, Icon, Select } from '../../components/ui'
import { can } from '../../nav'
import { navigate, usePath } from '../../router'
import { Textarea } from '../crm/components'
import { formatDateTime } from '../platform/format'
import { EmptyState, PageHeader, Pager, StatCard } from '../platform/parts'
import { ticketsApi, type ListParams, type ShowPage, type TicketMessage } from './api'
import { Attachments } from './Attachments'
import { initial, NEW_PATH, newToken, priorityTone, statusTone, ticketPath, ticketRoute, TICKETS_PATH } from './logic'

// Ticket hỗ trợ (SupportTicketController): danh sách /tickets, tạo /tickets/new, trao đổi /tickets/<id hoặc mã>.
export function TicketsPage({ me }: { me: Me }) {
  const r = ticketRoute(usePath())
  if (r.kind === 'new') return <CreatePage me={me} />
  if (r.kind === 'show') return <ShowView me={me} id={r.id} />
  return <ListPage me={me} />
}

function Link({ href, children, className }: { href: string; children: ReactNode; className?: string }) {
  return (
    <a
      href={href}
      className={className ?? 'font-medium text-primary hover:underline'}
      onClick={(e: MouseEvent) => {
        if (e.metaKey || e.ctrlKey) return
        e.preventDefault()
        navigate(href)
      }}
    >
      {children}
    </a>
  )
}

const th = 'px-3 py-2 text-left text-xs font-medium text-on-surface-variant'
const td = 'px-3 py-2 align-top'

function Flash({ text }: { text: string | null }) {
  return text ? <Alert tone="success">{text}</Alert> : null
}

// ───────────── Danh sách ─────────────

function ListPage({ me }: { me: Me }) {
  const [filter, setFilter] = useState<ListParams>({})
  const [search, setSearch] = useState('')
  const q = useQuery({ queryKey: ['tickets', 'list', filter], queryFn: () => ticketsApi.list(filter), placeholderData: keepPreviousData })
  const d = q.data
  return (
    <>
      <PageHeader
        title="Yêu cầu hỗ trợ"
        description="Báo lỗi hệ thống, cơ sở vật chất, học vụ… và trao đổi với bộ phận xử lý."
        actions={
          <>
            {can(me, 'support_ticket.update') && (
              <Button variant="secondary" onClick={() => navigate('/system-config/ticket-emails')}>
                <Icon name="settings" className="mr-1.5 text-[20px]" /> Cấu hình Email nhận
              </Button>
            )}
            {can(me, 'support_ticket.create') && (
              <Button onClick={() => navigate(NEW_PATH)}>
                <Icon name="add_circle" className="mr-1.5 text-[20px]" /> Tạo Ticket Mới
              </Button>
            )}
          </>
        }
      />
      {d && (
        <div className="mb-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
          <StatCard label="Tổng Ticket" value={d.stats.total} icon="inbox" />
          <StatCard label="Mới tiếp nhận" value={d.stats.open} icon="hourglass_top" tone="warning" />
          <StatCard label="Đang xử lý" value={d.stats.in_progress} icon="pending" tone="primary" />
          <StatCard label="Đã giải quyết" value={d.stats.resolved} icon="check_circle" tone="success" />
        </div>
      )}
      <form
        className="mb-4 grid grid-cols-1 items-end gap-3 rounded-xl border border-surface-container-highest bg-surface p-4 sm:grid-cols-[1fr_16rem_auto]"
        onSubmit={(e) => {
          e.preventDefault()
          setFilter({ ...filter, q: search.trim() || undefined, page: 1 })
        }}
      >
        <Field label="Tìm kiếm" placeholder="Tìm theo mã TK, tiêu đề, nội dung..." value={search} onChange={(e) => setSearch(e.target.value)} />
        <Select label="Trạng thái" value={filter.status ?? ''} onChange={(e) => setFilter({ ...filter, status: e.target.value || undefined, page: 1 })}>
          <option value="">Tất cả trạng thái</option>
          {d?.statuses.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
        <Button type="submit" variant="secondary">
          Lọc
        </Button>
      </form>
      {q.error && <Alert>{errorMessage(q.error)}</Alert>}
      <div className="overflow-x-auto rounded-xl border border-surface-container-highest bg-surface shadow-sm">
        <table className="w-full text-sm">
          <thead className="border-b border-surface-container-highest">
            <tr>
              {['Mã Ticket', 'Tiêu đề & Danh mục', 'Mức độ ưu tiên', 'Người tạo', 'Người phụ trách', 'Trạng thái', ''].map((h) => (
                <th key={h} className={th}>
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {d?.tickets.map((t) => (
              <tr key={t.id} className="border-b border-surface-container-highest/60 last:border-0">
                <td className={td}>
                  <span className="rounded-lg bg-primary-light px-2 py-1 font-mono text-xs font-bold text-primary">#{t.code}</span>
                </td>
                <td className={td}>
                  <Link href={ticketPath(t.id)} className="font-semibold text-on-surface hover:text-primary">
                    {t.title}
                  </Link>
                  <div className="text-xs text-on-surface-variant">
                    {t.category_label} · {formatDateTime(t.created_at)}
                  </div>
                </td>
                <td className={td}>
                  <Badge tone={priorityTone(t.priority)}>{t.priority_label}</Badge>
                </td>
                <td className={td}>{t.creator}</td>
                <td className={td}>{t.assignee ? <span className="font-semibold text-primary">{t.assignee}</span> : <span className="text-on-surface-variant italic">Chưa phân công</span>}</td>
                <td className={td}>
                  <Badge tone={statusTone(t.status)}>{t.status_label}</Badge>
                </td>
                <td className={`${td} text-right whitespace-nowrap`}>
                  <Link href={ticketPath(t.id)}>
                    <Icon name="forum" className="mr-1 align-middle text-[18px]" />
                    Trao đổi
                  </Link>
                </td>
              </tr>
            ))}
            {d && d.tickets.length === 0 && (
              <tr>
                <td colSpan={7}>
                  <EmptyState icon="confirmation_number" title="Chưa có ticket nào được tạo." />
                </td>
              </tr>
            )}
          </tbody>
        </table>
        {d && d.total > d.per_page && <Pager page={d.page} perPage={d.per_page} total={d.total} unit="ticket" onPage={(page) => setFilter({ ...filter, page })} />}
      </div>
    </>
  )
}

// ───────────── Tạo ticket ─────────────

function CreatePage({ me }: { me: Me }) {
  const qc = useQueryClient()
  const opts = useQuery({ queryKey: ['tickets', 'form'], queryFn: ticketsApi.form, enabled: can(me, 'support_ticket.create') })
  const [token, setToken] = useState(newToken)
  const [title, setTitle] = useState('')
  const [category, setCategory] = useState('technical_issue')
  const [priority, setPriority] = useState('medium')
  const [assignee, setAssignee] = useState('')
  const [description, setDescription] = useState('')
  const [files, setFiles] = useState<File[]>([])
  const save = useMutation({
    mutationFn: () => ticketsApi.create({ title, category, priority, description, assignee_id: assignee || null, submission_token: token }, files),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['tickets'] })
      setToken(newToken())
      navigate(ticketPath(res.id))
    },
  })
  if (!can(me, 'support_ticket.create')) return <Alert>Bạn không có quyền tạo ticket hỗ trợ.</Alert>
  const o = opts.data
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate()
  }
  return (
    <>
      <PageHeader title="Tạo yêu cầu hỗ trợ (Ticket)" description="Mô tả sự cố / yêu cầu; có thể đính kèm ảnh chụp màn hình (dán Ctrl + V)." />
      <form onSubmit={submit} className="max-w-3xl space-y-4 rounded-xl border border-surface-container-highest bg-surface p-6 shadow-sm">
        {opts.error && <Alert>{errorMessage(opts.error)}</Alert>}
        <Field
          label="Tiêu đề sự cố / yêu cầu"
          required
          maxLength={255}
          placeholder="Ví dụ: Lỗi không xuất được hóa đơn điện tử cho học viên HV-0012"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
        />
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Select label="Phân loại danh mục" required value={category} onChange={(e) => setCategory(e.target.value)}>
            {o?.categories.map((c) => (
              <option key={c.value} value={c.value}>
                {c.label}
              </option>
            ))}
          </Select>
          <Select label="Mức độ ưu tiên" required value={priority} onChange={(e) => setPriority(e.target.value)}>
            {o?.priorities.map((c) => (
              <option key={c.value} value={c.value}>
                {c.label}
              </option>
            ))}
          </Select>
          {o?.can_assign && (
            <Select label="Phân công người xử lý" value={assignee} onChange={(e) => setAssignee(e.target.value)}>
              <option value="">-- Để mở (Chưa gán) --</option>
              {o.staffs.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </Select>
          )}
        </div>
        <Textarea
          label="Mô tả chi tiết sự cố / Nội dung yêu cầu"
          required
          rows={5}
          placeholder="Mô tả cụ thể các bước tái hiện lỗi, đường dẫn URL bị lỗi hoặc yêu cầu nghiệp vụ cần xử lý..."
          value={description}
          onChange={(e) => setDescription(e.target.value)}
        />
        <Attachments files={files} onChange={setFiles} />
        {save.error && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="flex justify-end gap-3 border-t border-surface-container-highest pt-4">
          <Button type="button" variant="secondary" onClick={() => navigate(TICKETS_PATH)}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            <Icon name="send" className="mr-1.5 text-[18px]" /> {save.isPending ? 'Đang gửi…' : 'Tạo & Gửi Ticket'}
          </Button>
        </div>
      </form>
    </>
  )
}

// ───────────── Chi tiết + hội thoại ─────────────

function ShowView({ me, id }: { me: Me; id: string }) {
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['tickets', 'show', id], queryFn: () => ticketsApi.show(id) })
  const [flash, setFlash] = useState<string | null>(null)
  const [zoom, setZoom] = useState<string | null>(null)
  const done = (msg: string) => {
    setFlash(msg)
    qc.invalidateQueries({ queryKey: ['tickets'] })
  }
  if (q.error) return <Alert>{errorMessage(q.error)}</Alert>
  const d = q.data
  if (!d) return <p className="text-sm text-on-surface-variant">Đang tải…</p>
  const t = d.ticket
  return (
    <>
      <div className="mb-2">
        <Link href={TICKETS_PATH} className="text-sm text-on-surface-variant hover:text-primary">
          <Icon name="arrow_back" className="mr-1 align-middle text-[18px]" />
          Danh sách ticket
        </Link>
      </div>
      <PageHeader
        title={`#${t.code} · ${t.title}`}
        description={`Tạo bởi ${t.creator} lúc ${formatDateTime(t.created_at)} · ${t.category_label}`}
        actions={<StatusControls page={d} onDone={done} />}
      />
      <div className="mb-4">
        <Flash text={flash} />
      </div>
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          {d.messages.map((m) => (
            <MessageCard key={m.id} m={m} onZoom={setZoom} />
          ))}
          <ReplyForm page={d} me={me} onDone={done} />
        </div>
        <TicketInfo page={d} onDone={done} />
      </div>
      {zoom && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4" onClick={() => setZoom(null)} role="dialog" aria-label="Xem ảnh">
          <img src={zoom} alt="" className="max-h-[85vh] max-w-full rounded-xl object-contain shadow-lg" />
        </div>
      )}
    </>
  )
}

function MessageCard({ m, onZoom }: { m: TicketMessage; onZoom: (url: string) => void }) {
  return (
    <article
      className={`space-y-3 rounded-xl border p-5 shadow-sm ${m.is_internal_note ? 'border-warning/30 bg-warning-container/30' : 'border-surface-container-highest bg-surface'}`}
    >
      <div className="flex items-center gap-2.5">
        <span className="flex size-8 items-center justify-center rounded-full bg-primary-light text-xs font-bold text-primary">{initial(m.user)}</span>
        <div>
          <div className="flex items-center gap-2 text-sm font-semibold">
            <span>{m.user ?? 'Tài khoản đã xóa'}</span>
            {m.is_internal_note && <Badge tone="warning">Ghi chú nội bộ</Badge>}
          </div>
          <div className="font-mono text-xs text-on-surface-variant">{formatDateTime(m.created_at)}</div>
        </div>
      </div>
      <p className="pl-10 text-sm leading-relaxed whitespace-pre-line">{m.message}</p>
      {m.attachments.length > 0 && (
        <div className="pl-10">
          <p className="mb-2 flex items-center gap-1 text-xs font-semibold text-on-surface-variant">
            <Icon name="attach_file" className="text-[16px] text-primary" /> Tệp / Hình ảnh đính kèm ({m.attachments.length}):
          </p>
          <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
            {m.attachments.map((f) =>
              f.is_image ? (
                <a
                  key={f.id}
                  href={f.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="block overflow-hidden rounded-lg border border-surface-container-highest"
                  onClick={(e) => {
                    if (e.ctrlKey || e.metaKey) return
                    e.preventDefault()
                    onZoom(f.url)
                  }}
                >
                  <img src={f.url} alt={f.name} className="h-28 w-full object-cover" />
                  <span className="block truncate p-1.5 text-xs text-on-surface-variant">{f.name}</span>
                </a>
              ) : (
                <a key={f.id} href={f.url} target="_blank" rel="noopener noreferrer" className="flex items-center gap-2 rounded-lg border border-surface-container-highest p-2.5 hover:bg-surface-container-low">
                  <span className="flex size-8 shrink-0 items-center justify-center rounded bg-primary-light text-xs font-bold text-primary">{f.ext.toUpperCase()}</span>
                  <span className="min-w-0">
                    <span className="block truncate text-xs font-semibold">{f.name}</span>
                    <span className="text-xs text-on-surface-variant">Nhấn để tải về</span>
                  </span>
                </a>
              ),
            )}
          </div>
        </div>
      )}
    </article>
  )
}

function ReplyForm({ page, me, onDone }: { page: ShowPage; me: Me; onDone: (m: string) => void }) {
  const qc = useQueryClient()
  const [text, setText] = useState('')
  const [internal, setInternal] = useState(false)
  const [files, setFiles] = useState<File[]>([])
  const send = useMutation({
    mutationFn: () => ticketsApi.message(page.ticket.id, { message: text, is_internal_note: internal }, files),
    onSuccess: (res) => {
      setText('')
      setInternal(false)
      setFiles([])
      qc.invalidateQueries({ queryKey: ['tickets', 'show'] })
      onDone(res.message)
    },
  })
  if (!can(me, 'support_ticket.view')) return null
  return (
    <form
      className="space-y-3 rounded-xl border border-surface-container-highest bg-surface p-5 shadow-sm"
      onSubmit={(e) => {
        e.preventDefault()
        if (text.trim()) send.mutate()
      }}
    >
      <h3 className="flex items-center gap-1.5 text-xs font-bold tracking-wider text-on-surface uppercase">
        <Icon name="reply" className="text-[18px] text-primary" /> Gửi phản hồi / Cập nhật tiến độ
      </h3>
      <Textarea label="Nội dung phản hồi" required rows={3} placeholder="Nhập câu trả lời hoặc tiến độ giải quyết vấn đề..." value={text} onChange={(e) => setText(e.target.value)} />
      <Attachments files={files} onChange={setFiles} compact />
      {send.error && <Alert>{errorMessage(send.error)}</Alert>}
      <div className="flex items-center justify-between gap-3">
        {page.can_post_internal ? <Checkbox label="Chỉ hiển thị nội bộ giữa các phòng ban" checked={internal} onChange={(e) => setInternal(e.target.checked)} /> : <span />}
        <Button type="submit" disabled={send.isPending || !text.trim()}>
          <Icon name="send" className="mr-1.5 text-[18px]" />
          {send.isPending ? (files.length ? `Đang gửi kèm ${files.length} tệp…` : 'Đang gửi…') : 'Gửi phản hồi'}
        </Button>
      </div>
    </form>
  )
}

// Đổi trạng thái (support_ticket.close, chọn là gửi) + nút "Mở lại" cho ticket đã giải quyết / đã đóng.
function StatusControls({ page, onDone }: { page: ShowPage; onDone: (m: string) => void }) {
  const qc = useQueryClient()
  const t = page.ticket
  const act = useMutation({
    mutationFn: (status: string | null) => (status ? ticketsApi.status(t.id, status) : ticketsApi.reopen(t.id)),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['tickets', 'show'] })
      onDone(res.message)
    },
  })
  return (
    <div className="flex flex-wrap items-end gap-2">
      {page.can_close ? (
        <div className="w-56">
          <Select label="Trạng thái ticket" value={t.status} disabled={act.isPending} onChange={(e) => act.mutate(e.target.value)}>
            {page.statuses.map((s) => (
              <option key={s.value} value={s.value}>
                {s.label}
              </option>
            ))}
          </Select>
        </div>
      ) : (
        <Badge tone={statusTone(t.status)}>{t.status_label}</Badge>
      )}
      {t.is_finished && page.can_reopen && (
        <Button variant="secondary" disabled={act.isPending} onClick={() => act.mutate(null)}>
          <Icon name="restart_alt" className="mr-1.5 text-[18px]" /> Mở lại
        </Button>
      )}
      {act.error && <Alert>{errorMessage(act.error)}</Alert>}
    </div>
  )
}

function TicketInfo({ page, onDone }: { page: ShowPage; onDone: (m: string) => void }) {
  const qc = useQueryClient()
  const t = page.ticket
  const [assignee, setAssignee] = useState(t.assignee_id ?? '')
  const assign = useMutation({
    mutationFn: () => ticketsApi.assign(t.id, assignee),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['tickets', 'show'] })
      onDone(res.message)
    },
  })
  const row = (label: string, value: ReactNode) => (
    <div className="flex justify-between gap-3">
      <span className="text-on-surface-variant">{label}</span>
      <span className="text-right font-semibold">{value}</span>
    </div>
  )
  return (
    <aside className="h-fit space-y-3 rounded-xl border border-surface-container-highest bg-surface p-5 text-sm shadow-sm">
      <h3 className="border-b border-surface-container-highest pb-2 text-xs font-bold tracking-wider uppercase">Thông tin Ticket</h3>
      {row('Danh mục:', t.category_label)}
      {row('Mức độ ưu tiên:', <Badge tone={priorityTone(t.priority)}>{t.priority_label}</Badge>)}
      {row('Trạng thái:', <Badge tone={statusTone(t.status)}>{t.status_label}</Badge>)}
      {row('Người tạo:', t.creator)}
      {row('Ngày tạo:', formatDateTime(t.created_at))}
      {page.can_assign ? (
        <form
          className="space-y-2 border-t border-surface-container-highest pt-3"
          onSubmit={(e) => {
            e.preventDefault()
            assign.mutate()
          }}
        >
          <Select label="Người phụ trách xử lý" value={assignee} onChange={(e) => setAssignee(e.target.value)}>
            <option value="">-- Chưa phân công --</option>
            {page.staffs.map((s) => (
              <option key={s.value} value={s.value}>
                {s.label}
              </option>
            ))}
          </Select>
          {assign.error && <Alert>{errorMessage(assign.error)}</Alert>}
          <Button type="submit" variant="secondary" className="w-full" disabled={assign.isPending}>
            Cập nhật Phân công
          </Button>
        </form>
      ) : (
        <div className="border-t border-surface-container-highest pt-3">{row('Người phụ trách xử lý:', t.assignee ?? 'Chưa phân công')}</div>
      )}
    </aside>
  )
}
