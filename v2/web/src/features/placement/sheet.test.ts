import { expect, test } from 'vitest'
import {
  addSheetRow,
  blankSheet,
  fillAnswers,
  gradableCount,
  keyedCount,
  nextSheetId,
  setOptionCount,
  setSheetType,
  sheetHasWork,
  sheetQuestion,
  showSection,
} from './logic'

test('phiếu trống và thêm câu', () => {
  expect(blankSheet(30)).toHaveLength(30)
  expect(blankSheet(999)).toHaveLength(200)
  expect(blankSheet(0)).toHaveLength(1)
  const qs = blankSheet(2).map((q) => ({ ...q, skill: 'listening', section: 'PART 1' }))
  const next = addSheetRow(qs)
  expect(next[2]).toMatchObject({ id: '3', number: '3', skill: 'listening', section: 'PART 1' })
  expect(nextSheetId([{ ...sheetQuestion('7', 1) }, { ...sheetQuestion('abc', 2) }])).toBe('8')
  expect(showSection(next, 0)).toBe(true)
  expect(showSection(next, 1)).toBe(false)
})

test('đổi dạng câu và số phương án', () => {
  const q = { ...sheetQuestion('1', 1), correct_answer: 'C' }
  expect(setOptionCount(q, 2).correct_answer).toBe('')
  expect(setOptionCount(q, 99).options).toHaveLength(8)
  expect(setSheetType(q, 'essay')).toMatchObject({ options: [], correct_answer: '' })
  const fill = setSheetType({ ...q, correct_answer: 'apple' }, 'fill_blank')
  expect(fill.correct_answer).toBe('apple')
  expect(setSheetType(fill, 'multiple_choice').options).toHaveLength(3)
})

test('fillAnswers ghép theo số câu', () => {
  const qs = blankSheet(4)
  qs[3] = setSheetType(qs[3], 'essay')
  const { questions, applied } = fillAnswers(qs, [
    { number: 1, answer: 'b' },
    { number: 2, answer: 'E' },
    { number: 3, answer: 'apple | apples' },
    { number: 4, answer: 'x' },
  ])
  expect(applied).toBe(3)
  expect(questions[0].correct_answer).toBe('B')
  // Chữ ngoài số phương án → thêm phương án
  expect(questions[1].options).toHaveLength(5)
  expect(questions[1].correct_answer).toBe('E')
  expect(questions[2]).toMatchObject({ type: 'fill_blank', correct_answer: 'apple | apples' })
  expect(questions[3].correct_answer).toBe('')
  expect(qs[0].correct_answer).toBe('') // không sửa mảng cũ
  expect(keyedCount(questions)).toBe(3)
  expect(gradableCount(questions)).toBe(3)
  expect(sheetHasWork(questions)).toBe(true)
  expect(sheetHasWork(qs)).toBe(false)
})

test('fillAnswers ghép theo thứ tự khi đề đánh lại số từng phần', () => {
  const qs = ['1', '2', '1', '2'].map((n, i) => sheetQuestion(String(i + 1), n))
  const { questions, applied } = fillAnswers(qs, [
    { number: 1, answer: 'C' },
    { number: 2, answer: 'A' },
    { number: 1, answer: 'B' },
    { number: 2, answer: 'C' },
  ])
  expect(applied).toBe(4)
  expect(questions.map((q) => q.correct_answer)).toEqual(['C', 'A', 'B', 'C'])
  // Phiếu rỗng → tạo phiếu theo đáp án
  expect(fillAnswers([], [{ number: 5, answer: 'A' }]).questions[0]).toMatchObject({ number: '5', correct_answer: 'A' })
  expect(fillAnswers(qs, []).applied).toBe(0)
})
