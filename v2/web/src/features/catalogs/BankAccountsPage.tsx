// Tài khoản ngân hàng nhận học phí (BankAccountController của Laravel); một tài khoản mặc định cho mã VietQR.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Checkbox, Dialog, Field, Select } from '../../components/ui'
import { catalogsApi, type BankAccount, type BankAccountInput, type BankAccountList } from './api'
import { Empty, PageTitle, Table, actionsTd, td, useAction } from './common'

export function BankAccountsPage(_: { me: Me }) {
  const [q, setQ] = useState('')
  const [editing, setEditing] = useState<BankAccount | 'new' | null>(null)
  const list = useQuery({ queryKey: ['bank-accounts', q], queryFn: () => catalogsApi.bankAccounts(q) })
  const act = useAction([['bank-accounts']])
  const d = list.data

  return (
    <Card title={<PageTitle title="Tài khoản ngân hàng">{<Button onClick={() => setEditing('new')}>Thêm tài khoản</Button>}</PageTitle>}>
      <div className="space-y-4">
        {d?.default_account ? (
          <Alert tone="info">
            Tài khoản mặc định cho mã VietQR: <strong>{d.default_account.bank_name}</strong> · {d.default_account.account_number} ·{' '}
            {d.default_account.account_holder}
          </Alert>
        ) : (
          d && <Alert tone="info">Chưa có tài khoản mặc định cho mã VietQR.</Alert>
        )}
        <div className="max-w-md">
          <Field label="Tìm kiếm" placeholder="Ngân hàng, số tài khoản, chủ tài khoản" value={q} onChange={(e) => setQ(e.target.value)} />
        </div>
        {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
        {act.notice}
        {d && d.accounts.length === 0 && <Empty>Chưa có tài khoản ngân hàng nào.</Empty>}
        {d && d.accounts.length > 0 && (
          <Table head={['Ngân hàng', 'Số tài khoản', 'Chủ tài khoản', 'Loại', 'Cơ sở', 'Trạng thái', '']}>
            {d.accounts.map((a) => (
              <tr key={a.id}>
                <td className={td}>
                  <p className="font-medium">{a.bank_name}</p>
                  <p className="text-xs text-on-surface-variant">
                    {a.bank_code}
                    {a.branch_location ? ` · ${a.branch_location}` : ''}
                  </p>
                </td>
                <td className={`${td} font-mono`}>{a.account_number}</td>
                <td className={td}>{a.account_holder}</td>
                <td className={td}>{a.type_label}</td>
                <td className={td}>{a.branch_name ?? 'Tất cả'}</td>
                <td className={`${td} space-x-1`}>
                  {a.is_default_vietqr && <Badge tone="info">Mặc định VietQR</Badge>}
                  {a.is_active ? <Badge tone="success">Đang dùng</Badge> : <Badge>Ngừng dùng</Badge>}
                </td>
                <td className={actionsTd}>
                  <a className="text-sm text-primary-hover hover:underline" href={a.vietqr_preview_url} target="_blank" rel="noopener noreferrer">
                    Xem QR
                  </a>
                  {!a.is_default_vietqr && a.is_active && (
                    <Button variant="secondary" onClick={() => act.run(() => catalogsApi.setDefaultBankAccount(a.id))}>
                      Đặt mặc định
                    </Button>
                  )}
                  <Button variant="secondary" onClick={() => setEditing(a)}>
                    Sửa
                  </Button>
                  <Button
                    variant="danger"
                    onClick={() => act.run(() => catalogsApi.deleteBankAccount(a.id), `Xóa tài khoản ${a.bank_name} · ${a.account_number}?`)}
                  >
                    Xóa
                  </Button>
                </td>
              </tr>
            ))}
          </Table>
        )}
      </div>
      {editing && d && (
        <BankAccountDialog
          account={editing === 'new' ? null : editing}
          list={d}
          onClose={() => setEditing(null)}
          onSaved={(msg) => act.setNotice({ tone: 'success', text: msg })}
        />
      )}
    </Card>
  )
}

function BankAccountDialog({
  account,
  list,
  onClose,
  onSaved,
}: {
  account: BankAccount | null
  list: BankAccountList
  onClose: () => void
  onSaved: (msg: string) => void
}) {
  const qc = useQueryClient()
  const [form, setForm] = useState({
    account_type: account?.account_type ?? list.types[0]?.value ?? 'company',
    bank_code: account?.bank_code ?? '',
    bank_name: account?.bank_name ?? '',
    account_number: account?.account_number ?? '',
    account_holder: account?.account_holder ?? '',
    branch_location: account?.branch_location ?? '',
    branch_id: account?.branch_id ?? '',
    is_default_vietqr: account?.is_default_vietqr ?? list.total_accounts === 0,
    is_active: account?.is_active ?? true,
  })
  const save = useMutation({
    mutationFn: () => {
      const body: BankAccountInput = { ...form, branch_id: form.branch_id || null }
      if (!account) delete body.is_active
      return account ? catalogsApi.updateBankAccount(account.id, body) : catalogsApi.createBankAccount(body)
    },
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['bank-accounts'] })
      onSaved(r.message)
      onClose()
    },
  })
  const set = (k: 'bank_code' | 'bank_name' | 'account_number' | 'account_holder' | 'branch_location') => (e: { target: { value: string } }) =>
    setForm({ ...form, [k]: e.target.value })

  return (
    <Dialog title={account ? 'Sửa tài khoản ngân hàng' : 'Thêm tài khoản ngân hàng'} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="grid gap-4 sm:grid-cols-2">
          <Select label="Loại tài khoản" value={form.account_type} onChange={(e) => setForm({ ...form, account_type: e.target.value })}>
            {list.types.map((t) => (
              <option key={t.value} value={t.value}>
                {t.label}
              </option>
            ))}
          </Select>
          <Select label="Cơ sở sử dụng" value={form.branch_id} onChange={(e) => setForm({ ...form, branch_id: e.target.value })}>
            <option value="">Tất cả cơ sở</option>
            {list.branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.code} – {b.name}
              </option>
            ))}
          </Select>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Mã ngân hàng (VietQR)" required maxLength={20} value={form.bank_code} onChange={set('bank_code')} hint="Ví dụ VCB, TCB, MB" />
          <Field label="Tên ngân hàng" required maxLength={255} value={form.bank_name} onChange={set('bank_name')} />
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Số tài khoản" required maxLength={50} value={form.account_number} onChange={set('account_number')} />
          <Field label="Chủ tài khoản" required maxLength={255} value={form.account_holder} onChange={set('account_holder')} />
        </div>
        <Field label="Chi nhánh ngân hàng" maxLength={255} value={form.branch_location} onChange={set('branch_location')} />
        <Checkbox
          label="Đặt làm tài khoản mặc định cho mã VietQR"
          checked={form.is_default_vietqr}
          onChange={(e) => setForm({ ...form, is_default_vietqr: e.target.checked })}
        />
        {account && (
          <Checkbox label="Đang dùng" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
        )}
        <Button type="submit" disabled={save.isPending}>
          Lưu
        </Button>
      </form>
    </Dialog>
  )
}
