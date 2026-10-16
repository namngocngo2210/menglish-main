// API danh mục & tổ chức (Go: internal/catalogs). Mọi thao tác ghi trả { message } để hiện thông báo như flash của Laravel.
import { request } from '../../api'

export type Option = { value: string; label: string }
export type Msg = { message: string }
export type Page<T> = { items: T[]; total: number; page: number; page_size: number }
export type BranchOption = { id: string; code: string; name: string }

// Chuỗi truy vấn bỏ tham số rỗng.
export function qs(params: Record<string, string | number | undefined | null>) {
  const q = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') q.set(k, String(v))
  const s = q.toString()
  return s ? `?${s}` : ''
}

// ── Khóa học ──
export type Course = {
  id: string
  code: string
  name: string
  description: string | null
  course_level_id: string | null
  tuition_fee: number
  total_lessons: number
  classes_count: number
  is_active: boolean
  level: { code: string; name: string; target: string } | null
}
export type CourseList = Page<Course> & {
  stats: { total_courses: number; active_courses: number; avg_tuition: number; max_tuition: number }
  levels: Option[]
}
export type CourseInput = {
  name: string
  course_level_id: string | null
  tuition_fee: number | null
  total_lessons: number | null
  description: string
  is_active: boolean
}

// ── Trình độ ──
export type Level = {
  id: string
  code: string
  name: string
  description: string | null
  level_group: string | null
  grade_levels: string[]
  grade_labels: string[]
  target: string
  lessons_count: number
  syllabus_curriculum_id: string | null
  is_active: boolean
  courses_count: number
  classes_count: number
  students_using: number
}
export type LevelList = Page<Level> & {
  stats: { total: number; active: number; syllabus: number; groups: number }
  groups: string[]
  grade_levels: Option[]
  can_reorder: boolean
}
export type LevelInput = {
  code: string
  name: string
  description: string
  level_group: string
  grade_levels: string[]
  target: string
  lessons_count: number | null
  is_active: boolean
}

// ── Phòng học ──
export type RoomScope = {
  blocked: boolean
  branches: BranchOption[]
  locked_branch: BranchOption | null
  counts: { rooms: number; types: number }
}
export type Room = {
  id: string
  branch_id: string
  branch: string
  room_type_id: string
  type: string
  type_active: boolean
  name: string
  capacity: number | null
  description: string | null
}
export type RoomTypeOption = { value: string; label: string; is_active: boolean }
export type RoomList = RoomScope & { rooms: Page<Room> | null; types: RoomTypeOption[] }
export type RoomInput = {
  branch_id: string | null
  name: string
  room_type_id: string | null
  capacity: number | null
  description: string
}
export type RoomType = { id: string; name: string; is_active: boolean; in_use: boolean }
export type RoomTypeList = RoomScope & { room_types: RoomType[] }

// ── Khung giờ ca dạy ──
export type Shift = {
  id: string
  day_type: string
  day_label: string
  name: string
  start_time: string
  end_time: string
  alt_start_time: string | null
  alt_end_time: string | null
  standard: string
  alt: string | null
  note: string | null
  is_active: boolean
}
export type ShiftList = { day_types: Option[]; shifts: Shift[]; weekday_rule: string; duration: number }
export type ShiftInput = {
  day_type: string
  name: string
  start_time: string
  end_time: string
  alt_start_time: string
  alt_end_time: string
  note: string
}

// ── Ngày nghỉ lễ ──
export type Holiday = {
  id: string
  code: string
  name: string
  start_date: string
  end_date: string
  is_system_wide: boolean
  branches?: string[]
  branch_ids?: string[]
}
export type HolidayList = Page<Holiday> & { branch_options: BranchOption[] }
export type HolidayInput = {
  code: string
  name: string
  start_date: string
  end_date: string
  is_system_wide: boolean
  branch_ids: string[]
}

// ── Danh mục hệ thống ──
export type Category = { id: string; type: string; code: string; name: string; sort_order: number; is_active: boolean }
export type CategoryList = Page<Category> & { type: string; types: Option[]; suggested_code: string; next_order: number }
export type CategoryInput = { type: string; code: string; name: string; sort_order: number | null; is_active: boolean }

