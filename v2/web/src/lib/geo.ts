// Vị trí GPS và camera trên trình duyệt (cài tọa độ chi nhánh, chấm công). Giống resources/js/lib/geo.js.
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

const EARTH_RADIUS_METERS = 6371000

// Khoảng cách đường chim bay (mét), cùng công thức với máy chủ (máy chủ quyết định cuối).
export function distanceMeters(lat1: number, lng1: number, lat2: number, lng2: number): number {
  const rad = (deg: number) => (deg * Math.PI) / 180
  const dLat = rad(lat2 - lat1)
  const dLng = rad(lng2 - lng1)
  const a = Math.sin(dLat / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(dLng / 2) ** 2
  return EARTH_RADIUS_METERS * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a))
}

const INSECURE = 'Trình duyệt chỉ cho dùng định vị và camera khi mở trang bằng https://. Hãy mở hệ thống bằng địa chỉ https.'

// Mở camera trước (selfie). Không có camera / bị chặn → Error với thông báo tiếng Việt.
export async function openFrontCamera(): Promise<MediaStream> {
  if (!window.isSecureContext) throw new Error(INSECURE)
  if (!navigator.mediaDevices?.getUserMedia) throw new Error('Trình duyệt này không mở được camera trực tiếp.')
  try {
    return await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 960 }, height: { ideal: 960 } }, audio: false })
  } catch (e) {
    if ((e as { name?: string })?.name === 'NotAllowedError') {
      throw new Error('Bạn chưa cho phép dùng camera. Vào cài đặt trình duyệt → Quyền của trang → Camera → Cho phép, rồi thử lại.')
    }
    throw new Error('Không mở được camera trước của điện thoại.')
  }
}

// Kích thước ảnh sau khi thu nhỏ để cạnh dài tối đa max px.
export function fitSize(width: number, height: number, max: number): [number, number] {
  const scale = Math.min(1, max / Math.max(width, height, 1))
  return [Math.round(width * scale), Math.round(height * scale)]
}

// Chụp 1 khung hình từ <video> / <img> → File JPEG cạnh dài tối đa max px (ảnh nhỏ, gửi nhanh qua 4G).
export function snapshot(source: HTMLVideoElement | HTMLImageElement, max = 720, quality = 0.82): Promise<File> {
  const width = source instanceof HTMLVideoElement ? source.videoWidth : source.naturalWidth || source.width
  const height = source instanceof HTMLVideoElement ? source.videoHeight : source.naturalHeight || source.height
  const [w, h] = fitSize(width, height, max)
  const canvas = document.createElement('canvas')
  canvas.width = w
  canvas.height = h
  canvas.getContext('2d')?.drawImage(source, 0, 0, w, h)
  return new Promise((resolve, reject) => {
    canvas.toBlob(
      (blob) => (blob ? resolve(new File([blob], `cham-cong-${Date.now()}.jpg`, { type: 'image/jpeg' })) : reject(new Error('Không chụp được ảnh, hãy thử lại.'))),
      'image/jpeg',
      quality,
    )
  })
}

// Thu nhỏ ảnh chọn từ ô chụp ảnh của điện thoại (dự phòng khi không mở được camera trực tiếp).
export function shrinkImageFile(file: File): Promise<File> {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file)
    const img = new Image()
    img.onload = () =>
      snapshot(img)
        .then(resolve, reject)
        .finally(() => URL.revokeObjectURL(url))
    img.onerror = () => {
      URL.revokeObjectURL(url)
      reject(new Error('Ảnh không đọc được, hãy chụp lại.'))
    }
    img.src = url
  })
}
