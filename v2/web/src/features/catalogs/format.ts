// Hàm định dạng dùng chung cho các màn danh mục (thuần, có test).

// 12500000 → "12.500.000 đ" (như number_format(..., 0, ',', '.') . ' đ' của Laravel).
export function formatMoney(n: number) {
  const s = Math.round(Math.abs(n))
    .toString()
    .replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  return `${n < 0 ? '-' : ''}${s} đ`
}

// "2026-04-30" → "30/04/2026"; chuỗi khác giữ nguyên.
export function formatDate(iso: string) {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso)
  return m ? `${m[3]}/${m[2]}/${m[1]}` : iso
}

// Khoảng ngày nghỉ: một ngày thì chỉ in một lần.
export function formatDateRange(start: string, end: string) {
  return start === end ? formatDate(start) : `${formatDate(start)} – ${formatDate(end)}`
}

// "HH:MM" + phút → "HH:MM" (gợi ý giờ kết thúc ca 90 phút); sai định dạng hoặc qua nửa đêm thì trả "".
export function addMinutes(time: string, minutes: number) {
  const m = /^(\d{1,2}):(\d{2})$/.exec(time)
  if (!m) return ''
  const total = Number(m[1]) * 60 + Number(m[2]) + minutes
  if (Number(m[1]) > 23 || Number(m[2]) > 59 || total >= 24 * 60 || total < 0) return ''
  return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`
}

// Đơn vị SLA → nhãn cạnh ô giá trị (giờ thì nhập "HH:MM").
const SLA_UNITS: Record<string, string> = { hours: 'giờ', days: 'ngày', count: 'lần', day_of_month: 'ngày trong tháng', time: '' }

export function slaUnitLabel(unit: string) {
  return SLA_UNITS[unit] ?? 'lần'
}

// Số phút từ 00:00 → "HH:MM" (đơn vị time của SLA).
export function minutesToTime(m: number) {
  const v = Math.max(0, Math.min(1439, Math.round(m)))
  return `${String(Math.floor(v / 60)).padStart(2, '0')}:${String(v % 60).padStart(2, '0')}`
}

// Giá trị SLA ra chữ, giống FormatSlaValue của Go: "24 giờ", "3 ngày", "3 lần", "19:00", "ngày 5".
export function slaValueLabel(value: number, unit: string) {
  switch (unit) {
    case 'hours':
      return `${value} giờ`
    case 'days':
      return `${value} ngày`
    case 'time':
      return minutesToTime(value)
    case 'day_of_month':
      return `ngày ${value}`
  }
  return `${value} lần`
}

// Bậc thang phạt [0, 30000, 60000] → "0, 30000, 60000" để sửa trong ô văn bản.
export function ladderText(ladder: number[]) {
  return ladder.join(', ')
}

// Mốc nhắc nợ → mô tả, giống offsetLabel của Go: "3 ngày trước hạn" / "Đúng ngày đến hạn" / "Quá hạn 5 ngày".
export function offsetLabel(timing: 'before' | 'due' | 'after', days: number | null) {
  if (timing === 'due' || !days) return 'Đúng ngày đến hạn'
  return timing === 'before' ? `${days} ngày trước hạn` : `Quá hạn ${days} ngày`
}

// Danh sách email từ ô văn bản (phẩy, chấm phẩy hoặc xuống dòng), bỏ trống và trùng.
export function splitEmails(text: string) {
  const out: string[] = []
  for (const part of text.split(/[\s,;]+/)) {
    const e = part.trim()
    if (e && !out.some((x) => x.toLowerCase() === e.toLowerCase())) out.push(e)
  }
  return out
}

// Đổi chỗ phần tử i với i+delta (sắp xếp trình độ); ngoài biên thì giữ nguyên.
export function moveItem<T>(list: T[], i: number, delta: number) {
  const j = i + delta
  if (j < 0 || j >= list.length) return list
  const out = [...list]
  ;[out[i], out[j]] = [out[j], out[i]]
  return out
}
