// Nhập khách từ Excel (Laravel Crm/Import): tải file → xem trước + lỗi từng dòng → nhập các dòng hợp lệ.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Field, Select } from '../../components/ui'
import { navigate } from '../../router'
import { crmApi, downloads, previewImport, type ImportPreview, type ImportResult } from './api'
import { PATHS, Table, Td } from './components'
import { ownersForBranch } from './logic'

export function ImportPage({ me }: { me: Me }) {
  const qc = useQueryClient()
  const options = useQuery({ queryKey: ['crm', 'import-options'], queryFn: crmApi.importOptions })
  const [file, setFile] = useState<File | null>(null)
  const [branch, setBranch] = useState<string | null>(null)
  const [owner, setOwner] = useState('')
  const [source, setSource] = useState('')
  const [preview, setPreview] = useState<ImportPreview | null>(null)
  const [result, setResult] = useState<ImportResult | null>(null)
  const o = options.data
  const branchId = branch ?? o?.default_branch_id ?? ''

  const upload = useMutation({
    mutationFn: () => {
      const form = new FormData()
      if (file) form.append('file', file)
      form.append('branch_id', branchId)
      if (owner) form.append('assigned_user_id', owner)
      form.append('default_source', source)
      return previewImport(me.csrf_token, form)
    },
    onSuccess: (p) => {
      setPreview(p)
      setResult(null)
    },
  })
  const commit = useMutation({
    mutationFn: () => crmApi.commitImport(preview!.id),
    onSuccess: (r) => {
      setResult(r)
      setPreview(null)
      qc.invalidateQueries({ queryKey: ['crm'] })
    },
  })
  const cancel = useMutation({ mutationFn: () => crmApi.cancelImport(preview!.id), onSuccess: () => setPreview(null) })

  return (
    <div className="space-y-4">
      <Card
        title={
          <span className="flex flex-wrap items-center justify-between gap-3">
            Nhập khách hàng từ Excel
            <a
              className="inline-flex h-10 items-center rounded-lg border border-outline-variant px-4 text-sm font-medium hover:bg-surface-container-low"
              href={downloads.importTemplate}
            >
              Tải file mẫu
            </a>
          </span>
        }
      >
        <p className="mb-3 text-sm text-on-surface-variant">
          File .xlsx hoặc .csv (UTF-8), dòng 1 là tiêu đề, bắt buộc cột “Họ tên” và “Số điện thoại”; tối đa {o?.max_rows ?? 1000} dòng, 5 MB.
          File .xls cũ: mở bằng Excel và lưu lại dạng .xlsx.
        </p>
        {options.isError && <Alert>{errorMessage(options.error)}</Alert>}
        {o && (
          <form
            className="grid gap-3 sm:grid-cols-2"
            onSubmit={(e) => {
              e.preventDefault()
              upload.mutate()
            }}
          >
            <div className="space-y-1.5">
              <label className="block text-sm font-medium" htmlFor="crm-import-file">
                File khách hàng *
              </label>
              <input
                id="crm-import-file"
                type="file"
                accept=".xlsx,.csv"
                className="block w-full text-sm"
                onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              />
            </div>
            <Select label="Chi nhánh nhận khách *" value={branchId} onChange={(e) => (setBranch(e.target.value), setOwner(''))}>
              <option value="">— Chọn —</option>
              {o.branches.map((b) => (
                <option key={b.value} value={b.value}>
                  {b.label}
                </option>
              ))}
            </Select>
            {o.can_assign && (
              <Select label="Người phụ trách mặc định" value={owner} onChange={(e) => setOwner(e.target.value)}>
                <option value="">— Chính tôi —</option>
                {ownersForBranch(o.owners, branchId).map((u) => (
                  <option key={u.value} value={u.value}>
                    {u.label}
                  </option>
                ))}
              </Select>
            )}
            <Field label="Nguồn mặc định (dòng không ghi nguồn)" value={source} onChange={(e) => setSource(e.target.value)} maxLength={255} />
            <div className="flex items-end sm:col-span-2">
              <Button type="submit" disabled={!file || !branchId || upload.isPending}>
                {upload.isPending ? 'Đang kiểm tra…' : 'Kiểm tra dữ liệu'}
              </Button>
            </div>
          </form>
        )}
        <div className="mt-3 space-y-2">
          {upload.isError && <Alert>{errorMessage(upload.error)}</Alert>}
          {result && (
            <Alert tone="success">
              {result.message}{' '}
              <button type="button" className="underline" onClick={() => navigate(PATHS.list)}>
                Xem danh sách khách
              </button>
            </Alert>
          )}
          {result && result.skipped.length > 0 && (
            <ul className="list-disc pl-5 text-xs text-on-surface-variant">
              {result.skipped.map((s) => (
                <li key={s}>{s}</li>
              ))}
            </ul>
          )}
        </div>
      </Card>
      {preview && (
        <Card title={`Xem trước: ${preview.file_name}`}>
          <p className="mb-3 text-sm">
            Chi nhánh <b>{preview.branch_name}</b> · người phụ trách mặc định <b>{preview.assigned_user_name}</b> ·{' '}
            <Badge tone="success">{preview.valid_count} dòng hợp lệ</Badge> <Badge tone="error">{preview.error_count} dòng lỗi</Badge>
          </p>
          <div className="mb-3 flex flex-wrap gap-2">
            <Button onClick={() => commit.mutate()} disabled={preview.valid_count === 0 || commit.isPending}>
              Nhập {preview.valid_count} khách hợp lệ
            </Button>
            {preview.error_count > 0 && (
              <a
                className="inline-flex h-10 items-center rounded-lg border border-outline-variant px-4 text-sm font-medium hover:bg-surface-container-low"
                href={downloads.importErrors(preview.id)}
              >
                Tải file dòng lỗi
              </a>
            )}
            <Button variant="secondary" onClick={() => cancel.mutate()} disabled={cancel.isPending}>
              Hủy
            </Button>
          </div>
          {commit.isError && <Alert>{errorMessage(commit.error)}</Alert>}
          <Table head={['Dòng', 'Họ tên', 'SĐT', 'Email', 'Nguồn', 'Phụ trách', 'Kết quả']}>
            {preview.rows.map((r) => (
              <tr key={r.line} className={r.errors.length ? 'bg-error-container/40' : ''}>
                <Td>{r.line}</Td>
                <Td>{r.data.name || '—'}</Td>
                <Td>{r.data.phone || '—'}</Td>
                <Td>{r.data.email || '—'}</Td>
                <Td>{r.data.source || '—'}</Td>
                <Td>{r.data.owner_name || r.data.owner || '—'}</Td>
                <Td>{r.errors.length ? <span className="text-error">{r.errors.join('; ')}</span> : <Badge tone="success">Hợp lệ</Badge>}</Td>
              </tr>
            ))}
          </Table>
        </Card>
      )}
    </div>
  )
}
