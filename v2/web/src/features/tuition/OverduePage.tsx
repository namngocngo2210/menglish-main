// Thu phí quá hạn (Tuition/Overdue.vue): nhóm quá hạn nghiêm trọng / mới quá hạn / sắp đến hạn / đang khất nợ – bảo lưu,
// thống kê công nợ theo chi nhánh và lớp; gửi nhắc theo mốc, ghi nhận đã liên hệ phụ huynh, báo cáo Admin.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Dialog, Field, Select } from '../../components/ui'
import { can } from '../../nav'
import { navigate, useQueryParam } from '../../router'
import { tuitionApi, type DueRow } from './api'
import { Pager, Stat, StudentPaymentsDialog, Tag } from './components'
import { dueTone, formatMoney } from './logic'

type Action = { kind: 'contacted' | 'report'; row: DueRow }

export function OverduePage({ me }: { me: Me }) {
  const qc = useQueryClient()
  const initial = useQueryParam('search')
  const [q, setQ] = useState(initial)
  const [search, setSearch] = useState(initial)
  const [branchId, setBranchId] = useState('')
  const [classId, setClassId] = useState('')
  const [type, setType] = useState('all')
  const [upcomingPage, setUpcomingPage] = useState(1)
  const [student, setStudent] = useState<string | null>(null)
  const [action, setAction] = useState<Action | null>(null)
  const [note, setNote] = useState('')
  const [contactedAt, setContactedAt] = useState('')
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)

  const list = useQuery({
    queryKey: ['tuition', 'overdue', search, branchId, classId, type, upcomingPage],
    queryFn: () => tuitionApi.overdue({ search, branch_id: branchId, class_id: classId, type, upcoming_page: upcomingPage }),
    placeholderData: keepPreviousData,
  })
  const d = list.data
  const serious = d?.due.serious_days ?? 7

  const done = (text: string) => {
    setNotice({ tone: 'success', text })
    setAction(null)
    setNote('')
    setContactedAt('')
    qc.invalidateQueries({ queryKey: ['tuition'] })
  }
  const fail = (e: unknown) => setNotice({ tone: 'error', text: errorMessage(e) })
  const remind = useMutation({
    mutationFn: (p: { id: string; upcoming: boolean }) => tuitionApi.remind(p.id, p.upcoming),
    onSuccess: (x) => done(x.message),
    onError: fail,
  })
  const submit = useMutation({
    mutationFn: (a: Action) =>
      a.kind === 'contacted'
        ? tuitionApi.contacted(a.row.id, { note: note || undefined, contacted_at: contactedAt || undefined })
        : tuitionApi.reportAdmin(a.row.id, note),
    onSuccess: (x) => done(x.message),
    onError: fail,
  })

  const actions = {
    canContact: can(me, 'tuition.mark_contacted'),
    canReport: can(me, 'tuition.report_overdue'),
    canCreate: can(me, 'tuition.create'),
    busy: remind.isPending,
    onRemind: (row: DueRow, upcoming: boolean) => remind.mutate({ id: row.id, upcoming }),
    onAction: (kind: Action['kind'], row: DueRow) => {
      setNotice(null)
      setNote('')
      setContactedAt('')
      setAction({ kind, row })
    },
    onOpen: setStudent,
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold">Thu phí quá hạn</h1>
        <p className="text-sm text-on-surface-variant">
          Khoản quá hạn, sắp đến hạn và đang khất nợ / bảo lưu; quá hạn từ {serious} ngày bắt buộc liên hệ trực tiếp phụ huynh.
        </p>
      </div>

      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      <Card>
        <form
          className="grid gap-3 sm:grid-cols-[1fr_180px_180px_160px_auto] sm:items-end"
          onSubmit={(e) => {
            e.preventDefault()
            setUpcomingPage(1)
            setSearch(q)
          }}
        >
          <Field label="Tìm kiếm" placeholder="Họ tên, SĐT hoặc mã học viên..." value={q} onChange={(e) => setQ(e.target.value)} />
          <Select label="Chi nhánh" value={branchId} onChange={(e) => setBranchId(e.target.value)}>
            <option value="">Tất cả chi nhánh</option>
            {d?.branches.map((b) => (
              <option key={b.value} value={b.value}>
                {b.label}
              </option>
            ))}
          </Select>
          <Select label="Lớp học" value={classId} onChange={(e) => setClassId(e.target.value)}>
            <option value="">Tất cả lớp học</option>
            {d?.classes.map((c) => (
              <option key={c.value} value={c.value}>
                {c.label}
              </option>
            ))}
          </Select>
          <Select label="Nhóm" value={type} onChange={(e) => setType(e.target.value)}>
            <option value="all">Tất cả</option>
            <option value="overdue">Quá hạn</option>
            <option value="upcoming">Sắp đến hạn</option>
          </Select>
          <Button type="submit" variant="secondary">
            Lọc
          </Button>
        </form>
      </Card>

      {d && (
        <>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <Stat label="Khoản quá hạn" value={`${d.due.overdue_count} khoản`} tone="error" />
            <Stat label={`Quá hạn từ ${serious} ngày`} value={`${d.due.serious_overdue.length} khoản`} tone="error" />
            <Stat label={`Sắp đến hạn (${d.due.upcoming_days} ngày)`} value={`${d.due.upcoming_page.total} khoản`} tone="warning" />
          </div>

          {d.due.type !== 'upcoming' && (
            <>
              <DueTable
                title={`Quá hạn từ ${serious} ngày — bắt buộc liên hệ trực tiếp`}
                rows={d.due.serious_overdue}
                serious={serious}
                {...actions}
              />
              <DueTable title="Mới quá hạn" rows={d.due.new_overdue} serious={serious} {...actions} />
            </>
          )}
          {d.due.type !== 'overdue' && (
            <>
              <DueTable title={`Sắp đến hạn (${d.due.upcoming_days} ngày tới)`} rows={d.due.upcoming} serious={serious} upcoming {...actions} />
              <Pager page={d.due.upcoming_page} unit="khoản sắp đến hạn" onPage={setUpcomingPage} />
            </>
          )}
          {d.due.paused.length > 0 && (
            <DueTable title="Đang khất nợ / bảo lưu (tạm dừng nhắc nợ)" rows={d.due.paused} serious={serious} paused {...actions} />
          )}

          <div className="grid gap-6 lg:grid-cols-2">
            <Card title="Công nợ theo chi nhánh">
              <StatTable
                head="Chi nhánh"
                rows={d.stats_by_branch.map((s) => ({ key: s.branch_name, label: s.branch_name, ...s }))}
                empty="Không có khoản nợ nào."
              />
            </Card>
            <Card title="Công nợ theo lớp">
              <StatTable
                head="Lớp"
                rows={d.stats_by_class.map((s) => ({ key: s.class_code + s.class_name, label: `${s.class_name} (${s.class_code})`, ...s }))}
                empty="Không có khoản nợ nào."
              />
            </Card>
          </div>
        </>
      )}

      {action && (
        <Dialog title={action.kind === 'contacted' ? 'Ghi nhận đã liên hệ phụ huynh' : 'Báo cáo Admin khoản quá hạn'} onClose={() => setAction(null)}>
          <form
            className="space-y-4"
            onSubmit={(e) => {
              e.preventDefault()
              submit.mutate(action)
            }}
          >
            <p className="text-sm">
              {action.row.student.name} ({action.row.student.code}) · còn nợ {formatMoney(action.row.debt_amount)}
              {action.row.days_overdue > 0 && ` · quá hạn ${action.row.days_overdue} ngày`}
            </p>
            {action.kind === 'contacted' && (
              <Field
                label="Thời gian liên hệ"
                type="datetime-local"
                value={contactedAt}
                onChange={(e) => setContactedAt(e.target.value)}
                hint="Để trống: bây giờ."
              />
            )}
            <label className="block space-y-1.5 text-sm font-medium">
              <span>Ghi chú</span>
              <textarea
                className="block min-h-24 w-full rounded-lg border border-outline-variant px-3 py-2 text-sm font-normal outline-none focus:border-primary"
                maxLength={1000}
                value={note}
                onChange={(e) => setNote(e.target.value)}
              />
            </label>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="secondary" onClick={() => setAction(null)}>
                Hủy
              </Button>
              <Button type="submit" disabled={submit.isPending}>
                {action.kind === 'contacted' ? 'Lưu liên hệ' : 'Gửi báo cáo'}
              </Button>
            </div>
          </form>
        </Dialog>
      )}

      {student && <StudentPaymentsDialog studentId={student} onClose={() => setStudent(null)} />}
    </div>
  )
}

