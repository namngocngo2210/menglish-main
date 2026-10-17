// Định dạng ngày giờ theo giờ Việt Nam (máy người dùng ở múi giờ khác vẫn hiện đúng giờ trung tâm).
const TZ = 'Asia/Ho_Chi_Minh'
const timeFmt = new Intl.DateTimeFormat('vi-VN', { timeZone: TZ, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false })
const dateFmt = new Intl.DateTimeFormat('vi-VN', { timeZone: TZ, day: '2-digit', month: '2-digit', year: 'numeric' })
const shortFmt = new Intl.DateTimeFormat('vi-VN', { timeZone: TZ, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false })

export const formatTime = (iso: string) => timeFmt.format(new Date(iso))
export const formatDate = (iso: string) => dateFmt.format(new Date(iso))
export const formatDateTime = (iso: string) => shortFmt.format(new Date(iso))

export function formatNumber(n: number) {
  return new Intl.NumberFormat('vi-VN').format(n)
}

// timeAgo: "vừa xong", "5 phút trước", "3 giờ trước", "2 ngày trước"; quá 30 ngày thì hiện ngày.
export function timeAgo(iso: string, now = Date.now()) {
  const sec = Math.max(0, Math.round((now - new Date(iso).getTime()) / 1000))
  if (sec < 60) return 'vừa xong'
  const min = Math.floor(sec / 60)
  if (min < 60) return `${min} phút trước`
  const hours = Math.floor(min / 60)
  if (hours < 24) return `${hours} giờ trước`
  const days = Math.floor(hours / 24)
  if (days <= 30) return `${days} ngày trước`
  return formatDate(iso)
}

// Chuỗi query từ bộ lọc, bỏ giá trị rỗng.
export function toQuery(params: Record<string, string | number | boolean | undefined | null>) {
  const qs = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== null && v !== '' && v !== false) qs.set(k, String(v))
  }
  return qs.toString()
}
