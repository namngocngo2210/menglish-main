// Hồ sơ khách (Laravel Crm/Customers/Show): thông tin, trạng thái & hạn xử lý, chuyển giai đoạn, nhật ký chăm sóc,
// lịch sử hoạt động, sửa thông tin, phân công lại / chuyển cơ sở, chăm sóc tháng đầu, Chốt & Xếp lớp.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Checkbox, Dialog, Field, Select } from '../../components/ui'
import { can } from '../../nav'
import { crmApi, type LeadDetail, type Options } from './api'
import { LeadFields, leadFormFrom, toInput, type LeadForm } from './CustomerForm'
import { Link, PATHS, ReasonDialog, SlaBadge, Textarea, useFlash } from './components'
import { formatDate, formatDateTime, formatMoney, ownersForBranch } from './logic'

const noteTypes = ['call', 'message', 'meet', 'test', 'note', 'result']
const contactTypes = ['call', 'message', 'meet']
const lockedKeys = ['deal_value', 'branch_id', 'course_interest']

type Modal =
  | { kind: 'edit' }
  | { kind: 'reassign' }
  | { kind: 'back' }
  | { kind: 'lost' }
  | { kind: 'reopen' }
  | { kind: 'cancel' }
  | { kind: 'close' }
  | { kind: 'place' }
  | null