// ── SLA ──
export type SlaRule = {
  key: string
  group: string
  group_label: string
  label: string
  description: string
  unit: string
  value: number
  min: number
  max: number
  switchable: boolean
  enabled: boolean
  has_penalty: boolean
  penalty: boolean
  amount: number
  amount_label: string
  amount_per: string | null
  task: boolean
  ladder: number[]
  has_ladder: boolean
  customized: boolean
  category: string
  violation: string
  default_label: string
}
export type SlaConfig = { rules: SlaRule[]; reset_months: number }
export type SlaInput = {
  value: number | string
  enabled?: boolean
  penalty?: boolean
  amount?: number
  ladder?: string
}

// ── Tài khoản ngân hàng ──
export type BankAccount = {
  id: string
  account_type: string
  type_label: string
  bank_code: string
  bank_name: string
  account_number: string
  account_holder: string
  branch_location: string | null
  branch_id: string | null
  branch_name: string | null
  is_default_vietqr: boolean
  is_active: boolean
  vietqr_preview_url: string
}
export type BankAccountList = {
  accounts: BankAccount[]
  total_accounts: number
  default_account: BankAccount | null
  branches: BranchOption[]
  types: Option[]
}
export type BankAccountInput = {
  account_type: string
  bank_code: string
  bank_name: string
  account_number: string
  account_holder: string
  branch_location: string
  branch_id: string | null
  is_default_vietqr: boolean
  is_active?: boolean
}

// ── Nhắc nợ học phí ──
export type Timing = 'before' | 'due' | 'after'
export type DebtRule = {
  id: string
  milestone_key: string
  offset_days: number
  offset_label: string
  title: string
  template_content: string
  is_enabled: boolean
  timing: Timing
  days: number | null
  channels: string[]
}
export type DebtReminderConfig = {
  rules: DebtRule[]
  channels: Option[]
  variables: Option[]
  must_contact_days: number
  first_days: number
  repeat_days: number
}
export type DebtRuleInput = {
  milestone_key: string
  title: string
  template_content: string
  timing: Timing
  days: number | null
  channels: string[]
  is_enabled: boolean
}
export type DebtSettings = { must_contact_days: number; first_days: number; repeat_days: number }

// ── Email nhận Ticket ──
export type MailConfig = {
  host: string
  port: number
  encryption: string
  username: string
  has_password: boolean
  from_address: string
  from_name: string
}
export type TicketEmailConfig = {
  emails: string[]
  events: Record<string, boolean>
  mail: MailConfig | null
  can_manage_mail: boolean
  event_labels: Option[]
}
export type MailInput = Omit<MailConfig, 'has_password'> & { password: string }
export type TicketEmailInput = { emails: string[]; events: Record<string, boolean>; mail?: MailInput }

