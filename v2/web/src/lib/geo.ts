// Vị trí GPS trên trình duyệt (cài tọa độ chi nhánh, sau này chấm công điện thoại). Giống resources/js/lib/geo.js.
// Trình duyệt chỉ cho dùng GPS trên https (hoặc localhost).

export type Position = { latitude: number; longitude: number; accuracy: number }

const GEO_ERRORS: Record<number, string> = {
  1: 'Bạn chưa cho phép truy cập vị trí. Vào cài đặt trình duyệt → Quyền của trang → Vị trí → Cho phép, rồi thử lại.',
  2: 'Không xác định được vị trí. Hãy bật GPS / định vị của điện thoại và ra chỗ thoáng.',
  3: 'Lấy vị trí quá lâu. Hãy bật GPS và thử lại.',
}

// Vị trí hiện tại (độ chính xác cao, không dùng vị trí cũ). Lỗi → Error với thông báo tiếng Việt.
export function currentPosition(timeout = 20000): Promise<Position> {
  return new Promise((resolve, reject) => {
    if (!window.isSecureContext) {
      reject(new Error('Trình duyệt chỉ cho dùng định vị khi mở trang bằng https://. Hãy mở hệ thống bằng địa chỉ https.'))
      return
    }
    if (!navigator.geolocation) {
      reject(new Error('Trình duyệt này không hỗ trợ định vị GPS.'))
      return
    }
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy: p.coords.accuracy }),
      (err) => reject(new Error(GEO_ERRORS[err.code] ?? 'Không lấy được vị trí GPS.')),
      { enableHighAccuracy: true, timeout, maximumAge: 0 },
    )
  })
}

// "21.0285, 105.8542" (sao chép từ Google Maps) → [vĩ độ, kinh độ] dạng chuỗi; không khớp → null.
export function parseCoordinates(text: string): [string, string] | null {
  const m = text.match(/(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/)
  return m ? [m[1], m[2]] : null
}

// Chuỗi ô nhập → điểm hợp lệ hoặc null.
export function toPoint(lat: string, lng: string): [number, number] | null {
  const a = Number.parseFloat(lat)
  const b = Number.parseFloat(lng)
  if (!Number.isFinite(a) || !Number.isFinite(b) || Math.abs(a) > 90 || Math.abs(b) > 180) return null
  return [a, b]
}