export function CustomerDetailPage({ me, id }: { me: Me; id: string }) {
  const qc = useQueryClient()
  const lead = useQuery({ queryKey: ['crm', 'lead', id], queryFn: () => crmApi.lead(id) })
  const options = useQuery({ queryKey: ['crm', 'options'], queryFn: crmApi.options })
  const [modal, setModal] = useState<Modal>(null)
  const flash = useFlash()
  const refresh = () => qc.invalidateQueries({ queryKey: ['crm'] })
  const done = (msg: string) => {
    flash.ok(msg)
    refresh()
  }
  const next = useMutation({
    mutationFn: () => crmApi.nextStage(id),
    onSuccess: (r) => done(r.message),
    onError: (e) => flash.fail(errorMessage(e)),
  })

  if (lead.isError) {
    return (
      <Card title="Hồ sơ khách hàng">
        <Alert>{errorMessage(lead.error)}</Alert>
        <p className="mt-3 text-sm">
          <Link href={PATHS.list}>← Về danh sách khách</Link>
        </p>
      </Card>
    )
  }
  const d = lead.data
  if (!d) return <p className="text-sm text-on-surface-variant">Đang tải…</p>
  const sc = d.stage_controls

  return (
    <div className="space-y-4">
      <p className="text-sm">
        <Link href={PATHS.list}>← Danh sách khách</Link>
      </p>
      <Card>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h1 className="text-lg font-semibold">
              {d.name}{' '}
              <Badge tone={d.stage === 'lost' ? 'error' : d.stage === 'won' ? 'success' : 'info'}>{d.stage_label}</Badge>
            </h1>
            <p className="text-sm text-on-surface-variant">
              {d.short_code} · {d.phone} · {d.branch ?? 'Chưa có cơ sở'} · Phụ trách: {d.assigned_user ?? '—'}
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            {sc.next && (
              <Button onClick={() => next.mutate()} disabled={next.isPending}>
                Tiếp theo: {sc.next_label}
              </Button>
            )}
            {d.actions.can_close && <Button onClick={() => setModal({ kind: 'close' })}>Chốt & Xếp lớp</Button>}
            {d.actions.can_place && <Button onClick={() => setModal({ kind: 'place' })}>Xếp lớp</Button>}
            {sc.backward.length > 0 && (
              <Button variant="secondary" onClick={() => setModal({ kind: 'back' })}>
                Lùi giai đoạn
              </Button>
            )}
            {sc.can_lose && (
              <Button variant="danger" onClick={() => setModal({ kind: 'lost' })}>
                Thất bại
              </Button>
            )}
            {sc.can_reopen && (
              <Button variant="secondary" onClick={() => setModal({ kind: 'reopen' })}>
                Mở lại khách
              </Button>
            )}
            {sc.can_cancel_closing && (
              <Button variant="danger" onClick={() => setModal({ kind: 'cancel' })}>
                Huỷ chốt
              </Button>
            )}
            {d.actions.can_update && d.edit_form && (
              <Button variant="secondary" onClick={() => setModal({ kind: 'edit' })}>
                Sửa thông tin
              </Button>
            )}
            {d.actions.can_reassign && (
              <Button variant="secondary" onClick={() => setModal({ kind: 'reassign' })} disabled={!!d.pending_transfer}>
                Phân công lại
              </Button>
            )}
          </div>
        </div>
        {sc.next_blocked && <p className="mt-3 text-sm text-on-surface-variant">{sc.next_blocked}</p>}
        <div className="mt-3 space-y-2">
          {flash.node}
          {d.pending_transfer && (
            <Alert tone="info">
              Đang chờ Admin duyệt chuyển sang {d.pending_transfer.to_user} ({d.pending_transfer.from_branch ?? '—'} →{' '}
              {d.pending_transfer.to_branch}), gửi bởi {d.pending_transfer.requester ?? '—'} lúc {formatDateTime(d.pending_transfer.created_at)}.
            </Alert>
          )}
          {d.lost_reason && d.stage === 'lost' && <Alert>Lý do thất bại: {d.lost_reason}</Alert>}
        </div>
      </Card>

      <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
        <div className="space-y-4">
          <Card title="Thông tin khách hàng">
            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
              <Info label="Phụ huynh">{d.parent_name ? `${d.parent_name}${d.parent_phone ? ` · ${d.parent_phone}` : ''}` : '—'}</Info>
              <Info label="Email">{d.email ?? '—'}</Info>
              <Info label="Ngày sinh">{formatDate(d.dob)}</Info>
              <Info label="Giới tính">{d.gender ?? '—'}</Info>
              <Info label="Trường">{d.school ?? '—'}</Info>
              <Info label="Địa chỉ">{d.address ?? '—'}</Info>
              <Info label="Nguồn">{d.source ?? '—'}</Info>
              <Info label="Khóa quan tâm">{d.course_interest ?? '—'}</Info>
              <Info label={d.converted_at ? 'Giá trị hợp đồng' : 'Giá trị dự kiến'}>{formatMoney(d.deal_value)}</Info>
              <Info label="Ngày tạo">{formatDateTime(d.created_at)}</Info>
              {d.appointment_at && <Info label="Lịch hẹn test">{formatDateTime(d.appointment_at)}</Info>}
              {d.test_score && <Info label="Điểm test">{d.test_score}</Info>}
              {d.converted_at && <Info label="Ngày chốt">{formatDateTime(d.converted_at)}</Info>}
              {d.waiting_since && <Info label="Chờ xếp lớp từ">{formatDate(d.waiting_since)}</Info>}
              <div className="sm:col-span-2">
                <Info label="Ghi chú">{d.notes ?? '—'}</Info>
              </div>
            </dl>
          </Card>
          {can(me, 'lead.update') && options.data && <NoteForm id={d.id} options={options.data} onDone={done} />}
          <Timeline d={d} />
        </div>
        <div className="space-y-4">
          <StatusCard d={d} />
          {d.converted_student_id && d.care_checklist && <CareCard d={d} canEdit={can(me, 'lead.update')} onDone={done} />}
        </div>
      </div>

      {modal?.kind === 'edit' && d.edit_form && <EditDialog d={d} me={me} onClose={() => setModal(null)} onDone={done} />}
      {modal?.kind === 'reassign' && <ReassignDialog d={d} onClose={() => setModal(null)} onDone={done} />}
      {modal?.kind === 'back' && (
        <ReasonDialog
          title="Lùi giai đoạn"
          options={sc.backward}
          onClose={() => setModal(null)}
          onSubmit={async (reason, stage) => done((await crmApi.stage(d.id, stage, reason)).message)}
        />
      )}
      {modal?.kind === 'lost' && (
        <ReasonDialog
          title={`Đánh dấu ${d.name} Thất bại`}
          label="Lý do thất bại"
          onClose={() => setModal(null)}
          onSubmit={async (reason) => done((await crmApi.stage(d.id, 'lost', reason)).message)}
        />
      )}
      {modal?.kind === 'reopen' && (
        <ReasonDialog
          title="Mở lại khách"
          description="Khách quay lại sẽ về bước Đang tư vấn."
          label="Lý do mở lại"
          onClose={() => setModal(null)}
          onSubmit={async (reason) => done((await crmApi.stage(d.id, 'consulting', reason)).message)}
        />
      )}
      {modal?.kind === 'cancel' && (
        <ReasonDialog
          title="Huỷ chốt khách"
          description="Khách chuyển Thất bại, học viên chuyển Thôi học. Phần học phí đã thu lập hồ sơ hoàn phí chờ duyệt."
          label="Lý do huỷ chốt (ít nhất 5 ký tự)"
          submitLabel="Huỷ chốt"
          onClose={() => setModal(null)}
          onSubmit={async (reason) => done((await crmApi.cancelClosing(d.id, reason)).message)}
        />
      )}
      {modal?.kind === 'close' && <CloseDialog d={d} onClose={() => setModal(null)} onDone={done} />}
      {modal?.kind === 'place' && <PlaceDialog d={d} onClose={() => setModal(null)} onDone={done} />}
    </div>
  )
}

