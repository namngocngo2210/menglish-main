import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, errorMessage } from '../../api'
import { Alert, Button, Dialog, Field, Icon } from '../../components/ui'
import { StudentPaymentsDialog } from '../tuition/components'
import { formatMoney } from '../tuition/logic'
import { portalApi, type Home, type PaymentReport } from './api'
import { Block, Empty, Pill, PortalFrame, PortalLink, QueryError, TextArea, useNotice, useStudentParam } from './components'
import { dmy, parseAmount, portalHref, sessionBadge, sessionLabel, submittedAt, suggestedAmount } from './logic'

// Trang chủ cổng học viên (Portal/Home.vue): lời chào, lịch học sắp tới, lối vào bài tập / phát âm, tiến độ, điểm danh,
// Big Test, học phí (lịch sử thu, báo đóng học phí), thông tin học sinh (sửa liên hệ).
export function HomePage() {
  const sid = useStudentParam()
  const q = useQuery({ queryKey: ['portal', 'home', sid], queryFn: () => portalApi.home(sid) })
  const notice = useNotice()
  const [dialog, setDialog] = useState<'history' | 'report' | 'profile' | null>(null)
  if (q.isError) return <QueryError error={q.error} />
  const d = q.data
  const student = d?.student ?? null
  const close = () => setDialog(null)

  return (
    <PortalFrame ctx={d} title="MENGLISH" tab="home">
      {notice.view}
      {d && (
        <div className="grid gap-5 md:grid-cols-2 md:items-start">
          <div className="flex flex-col gap-1 md:col-span-2">
            <span className="text-sm text-on-surface-variant">Xin chào,</span>
            <h2 className="text-2xl font-bold text-primary">{student?.name ?? 'Học viên'}</h2>
          </div>

          <Block title="Lịch học sắp tới" icon="calendar_month">
            <div className="flex flex-col gap-2">
              {d.upcoming_sessions.map((s) => {
                const [tone, label] = sessionBadge(s)
                return (
                  <div
                    key={s.id}
                    className={`flex items-center justify-between gap-2 rounded-xl border border-surface-container-highest px-3 py-2 text-xs ${s.cancelled ? 'bg-surface-container-low text-on-surface-variant' : ''}`}
                  >
                    <div>
                      <div className={`font-bold ${s.cancelled ? 'line-through' : 'text-on-surface'}`}>{sessionLabel(s)}</div>
                      <div className="text-on-surface-variant">
                        {s.class_name}
                        {s.room_label && ` · ${s.room_label}`}
                      </div>
                    </div>
                    <Pill tone={tone}>{label}</Pill>
                  </div>
                )
              })}
              {d.upcoming_sessions.length === 0 && (
                <p className="text-xs text-on-surface-variant">Chưa có buổi học nào trong {d.upcoming_days} ngày tới.</p>
              )}
            </div>
          </Block>

          <div className="grid grid-cols-2 gap-3 md:col-span-2">
            <QuickLink href={portalHref('/portal/student-homework', student?.id)} icon="upload_file" title="Nộp bài tập" sub="Video & bài viết" />
            <QuickLink href={portalHref('/portal/pronunciation', student?.id)} icon="mic" title="Luyện phát âm" sub="Thu âm AI" tone="text-error bg-error/10" />
          </div>

          <Block title="Tiến độ học tập đã duyệt">
            <div className="grid grid-cols-3 gap-2 text-center">
              <Metric value={`${d.progress.attendance_present}/${d.progress.attendance_total}`} label="Chuyên cần" tone="bg-success/10 text-success" />
              <Metric value={`${d.progress.homework_submitted}/${d.progress.homework_total}`} label="Bài tập" tone="bg-info/10 text-info" />
              <Metric value={d.progress.latest_big_test === null ? '—' : String(d.progress.latest_big_test)} label="Big Test mới nhất" tone="bg-primary/10 text-primary" />
            </div>
          </Block>

          <Block title="Lịch sử điểm danh" icon="fact_check">
            <div className="divide-y divide-surface-container-highest">
              {d.attendance_history.map((a) => (
                <div key={a.id} className="flex items-center justify-between py-2 text-xs">
                  <div>
                    <div className="font-semibold text-on-surface">{dmy(a.date)}</div>
                    <div className="text-on-surface-variant">
                      {a.class_name}
                      {a.note && ` · ${a.note}`}
                    </div>
                  </div>
                  <Pill tone={a.present ? 'success' : 'error'}>{a.status_label}</Pill>
                </div>
              ))}
              {d.attendance_history.length === 0 && <p className="text-xs text-on-surface-variant">Chưa có dữ liệu điểm danh.</p>}
            </div>
          </Block>

          <Block title="Kết quả Big Test" icon="workspace_premium">
            <div className="flex flex-col gap-2">
              {d.big_test_results.map((r) => (
                <div key={r.id} className="rounded-xl border border-surface-container-highest px-3 py-2 text-xs">
                  <div className="flex items-center justify-between">
                    <span className="font-bold text-on-surface">{r.title}</span>
                    <span className="font-mono font-black text-primary">{r.is_absent ? 'Vắng thi' : (r.overall_score ?? '—')}</span>
                  </div>
                  {!r.is_absent && (
                    <div className="mt-1 grid grid-cols-4 gap-1 text-center text-on-surface-variant">
                      <span>Nghe {r.listening_score ?? '—'}</span>
                      <span>Đọc {r.reading_score ?? '—'}</span>
                      <span>Viết {r.writing_score ?? '—'}</span>
                      <span>Nói {r.speaking_score ?? '—'}</span>
                    </div>
                  )}
                  {r.progress_note && <p className="mt-1 text-on-surface-variant">{r.progress_note}</p>}
                </div>
              ))}
              {d.big_test_results.length === 0 && <p className="text-xs text-on-surface-variant">Chưa có kết quả Big Test đã duyệt.</p>}
            </div>
          </Block>

          <TuitionCard d={d} onHistory={() => setDialog('history')} onReport={() => setDialog('report')} />

          <ProfileCard d={d} onEdit={() => setDialog('profile')} />
        </div>
      )}

      {dialog === 'history' && student && (
        <StudentPaymentsDialog
          studentId={student.id}
          onClose={close}
          load={async (id) => {
            const r = await portalApi.tuition(id)
            if (!r.history) throw new ApiError(404, 'not_found', 'Học viên chưa có khoản học phí nào.')
            return r.history
          }}
        />
      )}
      {dialog === 'report' && d?.student && (
        <ReportDialog
          studentId={d.student.id}
          debt={d.tuition.debt_amount}
          onClose={close}
          onDone={(m) => {
            close()
            notice.ok(m)
          }}
        />
      )}
      {dialog === 'profile' && d?.student && d.profile && (
        <ProfileDialog
          d={d}
          onClose={close}
          onDone={(m) => {
            close()
            notice.ok(m)
          }}
        />
      )}
    </PortalFrame>
  )
}

