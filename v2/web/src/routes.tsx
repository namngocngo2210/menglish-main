import type { ReactNode } from 'react'
import type { Me } from './api'
import { AccountPage } from './pages/AccountPage'
import { BranchesPage } from './pages/admin/BranchesPage'
import { RolesPage } from './pages/admin/RolesPage'
import { UsersPage } from './pages/admin/UsersPage'
import { BankAccountsPage } from './features/catalogs/BankAccountsPage'
import { CategoriesPage } from './features/catalogs/CategoriesPage'
import { CoursesPage } from './features/catalogs/CoursesPage'
import { DebtRemindersPage } from './features/catalogs/DebtRemindersPage'
import { HolidaysPage } from './features/catalogs/HolidaysPage'
import { LevelsPage } from './features/catalogs/LevelsPage'
import { RoomsPage } from './features/catalogs/RoomsPage'
import { ShiftsPage } from './features/catalogs/ShiftsPage'
import { SlaPage } from './features/catalogs/SlaPage'
import { TicketEmailsPage } from './features/catalogs/TicketEmailsPage'

// Trang theo đường dẫn mục menu (NAV trong nav.ts). Trang con (vd. /students/<id>) dùng chung mục cha và tự đọc
// phần còn lại của đường dẫn bằng usePath(). Module mới: thêm mục vào NAV và một dòng ở đây.
export const PAGES: Record<string, (me: Me) => ReactNode> = {
  '/': (me) => <AccountPage me={me} />,
  '/users': (me) => <UsersPage me={me} />,
  '/roles': (me) => <RolesPage me={me} />,
  '/branches': (me) => <BranchesPage me={me} />,
  '/courses': (me) => <CoursesPage me={me} />,
  '/course-levels': (me) => <LevelsPage me={me} />,
  '/holidays': (me) => <HolidaysPage me={me} />,
  '/system-categories': (me) => <CategoriesPage me={me} />,
  '/teaching-shifts': (me) => <ShiftsPage me={me} />,
  '/rooms': (me) => <RoomsPage me={me} />,
  '/system-config/debt-reminders': (me) => <DebtRemindersPage me={me} />,
  '/system-config/bank-accounts': (me) => <BankAccountsPage me={me} />,
  '/system-config/ticket-emails': (me) => <TicketEmailsPage me={me} />,
  '/system-config/sla': (me) => <SlaPage me={me} />,
}
