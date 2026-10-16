import { useQueryClient } from '@tanstack/react-query'
import { api, type Me } from '../api'
import { Alert, GuestShell } from '../components/ui'
import { TwoFactorCard } from './TwoFactorCard'

// Admin và người có quyền phân quyền, duyệt tiền, chạy lương phải bật 2FA trước khi dùng hệ thống.
export function TotpRequiredPage({ me }: { me: Me }) {
  const qc = useQueryClient()
  return (
    <GuestShell>
      <div className="space-y-4">
        <Alert tone="info">
          Chào {me.user.full_name}. Vai trò của bạn ({me.roles.map((r) => r.short_label).join(', ')}) có quyền quản trị
          hoặc tài chính nên bắt buộc bật xác thực hai bước trước khi tiếp tục.
        </Alert>
        <TwoFactorCard me={me} />
        <button
          type="button"
          className="w-full text-center text-xs text-on-surface-variant hover:underline"
          onClick={async () => {
            await api.logout()
            qc.invalidateQueries({ queryKey: ['me'] })
          }}
        >
          Đăng xuất
        </button>
      </div>
    </GuestShell>
  )
}