export const catalogsApi = {
  courses: (p: { search?: string; course_level_id?: string; status?: string; page?: number }) =>
    request<CourseList>('GET', `/courses${qs(p)}`),
  createCourse: (c: CourseInput) => request<Msg & { id: string; code: string }>('POST', '/courses', c),
  updateCourse: (id: string, c: CourseInput) => request<Msg>('PUT', `/courses/${id}`, c),
  toggleCourse: (id: string) => request<Msg>('POST', `/courses/${id}/toggle`),
  deleteCourse: (id: string) => request<Msg>('DELETE', `/courses/${id}`),

  levels: (p: { search?: string; status?: string; group?: string; page?: number }) =>
    request<LevelList>('GET', `/course-levels${qs(p)}`),
  createLevel: (l: LevelInput) => request<Msg>('POST', '/course-levels', l),
  updateLevel: (id: string, l: LevelInput) => request<Msg>('PUT', `/course-levels/${id}`, l),
  toggleLevel: (id: string) => request<Msg>('POST', `/course-levels/${id}/toggle`),
  deleteLevel: (id: string) => request<Msg>('DELETE', `/course-levels/${id}`),
  reorderLevels: (ids: string[]) => request<Msg>('POST', '/course-levels/reorder', { ids }),

  rooms: (p: { branch_id?: string; room_type_id?: string; page?: number }) => request<RoomList>('GET', `/rooms${qs(p)}`),
  createRoom: (r: RoomInput) => request<Msg>('POST', '/rooms', r),
  updateRoom: (id: string, r: RoomInput) => request<Msg>('PUT', `/rooms/${id}`, r),
  deleteRoom: (id: string) => request<Msg>('DELETE', `/rooms/${id}`),
  roomTypes: () => request<RoomTypeList>('GET', '/rooms/types'),
  createRoomType: (name: string) => request<Msg>('POST', '/rooms/types', { name }),
  renameRoomType: (id: string, name: string) => request<Msg>('PUT', `/rooms/types/${id}`, { name }),
  toggleRoomType: (id: string) => request<Msg>('POST', `/rooms/types/${id}/toggle`),
  deleteRoomType: (id: string) => request<Msg>('DELETE', `/rooms/types/${id}`),

  shifts: () => request<ShiftList>('GET', '/teaching-shifts'),
  createShift: (s: ShiftInput) => request<Msg>('POST', '/teaching-shifts', s),
  updateShift: (id: string, s: ShiftInput) => request<Msg>('PUT', `/teaching-shifts/${id}`, s),
  toggleShift: (id: string) => request<Msg>('POST', `/teaching-shifts/${id}/toggle`),
  deleteShift: (id: string) => request<Msg>('DELETE', `/teaching-shifts/${id}`),

  holidays: (p: { search?: string; page?: number }) => request<HolidayList>('GET', `/holidays${qs(p)}`),
  holiday: (id: string) => request<Holiday>('GET', `/holidays/${id}`),
  createHoliday: (h: HolidayInput) => request<Msg>('POST', '/holidays', h),
  updateHoliday: (id: string, h: HolidayInput) => request<Msg>('PUT', `/holidays/${id}`, h),
  deleteHoliday: (id: string) => request<Msg>('DELETE', `/holidays/${id}`),

  categories: (p: { type?: string; q?: string; page?: number }) =>
    request<CategoryList>('GET', `/system-categories${qs(p)}`),
  createCategory: (c: CategoryInput) => request<Msg>('POST', '/system-categories', c),
  updateCategory: (id: string, c: CategoryInput) => request<Msg>('PUT', `/system-categories/${id}`, c),
  setCategoryActive: (id: string, active: boolean) =>
    request<Msg>('POST', `/system-categories/${id}/${active ? 'reactivate' : 'deactivate'}`),

  sla: () => request<SlaConfig>('GET', '/system-config/sla'),
  updateSla: (key: string, s: SlaInput) => request<Msg>('PUT', `/system-config/sla/${encodeURIComponent(key)}`, s),
  updateSlaSettings: (ladder_reset_months: number) =>
    request<Msg>('POST', '/system-config/sla/settings', { ladder_reset_months }),

  bankAccounts: (q?: string) => request<BankAccountList>('GET', `/system-config/bank-accounts${qs({ q })}`),
  createBankAccount: (b: BankAccountInput) => request<Msg>('POST', '/system-config/bank-accounts', b),
  updateBankAccount: (id: string, b: BankAccountInput) => request<Msg>('PUT', `/system-config/bank-accounts/${id}`, b),
  deleteBankAccount: (id: string) => request<Msg>('DELETE', `/system-config/bank-accounts/${id}`),
  setDefaultBankAccount: (id: string) => request<Msg>('POST', `/system-config/bank-accounts/${id}/default`),

  debtReminders: () => request<DebtReminderConfig>('GET', '/system-config/debt-reminders'),
  saveDebtRule: (r: DebtRuleInput) => request<Msg>('POST', '/system-config/debt-reminders', r),
  updateDebtSettings: (s: DebtSettings) => request<Msg>('POST', '/system-config/debt-reminders/settings', s),

  ticketEmails: () => request<TicketEmailConfig>('GET', '/system-config/ticket-emails'),
  updateTicketEmails: (t: TicketEmailInput) => request<Msg>('POST', '/system-config/ticket-emails', t),
}
