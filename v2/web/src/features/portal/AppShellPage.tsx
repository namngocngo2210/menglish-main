import { useQuery } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { Icon } from '../../components/ui'
import { formatMoney } from '../tuition/logic'
import { portalApi } from './api'
import { Pill, PortalFrame, PortalLink, QueryError, useStudentParam } from './components'
import { portalHref } from './logic'

// Ứng dụng di động — App Shell (Portal/AppShell.vue): lời chào, lưới chức năng chính kèm số liệu thật của học viên đang
// chọn, thông tin tài khoản.
export function AppShellPage() {
  const sid = useStudentParam()
  const q = useQuery({ queryKey: ['portal', 'app-shell', sid], queryFn: () => portalApi.appShell(sid) })
  if (q.isError) return <QueryError error={q.error} />
  const d = q.data
  const id = d?.student?.id
  const total = d?.homework_types ?? 6

  return (
    <PortalFrame ctx={d} title="Cổng Phụ huynh / Học sinh" tab="home">
      {d && (
        <div className="space-y-4">
          <div className="flex items-center justify-between rounded-2xl bg-gradient-to-r from-primary to-primary-hover p-4 text-white shadow-md">
            <div>
              <span className="rounded-full bg-white/20 px-2 py-0.5 text-xs font-bold tracking-wider uppercase">Cổng Học Sinh &amp; Phụ Huynh</span>
              <h2 className="mt-1 text-lg font-bold">Xin chào, {d.student?.name ?? 'Học viên'}</h2>
              <p className="mt-0.5 text-xs text-white/90">Lớp: {d.student?.class_name ?? 'Chưa xếp lớp'}</p>
            </div>
            <span className="flex size-12 items-center justify-center rounded-2xl bg-white/20">
              <Icon name="school" className="text-2xl" />
            </span>
          </div>

          <div>
            <h3 className="mb-2.5 px-1 text-xs font-bold tracking-wider text-on-surface-variant uppercase">Các chức năng chính</h3>
            <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
              <Tile href={portalHref('/portal/home', id)} icon="home" tone="bg-primary/10 text-primary" title="Trang chủ" sub="Thông tin học sinh & học phí"
                badge={d.tuition.has_tuition ? <Pill tone="success">{formatMoney(d.tuition.final_amount)}</Pill> : null} />
              <Tile href={portalHref('/portal/student-homework', id)} icon="upload_file" tone="bg-info/10 text-info" title="Học tập & Nộp bài" sub="Video, từ vựng, workbook"
                badge={<Pill tone={d.submitted_homeworks >= total ? 'success' : 'info'}>{d.submitted_homeworks}/{total} nộp</Pill>} />
              <Tile href={portalHref('/portal/pronunciation', id)} icon="mic" tone="bg-error/10 text-error" title="Luyện phát âm AI" sub="Thu âm & chấm giọng đọc"
                badge={d.pronunciation_count > 0 ? <Pill tone="error">{d.pronunciation_count} bài</Pill> : null} />
              <Tile href={portalHref('/portal/notifications', id)} icon="notifications" tone="bg-warning-container text-warning" title="Hộp thư Thông báo" sub="Học phí, sinh nhật, nghỉ lễ"
                badge={d.unread_count > 0 ? <Pill tone="error">{d.unread_count} mới</Pill> : <Pill>Đã đọc</Pill>} />
              <Tile href={portalHref('/portal/survey', id)} icon="assignment" tone="bg-success/10 text-success" title="Khảo sát Đánh giá" sub="Cơ sở vật chất & giáo trình"
                badge={d.survey_count > 0 ? <Pill tone="success">{d.survey_count} đã làm</Pill> : null} />
              <Tile href={portalHref('/portal/feedback', id)} icon="rate_review" tone="bg-primary/10 text-primary" title="Gửi Feedback chặng" sub="Đánh giá chặng học 5 sao"
                badge={d.has_feedback ? <Pill tone="info">Đã phản hồi</Pill> : <Pill>Chưa gửi</Pill>} />
            </div>
          </div>

          <section className="space-y-3 rounded-2xl border border-surface-container-highest bg-surface p-4 shadow-sm">
            <div className="flex items-center justify-between border-b border-surface-container-highest pb-2.5">
              <h4 className="flex items-center gap-1.5 text-xs font-bold">
                <Icon name="verified_user" className="text-[18px] text-primary" /> Thông tin tài khoản
              </h4>
              <Pill tone="success">{d.status_label ?? '—'}</Pill>
            </div>
            <div className="grid grid-cols-2 gap-2 text-xs">
              <div>
                <span className="block font-bold text-on-surface-variant uppercase">Mã học viên</span>
                <span className="font-mono font-bold">{d.student?.code ?? '—'}</span>
              </div>
              <div>
                <span className="block font-bold text-on-surface-variant uppercase">Số điện thoại</span>
                <span className="font-mono">{d.phone ?? '—'}</span>
              </div>
            </div>
            <PortalLink href="/profile" className="flex items-center justify-between rounded-xl border border-surface-container-highest px-3 py-2 text-xs font-bold text-primary hover:border-primary">
              <span className="flex items-center gap-1.5">
                <Icon name="lock" className="text-[18px]" /> Tài khoản &amp; đổi mật khẩu
              </span>
              <Icon name="chevron_right" className="text-[16px]" />
            </PortalLink>
          </section>
        </div>
      )}
    </PortalFrame>
  )
}

function Tile({ href, icon, tone, title, sub, badge }: { href: string; icon: string; tone: string; title: string; sub: string; badge: ReactNode }) {
  return (
    <PortalLink href={href} className="group flex flex-col gap-2 rounded-2xl border border-surface-container-highest bg-surface p-3.5 hover:border-primary hover:shadow-md">
      <div className="flex items-center justify-between gap-1">
        <span className={`flex size-10 items-center justify-center rounded-xl ${tone}`}>
          <Icon name={icon} className="text-[22px]" />
        </span>
        {badge}
      </div>
      <div>
        <h4 className="text-xs font-bold group-hover:text-primary">{title}</h4>
        <p className="mt-0.5 line-clamp-1 text-xs text-on-surface-variant">{sub}</p>
      </div>
      <span className="mt-auto flex items-center gap-0.5 text-xs font-bold text-primary">
        Mở xem <Icon name="chevron_right" className="text-[12px]" />
      </span>
    </PortalLink>
  )
}
