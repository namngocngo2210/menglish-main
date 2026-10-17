// Nhắc nợ học phí (DebtReminderConfigController của Laravel): thiết lập nhanh và các mốc nhắc theo hạn đóng.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Checkbox, Dialog, Field, Select } from '../../components/ui'
import { catalogsApi, type DebtReminderConfig, type DebtRule, type DebtRuleInput, type Timing } from './api'
import { Empty, PageTitle, Table, TextArea, actionsTd, intOrNull, td, useAction } from './common'
import { offsetLabel } from './format'

export function DebtRemindersPage(_: { me: Me }) {
  const list = useQuery({ queryKey: ['debt-reminders'], queryFn: catalogsApi.debtReminders })
  const act = useAction([['debt-reminders']])
  const [editing, setEditing] = useState<DebtRule | 'new' | null>(null)
  const d = list.data

  return (
    <div className="space-y-4">
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {act.notice}
      {d && <QuickSettings config={d} onSaved={(msg) => act.setNotice({ tone: 'success', text: msg })} />}
      <Card title={<PageTitle title="Mốc nhắc nợ">{d && <Button onClick={() => setEditing('new')}>Thêm mốc nhắc</Button>}</PageTitle>}>
        {d && d.rules.length === 0 && <Empty>Chưa có mốc nhắc nợ nào.</Empty>}
        {d && d.rules.length > 0 && (
          <Table head={['Mã mốc', 'Thời điểm', 'Tiêu đề', 'Kênh gửi', 'Trạng thái', '']}>
            {d.rules.map((r) => (
              <tr key={r.id}>
                <td className={`${td} font-mono`}>{r.milestone_key}</td>
                <td className={`${td} whitespace-nowrap`}>{r.offset_label}</td>
                <td className={td}>
                  <p className="font-medium">{r.title}</p>
                  <p className="line-clamp-2 text-xs text-on-surface-variant">{r.template_content}</p>
                </td>
                <td className={`${td} text-xs`}>
                  {r.channels.map((c) => d.channels.find((x) => x.value === c)?.label ?? c).join('; ') || '—'}
                </td>
                <td className={td}>{r.is_enabled ? <Badge tone="success">Đang bật</Badge> : <Badge>Đang tắt</Badge>}</td>
                <td className={actionsTd}>
                  <Button variant="secondary" onClick={() => setEditing(r)}>
                    Sửa
                  </Button>
                </td>
              </tr>
            ))}
          </Table>
        )}
      </Card>
      {editing && d && (
        <RuleDialog
          rule={editing === 'new' ? null : editing}
          config={d}
          onClose={() => setEditing(null)}
          onSaved={(msg) => act.setNotice({ tone: 'success', text: msg })}
        />
      )}
    </div>
  )
}

function QuickSettings({ config, onSaved }: { config: DebtReminderConfig; onSaved: (msg: string) => void }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({
    must_contact_days: String(config.must_contact_days),
    first_days: String(config.first_days),
    repeat_days: String(config.repeat_days),
  })
  const save = useMutation({
    mutationFn: () =>
      catalogsApi.updateDebtSettings({
        must_contact_days: intOrNull(form.must_contact_days) ?? 0,
        first_days: intOrNull(form.first_days) ?? 0,
        repeat_days: intOrNull(form.repeat_days) ?? 0,
      }),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['debt-reminders'] })
      onSaved(r.message)
    },
  })
  return (
    <Card title="Thiết lập nhanh">
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="grid gap-4 md:grid-cols-3">
          <Field
            label="Nhắc trước hạn (ngày)"
            type="number"
            min={1}
            max={60}
            required
            value={form.first_days}
            onChange={(e) => setForm({ ...form, first_days: e.target.value })}
          />
          <Field
            label="Nhắc lại sát hạn (ngày)"
            type="number"
            min={1}
            max={3}
            required
            value={form.repeat_days}
            onChange={(e) => setForm({ ...form, repeat_days: e.target.value })}
            hint="1–3 ngày, gần hạn hơn mốc nhắc trước hạn."
          />
          <Field
            label="Quá hạn bắt buộc liên hệ (ngày)"
            type="number"
            min={1}
            max={60}
            required
            value={form.must_contact_days}
            onChange={(e) => setForm({ ...form, must_contact_days: e.target.value })}
          />
        </div>
        <Button type="submit" disabled={save.isPending}>
          Lưu thiết lập
        </Button>
      </form>
    </Card>
  )
}

