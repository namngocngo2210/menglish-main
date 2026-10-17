import { expect, test } from 'vitest'
import { acceptOf, checkFile, CONTRACT_RULE, extOf, formatBytes, IMAGE_RULE, PDF_RULE } from './files'

test('checkFile theo luật máy chủ', () => {
  expect(checkFile({ name: 'HĐ lao động.PDF', size: 1000 }, CONTRACT_RULE)).toBeNull()
  expect(checkFile({ name: 'hd.pdf', size: 10240 * 1024 + 1 }, CONTRACT_RULE)).toBe(
    'Dung lượng tập tin file hợp đồng không được vượt quá 10240 kB.',
  )
  expect(checkFile({ name: 'hd.exe', size: 10 }, CONTRACT_RULE)).toBe(
    'Định dạng file không được phép. Chấp nhận: pdf, doc, docx, jpg, jpeg, png, webp.',
  )
  expect(checkFile({ name: 'anh.png', size: 0 }, IMAGE_RULE)).toBe('File tải lên bị rỗng.')
  expect(checkFile({ name: 'khong-duoi', size: 10 }, PDF_RULE)).toBe('Định dạng file không được phép. Chấp nhận: pdf.')
})

test('extOf, acceptOf, formatBytes', () => {
  expect(extOf('a.b.DOCX')).toBe('docx')
  expect(extOf('README')).toBe('')
  expect(acceptOf(PDF_RULE)).toBe('.pdf')
  expect(formatBytes(512)).toBe('512 B')
  expect(formatBytes(1536)).toBe('1,5 KB')
  expect(formatBytes(20 * 1024 * 1024)).toBe('20 MB')
})
