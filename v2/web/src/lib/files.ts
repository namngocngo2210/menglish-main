// Kiểm file trước khi tải lên (giống luật của máy chủ để báo lỗi sớm; máy chủ vẫn kiểm lại theo nội dung file).

export type FileRule = {
  // Đuôi được nhận, vd. ['pdf', 'docx']
  types: string[]
  maxBytes: number
  // Thông báo khi quá dung lượng (như máy chủ)
  tooLarge: string
}

export const extOf = (name: string) => {
  const i = name.lastIndexOf('.')
  return i < 0 ? '' : name.slice(i + 1).toLowerCase()
}

// checkFile: thông báo lỗi tiếng Việt, hoặc null nếu file hợp lệ.
export function checkFile(file: { name: string; size: number }, rule: FileRule): string | null {
  if (file.size > rule.maxBytes) return rule.tooLarge
  if (file.size === 0) return 'File tải lên bị rỗng.'
  if (!rule.types.includes(extOf(file.name))) return `Định dạng file không được phép. Chấp nhận: ${rule.types.join(', ')}.`
  return null
}

// accept của <input type="file">: ".pdf,.docx"
export const acceptOf = (rule: FileRule) => rule.types.map((t) => `.${t}`).join(',')

// formatBytes: 1536 → "1,5 KB"
export function formatBytes(n: number) {
  if (n < 1024) return `${n} B`
  const units = ['KB', 'MB', 'GB']
  let v = n / 1024
  let i = 0
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024
    i++
  }
  return `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 1 }).format(v)} ${units[i]}`
}

const KB = 1024
const MB = 1024 * KB

// Luật tải file của từng chỗ dùng (khớp v2/api: iam.ContractPolicy, placement.AudioPolicy / ImagePolicy / PdfPolicy).
export const CONTRACT_RULE: FileRule = {
  types: ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp'],
  maxBytes: 10240 * KB,
  tooLarge: 'Dung lượng tập tin file hợp đồng không được vượt quá 10240 kB.',
}
const MEDIA_TOO_LARGE = 'File quá lớn (âm thanh tối đa 20 MB, ảnh tối đa 5 MB).'
export const AUDIO_RULE: FileRule = {
  types: ['mp3', 'wav', 'm4a', 'ogg', 'oga', 'weba', 'webm', 'aac'],
  maxBytes: 20 * MB,
  tooLarge: MEDIA_TOO_LARGE,
}
export const IMAGE_RULE: FileRule = { types: ['jpg', 'jpeg', 'png', 'gif', 'webp'], maxBytes: 5 * MB, tooLarge: MEDIA_TOO_LARGE }
export const PDF_RULE: FileRule = { types: ['pdf'], maxBytes: 20 * MB, tooLarge: 'File PDF tối đa 20 MB.' }
