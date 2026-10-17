// Tab "Học phí" trên hồ sơ học viên: tổng phải nộp / đã nộp / còn nợ, các khoản học phí, mở lịch sử thanh toán,
// bill "Thông báo nộp học phí" và lập phiếu thu.
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button } from '../../components/ui'
import { can } from '../../nav'
import { navigate } from '../../router'
import { tuitionApi } from './api'
import { Stat, StudentPaymentsDialog, Tag } from './components'
import { formatMoney } from './logic'

export function StudentTuitionPanel({ me, studentId }: { me: Me; studentId: string }) {
  const q = useQuery({
    queryKey: ['tuition', 'payments', studentId],
    queryFn: () => tuitionApi.payments(studentId),
  })
  const [open, setOpen] = useState(false)
  const d = q.data
  if (q.isError) return <Alert>{errorMessage(q.error)}</Alert>
  if (!d) return <p className="text-sm text-on-surface-variant">Đang tải…</p>
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Stat label="Phải nộp" value={formatMoney(d.summary.final_amount)} />
        <Stat label="Đã nộp" value={formatMoney(d.summary.paid_amount)} tone="success" />
        <Stat label="Còn nợ" value={formatMoney(d.summary.debt_amount)} tone={d.summary.debt_amount > 0 ? 'error' : undefined} />
        <Stat label="Số đợt nộp" value={String(d.summary.installments)} />
      </div>
      {d.tuitions.length === 0 ? (
        <p className="text-sm text-on-surface-variant">Học viên chưa có khoản học phí.</p>
      ) : (
        <ul className="divide-y divide-surface-container-highest text-sm">
          {d.tuitions.map((t) => (
            <li key={t.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <span>
                <span className="font-medium">{t.label}</span>
                {t.class_name && <span className="text-on-surface-variant"> · {t.class_name}</span>}
                <span className="block text-xs text-on-surface-variant">
                  Đã nộp {formatMoney(t.paid_amount)} / {formatMoney(t.final_amount)}
                  {t.due_date && ` · Hạn ${t.due_date}`}
                </span>
              </span>
              <span className="flex items-center gap-2">
                <Tag tone={t.status_color}>{t.status_label}</Tag>
                <Button variant="secondary" onClick={() => navigate(`/tuition/bill/${t.id}`)}>
                  Thông báo nộp học phí
                </Button>
              </span>
            </li>
          ))}
        </ul>
      )}
      <div className="flex flex-wrap justify-end gap-2">
        <Button variant="secondary" onClick={() => setOpen(true)}>
          Lịch sử thanh toán
        </Button>
        {can(me, 'tuition.create') && <Button onClick={() => navigate(`/tuition/receipts/new?student_id=${studentId}`)}>Lập phiếu thu</Button>}
      </div>
      {open && <StudentPaymentsDialog studentId={studentId} onClose={() => setOpen(false)} />}
    </div>
  )
}
