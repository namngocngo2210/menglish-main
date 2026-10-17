import { expect, test } from 'vitest'
import type { PenaltiesPage, RecordPage } from './api'
import { adjustDraft, adjustInput, draftDelta, money, numOrNull, periodBadge, showAmount, statusTone, trimNumber, violationSuggestions, vnd } from './logic'

test('định dạng tiền và số', () => {
  expect(vnd(1500000)).toBe('1.500.000')
  expect(vnd(-25000)).toBe('-25.000')
  expect(vnd(999.6)).toBe('1.000')
  expect(money(0)).toBe('0 đ')
  expect(trimNumber(10.5)).toBe('10,5')
  expect(trimNumber(85)).toBe('85')
  expect(trimNumber(0.25)).toBe('0,25')
})

test('màu trạng thái', () => {
  expect(statusTone('primary')).toBe('info')
  expect(statusTone('secondary')).toBe('neutral')
  expect(statusTone('warning')).toBe('warning')
  expect(periodBadge('approved')).toEqual(['Đã chốt', 'success'])
  expect(periodBadge('draft')).toEqual(['Đang tính', 'info'])
})

test('numOrNull', () => {
  expect(numOrNull('')).toBeNull()
  expect(numOrNull('  ')).toBeNull()
  expect(numOrNull('10,5')).toBe(10.5)
  expect(numOrNull('500000')).toBe(500000)
  expect(numOrNull('abc')).toBeNull()
})

function recordPage(extra: Partial<RecordPage['record']> = {}): RecordPage {
  return {
    record: {
      kpi_source: 'manual',
      kpi_manual_amount: null,
      retention_tier: null,
      foreign_session_pay: 0,
      tax_deduction: 0,
      adjustment_notes: null,
      is_part_time: false,
      is_full_time: true,
      teaching_concurrent: false,
      ...extra,
    } as RecordPage['record'],
    lines: [
      { kind: 'earning', label: 'Hỗ trợ thỏa thuận', amount: '' },
      { kind: 'deduction', label: 'Gửi xe', amount: 50000 },
    ],
  } as RecordPage
}

test('form điều chỉnh phiếu Full-time', () => {
  const p = recordPage()
  const d = adjustDraft(p)
  expect(d.lines[1].amount).toBe('50000')
  d.kpi_manual_amount = '500000'
  d.tax_deduction = '120000'
  d.lines.push({ kind: 'earning', label: ' Thưởng khác ', amount: '200000' })
  const input = adjustInput(p, d)
  expect(input.kpi_manual_amount).toBe(500000)
  expect(input.tax_deduction).toBe(120000)
  expect(input.retention_tier).toBeUndefined()
  expect(input.foreign_session_pay).toBeUndefined()
  // Khoản trống bị bỏ, khoản có tiền giữ (tên được cắt khoảng trắng)
  expect(input.lines).toEqual([
    { kind: 'deduction', label: 'Gửi xe', amount: 50000 },
    { kind: 'earning', label: 'Thưởng khác', amount: 200000 },
  ])
  expect(draftDelta(d.lines)).toEqual({ earning: 200000, deduction: 50000 })
})

test('form điều chỉnh phiếu Part-time', () => {
  const p = recordPage({
    kpi_source: 'retention',
    is_part_time: true,
    is_full_time: false,
    retention_tier: 20000,
  })
  const d = adjustDraft(p)
  expect(d.retention_tier).toBe('20000')
  d.foreign_session_pay = ''
  const input = adjustInput(p, d)
  expect(input.retention_tier).toBe(20000)
  expect(input.foreign_session_pay).toBe(0)
  expect(input.tax_deduction).toBeUndefined()
  expect(input.kpi_manual_amount).toBeUndefined()
  // Khoản có số tiền mà thiếu tên vẫn gửi để server báo lỗi
  d.lines = [{ kind: 'earning', label: '', amount: '1000' }]
  expect(adjustInput(p, d).lines).toEqual([{ kind: 'earning', label: '', amount: 1000 }])
})

test('gợi ý lỗi vi phạm theo vai trò', () => {
  const page = {
    users: [
      {
        value: 'u1',
        label: 'GV',
        roles: ['teacher_parttime', 'assistant'],
      },
      { value: 'u2', label: 'Khác', roles: ['custom'] },
    ],
    role_violations: {
      teacher_parttime: {
        label: 'GV PT',
        violations: [
          { value: 'A', category: 'academic' },
          { value: 'B', category: 'operations' },
        ],
      },
      assistant: {
        label: 'TA',
        violations: [
          { value: 'B', category: 'academic' },
          { value: 'C', category: 'operations' },
        ],
      },
    },
    all_violations: [{ value: 'Z', category: 'operations' }],
  } as unknown as PenaltiesPage
  const s = violationSuggestions(page, 'u1')
  expect(s.roles).toEqual(['GV PT', 'TA'])
  expect(s.list.map((v) => `${v.value}:${v.category}`)).toEqual(['A:academic', 'B:operations', 'C:operations'])
  expect(violationSuggestions(page, 'u2').list).toEqual(page.all_violations)
  expect(violationSuggestions(page, '').roles).toEqual([])
})

test('hiện số tiền phạt', () => {
  expect(showAmount({ status: 'fined', amount: 100000 })).toBe(true)
  expect(showAmount({ status: 'confirmed', amount: 100000 })).toBe(false)
  expect(showAmount({ status: 'paid', amount: 0 })).toBe(false)
})