function DueTable({
  title,
  rows,
  serious,
  upcoming,
  paused,
  canContact,
  canReport,
  canCreate,
  busy,
  onRemind,
  onAction,
  onOpen,
}: {
  title: string
  rows: DueRow[]
  serious: number
  upcoming?: boolean
  paused?: boolean
  canContact: boolean
  canReport: boolean
  canCreate: boolean
  busy: boolean
  onRemind: (row: DueRow, upcoming: boolean) => void
  onAction: (kind: Action['kind'], row: DueRow) => void
  onOpen: (studentId: string) => void
}) {
  return (
    <Card title={title}>
      {rows.length === 0 ? (
        <p className="text-sm text-on-surface-variant">Không có khoản nào.</p>
      ) : (
        <ul className="divide-y divide-surface-container-highest">
          {rows.map((r) => (
            <li key={r.id} className="flex flex-wrap items-start justify-between gap-3 py-3 text-sm">
              <button type="button" className="text-left" onClick={() => onOpen(r.student.id)}>
                <div className="font-medium hover:text-primary">
                  {r.student.name} <span className="font-mono text-xs text-on-surface-variant">{r.student.code}</span>
                </div>
                <div className="text-xs text-on-surface-variant">
                  {r.student.phone} · {r.class_label} · {r.fee_label}
                  {r.branch_name && ` · ${r.branch_name}`}
                </div>
                {r.session_lines.length > 0 && (
                  <div className="text-xs text-on-surface-variant">{r.session_lines.map((l) => `${l.label}: ${l.value}`).join(' · ')}</div>
                )}
                {r.last_contact && (
                  <div className="text-xs text-success">
                    Đã liên hệ — chờ thu ({r.last_contact.at ?? '—'}
                    {r.last_contact.user_name && ` · ${r.last_contact.user_name}`}){r.last_contact.note_short && ` · ${r.last_contact.note_short}`}
                  </div>
                )}
                {r.last_report_date && <div className="text-xs text-warning">Đã báo cáo Admin ngày {r.last_report_date}</div>}
              </button>
              <div className="space-y-2 text-right">
                <div className="font-mono text-error">{formatMoney(r.debt_amount)}</div>
                <div className="text-xs text-on-surface-variant">
                  Hạn {r.due_date ?? '—'}
                  {r.days_overdue > 0 && (
                    <>
                      {' '}
                      · <Tag tone={dueTone(r.days_overdue, serious)}>Quá hạn {r.days_overdue} ngày</Tag>
                    </>
                  )}
                  {paused && r.reminder_paused_until && ` · dừng nhắc tới ${r.reminder_paused_until}`}
                  {paused && r.deferred_until && ` · bảo lưu tới ${r.deferred_until}`}
                </div>
                <div className="flex flex-wrap justify-end gap-2">
                  {canContact && !paused && (
                    <Button variant="secondary" className="h-8 px-2 text-xs" disabled={busy} onClick={() => onRemind(r, !!upcoming)}>
                      Gửi nhắc
                    </Button>
                  )}
                  {canContact && !upcoming && (
                    <Button variant="secondary" className="h-8 px-2 text-xs" onClick={() => onAction('contacted', r)}>
                      Đã liên hệ
                    </Button>
                  )}
                  {canReport && !upcoming && !paused && (
                    <Button variant="secondary" className="h-8 px-2 text-xs" onClick={() => onAction('report', r)}>
                      Báo cáo Admin
                    </Button>
                  )}
                  {canCreate && (
                    <Button className="h-8 px-2 text-xs" onClick={() => navigate(`/tuition/receipts/new?tuition_id=${r.id}`)}>
                      Lập phiếu thu
                    </Button>
                  )}
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

function StatTable({
  head,
  rows,
  empty,
}: {
  head: string
  rows: { key: string; label: string; total_debt: number; count: number; overdue_count: number }[]
  empty: string
}) {
  if (rows.length === 0) return <p className="text-sm text-on-surface-variant">{empty}</p>
  return (
    <table className="w-full text-left text-sm">
      <thead className="text-xs text-on-surface-variant">
        <tr>
          <th className="py-2 pr-3 font-medium">{head}</th>
          <th className="py-2 pr-3 text-right font-medium">Khoản nợ</th>
          <th className="py-2 pr-3 text-right font-medium">Quá hạn</th>
          <th className="py-2 text-right font-medium">Tổng nợ</th>
        </tr>
      </thead>
      <tbody className="divide-y divide-surface-container-highest">
        {rows.map((r) => (
          <tr key={r.key}>
            <td className="py-2 pr-3">{r.label}</td>
            <td className="py-2 pr-3 text-right">{r.count}</td>
            <td className={`py-2 pr-3 text-right ${r.overdue_count > 0 ? 'text-error' : ''}`}>{r.overdue_count}</td>
            <td className="py-2 text-right font-mono">{formatMoney(r.total_debt)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}
