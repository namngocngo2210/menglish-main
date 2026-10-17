// Công nợ học viên (Tuition/Students.vue): sổ toàn bộ khoản học phí, thẻ số liệu, lọc chi nhánh / lớp / trạng thái,
// bấm dòng xem lịch sử thu của học viên, nút "Lập phiếu thu" ở dòng còn nợ; nhóm quá hạn / sắp đến hạn thu gọn.
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Field, Icon, Select } from '../../components/ui'
import { can } from '../../nav'
import { navigate } from '../../router'
import { tuitionApi, type DueRow } from './api'
import { Pager, Stat, StudentPaymentsDialog, Tag } from './components'
import { dueTone, formatMoney } from './logic'

export function StudentsPage({ me }: { me: Me }) {
  const [q, setQ] = useState('')
  const [search, setSearch] = useState('')
  const [branchId, setBranchId] = useState('')
  const [classId, setClassId] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [upcomingPage, setUpcomingPage] = useState(1)
  const [student, setStudent] = useState<string | null>(null)

  const list = useQuery({
    queryKey: ['tuition', 'students', search, branchId, classId, status, page, upcomingPage],
    queryFn: () => tuitionApi.students({ search, branch_id: branchId, class_id: classId, status, page, upcoming_page: upcomingPage }),
    placeholderData: keepPreviousData,
  })
  const d = list.data
  const canCreate = can(me, 'tuition.create')
  const serious = d?.due.serious_days ?? 7

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Công nợ học viên</h1>
          <p className="text-sm text-on-surface-variant">Sổ toàn bộ khoản học phí: đã thu, còn nợ, hạn nộp và trạng thái của từng học viên.</p>
        </div>
        {canCreate && <Button onClick={() => navigate('/tuition/receipts/new')}>Lập phiếu thu</Button>}
      </div>

      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}

      <Card>
        <form
          className="grid gap-3 sm:grid-cols-[1fr_200px_200px_auto] sm:items-end"
          onSubmit={(e) => {
            e.preventDefault()
            setPage(1)
            setSearch(q)
          }}
        >
          <Field label="Tìm kiếm" placeholder="Họ tên hoặc mã học sinh..." value={q} onChange={(e) => setQ(e.target.value)} />
          <Select
            label="Chi nhánh"
            value={branchId}
            onChange={(e) => {
              setPage(1)
              setBranchId(e.target.value)
            }}
          >
            <option value="">Tất cả chi nhánh</option>
            {d?.branches.map((b) => (
              <option key={b.value} value={b.value}>
                {b.label}
              </option>
            ))}
          </Select>
          <Select
            label="Lớp học"
            value={classId}
            onChange={(e) => {
              setPage(1)
              setClassId(e.target.value)
            }}
          >
            <option value="">Tất cả lớp học</option>
            {d?.classes.map((c) => (
              <option key={c.value} value={c.value}>
                {c.label}
              </option>
            ))}
          </Select>
          <Button type="submit" variant="secondary">
            Lọc
          </Button>
        </form>
      </Card>

      {d && (
        <>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Stat label="Tổng học phí phải thu" value={formatMoney(d.stats.final)} />
            <Stat label="Đã thực thu" value={formatMoney(d.stats.paid)} tone="success" />
            <Stat label="Công nợ còn lại" value={formatMoney(d.stats.debt)} tone="warning" />
            <Stat label="Học viên quá hạn" value={`${d.stats.overdue} học viên`} tone="error" />
          </div>

          <Card
            title={
              <span className="flex flex-wrap items-center justify-between gap-3">
                Toàn bộ khoản học phí
                <select
                  aria-label="Trạng thái công nợ"
                  className="h-9 rounded-lg border border-outline-variant bg-surface px-2 text-sm font-normal"
                  value={status}
                  onChange={(e) => {
                    setPage(1)
                    setStatus(e.target.value)
                  }}
                >
                  <option value="">Tất cả trạng thái</option>
                  {d.statuses.map((s) => (
                    <option key={s.value} value={s.value}>
                      {s.label}
                    </option>
                  ))}
                </select>
              </span>
            }
          >
            {d.tuitions.length === 0 ? (
              <p className="text-sm text-on-surface-variant">Không tìm thấy khoản học phí nào.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[900px] text-left text-sm">
                  <thead className="text-xs text-on-surface-variant">
                    <tr>
                      <th className="py-2 pr-3 font-medium">Học viên</th>
                      <th className="py-2 pr-3 font-medium">Lớp học · Cơ sở</th>
                      <th className="py-2 pr-3 font-medium">Khoản thu</th>
                      <th className="py-2 pr-3 text-right font-medium">Tổng học phí</th>
                      <th className="py-2 pr-3 text-right font-medium">Đã nộp</th>
                      <th className="py-2 pr-3 text-right font-medium">Còn nợ</th>
                      <th className="py-2 pr-3 font-medium">Hạn nộp</th>
                      <th className="py-2 pr-3 font-medium">Trạng thái</th>
                      <th className="py-2 text-right font-medium">Thao tác</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-surface-container-highest">
                    {d.tuitions.map((t) => (
                      <tr
                        key={t.id}
                        tabIndex={0}
                        title="Xem lịch sử thu học phí"
                        className="cursor-pointer hover:bg-surface-container-low"
                        onClick={() => setStudent(t.student.id)}
                        onKeyDown={(e) => e.key === 'Enter' && e.target === e.currentTarget && setStudent(t.student.id)}
                      >
                        <td className="py-2 pr-3 whitespace-nowrap">
                          <div className="font-medium">{t.student.name}</div>
                          <div className="font-mono text-xs text-on-surface-variant">
                            {t.student.code} · {t.student.phone}
                          </div>
                        </td>
                        <td className="py-2 pr-3">
                          <div className="text-primary">{t.class_name ?? 'Chưa gán lớp'}</div>
                          <div className="text-xs text-on-surface-variant">{t.branch_name ?? '—'}</div>
                        </td>
                        <td className="py-2 pr-3">{t.fee_label}</td>
                        <td className="py-2 pr-3 text-right font-mono whitespace-nowrap">{formatMoney(t.final_amount)}</td>
                        <td className="py-2 pr-3 text-right font-mono whitespace-nowrap text-success">{formatMoney(t.paid_amount)}</td>
                        <td className={`py-2 pr-3 text-right font-mono whitespace-nowrap ${t.debt_amount > 0 ? 'text-error' : 'text-on-surface-variant'}`}>
                          {formatMoney(t.debt_amount)}
                        </td>
                        <td className="py-2 pr-3 font-mono text-xs whitespace-nowrap">{t.due_date ?? '—'}</td>
                        <td className="py-2 pr-3">
                          {t.days_overdue > 0 ? (
                            <>
                              <Tag tone={dueTone(t.days_overdue, serious)}>Quá hạn {t.days_overdue} ngày</Tag>
                              {t.days_overdue >= serious && <div className="mt-1 text-xs font-semibold text-error">Bắt buộc liên hệ trực tiếp</div>}
                            </>
                          ) : (
                            <Tag tone={t.status_color}>{t.status_label}</Tag>
                          )}
                        </td>
                        <td className="py-2 text-right whitespace-nowrap" onClick={(e) => e.stopPropagation()}>
                          {t.debt_amount > 0 ? (
                            canCreate && (
                              <Button
                                variant="secondary"
                                className="h-8 px-2"
                                aria-label={`Lập phiếu thu cho ${t.student.name}`}
                                title="Lập phiếu thu"
                                onClick={() => navigate(`/tuition/receipts/new?tuition_id=${t.id}`)}
                              >
                                <Icon name="payments" className="text-base" />
                              </Button>
                            )
                          ) : (
                            <span className="inline-flex text-success" title="Đã tất toán">
                              <Icon name="verified" className="text-lg" />
                              <span className="sr-only">Đã tất toán</span>
                            </span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <Pager page={d.page} unit="khoản học phí" onPage={setPage} />
          </Card>

          <details className="group rounded-xl border border-surface-container-highest bg-surface">
            <summary className="flex cursor-pointer list-none flex-wrap items-center gap-2 p-4 text-sm font-medium">
              <Icon name="chevron_right" className="text-lg text-on-surface-variant transition group-open:rotate-90" />
              Khoản quá hạn &amp; sắp đến hạn
              <Tag tone="error">{d.due.overdue_count} quá hạn</Tag>
              <Tag tone="warning">{d.due.upcoming_page.total} sắp đến hạn</Tag>
            </summary>
            <div className="space-y-6 border-t border-surface-container-highest p-4">
              <DueList title={`Quá hạn từ ${serious} ngày — bắt buộc liên hệ trực tiếp`} rows={d.due.serious_overdue} serious={serious} onOpen={setStudent} />
              <DueList title="Mới quá hạn" rows={d.due.new_overdue} serious={serious} onOpen={setStudent} />
              <DueList title={`Sắp đến hạn (${d.due.upcoming_days} ngày tới)`} rows={d.due.upcoming} serious={serious} onOpen={setStudent} />
              <Pager page={d.due.upcoming_page} unit="khoản sắp đến hạn" onPage={setUpcomingPage} />
              {d.due.paused.length > 0 && <DueList title="Đang khất nợ / bảo lưu" rows={d.due.paused} serious={serious} onOpen={setStudent} />}
            </div>
          </details>
        </>
      )}

      {student && <StudentPaymentsDialog studentId={student} onClose={() => setStudent(null)} />}
    </div>
  )
}

function DueList({ title, rows, serious, onOpen }: { title: string; rows: DueRow[]; serious: number; onOpen: (id: string) => void }) {
  return (
    <section>
      <h3 className="mb-2 text-sm font-semibold">{title}</h3>
      {rows.length === 0 ? (
        <p className="text-sm text-on-surface-variant">Không có khoản nào.</p>
      ) : (
        <ul className="divide-y divide-surface-container-highest">
          {rows.map((r) => (
            <li key={r.id} className="flex flex-wrap items-start justify-between gap-2 py-2 text-sm">
              <button type="button" className="text-left" onClick={() => onOpen(r.student.id)}>
                <div className="font-medium hover:text-primary">{r.student.name}</div>
                <div className="text-xs text-on-surface-variant">
                  {r.class_label} · {r.fee_label}
                  {r.session_lines.length > 0 && ` · ${r.session_lines.map((l) => `${l.label}: ${l.value}`).join(' · ')}`}
                </div>
                {r.last_contact && (
                  <div className="text-xs text-on-surface-variant">
                    Liên hệ gần nhất: {r.last_contact.at ?? '—'}
                    {r.last_contact.user_name && ` · ${r.last_contact.user_name}`}
                    {r.last_contact.note_short && ` · ${r.last_contact.note_short}`}
                  </div>
                )}
              </button>
              <div className="text-right">
                <div className="font-mono text-error">{formatMoney(r.debt_amount)}</div>
                <div className="text-xs text-on-surface-variant">
                  Hạn {r.due_date ?? '—'}
                  {r.days_overdue > 0 && (
                    <>
                      {' '}
                      · <Tag tone={dueTone(r.days_overdue, serious)}>{r.days_overdue} ngày</Tag>
                    </>
                  )}
                  {r.reminder_paused_until && ` · dừng nhắc tới ${r.reminder_paused_until}`}
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
