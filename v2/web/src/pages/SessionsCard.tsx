import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, errorMessage } from '../api'
import { Alert, Button, Card } from '../components/ui'

const dateTime = new Intl.DateTimeFormat('vi-VN', { dateStyle: 'short', timeStyle: 'short' })

// Rút gọn User-Agent thành "Chrome trên Windows" để người dùng nhận ra thiết bị.
const browsers: [RegExp, string][] = [
  [/Edg\//, 'Edge'],
  [/coc_coc|CocCoc/i, 'Cốc Cốc'],
  [/Chrome\//, 'Chrome'],
  [/Firefox\//, 'Firefox'],
  [/Safari\//, 'Safari'],
]
const systems: [RegExp, string][] = [
  [/Android/, 'Android'],
  [/iPhone|iPad/, 'iOS'],
  [/Windows/, 'Windows'],
  [/Mac OS X/, 'macOS'],
  [/Linux/, 'Linux'],
]

export function describeAgent(ua: string): string {
  const browser = browsers.find(([re]) => re.test(ua))?.[1] ?? 'Trình duyệt'
  const os = systems.find(([re]) => re.test(ua))?.[1]
  return os ? `${browser} trên ${os}` : browser
}

export function SessionsCard() {
  const qc = useQueryClient()
  const list = useQuery({ queryKey: ['sessions'], queryFn: api.sessions })
  const revoke = useMutation({
    mutationFn: api.revokeSession,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['sessions'] }),
  })

  return (
    <Card title="Thiết bị đang đăng nhập">
      {list.isError && <Alert>{errorMessage(list.error)}</Alert>}
      {revoke.isError && <Alert>{errorMessage(revoke.error)}</Alert>}
      <ul className="divide-y divide-slate-100">
        {list.data?.sessions
          .slice()
          .sort((a, b) => Number(b.current) - Number(a.current) || b.last_seen.localeCompare(a.last_seen))
          .map((s) => (
            <li key={s.id} className="flex items-center justify-between gap-4 py-3 text-sm">
              <div>
                <p className="font-medium">
                  {describeAgent(s.user_agent)}
                  {s.current && <span className="ml-2 text-xs font-normal text-emerald-700">Thiết bị này</span>}
                </p>
                <p className="text-xs text-slate-500">
                  {s.ip} · hoạt động {dateTime.format(new Date(s.last_seen))}
                </p>
              </div>
              {!s.current && (
                <Button variant="secondary" onClick={() => revoke.mutate(s.id)} disabled={revoke.isPending}>
                  Đăng xuất
                </Button>
              )}
            </li>
          ))}
      </ul>
    </Card>
  )
}
