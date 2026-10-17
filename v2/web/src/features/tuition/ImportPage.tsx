// Nhập học phí từ Excel (Tuition/Import.vue): Tải file → Xem trước (lỗi từng dòng) → Kết quả. Dòng hợp lệ tạo hồ sơ học
// phí (học viên chưa có) và / hoặc phiếu thu CHỜ DUYỆT cho số tiền đã đóng — không tự duyệt, không tự cấp số hóa đơn.
import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Select } from '../../components/ui'
import { can } from '../../nav'
import { navigate, useQueryParam } from '../../router'
import { tuitionApi, type ImportResult } from './api'
import { Tag } from './components'
import { formatMoney } from './logic'

export function ImportPage({ me }: { me: Me }) {
  const canCreate = can(me, 'tuition.create')
  const token = useQueryParam('token')
  const page = useQuery({ queryKey: ['tuition', 'import', token], queryFn: () => tuitionApi.importForm(token || undefined) })
  const d = page.data
  const [branchId, setBranchId] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [result, setResult] = useState<ImportResult | null>(null)
  const preview = useMutation({
    mutationFn: (x: { branch: string; file: File }) => tuitionApi.importPreview(x.branch, x.file),
    onSuccess: (p) => {
      setResult(null)
      navigate(`/tuition/import?token=${p.token}`)
    },
  })
  const confirm = useMutation({
    mutationFn: (t: string) => tuitionApi.importConfirm(t),
    onSuccess: (r) => {
      setResult(r)
      setFile(null)
      navigate('/tuition/import')
    },
  })
  const p = d?.preview ?? null
  const branch = branchId || (d?.branches.length === 1 ? d.branches[0].value : '')

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Nhập học phí từ Excel</h1>
          <p className="text-sm text-on-surface-variant">
            Tạo hồ sơ học phí cho học viên chưa có và ghi nhận số tiền đã đóng thành phiếu thu chờ duyệt (Kế toán đối soát & duyệt sau).
          </p>
        </div>
        <Button variant="secondary" onClick={() => navigate('/tuition/students')}>
          Về Công nợ học viên
        </Button>
      </div>
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {result && (
        <Alert tone="success">
          {result.message}
          {result.skipped > 0 && ` Bỏ qua ${result.skipped} dòng lỗi.`}
        </Alert>
      )}
      {result && result.failed.length > 0 && (
        <Alert>
          Không ghi được {result.failed.length} dòng:
          <ul className="mt-1 list-disc pl-5">
            {result.failed.map((f) => (
              <li key={f.line}>
                Dòng {f.line}: {f.error}
              </li>
            ))}
          </ul>
        </Alert>
      )}

      {d && !p && !canCreate && <Alert tone="info">Bạn chỉ xem được học phí; cần quyền lập phiếu thu để nhập từ Excel.</Alert>}
      {d && !p && canCreate && (
        <Card>
          <form
            className="space-y-4"
            onSubmit={(e) => {
              e.preventDefault()
              if (file) preview.mutate({ branch, file })
            }}
          >
            {preview.isError && <Alert>{errorMessage(preview.error)}</Alert>}
            <div className="grid gap-4 sm:grid-cols-2">
              <Select label="Chi nhánh" value={branch} onChange={(e) => setBranchId(e.target.value)}>
                <option value="">— Chọn chi nhánh —</option>
                {d.branches.map((b) => (
                  <option key={b.value} value={b.value}>
                    {b.label}
                  </option>
                ))}
              </Select>
              <div className="space-y-1.5">
                <label htmlFor="tuition-import-file" className="block text-sm font-medium text-on-surface">
                  File Excel / CSV
                </label>
                <input
                  id="tuition-import-file"
                  type="file"
                  accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                  className="block w-full text-sm"
                  onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                />
              </div>
            </div>
            <p className="text-xs text-on-surface-variant">
              Cột của file mẫu: {d.template_headings}. Tối đa 1000 dòng, 10MB.{' '}
              <a className="font-medium text-primary-hover hover:underline" href={tuitionApi.importTemplateUrl}>
                Tải file mẫu
              </a>
            </p>
            <div className="flex justify-end">
              <Button type="submit" disabled={!branch || !file || preview.isPending}>
                Xem trước
              </Button>
            </div>
          </form>
        </Card>
      )}

      {p && (
        <Card>
          <div className="mb-3 flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 className="font-semibold">Xem trước: {p.file_name}</h2>
              <p className="text-sm text-on-surface-variant">
                {p.branch_name} · {p.rows.length} dòng · {p.valid_count} dòng hợp lệ sẽ được nhập, dòng lỗi bị bỏ qua.
              </p>
            </div>
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => navigate('/tuition/import')}>
                Chọn file khác
              </Button>
              <Button disabled={!canCreate || p.valid_count === 0 || confirm.isPending} onClick={() => confirm.mutate(p.token)}>
                Nhập {p.valid_count} dòng hợp lệ
              </Button>
            </div>
          </div>
          {confirm.isError && <Alert>{errorMessage(confirm.error)}</Alert>}
          <ul className="divide-y divide-surface-container-highest">
            {p.rows.map((r) => (
              <li key={r.line} className="py-2 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-on-surface-variant">Dòng {r.line}</span>
                  <span className="font-medium">{r.student_name ?? r.student_code ?? '—'}</span>
                  {r.student_name && <span className="font-mono text-xs">{r.student_code}</span>}
                  {r.errors.length > 0 ? <Tag tone="error">Lỗi</Tag> : <Tag tone="success">Hợp lệ</Tag>}
                  {r.creates_tuition && <Tag tone="info">Tạo hồ sơ học phí</Tag>}
                </div>
                <div className="text-xs text-on-surface-variant">
                  {r.creates_tuition && r.final_amount !== null && `Phải nộp ${formatMoney(r.final_amount)}`}
                  {r.due_date && ` · Hạn ${r.due_date}`}
                  {r.class_code && ` · Lớp ${r.class_code}`}
                  {r.paid_amount > 0 &&
                    ` · Đã đóng ${formatMoney(r.paid_amount)} (${r.method_label}${r.transaction_code ? ` – ${r.transaction_code}` : ''})`}
                </div>
                {r.errors.length > 0 && (
                  <ul className="mt-1 list-disc pl-5 text-xs text-error">
                    {r.errors.map((e) => (
                      <li key={e}>{e}</li>
                    ))}
                  </ul>
                )}
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  )
}
