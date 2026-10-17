// Rà soát điểm danh (Kpi/AttendanceReview.vue): điểm danh học viên theo ngày / lớp trong phạm vi lớp của người xem, thống kê
// có mặt / muộn / vắng / có phép; bản ghi chờ rà soát → Duyệt hoặc Trả lại.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Field, Select } from '../../components/ui'
import { can } from '../../nav'
import { kpiApi } from './api'
import type { Tone } from './logic'

const statusTone = (s: string): Tone => (({ present: 'success', late: 'warning', absent: 'error' }) as Record<string, Tone>)[s] ?? 'info'

export function AttendanceReviewPage({ me }: { me: Me }) {
  const qc = useQueryClient()
  const [date, setDate] = useState('')
  const [classId, setClassId] = useState('')
  const [notice, setNotice] = useState('')
  const page = useQuery({ queryKey: ['kpi', 'attendance', date, classId], queryFn: () => kpiApi.attendance(date, classId), placeholderData: keepPreviousData })
  const review = useMutation({
    mutationFn: (v: { id: string; decision: 'approved' | 'rejected' }) => kpiApi.review(v.id, v.decision),
    onSuccess: (r) => {
      setNotice(r.message)
      qc.invalidateQueries({ queryKey: ['kpi', 'attendance'] })
    },
  })
  const d = page.data
  const canReview = can(me, 'kpi.confirm')

  return (
    <div className="space-y-6">
      <h1 className="text-xl font-semibold">Rà soát điểm danh (Học vụ)</h1>
      {notice && <Alert tone="success">{notice}</Alert>}
      {(page.isError || review.isError) && <Alert>{errorMessage(page.error ?? review.error)}</Alert>}
      {d?.pending && <Alert tone="info">{d.pending}</Alert>}
      {d && (
        <>
          <Card>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Ngày" type="date" value={d.date} onChange={(e) => setDate(e.target.value)} />
              <Select label="Lớp" value={d.class_id ?? ''} onChange={(e) => setClassId(e.target.value)}>
                <option value="">Tất cả lớp</option>
                {d.classes.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
            </div>
          </Card>
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <Stat label="Có mặt" value={d.summary.present} className="text-success" />
            <Stat label="Đi muộn" value={d.summary.late} className="text-warning" />
            <Stat label="Vắng" value={d.summary.absent} className="text-error" />
            <Stat label="Có phép" value={d.summary.excused} className="text-on-surface-variant" />
          </div>
          <div className="divide-y divide-surface-container-highest rounded-2xl border border-surface-container-highest bg-surface shadow-sm">
            {d.records.map((r) => (
              <div key={r.id} className="flex flex-wrap items-center justify-between gap-3 p-4">
                <div className="min-w-0">
                  <div className="text-sm font-semibold">{r.student}</div>
                  <div className="text-xs text-on-surface-variant">
                    {r.class} · GV: {r.teacher}
                    {r.note && ` · ${r.note}`}
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <Badge tone={statusTone(r.status)}>{r.status_label}</Badge>
                  <span className="text-xs text-on-surface-variant">{r.review_label}</span>
                  {canReview && r.review_status === 'pending_review' && (
                    <>
                      <Button className="h-8 px-3 text-xs" disabled={review.isPending} onClick={() => review.mutate({ id: r.id, decision: 'approved' })}>
                        Duyệt
                      </Button>
                      <Button
                        variant="danger"
                        className="h-8 px-3 text-xs"
                        disabled={review.isPending}
                        onClick={() => review.mutate({ id: r.id, decision: 'rejected' })}
                      >
                        Trả lại
                      </Button>
                    </>
                  )}
                </div>
              </div>
            ))}
            {d.records.length === 0 && <p className="p-6 text-center text-sm text-on-surface-variant">Không có dữ liệu điểm danh cho ngày/lớp đã chọn.</p>}
          </div>
        </>
      )}
    </div>
  )
}

function Stat({ label, value, className }: { label: string; value: number; className: string }) {
  return (
    <section className="rounded-xl border border-surface-container-highest bg-surface p-4 shadow-sm">
      <p className="text-xs font-semibold text-on-surface-variant uppercase">{label}</p>
      <p className={`mt-1 text-2xl font-bold ${className}`}>{value}</p>
    </section>
  )
}