function QuickLink({ href, icon, title, sub, tone = 'text-primary bg-primary/10' }: { href: string; icon: string; title: string; sub: string; tone?: string }) {
  return (
    <PortalLink href={href} className="flex items-center gap-2.5 rounded-xl border border-surface-container-highest bg-surface p-3 shadow-sm hover:border-primary">
      <span className={`flex size-8 shrink-0 items-center justify-center rounded-lg ${tone}`}>
        <Icon name={icon} className="text-[20px]" />
      </span>
      <span className="min-w-0">
        <span className="block truncate text-xs font-bold text-on-surface">{title}</span>
        <span className="block text-xs text-on-surface-variant">{sub}</span>
      </span>
    </PortalLink>
  )
}

function Metric({ value, label, tone }: { value: string; label: string; tone: string }) {
  return (
    <div className={`rounded-xl p-2 ${tone}`}>
      <div className="text-lg font-black">{value}</div>
      <div className="text-xs text-on-surface-variant">{label}</div>
    </div>
  )
}

const REPORT_TONE: Record<PaymentReport['status'], 'warning' | 'success' | 'error'> = { pending: 'warning', confirmed: 'success', rejected: 'error' }

function TuitionCard({ d, onHistory, onReport }: { d: Home; onHistory: () => void; onReport: () => void }) {
  return (
    <section className="flex flex-col gap-4 rounded-2xl bg-gradient-to-br from-primary to-primary-hover p-4 text-white shadow-lg">
      <div className="flex items-center justify-between gap-2">
        <h2 className="flex items-center gap-1.5 text-base font-bold">
          <Icon name="account_balance_wallet" fill /> Thông tin học phí
        </h2>
        <div className="flex items-center gap-1.5">
          {d.can_write && (
            <button type="button" onClick={onReport} className="flex items-center gap-1 rounded-full bg-white/20 px-2.5 py-1 text-xs font-semibold hover:bg-white/30">
              <Icon name="send" className="text-[13px]" /> Báo đóng
            </button>
          )}
          <button type="button" onClick={onHistory} className="flex items-center gap-1 rounded-full bg-white/20 px-2.5 py-1 text-xs font-semibold hover:bg-white/30">
            Lịch sử <Icon name="chevron_right" className="text-[14px]" />
          </button>
        </div>
      </div>
      <button
        type="button"
        onClick={onHistory}
        title="Xem lịch sử thu học phí"
        className="grid grid-cols-2 gap-3 rounded-xl border border-white/10 bg-black/15 p-3 text-left hover:bg-black/25"
      >
        <span className="flex flex-col gap-0.5">
          <span className="text-xs font-semibold tracking-wider text-white/80 uppercase">Tổng đã đóng</span>
          <span className="font-mono text-xl font-bold">{formatMoney(d.tuition.paid_amount)}</span>
        </span>
        <span className="flex flex-col gap-0.5 border-l border-white/20 pl-3">
          <span className="text-xs font-semibold tracking-wider text-white/80 uppercase">Còn nợ</span>
          <span className="font-mono text-lg font-bold">{formatMoney(d.tuition.debt_amount)}</span>
        </span>
        <span className="col-span-2 flex items-center justify-end gap-0.5 text-xs font-semibold text-white/90">
          Xem lịch sử {d.tuition.installments ? `${d.tuition.installments} lần đóng` : 'thu học phí'}
          <Icon name="chevron_right" className="text-[14px]" />
        </span>
      </button>
      <div className="flex items-center justify-between rounded-xl bg-white/10 px-3 py-2 text-xs">
        <span className="font-medium text-white/90">Dự kiến khóa tới:</span>
        <span className="font-mono font-bold">{formatMoney(d.next_term_fee)}</span>
      </div>
      {d.payment_reports.length > 0 && (
        <div className="space-y-1.5 rounded-xl bg-white p-3 text-xs text-on-surface">
          <div className="font-bold">Yêu cầu báo đóng đã gửi</div>
          {d.payment_reports.slice(0, 3).map((r) => (
            <div key={r.id} className="flex flex-wrap items-center justify-between gap-2">
              <span>
                <span className="font-mono">{formatMoney(r.amount)}</span> · {submittedAt(r.created_at)}
                {r.rejection_reason && <span className="block text-error">Lý do: {r.rejection_reason}</span>}
              </span>
              <Pill tone={REPORT_TONE[r.status]}>{r.status_label}</Pill>
            </div>
          ))}
        </div>
      )}
    </section>
  )
}

