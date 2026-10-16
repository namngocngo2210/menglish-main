// Thông báo chuyển sang trang kế tiếp (vd. "Tạo lớp … thành công" hiện trên Trang lớp vừa mở).
let pending = ''

export function setFlash(message: string) {
  pending = message
}

export function takeFlash(): string {
  const m = pending
  pending = ''
  return m
}
