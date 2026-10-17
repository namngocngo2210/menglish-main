import { expect, test } from 'vitest'
import type { Me } from '../../api'
import { navItemFor, visibleNav } from '../../nav'
import { addFiles, initial, MAX_FILES, pastedName, priorityTone, statusTone, ticketForm, ticketRoute } from './logic'

const file = (name: string, size = 10) => new File([new Uint8Array(size)], name)

test('trang con theo đường dẫn', () => {
  expect(ticketRoute('/tickets')).toEqual({ kind: 'list' })
  expect(ticketRoute('/tickets/new')).toEqual({ kind: 'new' })
  expect(ticketRoute('/tickets/TK-2026-0001')).toEqual({ kind: 'show', id: 'TK-2026-0001' })
  expect(ticketRoute('/tickets/abc/xyz')).toEqual({ kind: 'show', id: 'abc' })
})

test('kiểm file đính kèm: loại, dung lượng, tối đa 4 tệp', () => {
  let r = addFiles([], [file('a.png'), file('b.exe')])
  expect(r.files.map((f) => f.name)).toEqual(['a.png'])
  expect(r.error).toContain('b.exe')
  r = addFiles([], [file('big.pdf', 16 * 1024 * 1024)])
  expect(r.files).toHaveLength(0)
  expect(r.error).toContain('15360 kB')
  r = addFiles([file('1.png'), file('2.png'), file('3.png')], [file('4.pdf'), file('5.docx')])
  expect(r.files).toHaveLength(MAX_FILES)
  expect(r.error).toBe('Mỗi lần gửi tối đa 4 tệp đính kèm.')
})

test('ảnh dán đặt tên theo loại', () => {
  expect(pastedName('image/png', 1)).toBe('anh-chup-man-hinh-1.png')
  expect(pastedName('image/jpeg', 2)).toBe('anh-chup-man-hinh-2.jpg')
  expect(pastedName('image/bmp', 3)).toBe('anh-chup-man-hinh-3.png')
})

test('form multipart: payload JSON + attachments[i]', () => {
  const form = ticketForm({ message: 'Xin chào' }, [file('a.png'), file('b.pdf')])
  expect(JSON.parse(String(form.get('payload')))).toEqual({ message: 'Xin chào' })
  expect((form.get('attachments[0]') as File).name).toBe('a.png')
  expect((form.get('attachments[1]') as File).name).toBe('b.pdf')
})

test('màu nhãn và chữ cái đầu', () => {
  expect(statusTone('open')).toBe('warning')
  expect(statusTone('resolved')).toBe('success')
  expect(priorityTone('urgent')).toBe('error')
  expect(priorityTone('x')).toBe('neutral')
  expect(initial('đức')).toBe('Đ')
  expect(initial(null)).toBe('U')
})

const me = (perms: string[]): Me => ({ permissions: perms, roles: [] }) as unknown as Me

test('menu Ticket hỗ trợ neo portal.staff; học viên vẫn mở được trang ticket', () => {
  const staff = me(['support_ticket.view', 'portal.staff'])
  const student = me(['support_ticket.view', 'portal.student'])
  expect(visibleNav(staff).some((i) => i.path === '/tickets')).toBe(true)
  expect(visibleNav(student).some((i) => i.path === '/tickets')).toBe(false)
  expect(navItemFor('/tickets/TK-2026-0001')?.path).toBe('/tickets')
})
