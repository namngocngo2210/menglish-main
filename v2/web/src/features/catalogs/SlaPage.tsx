// Cấu hình SLA (SlaConfigController của Laravel): ngưỡng, bật/tắt, biên bản phạt và bậc thang tái phạm.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { errorMessage, type Me } from '../../api'
import { Alert, Badge, Button, Card, Checkbox, Dialog, Field } from '../../components/ui'
import { catalogsApi, type SlaInput, type SlaRule } from './api'
import { Table, actionsTd, intOrNull, td, useAction } from './common'
import { formatMoney, ladderText, minutesToTime, slaUnitLabel, slaValueLabel } from './format'

export function SlaPage(_: { me: Me }) {
  const list = useQuery({ queryKey: ['sla'], queryFn: catalogsApi.sla })
  const act = useAction([['sla']])
  const [editing, setEditing] = useState<SlaRule | null>(null)
  const d = list.data
  const groups = d ? [...new Map(d.rules.map((r) => [r.group, r.group_label])).entries()] : []

  return (
    <div className="space-y-4">
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {act.notice}
      {d && <ResetMonths months={d.reset_months} onSaved={(msg) => act.setNotice({ tone: 'success', text: msg })} />}
      {groups.map(([key, label]) => (
        <Card key={key} title={label}>
          <Table head={['Quy định', 'Ngưỡng', 'Áp dụng', 'Biên bản phạt', '']}>
            {d!.rules
              .filter((r) => r.group === key)
              .map((r) => (
                <tr key={r.key}>
                  <td className={td}>
                    <p className="font-medium">
                      {r.label} {r.customized && <Badge tone="info">Đã chỉnh</Badge>}
                    </p>
                    <p className="text-xs text-on-surface-variant">{r.description}</p>
                  </td>
                  <td className={`${td} whitespace-nowrap`}>
                    <p>{slaValueLabel(r.value, r.unit)}</p>
                    <p className="text-xs text-on-surface-variant">Mặc định: {r.default_label}</p>
                  </td>
                  <td className={td}>
                    {!r.switchable ? (
                      <Badge>Luôn áp dụng</Badge>
                    ) : r.enabled ? (
                      <Badge tone="success">Đang bật</Badge>
                    ) : (
                      <Badge>Đang tắt</Badge>
                    )}
                  </td>
                  <td className={`${td} text-xs`}>
                    {!r.has_penalty ? (
                      <span className="text-on-surface-variant">Không áp dụng</span>
                    ) : r.penalty ? (
                      <>
                        <Badge tone="error">Có lập</Badge>
                        <p className="mt-1">
                          {r.has_ladder
                            ? `Bậc phạt: ${r.ladder.map(formatMoney).join(' → ')}`
                            : `${r.amount_label || 'Mức phạt'}: ${formatMoney(r.amount)}${r.amount_per ? ` / ${r.amount_per}` : ''}`}
                        </p>
                      </>
                    ) : (
                      <Badge>Không lập</Badge>
                    )}
                  </td>
                  <td className={actionsTd}>
                    <Button variant="secondary" onClick={() => setEditing(r)}>
                      Sửa
                    </Button>
                  </td>
                </tr>
              ))}
          </Table>
        </Card>
      ))}
      {editing && (
        <SlaDialog rule={editing} onClose={() => setEditing(null)} onSaved={(msg) => act.setNotice({ tone: 'success', text: msg })} />
      )}
    </div>
  )
}

