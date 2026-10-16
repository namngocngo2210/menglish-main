// Báo cáo định kỳ của tôi: vai trò có màn có cấu trúc cho kỳ chính → mở màn đó; vai trò khác nộp báo cáo chữ tự do.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { errorMessage } from '../../api'
import { Alert, Button, Dialog, Field, Select } from '../../components/ui'
import { reportsApi, type MyReports } from './api'
import { Loading, PageHeader, Pager, ReportTabs, TextArea } from './components'
import { formatDate, kindPath } from './logic'
import { replacePath } from './shared'

export function MyPage() {
  const [page, setPage] = useState(1)
  const q = useQuery({ queryKey: ['reports', 'my', page], queryFn: () => reportsApi.my(page) })
  const primary = q.data?.primary_kind
  useEffect(() => {
    if (primary) replacePath(kindPath(primary))
  }, [primary])
  if (q.isError) return <Alert>{errorMessage(q.error)}</Alert>
  if (!q.data || primary) return <Loading />
  return <MyView data={q.data} onPage={setPage} />
}

function MyView({ data, onPage }: { data: MyReports; onPage: (p: number) => void }) {
  const [creating, setCreating] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const lower = data.label.toLocaleLowerCase('vi')
  return (
    <div>
      <PageHeader
        title={`${data.label} của tôi`}
        meta={`Nộp và theo dõi ${lower} theo vai trò của bạn`}
        actions={<Button onClick={() => setCreating(true)}>Nộp {lower}</Button>}
      />
      <ReportTabs tabs={data.tabs} current="" />
      <div className="space-y-6">
        {message && <Alert tone="success">{message}</Alert>}
        {data.monthly_due && (
          <Alert tone={data.monthly_due.submitted ? 'success' : 'error'}>
            <strong>Hạn nộp: {data.monthly_due.due_label}</strong> (báo cáo tháng {data.monthly_due.month_label}) —{' '}
            {data.monthly_due.submitted ? 'đã nộp.' : 'chưa nộp. Hệ thống sẽ nhắc trước hạn 3 ngày, 1 ngày và đúng ngày hạn.'}
          </Alert>
        )}
        <section className="space-y-3">
          <h2 className="text-sm font-bold tracking-wider uppercase">Lịch sử đã nộp</h2>
          {data.reports.map((r) => (
            <article key={r.id} className="rounded-xl border border-surface-container-highest bg-surface p-5 shadow-sm">
              <div className="flex items-center justify-between gap-4">
                <span className="text-sm font-bold">{r.title}</span>
                <span className="text-xs text-on-surface-variant">{formatDate(r.report_date)}</span>
              </div>
              <p className="mt-2 text-sm whitespace-pre-line text-on-surface-variant">{r.content}</p>
            </article>
          ))}
          {!data.reports.length && <p className="rounded-xl border border-surface-container-highest bg-surface p-6 text-center text-sm text-on-surface-variant">Bạn chưa nộp {lower} nào.</p>}
          <Pager page={data.page} onPage={onPage} />
        </section>
      </div>
      {creating && (
        <NewReportDialog
          data={data}
          onClose={() => setCreating(false)}
          onDone={(m) => {
            setMessage(m)
            setCreating(false)
          }}
        />
      )}
    </div>
  )
}

function NewReportDialog({ data, onClose, onDone }: { data: MyReports; onClose: () => void; onDone: (msg: string) => void }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({ title: '', content: '', report_date: data.today, class_id: '' })
  const save = useMutation({
    mutationFn: () => reportsApi.saveMy({ ...form, class_id: form.class_id || null }),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['reports', 'my'] })
      onDone(r.message)
    },
  })
  return (
    <Dialog title={`Nộp ${data.label.toLocaleLowerCase('vi')} mới`} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <Field label="Tiêu đề" required maxLength={255} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
        <Field label="Ngày báo cáo" type="date" value={form.report_date} onChange={(e) => setForm({ ...form, report_date: e.target.value })} />
        {data.classes.length > 0 && (
          <Select label="Lớp liên quan (tùy chọn)" value={form.class_id} onChange={(e) => setForm({ ...form, class_id: e.target.value })}>
            <option value="">-- Không gắn lớp --</option>
            {data.classes.map((c) => (
              <option key={c.value} value={c.value}>
                {c.label}
              </option>
            ))}
          </Select>
        )}
        <TextArea label="Nội dung" rows={6} required value={form.content} placeholder="Kết quả thực hiện, tồn đọng, kế hoạch..." onChange={(v) => setForm({ ...form, content: v })} />
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Hủy
          </Button>
          <Button type="submit" disabled={save.isPending}>
            Nộp báo cáo
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
