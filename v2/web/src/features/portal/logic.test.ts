import { describe, expect, it } from 'vitest'
import type { Me } from '../../api'
import {
  dmy,
  feedbackFilled,
  initials,
  isPortalOnly,
  markUrgent,
  parseAmount,
  portalHref,
  recordingExtension,
  sessionBadge,
  sessionLabel,
  submittedAt,
  suggestedAmount,
  timerText,
} from './logic'

const me = (permissions: string[]) => ({ permissions }) as unknown as Me

describe('isPortalOnly', () => {
  it('học viên / phụ huynh: có portal.student, không có portal.staff', () => {
    expect(isPortalOnly(me(['portal.student', 'notification.view']))).toBe(true)
    expect(isPortalOnly(me(['portal.student', 'portal.staff']))).toBe(false)
    expect(isPortalOnly(me(['portal.staff']))).toBe(false)
  })
})

describe('portalHref', () => {
  it('kèm học viên đang xem', () => {
    expect(portalHref('/portal/home', 'abc')).toBe('/portal/home?student_id=abc')
    expect(portalHref('/portal/home', null)).toBe('/portal/home')
  })
})

describe('lịch học', () => {
  it('nhãn thứ, ngày, giờ như Laravel', () => {
    expect(sessionLabel({ date: '2026-10-19', start_time: '18:00', end_time: '19:30' })).toBe('T2, 19/10 · 18:00-19:30')
    expect(sessionLabel({ date: '2026-10-18T00:00:00Z', start_time: '08:00', end_time: '09:00' })).toBe('CN, 18/10 · 08:00-09:00')
  })
  it('nhãn loại buổi', () => {
    expect(sessionBadge({ cancelled: true, type: 'regular' })).toEqual(['neutral', 'Nghỉ'])
    expect(sessionBadge({ cancelled: false, type: 'makeup' })).toEqual(['info', 'Học bù'])
    expect(sessionBadge({ cancelled: false, type: 'support' })).toEqual(['info', 'Phụ đạo'])
    expect(sessionBadge({ cancelled: false, type: 'regular' })).toEqual(['success', 'Buổi học'])
  })
})

describe('ngày giờ', () => {
  it('dd/mm/yyyy', () => {
    expect(dmy('2026-10-17')).toBe('17/10/2026')
    expect(dmy('2026-10-17T00:00:00Z')).toBe('17/10/2026')
    expect(dmy(null)).toBe('')
  })
  it('giờ nộp theo giờ Việt Nam', () => {
    expect(submittedAt('2026-10-17T02:05:00Z')).toBe('09:05, 17/10/2026')
  })
})

describe('markUrgent', () => {
  const sub = { id: '1' } as never
  it('chỉ tô mục chưa nộp đầu tiên', () => {
    const cards = [{ submission: sub }, { submission: null }, { submission: null }]
    expect(markUrgent(cards, true).map((c) => c.urgent)).toEqual([false, true, false])
    expect(markUrgent(cards, false).map((c) => c.urgent)).toEqual([false, false, false])
  })
})

describe('ghi âm', () => {
  it('đồng hồ mm:ss', () => {
    expect(timerText(0)).toBe('00:00')
    expect(timerText(75)).toBe('01:15')
  })
  it('đuôi file theo loại', () => {
    expect(recordingExtension('audio/mp4')).toBe('m4a')
    expect(recordingExtension('audio/ogg;codecs=opus')).toBe('ogg')
    expect(recordingExtension('audio/webm;codecs=opus')).toBe('webm')
  })
})

describe('góp ý chặng (R-04)', () => {
  const empty = { rating: 0, fb_hoc_thuat: false, fb_giao_vien: false, fb_khac: false, content: '  ' }
  it('cần ít nhất một mục', () => {
    expect(feedbackFilled(empty)).toBe(false)
    expect(feedbackFilled({ ...empty, rating: 3 })).toBe(true)
    expect(feedbackFilled({ ...empty, fb_khac: true })).toBe(true)
    expect(feedbackFilled({ ...empty, content: 'Tốt' })).toBe(true)
  })
})

describe('báo đóng học phí', () => {
  it('gợi ý số còn nợ, đọc số tiền', () => {
    expect(suggestedAmount(1500000.6)).toBe('1500000')
    expect(suggestedAmount(0)).toBe('')
    expect(parseAmount('1.500.000')).toBe(1500000)
    expect(parseAmount(' ')).toBeNull()
  })
})

it('initials', () => {
  expect(initials('Nguyễn An')).toBe('Ng')
  expect(initials(null)).toBe('HV')
})
