import { expect, test } from 'vitest'
import type { RubricGroupConfig } from './api'
import {
  bandComment,
  changeType,
  codeMatchesGroup,
  evaluate,
  formatDate,
  isBlockedShortcut,
  moveItem,
  newQuestion,
  parseScore,
  questionIssues,
  registerViolation,
  suggestClass,
  wordCount,
} from './logic'

const khoi34: RubricGroupConfig = {
  key: 'khoi_3_4',
  label: 'Khối 3 lên 4',
  has_rubric: true,
  max: { listening: 15, reading_writing: 20, speaking: 10 },
  bands: {
    listening: [
      { min: 0, range: '1 - 6', text: 'Nghe cơ bản' },
      { min: 6, range: '6 - 10', text: 'Nghe khá' },
      { min: 11, range: '11 - 15', text: 'Nghe tốt' },
    ],
  },
  placements: [
    { lt: 20, lte: null, range: '10 - 20', class: 'FAM 2 (NỬA ĐẦU)' },
    { lt: null, lte: 35, range: '20 - 35', class: 'FAM 2 (NỬA SAU)' },
    { lt: null, lte: null, range: '35 - 45', class: 'Luyện MOVERS' },
  ],
}
const manual: RubricGroupConfig = { key: 'khac', label: 'Khác', has_rubric: false, max: { listening: 100, reading_writing: 100, speaking: 100 }, bands: {}, placements: [] }

test('lớp đề xuất theo tổng điểm giống thang của server', () => {
  expect(suggestClass(khoi34, 19.5)).toBe('FAM 2 (NỬA ĐẦU)')
  expect(suggestClass(khoi34, 20)).toBe('FAM 2 (NỬA SAU)')
  expect(suggestClass(khoi34, 35)).toBe('FAM 2 (NỬA SAU)')
  expect(suggestClass(khoi34, 35.5)).toBe('Luyện MOVERS')
  expect(suggestClass(manual, 50)).toBeNull()
})

test('nhận xét gợi ý theo băng điểm', () => {
  expect(bandComment(khoi34, 'listening', 5.5)).toBe('Nghe cơ bản')
  expect(bandComment(khoi34, 'listening', 6)).toBe('Nghe khá')
  expect(bandComment(khoi34, 'listening', 15)).toBe('Nghe tốt')
  expect(bandComment(khoi34, 'listening', null)).toBe('')
  expect(bandComment(khoi34, 'speaking', 5)).toBe('')
})

test('evaluate: chỉ tra lớp khi đủ 3 kỹ năng; vượt điểm tối đa bị đánh dấu', () => {
  const half = evaluate(khoi34, { listening: '10', reading_writing: '12,5', speaking: '' })
  expect(half.complete).toBe(false)
  expect(half.suggested).toBeNull()
  expect(half.total).toBe(22.5)
  const full = evaluate(khoi34, { listening: '16', reading_writing: '12.5', speaking: '5' })
  expect(full.complete).toBe(true)
  expect(full.total).toBe(33.5)
  expect(full.maxTotal).toBe(45)
  expect(full.suggested).toBe('FAM 2 (NỬA SAU)')
  expect(full.overMax.listening).toBe(true)
  expect(full.percent.listening).toBe(100)
  expect(full.percent.reading_writing).toBe(63)
  expect(evaluate(manual, { listening: '8', reading_writing: '8', speaking: '8' }).maxTotal).toBe(30)
  expect(parseScore('abc')).toBeNull()
  expect(parseScore(' ')).toBeNull()
})

test('rời bài: gộp sự kiện trong 1,5 giây, tới lần thứ max thì tự nộp', () => {
  let state = { log: [], lastAt: -Infinity } as Parameters<typeof registerViolation>[0]
  let r = registerViolation(state, 'blur', 1000, 3)
  expect(r.action).toBe('warn')
  state = r.state
  r = registerViolation(state, 'hidden', 2000, 3)
  expect(r.action).toBe('ignored')
  expect(r.state.log).toHaveLength(1)
  r = registerViolation(state, 'hidden', 3000, 3)
  expect(r.action).toBe('warn')
  r = registerViolation(r.state, 'fullscreen_exit', 5000, 3)
  expect(r.action).toBe('auto_submit')
  expect(r.state.log.map((v) => v.type)).toEqual(['blur', 'hidden', 'fullscreen_exit'])
})

test('chặn phím tắt khi làm bài', () => {
  const k = (key: string, mods: Partial<{ ctrlKey: boolean; metaKey: boolean; shiftKey: boolean; altKey: boolean }> = {}) => ({
    key,
    ctrlKey: false,
    metaKey: false,
    shiftKey: false,
    altKey: false,
    ...mods,
  })
  expect(isBlockedShortcut(k('F12'))).toBe(true)
  expect(isBlockedShortcut(k('c', { ctrlKey: true }))).toBe(true)
  expect(isBlockedShortcut(k('v', { metaKey: true }))).toBe(true)
  expect(isBlockedShortcut(k('I', { ctrlKey: true, shiftKey: true }))).toBe(true)
  expect(isBlockedShortcut(k('ArrowLeft', { altKey: true }))).toBe(true)
  expect(isBlockedShortcut(k('a'))).toBe(false)
  expect(isBlockedShortcut(k('Backspace'))).toBe(false)
})

test('soạn câu hỏi: id duy nhất, đổi loại, cảnh báo', () => {
  const a = newQuestion([])
  const b = newQuestion([a])
  expect(a.id).not.toBe(b.id)
  expect(a.options).toHaveLength(4)
  const tf = changeType(a, 'true_false')
  expect(tf.options?.map((o) => o.key)).toEqual(['T', 'F'])
  const essay = changeType({ ...a, correct_answer: 'A' }, 'essay')
  expect(essay.options).toEqual([])
  expect(essay.correct_answer).toBe('')
  expect(questionIssues([{ ...a, title: 'Hỏi', correct_answer: 'E' }])).toEqual([
    'Câu 1: cần ít nhất 2 phương án.',
    'Câu 1: đáp án đúng không nằm trong các phương án.',
  ])
  expect(moveItem([1, 2, 3], 0, 2)).toEqual([2, 3, 1])
  expect(moveItem([1, 2, 3], 0, -1)).toEqual([1, 2, 3])
})

test('tiện ích', () => {
  expect(codeMatchesGroup('test-g3-g4-01', 'G3-G4')).toBe(true)
  expect(codeMatchesGroup('TEST-01', 'G3-G4')).toBe(false)
  expect(codeMatchesGroup('IELTS', 'KHAC')).toBe(true)
  expect(wordCount('My family has  4 people.')).toBe(4)
  expect(formatDate('2026-10-16')).toBe('16/10/2026')
})