function Info({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-xs text-on-surface-variant">{label}</dt>
      <dd className="whitespace-pre-line">{children}</dd>
    </div>
  )
}

function StatusCard({ d }: { d: LeadDetail }) {
  const s = d.status_card
  return (
    <Card title="Trạng thái & hạn xử lý">
      <dl className="space-y-2 text-sm">
        <Info label="Ở giai đoạn hiện tại">
          {s.days_in_stage} ngày (từ {formatDateTime(s.stage_since)})
        </Info>
        <Info label="Liên hệ gần nhất">{s.last_contact ? formatDateTime(s.last_contact) : 'Chưa liên hệ'}</Info>
        {s.sla && (
          <Info label={s.sla.label}>
            <SlaBadge sla={s.sla} compact /> <span className="text-xs text-on-surface-variant">({s.sla.deadline_label})</span>
          </Info>
        )}
        {s.neglected && <Alert>Khách bị bỏ quên {s.neglect_days} ngày chưa chăm sóc.</Alert>}
      </dl>
    </Card>
  )
}

function NoteForm({ id, options, onDone }: { id: string; options: Options; onDone: (m: string) => void }) {
  const [type, setType] = useState('call')
  const [content, setContent] = useState('')
  const [outcome, setOutcome] = useState('reached')
  const [sentAt, setSentAt] = useState('')
  const [followUp, setFollowUp] = useState('')
  const labels = Object.fromEntries(options.event_types.map((o) => [o.value, o.label]))
  const m = useMutation({
    mutationFn: () =>
      crmApi.note(id, {
        type,
        content,
        outcome: contactTypes.includes(type) ? outcome : undefined,
        sent_at: type === 'result' ? sentAt : undefined,
        next_follow_up_at: followUp || undefined,
      }),
    onSuccess: (r) => {
      setContent('')
      setFollowUp('')
      onDone(r.message)
    },
  })
  return (
    <Card title="Ghi nhật ký chăm sóc">
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          m.mutate()
        }}
      >
        <div className="grid gap-3 sm:grid-cols-3">
          <Select label="Loại" value={type} onChange={(e) => setType(e.target.value)}>
            {noteTypes.map((t) => (
              <option key={t} value={t}>
                {labels[t] ?? t}
              </option>
            ))}
          </Select>
          {contactTypes.includes(type) && (
            <Select label="Kết quả liên hệ" value={outcome} onChange={(e) => setOutcome(e.target.value)}>
              <option value="reached">Liên hệ được</option>
              <option value="failed">Không liên hệ được</option>
            </Select>
          )}
          {type === 'result' && <Field label="Gửi cho phụ huynh lúc" type="datetime-local" value={sentAt} onChange={(e) => setSentAt(e.target.value)} />}
          <Field label="Hẹn liên hệ tiếp" type="datetime-local" value={followUp} onChange={(e) => setFollowUp(e.target.value)} />
        </div>
        <Textarea
          label={type === 'result' ? 'Phản hồi của phụ huynh' : 'Nội dung'}
          value={content}
          onChange={(e) => setContent(e.target.value)}
          maxLength={1000}
        />
        {m.isError && <Alert>{errorMessage(m.error)}</Alert>}
        <div className="flex justify-end">
          <Button type="submit" disabled={m.isPending}>
            Lưu nhật ký
          </Button>
        </div>
      </form>
    </Card>
  )
}

