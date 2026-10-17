import { expect, test } from 'vitest'
import { attemptLabel, canCancel, canRetry, type MailStatus } from './api'

const all: MailStatus[] = ['pending', 'sending', 'sent', 'failed', 'blocked', 'cancelled']

test('gửi lại / hủy theo trạng thái (khớp RetryMail / CancelMail ở Go)', () => {
  expect(all.filter((status) => canRetry({ status }))).toEqual(['failed', 'blocked', 'cancelled'])
  expect(all.filter((status) => canCancel({ status }))).toEqual(['pending', 'failed'])
})

test('nhãn lần gửi', () => {
  expect(attemptLabel({ status: 'pending', attempts: 0, max_attempts: 8 })).toBe('Chưa gửi')
  expect(attemptLabel({ status: 'failed', attempts: 3, max_attempts: 3 })).toBe('Lần 3/3')
})