function ProfileCard({ d, onEdit }: { d: Home; onEdit: () => void }) {
  const p = d.profile
  return (
    <Block
      title="Thông tin học sinh"
      icon="person"
      aside={
        <div className="flex items-center gap-1.5">
          {d.can_write && p && (
            <Button variant="secondary" className="h-8 px-3" onClick={onEdit}>
              <Icon name="edit" className="mr-1 text-[16px]" /> Sửa
            </Button>
          )}
          <Pill tone="success">{p?.status_label ?? '—'}</Pill>
        </div>
      }
    >
      {p ? (
        <dl className="grid grid-cols-2 gap-3 text-xs">
          <Item label="Ngày sinh">{p.dob ? dmy(p.dob) : '—'}</Item>
          <Item label="Lớp đang học">
            <span className="font-bold text-info">{d.student_classes.length ? d.student_classes.join(', ') : 'Chưa xếp lớp'}</span>
          </Item>
          <Item label="Giáo viên chính">{p.teacher_name ?? '—'}</Item>
          <Item label="Số điện thoại">
            <span className="font-mono">{p.phone || '—'}</span>
          </Item>
          <Item label="Ngày bắt đầu học" wide>
            {p.study_started_on ? dmy(p.study_started_on) : 'Chưa có buổi học đầu tiên'}
            {p.tenure_label && <span className="text-on-surface-variant"> · đã học {p.tenure_label}</span>}
          </Item>
          <Item label="Địa chỉ" wide>
            {p.address ?? '—'}
          </Item>
          {p.notes && (
            <div className="col-span-2 rounded-lg border border-warning/30 bg-warning-container p-2">
              <dt className="text-xs font-bold tracking-wider text-warning uppercase">Ghi chú</dt>
              <dd className="text-on-surface-variant">{p.notes}</dd>
            </div>
          )}
        </dl>
      ) : (
        <Empty icon="person_search" title="Chưa chọn học viên" />
      )}
    </Block>
  )
}