function Timeline({ d }: { d: LeadDetail }) {
  const [filter, setFilter] = useState('')
  const events = filter ? d.events.filter((e) => e.type === filter) : d.events
  const types = Object.entries(d.event_counts)
  return (
    <Card title="Lịch sử hoạt động">
      {types.length > 1 && (
        <div className="mb-3 flex flex-wrap gap-2 text-xs">
          <button type="button" aria-pressed={!filter} className="rounded-full border px-2 py-1 aria-pressed:border-primary" onClick={() => setFilter('')}>
            Tất cả ({d.events.length})
          </button>
          {types.map(([t, n]) => (
            <button key={t} type="button" aria-pressed={filter === t} className="rounded-full border px-2 py-1 aria-pressed:border-primary" onClick={() => setFilter(t)}>
              {d.events.find((e) => e.type === t)?.type_label ?? t} ({n})
            </button>
          ))}
        </div>
      )}
      <ol className="space-y-3">
        {events.map((e) => (
          <li key={e.id} className="border-l-2 border-surface-container-highest pl-3 text-sm">
            <div className="flex flex-wrap items-center gap-2 text-xs text-on-surface-variant">
              <Badge tone={e.failed || e.is_lost ? 'error' : 'neutral'}>{e.type_label}</Badge>
              {formatDateTime(e.created_at)} · {e.user ?? 'Hệ thống'}
            </div>
            <p className="mt-1 whitespace-pre-line">{e.content}</p>
            {e.changes && (
              <ul className="mt-1 text-xs text-on-surface-variant">
                {Object.entries(e.changes).map(([k, c]) => (
                  <li key={k}>
                    {c.label}: {c.old ?? '(trống)'} → {c.new ?? '(trống)'}
                  </li>
                ))}
              </ul>
            )}
          </li>
        ))}
      </ol>
    </Card>
  )
}

function CareCard({ d, canEdit, onDone }: { d: LeadDetail; canEdit: boolean; onDone: (m: string) => void }) {
  const items = d.care_checklist ?? []
  const [checked, setChecked] = useState<Set<string>>(new Set(items.filter((i) => i.done).map((i) => i.key)))
  const [note, setNote] = useState('')
  const m = useMutation({ mutationFn: () => crmApi.care(d.id, [...checked], note), onSuccess: (r) => (setNote(''), onDone(r.message)) })
  return (
    <Card title="Chăm sóc tháng đầu">
      <div className="space-y-2">
        {items.map((i) => (
          <Checkbox
            key={i.key}
            label={
              <>
                {i.label}
                {i.done && i.done_at && (
                  <span className="block text-xs text-on-surface-variant">
                    {i.done_at} · {i.by}
                  </span>
                )}
              </>
            }
            checked={checked.has(i.key)}
            disabled={!canEdit}
            onChange={(e) => {
              const n = new Set(checked)
              if (e.target.checked) n.add(i.key)
              else n.delete(i.key)
              setChecked(n)
            }}
          />
        ))}
        {canEdit && (
          <>
            <Textarea label="Ghi chú" value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} />
            {m.isError && <Alert>{errorMessage(m.error)}</Alert>}
            <Button onClick={() => m.mutate()} disabled={m.isPending}>
              Lưu chăm sóc
            </Button>
          </>
        )}
      </div>
    </Card>
  )
}