function RuleDialog({
  rule,
  config,
  onClose,
  onSaved,
}: {
  rule: DebtRule | null
  config: DebtReminderConfig
  onClose: () => void
  onSaved: (msg: string) => void
}) {
  const qc = useQueryClient()
  const [form, setForm] = useState({
    milestone_key: rule?.milestone_key ?? '',
    title: rule?.title ?? '',
    template_content: rule?.template_content ?? '',
    timing: (rule?.timing ?? 'before') as Timing,
    days: rule?.days != null ? String(rule.days) : '',
    channels: rule?.channels ?? config.channels.map((c) => c.value),
    is_enabled: rule?.is_enabled ?? true,
  })
  const save = useMutation({
    mutationFn: () => {
      const body: DebtRuleInput = { ...form, days: form.timing === 'due' ? null : intOrNull(form.days) }
      return catalogsApi.saveDebtRule(body)
    },
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['debt-reminders'] })
      onSaved(r.message)
      onClose()
    },
  })
  const toggleChannel = (c: string, on: boolean) =>
    setForm({ ...form, channels: on ? [...form.channels, c] : form.channels.filter((x) => x !== c) })

  return (
    <Dialog title={rule ? `Sửa mốc ${rule.milestone_key}` : 'Thêm mốc nhắc nợ'} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <Field
          label="Mã mốc"
          maxLength={30}
          disabled={!!rule}
          value={form.milestone_key}
          onChange={(e) => setForm({ ...form, milestone_key: e.target.value })}
          hint={rule ? undefined : 'Để trống để hệ thống tự đặt theo thời điểm gửi.'}
        />
        <div className="grid gap-4 sm:grid-cols-2">
          <Select label="Thời điểm gửi" value={form.timing} onChange={(e) => setForm({ ...form, timing: e.target.value as Timing })}>
            <option value="before">Trước hạn đóng</option>
            <option value="due">Đúng ngày đến hạn</option>
            <option value="after">Quá hạn</option>
          </Select>
          {form.timing !== 'due' && (
            <Field
              label="Số ngày"
              type="number"
              min={1}
              max={60}
              required
              value={form.days}
              onChange={(e) => setForm({ ...form, days: e.target.value })}
            />
          )}
        </div>
        <p className="text-xs text-on-surface-variant">Mốc: {offsetLabel(form.timing, intOrNull(form.days))}</p>
        <Field label="Tiêu đề" required maxLength={255} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
        <TextArea
          label="Mẫu tin nhắn"
          required
          rows={5}
          maxLength={2000}
          value={form.template_content}
          onChange={(e) => setForm({ ...form, template_content: e.target.value })}
          hint={`Biến hỗ trợ: ${config.variables.map((v) => `${v.value} (${v.label})`).join(', ')}`}
        />
        <fieldset className="space-y-1">
          <legend className="mb-1.5 text-sm font-medium">Kênh gửi</legend>
          {config.channels.map((c) => (
            <Checkbox key={c.value} label={c.label} checked={form.channels.includes(c.value)} onChange={(e) => toggleChannel(c.value, e.target.checked)} />
          ))}
        </fieldset>
        <Checkbox label="Bật mốc nhắc này" checked={form.is_enabled} onChange={(e) => setForm({ ...form, is_enabled: e.target.checked })} />
        <Button type="submit" disabled={save.isPending}>
          Lưu
        </Button>
      </form>
    </Dialog>
  )
}
