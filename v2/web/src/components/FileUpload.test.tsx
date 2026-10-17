import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { expect, test, vi } from 'vitest'
import { ApiError } from '../api'
import { PDF_RULE } from '../lib/files'
import { FileUpload } from './FileUpload'

test('FileUpload kiểm file trước khi gửi và hiện lỗi máy chủ', async () => {
  const user = userEvent.setup({ applyAccept: false })
  const onUpload = vi.fn().mockRejectedValueOnce(new ApiError(422, 'invalid', 'File đề PDF không hợp lệ.')).mockResolvedValue(undefined)
  render(<FileUpload rule={PDF_RULE} label="Chọn file PDF" onUpload={onUpload} />)
  const input = screen.getByLabelText(/Chọn file PDF/) as HTMLInputElement

  await user.upload(input, new File(['abc'], 'anh.png', { type: 'image/png' }))
  expect(screen.getByRole('alert').textContent).toBe('Định dạng file không được phép. Chấp nhận: pdf.')
  expect(onUpload).not.toHaveBeenCalled()

  await user.upload(input, new File(['%PDF-1.4'], 'de.pdf', { type: 'application/pdf' }))
  expect(onUpload).toHaveBeenCalledTimes(1)
  expect((await screen.findByRole('alert')).textContent).toBe('File đề PDF không hợp lệ.')

  await user.upload(input, new File(['%PDF-1.4'], 'de2.pdf', { type: 'application/pdf' }))
  expect(onUpload).toHaveBeenCalledTimes(2)
  expect(screen.queryByRole('alert')).toBeNull()
})