function ResetMonths({ months, onSaved }: { months: number; onSaved: (msg: string) => void }) {
  const qc = useQueryClient()
  const [value, setValue] = useState(String(months))
  const save = useMutation({
    mutationFn: () => catalogsApi.updateSlaSettings(intOrNull(value) ?? 0),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['sla'] })
      onSaved(r.message)
    },
  })
  return (
    <Card title="Cấu hình SLA">
      <form
        className="flex flex-wrap items-end gap-3"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <div className="w-72">
          <Field
            label="Cộng dồn lần tái phạm trong (tháng)"
            type="number"
            min={1}
            max={60}
            required
            value={value}
            onChange={(e) => setValue(e.target.value)}
            hint="Quá thời gian này kể từ lần vi phạm gần nhất thì bậc phạt tính lại từ đầu."
          />
        </div>
        <Button type="submit" disabled={save.isPending}>
          Lưu
        </Button>
      </form>
      {save.isError && (
        <div className="mt-3">
          <Alert>{errorMessage(save.error)}</Alert>
        </div>
      )}
    </Card>
  )
}

function SlaDialog({ rule, onClose, onSaved }: { rule: SlaRule; onClose: () => void; onSaved: (msg: string) => void }) {
  const qc = useQueryClient()
  const isTime = rule.unit === 'time'
  const [form, setForm] = useState({
    value: isTime ? minutesToTime(rule.value) : String(rule.value),
    enabled: rule.enabled,
    penalty: rule.penalty,
    amount: String(rule.amount),
    ladder: ladderText(rule.ladder),
  })
  const save = useMutation({
    mutationFn: () => {
      const body: SlaInput = { value: isTime ? form.value : (intOrNull(form.value) ?? '') }
      if (rule.switchable) body.enabled = form.enabled
      if (rule.has_penalty) {
        body.penalty = form.penalty
        body.amount = intOrNull(form.amount) ?? 0
      }
      if (rule.has_ladder) body.ladder = form.ladder
      return catalogsApi.updateSla(rule.key, body)
    },
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['sla'] })
      onSaved(r.message)
      onClose()
    },
  })
  const unit = slaUnitLabel(rule.unit)

  return (
    <Dialog title={rule.label} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {save.isError && <Alert>{errorMessage(save.error)}</Alert>}
        <p className="text-sm text-on-surface-variant">{rule.description}</p>
        {isTime ? (
          <Field label="Mốc giờ" type="time" required value={form.value} onChange={(e) => setForm({ ...form, value: e.target.value })} />
        ) : (
          <Field
            label={`Ngưỡng (${unit})`}
            type="number"
            required
            min={rule.min}
            max={rule.max}
            value={form.value}
            onChange={(e) => setForm({ ...form, value: e.target.value })}
            hint={`Từ ${slaValueLabel(rule.min, rule.unit)} đến ${slaValueLabel(rule.max, rule.unit)}. Mặc định: ${rule.default_label}.`}
          />
        )}
        {rule.switchable && (
          <Checkbox label="Áp dụng quy định này" checked={form.enabled} onChange={(e) => setForm({ ...form, enabled: e.target.checked })} />
        )}
        {rule.has_penalty && (
          <>
            <Checkbox label="Lập biên bản phạt khi vi phạm" checked={form.penalty} onChange={(e) => setForm({ ...form, penalty: e.target.checked })} />
            {form.penalty && !rule.has_ladder && (
              <Field
                label={`${rule.amount_label || 'Mức phạt'} (đồng${rule.amount_per ? ` / ${rule.amount_per}` : ''})`}
                type="number"
                min={0}
                max={100000000}
                step={1000}
                value={form.amount}
                onChange={(e) => setForm({ ...form, amount: e.target.value })}
              />
            )}
            {form.penalty && rule.has_ladder && (
              <Field
                label="Bậc phạt theo lần tái phạm (đồng)"
                maxLength={200}
                value={form.ladder}
                onChange={(e) => setForm({ ...form, ladder: e.target.value })}
                hint="Các số tiền cách nhau bởi dấu phẩy, ví dụ 0, 30000, 60000. Để trống để dùng mặc định."
              />
            )}
          </>
        )}
        <p className="text-xs text-on-surface-variant">Thay đổi chỉ áp dụng cho các mốc phát sinh từ bây giờ.</p>
        <Button type="submit" disabled={save.isPending}>
          Lưu
        </Button>
      </form>
    </Dialog>
  )
}
