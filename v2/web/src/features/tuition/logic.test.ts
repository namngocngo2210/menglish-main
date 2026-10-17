import { expect, test } from 'vitest'
import type { Promotion, Quote } from './api'
import { calculate, formatMoney, promotionApplies, promotionDiscount, sessionValue, toInt, vietQrUrl } from './logic'

const quote = (over: Partial<Quote>): Quote => ({
  paid: 0,
  used: 0,
  balance: 0,
  class_name: null,
  course_sessions: 24,
  held: 0,
  remaining: 24,
  needed: 24,
  carry_over: 0,
  mode: 'new',
  tuition_id: null,
  unit_price: 100000,
  max_sessions: 24,
  contract_remaining_sessions: null,
  tuition_remaining: null,
  fee_due: 0,
  suggested: 24,
  ...over,
})

test('formatMoney / toInt', () => {
  expect(formatMoney(1234567)).toBe('1.234.567 đ')
  expect(formatMoney(-5000)).toBe('-5.000 đ')
  expect(formatMoney(null)).toBe('0 đ')
  expect(toInt('1.500.000')).toBe(1500000)
  expect(toInt('')).toBe(0)
})

test('calculate khớp cách tính phía server', () => {
  const c = calculate(quote({}), { sessions: 5, itemsTotal: 150000, examFee: 50000, otherFee: 20000, extra: 10000, discount: 80000 })
  expect(c).toMatchObject({ sessionValue: 500000, materialFee: 150000, subtotal: 720000, discount: 80000, totalDue: 640000, amount: 650000, tuitionAmount: 420000, surchargeAmount: 230000 })
  // Giảm trừ không vượt tiền buổi
  expect(calculate(quote({}), { sessions: 1, itemsTotal: 0, examFee: 0, otherFee: 0, extra: 0, discount: 999999 }).amount).toBe(0)
  // Thu hết hợp đồng: đúng phần học phí còn lại + học liệu còn nợ
  const contract = quote({ mode: 'contract', unit_price: 83333.67, contract_remaining_sessions: 3, tuition_remaining: 250001, fee_due: 120000 })
  expect(sessionValue(contract, 3)).toBe(250001)
  expect(sessionValue(contract, 2)).toBe(166667)
  expect(calculate(contract, { sessions: 3, itemsTotal: 0, examFee: 0, otherFee: 0, extra: 0, discount: 0 }).amount).toBe(370001)
  expect(sessionValue(null, 3)).toBe(0)
})

test('ưu đãi', () => {
  const p: Promotion = { id: 'p', code: 'P', name: 'Hè', type: 'percent', value: 10, max_discount_amount: 150000, branch_id: 'b1', course_id: null, is_default: false }
  expect(promotionDiscount(p, 1000000)).toBe(100000)
  expect(promotionDiscount(p, 3000000)).toBe(150000)
  expect(promotionDiscount({ ...p, type: 'fixed', value: 500000 }, 300000)).toBe(300000)
  expect(promotionApplies(p, 'b1', 'c1')).toBe(true)
  expect(promotionApplies(p, 'b2', 'c1')).toBe(false)
})

test('vietQrUrl', () => {
  expect(vietQrUrl(null, 1, 'x')).toBe('')
  expect(vietQrUrl({ bank_code: 'VCB', account_number: '0011', account_holder: 'MENGLISH' }, 500000, 'NGUYENVANAN HV1')).toBe(
    'https://img.vietqr.io/image/VCB-0011-compact2.png?amount=500000&addInfo=NGUYENVANAN%20HV1&accountName=MENGLISH',
  )
})