function EditDialog({ d, me, onClose, onDone }: { d: LeadDetail; me: Me; onClose: () => void; onDone: (m: string) => void }) {
  const cfg = d.edit_form!
  const [form, setForm] = useState<LeadForm>(leadFormFrom(d))
  const withOwner = can(me, 'lead.assign')
  const m = useMutation({
    mutationFn: () => crmApi.update(d.id, toInput(form, withOwner)),
    onSuccess: (r) => (onDone(r.message), onClose()),
  })
  return (
    <Dialog title="Sửa thông tin khách" onClose={onClose}>
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          m.mutate()
        }}
      >
        {d.contract_locked && <Alert tone="info">Khách đã chốt: không sửa được {cfg.locked_fields}.</Alert>}
        <LeadFields
          form={form}
          setForm={setForm}
          branches={cfg.branches}
          owners={cfg.owners}
          sources={cfg.sources.map((s) => s.value)}
          withOwner={withOwner}
          locked={d.contract_locked ? lockedKeys : []}
        />
        {m.isError && <Alert>{errorMessage(m.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={m.isPending}>
            Lưu thay đổi
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function ReassignDialog({ d, onClose, onDone }: { d: LeadDetail; onClose: () => void; onDone: (m: string) => void }) {
  const [branch, setBranch] = useState(d.branch_id ?? '')
  const [owner, setOwner] = useState('')
  const [reason, setReason] = useState('')
  const owners = ownersForBranch(d.reassign_owners ?? [], branch)
  const m = useMutation({
    mutationFn: () => crmApi.reassign(d.id, { assigned_user_id: owner, branch_id: branch || null, reason }),
    onSuccess: (r) => (onDone(r.message), onClose()),
  })
  return (
    <Dialog title="Phân công lại người phụ trách" onClose={onClose}>
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          m.mutate()
        }}
      >
        {(d.reassign_branches?.length ?? 0) > 0 && (
          <Select label="Cơ sở" value={branch} onChange={(e) => (setBranch(e.target.value), setOwner(''))}>
            {d.reassign_branches!.map((b) => (
              <option key={b.value} value={b.value}>
                {b.label}
              </option>
            ))}
          </Select>
        )}
        <Select label="Người phụ trách mới" value={owner} onChange={(e) => setOwner(e.target.value)}>
          <option value="">— Chọn —</option>
          {owners.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
        {branch && branch !== d.branch_id && (
          <p className="text-xs text-on-surface-variant">Đổi cơ sở cần Admin duyệt (Admin đổi thì áp dụng ngay).</p>
        )}
        <Textarea label="Lý do (bắt buộc)" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000} />
        {m.isError && <Alert>{errorMessage(m.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={!owner || !reason.trim() || m.isPending}>
            Phân công
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

// Chốt & Xếp lớp. Lớp / khóa / ưu đãi chọn theo mã (danh mục lớp, khóa của hệ thống mới chưa gộp).
function CloseDialog({ d, onClose, onDone }: { d: LeadDetail; onClose: () => void; onDone: (m: string) => void }) {
  const [f, setF] = useState({
    class_id: '',
    course_id: '',
    promotion_id: '',
    fee_paid_at_closing: false,
    paid_amount: '',
    prepaid_amount: '',
    payment_method: '',
    student_code: '',
    bill_notes: '',
  })
  const num = (s: string) => Number(s.replace(/\D/g, '') || 0)
  const m = useMutation({
    mutationFn: () =>
      crmApi.close(d.id, {
        class_id: f.class_id || null,
        course_id: f.course_id || null,
        promotion_id: f.promotion_id || null,
        fee_paid_at_closing: f.fee_paid_at_closing,
        paid_amount: num(f.paid_amount),
        prepaid_amount: num(f.prepaid_amount),
        payment_method: f.payment_method || null,
        student_code: f.student_code || null,
        bill_notes: f.bill_notes || null,
      }),
    onSuccess: (r) => (onDone(r.message), onClose()),
  })
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value })
  return (
    <Dialog title={`Chốt & Xếp lớp: ${d.name}`} onClose={onClose}>
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          m.mutate()
        }}
      >
        <Field label="Mã lớp (xếp lớp ngay)" value={f.class_id} onChange={set('class_id')} />
        <Field label="Mã khóa học (xếp lớp sau)" value={f.course_id} onChange={set('course_id')} />
        <Field label="Mã ưu đãi" value={f.promotion_id} onChange={set('promotion_id')} />
        <Field label="Mã học viên (để trống: tự sinh)" value={f.student_code} onChange={set('student_code')} />
        <Checkbox
          label="Đã đóng học phí đăng ký"
          checked={f.fee_paid_at_closing}
          onChange={(e) => setF({ ...f, fee_paid_at_closing: e.target.checked })}
        />
        {f.fee_paid_at_closing && (
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Số tiền đã thu (đ)" inputMode="numeric" value={f.paid_amount} onChange={set('paid_amount')} />
            <Field label="Thu trước (đ)" inputMode="numeric" value={f.prepaid_amount} onChange={set('prepaid_amount')} />
            <Select label="Phương thức" value={f.payment_method} onChange={set('payment_method')}>
              <option value="">— Chọn —</option>
              <option value="cash">Tiền mặt</option>
              <option value="transfer">Chuyển khoản</option>
            </Select>
          </div>
        )}
        <Textarea label="Ghi chú phiếu thu" value={f.bill_notes} onChange={set('bill_notes')} maxLength={1000} />
        {m.isError && <Alert>{errorMessage(m.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={m.isPending}>
            Chốt khách
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function PlaceDialog({ d, onClose, onDone }: { d: LeadDetail; onClose: () => void; onDone: (m: string) => void }) {
  const [classId, setClassId] = useState('')
  const m = useMutation({ mutationFn: () => crmApi.assignClass(d.id, classId), onSuccess: (r) => (onDone(r.message), onClose()) })
  return (
    <Dialog title={`Xếp lớp cho ${d.name}`} onClose={onClose}>
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          m.mutate()
        }}
      >
        <Field label="Mã lớp" value={classId} onChange={(e) => setClassId(e.target.value)} required />
        {m.isError && <Alert>{errorMessage(m.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={!classId || m.isPending}>
            Xếp lớp
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
