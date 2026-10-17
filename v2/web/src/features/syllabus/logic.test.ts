import { describe, expect, it } from 'vitest'
import {
  badgeTone,
  daysLeftClass,
  daysLeftLabel,
  draftOf,
  fmtDate,
  fmtDateTime,
  lessonCount,
  overallOf,
  parseScore,
  resultsPayload,
  resultTone,
  timeAgo,
  todayISO,
  type Draft,
} from './logic'

const draft = (over: Partial<Draft> = {}): Draft => ({
  ...draftOf(null),
  ...over,
})

describe('ngày giờ', () => {
  it('định dạng ngày ISO và giờ Việt Nam', () => {
    expect(fmtDate('2026-10-17')).toBe('17/10/2026')
    expect(fmtDate('')).toBe('—')
    expect(fmtDate('2026-10-16T18:30:00Z')).toBe('17/10/2026')
    expect(fmtDateTime('2026-10-17T01:05:00Z')).toBe('17/10/2026 08:05')
    expect(todayISO(new Date('2026-10-16T18:00:00Z'))).toBe('2026-10-17')
  })
  it('thời gian trước', () => {
    const now = new Date('2026-10-17T10:00:00Z')
    expect(timeAgo('2026-10-17T09:59:30Z', now)).toBe('vừa xong')
    expect(timeAgo('2026-10-17T09:55:00Z', now)).toBe('5 phút trước')
    expect(timeAgo('2026-10-17T07:00:00Z', now)).toBe('3 giờ trước')
    expect(timeAgo('2026-10-15T10:00:00Z', now)).toBe('2 ngày trước')
    expect(timeAgo('2026-09-01T10:00:00Z', now)).toBe('1 tháng trước')
  })
})

describe('bảng điểm', () => {
  it('điểm tổng như máy chủ', () => {
    expect(
      overallOf(
        draft({
          listening_score: '8',
          reading_score: '7',
          writing_score: '9',
          speaking_score: '6,5',
        }),
      ),
    ).toBe(7.6)
    expect(
      overallOf(
        draft({
          listening_score: '8',
          reading_score: '7',
          writing_score: '9',
        }),
      ),
    ).toBeNull()
    expect(
      overallOf(
        draft({
          listening_score: 'x',
          reading_score: '7',
          writing_score: '9',
          speaking_score: '6',
        }),
      ),
    ).toBeNull()
    expect(parseScore(' ')).toBeNull()
    expect(parseScore('7,5')).toBe(7.5)
  })
  it('dòng gửi lên: bỏ dòng khóa, vắng thi không gửi điểm', () => {
    const rows = [
      {
        student_id: 'a',
        locked: false,
        draft: draft({ listening_score: '5', progress_note: '  tốt ' }),
      },
      {
        student_id: 'b',
        locked: true,
        draft: draft({ listening_score: '9' }),
      },
      {
        student_id: 'c',
        locked: false,
        draft: draft({ listening_score: '9', is_absent: true }),
      },
    ]
    const out = resultsPayload(rows)
    expect(out.map((r) => r.student_id)).toEqual(['a', 'c'])
    expect(out[0]).toMatchObject({
      listening_score: 5,
      reading_score: null,
      progress_note: 'tốt',
      video_url: null,
    })
    expect(out[1]).toMatchObject({
      listening_score: null,
      is_absent: true,
    })
  })
  it('ô nhập lấy từ kết quả đã lưu', () => {
    const d = draftOf({
      listening_score: 7.5,
      reading_score: null,
      is_absent: false,
      progress_note: null,
    } as never)
    expect(d.listening_score).toBe('7.5')
    expect(d.reading_score).toBe('')
  })
})

describe('nhãn', () => {
  it('màu và số ngày', () => {
    expect(badgeTone('warning')).toBe('warning')
    expect(badgeTone('primary')).toBe('neutral')
    expect(resultTone('sent')).toBe('info')
    expect(resultTone(undefined)).toBe('neutral')
    expect(daysLeftClass(2)).toBe('text-error')
    expect(daysLeftClass(3)).toBe('text-warning')
    expect(daysLeftLabel(-2)).toBe('Quá hạn 2 ngày')
    expect(daysLeftLabel(3)).toBe('Còn 3 ngày')
    expect(
      lessonCount({
        units: [{ lessons: [1, 2] }, { lessons: [3] }],
      } as never),
    ).toBe(3)
  })
})