function Item({ label, wide, children }: { label: string; wide?: boolean; children: React.ReactNode }) {
  return (
    <div className={`flex flex-col gap-0.5 ${wide ? 'col-span-2 border-t border-surface-container-highest pt-2' : ''}`}>
      <dt className="text-xs font-bold tracking-wider text-on-surface-variant uppercase">{label}</dt>
      <dd className="font-medium text-on-surface">{children}</dd>
    </div>
  )
}

function ReportDialog({ studentId, debt, onClose, onDone }: { studentId: string; debt: number; onClose: () => void; onDone: (m: string) => void }) {
  const qc = useQueryClient()
  const [amount, setAmount] = useState(suggestedAmount(debt))
  const [content, setContent] = useState('')
  const m = useMutation({
    mutationFn: () => portalApi.reportPayment({ student_id: studentId, amount: parseAmount(amount), content }),
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['portal'] })
      onDone(r.message)
    },
  })
  const submit = (e: FormEvent) => {
    e.preventDefault()
    m.mutate()
  }
  return (
    <Dialog title="Báo đóng học phí" onClose={onClose}>
      <form onSubmit={submit} className="space-y-3">
        {m.isError && <Alert>{errorMessage(m.error)}</Alert>}
        <Field label="Số tiền đã chuyển (VNĐ)" inputMode="numeric" value={amount} onChange={(e) => setAmount(e.target.value)} required className="font-mono" />
        <TextArea
          label="Nội dung chuyển khoản / Ghi chú"
          value={content}
          onChange={setContent}
          maxLength={500}
          placeholder="Nhập mã giao dịch ngân hàng hoặc nội dung chuyển tiền..."
        />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={m.isPending}>
            Gửi xác nhận
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

function ProfileDialog({ d, onClose, onDone }: { d: Home; onClose: () => void; onDone: (m: string) => void }) {
  const qc = useQueryClient()
  const [phone, setPhone] = useState(d.profile?.phone ?? '')
  const [address, setAddress] = useState(d.profile?.address ?? '')
  const [notes, setNotes] = useState(d.profile?.notes ?? '')
  const m = useMutation({
    mutationFn: () => portalApi.updateContact(d.student!.id, { phone, address, notes }),
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['portal'] })
      onDone(r.message)
    },
  })
  return (
    <Dialog title="Cập nhật thông tin học viên" onClose={onClose}>
      <form
        onSubmit={(e) => {
          e.preventDefault()
          m.mutate()
        }}
        className="space-y-3"
      >
        {m.isError && <Alert>{errorMessage(m.error)}</Alert>}
        <Field label="Số điện thoại liên hệ" value={phone} onChange={(e) => setPhone(e.target.value)} required maxLength={20} />
        <Field label="Địa chỉ" value={address} onChange={(e) => setAddress(e.target.value)} maxLength={255} />
        <TextArea
          label="Ghi chú cho trung tâm / giáo viên"
          value={notes}
          onChange={setNotes}
          maxLength={500}
          placeholder="Ví dụ: Bé hay dị ứng phấn, xin phép vào muộn 5p..."
        />
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
