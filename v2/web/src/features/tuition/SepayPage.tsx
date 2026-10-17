// Webhook SePay (tab "Webhook SePay" + "Nhật ký giao dịch SePay" của SystemConfig/BankAccounts.vue): cấu hình kết nối
// cổng SePay tự gạch nợ học phí và 15 giao dịch gần nhất. Khóa bí mật không gửi xuống trình duyệt: để trống = giữ khóa cũ.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Button, Card, Checkbox, Field, PasswordField, Select } from '../../components/ui'
import { can, navItemFor } from '../../nav'
import { navigate } from '../../router'
import { tuitionApi, type BankTxn, type SepayConfig, type SepayInput } from './api'
import { Tag } from './components'
import { formatMoney } from './logic'

const statusTone = (s: string) =>
  s === 'matched' || s === 'manual_matched'
    ? 'success'
    : s === 'ignored' || s === 'pending'
      ? 'neutral'
      : s === 'rejected_account'
        ? 'error'
        : 'warning'

const formOf = (c: SepayConfig): SepayInput => ({
  webhook_name: c.webhook_name,
  webhook_url: c.webhook_url,
  transaction_type: c.transaction_type,
  data_format: c.data_format,
  auth_method: c.auth_method,
  secret_key: '',
  api_key: '',
  is_active: c.is_active,
  auto_retry: c.auto_retry,
})

export function SepayPage({ me }: { me: Me }) {
  const qc = useQueryClient()
  const page = useQuery({ queryKey: ['tuition', 'sepay'], queryFn: tuitionApi.sepay })
  const d = page.data
  const [form, setForm] = useState<SepayInput | null>(null)
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  useEffect(() => {
    if (d?.config && !form) setForm(formOf(d.config))
  }, [d, form])
  const save = useMutation({
    mutationFn: (x: SepayInput) => tuitionApi.saveSepay(x),
    onSuccess: (x) => {
      setNotice({ tone: 'success', text: x.message })
      setForm(null)
      qc.invalidateQueries({ queryKey: ['tuition', 'sepay'] })
    },
    onError: (e) => setNotice({ tone: 'error', text: errorMessage(e) }),
  })
  const set = <K extends keyof SepayInput>(k: K, v: SepayInput[K]) => setForm((f) => (f ? { ...f, [k]: v } : f))
  const showBank = !!navItemFor('/system-config/bank-accounts') && can(me, 'bank_account.manage')
  const c = d?.config

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Webhook SePay</h1>
          <p className="text-sm text-on-surface-variant">
            Cổng SePay báo giao dịch vào tài khoản ngân hàng của trung tâm; hệ thống tự khớp học viên theo nội dung chuyển khoản, lập phiếu thu và cấp
            hóa đơn điện tử.
          </p>
        </div>
        {showBank && (
          <Button variant="secondary" onClick={() => navigate('/system-config/bank-accounts')}>
            Tài khoản ngân hàng
          </Button>
        )}
      </div>
      {page.isError && <Alert>{errorMessage(page.error)}</Alert>}
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      {d && !d.enabled && (
        <Alert tone="info">SePay webhook hiện đang tắt trên hệ thống (SEPAY_WEBHOOK_ENABLED). Liên hệ quản trị máy chủ để bật.</Alert>
      )}

      {c && form && (
        <Card>
          <form
            className="space-y-4"
            onSubmit={(e) => {
              e.preventDefault()
              save.mutate(form)
            }}
          >
            <div className="rounded-lg bg-surface-container-low p-3 text-sm">
              <div className="font-medium">Địa chỉ webhook của máy chủ này</div>
              <div className="font-mono break-all">{c.current_endpoint}</div>
              <div className="text-xs text-on-surface-variant">Khai địa chỉ này trên SePay (Tích hợp → Webhook).</div>
            </div>
            {c.different_host && (
              <Alert tone="info">
                URL webhook đã lưu trỏ tới {c.saved_host}, khác tên miền đang mở ({c.current_host}). Kiểm tra lại cấu hình trên SePay.
              </Alert>
            )}
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Tên webhook" value={form.webhook_name} onChange={(e) => set('webhook_name', e.target.value)} />
              <Field label="URL webhook" value={form.webhook_url} onChange={(e) => set('webhook_url', e.target.value)} />
              <Select label="Loại giao dịch" value={form.transaction_type} onChange={(e) => set('transaction_type', e.target.value)}>
                <option value="in">Tiền vào</option>
                <option value="out">Tiền ra</option>
                <option value="all">Tất cả</option>
              </Select>
              <Select label="Định dạng dữ liệu" value={form.data_format} onChange={(e) => set('data_format', e.target.value)}>
                <option value="json">JSON</option>
                <option value="form">Form data</option>
                <option value="urlencoded">x-www-form-urlencoded</option>
              </Select>
              <Select label="Phương thức xác thực" value={form.auth_method} onChange={(e) => set('auth_method', e.target.value)}>
                <option value="hmac_sha256">HMAC-SHA256 (chữ ký)</option>
                <option value="api_key">API Key</option>
              </Select>
              <div />
              <PasswordField
                label={c.has_secret ? 'Secret Key (để trống = giữ khóa đã lưu)' : 'Secret Key'}
                value={form.secret_key}
                autoComplete="new-password"
                onChange={(e) => set('secret_key', e.target.value)}
              />
              <PasswordField
                label={c.has_api_key ? 'API Key (để trống = giữ khóa đã lưu)' : 'API Key'}
                value={form.api_key}
                autoComplete="new-password"
                onChange={(e) => set('api_key', e.target.value)}
              />
            </div>
            <div className="flex flex-wrap gap-6">
              <Checkbox label="Kích hoạt webhook" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} />
              <Checkbox label="Tự gửi lại khi lỗi" checked={form.auto_retry} onChange={(e) => set('auto_retry', e.target.checked)} />
            </div>
            <div className="flex justify-end">
              <Button type="submit" disabled={save.isPending}>
                Lưu cấu hình SePay
              </Button>
            </div>
          </form>
        </Card>
      )}

      {d?.enabled && (
        <Card>
          <h2 className="mb-3 font-semibold">Nhật ký giao dịch SePay</h2>
          {d.transactions.length === 0 ? (
            <p className="text-sm text-on-surface-variant">Chưa nhận giao dịch nào từ SePay.</p>
          ) : (
            <ul className="divide-y divide-surface-container-highest">
              {d.transactions.map((t: BankTxn) => (
                <li key={t.id} className="flex flex-wrap items-start justify-between gap-3 py-3 text-sm">
                  <div className="min-w-0 space-y-0.5">
                    <div className="font-medium">
                      #{t.sepay_id} · {t.date} <Tag tone={statusTone(t.status)}>{t.status_label}</Tag>
                    </div>
                    <div className="break-words text-on-surface-variant">{t.content || '(không có nội dung)'}</div>
                    {t.response_message && <div className="text-xs text-on-surface-variant">{t.response_message}</div>}
                  </div>
                  <div className={`font-semibold ${t.transfer_type === 'in' ? 'text-success' : 'text-error'}`}>
                    {t.transfer_type === 'in' ? '+' : '−'}
                    {formatMoney(t.amount)}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}
    </div>
  )
}
