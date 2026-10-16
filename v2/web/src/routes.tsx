import type { ReactNode } from 'react'
import type { Me } from './api'
import { CreateCustomerPage } from './features/crm/CustomerForm'
import { CustomersRoute } from './features/crm/CustomersPage'
import { ImportPage } from './features/crm/ImportPage'
import { DeletedPage, LostPage, WaitingPage, WonPage } from './features/crm/ListPages'
import { PipelinePage } from './features/crm/PipelinePage'
import { PromotionsPage } from './features/crm/PromotionsPage'
import { ReportsPage } from './features/crm/ReportsPage'
import { ConfirmationsPage, TransfersPage } from './features/crm/WorkflowPages'
import { AccountPage } from './pages/AccountPage'
import { BranchesPage } from './pages/admin/BranchesPage'
import { RolesPage } from './pages/admin/RolesPage'
import { UsersPage } from './pages/admin/UsersPage'

// Trang theo đường dẫn mục menu (NAV trong nav.ts). Trang con (vd. /students/<id>) dùng chung mục cha và tự đọc
// phần còn lại của đường dẫn bằng usePath(). Module mới: thêm mục vào NAV và một dòng ở đây.
export const PAGES: Record<string, (me: Me) => ReactNode> = {
  '/': (me) => <AccountPage me={me} />,
  '/users': (me) => <UsersPage me={me} />,
  '/roles': (me) => <RolesPage me={me} />,
  '/branches': (me) => <BranchesPage me={me} />,
  '/crm/pipeline': (me) => <PipelinePage me={me} />,
  '/crm/customers': (me) => <CustomersRoute me={me} />,
  '/crm/customers/new': (me) => <CreateCustomerPage me={me} />,
  '/crm/won': (me) => <WonPage me={me} />,
  '/crm/lost': (me) => <LostPage me={me} />,
  '/crm/waiting': (me) => <WaitingPage me={me} />,
  '/crm/deleted': (me) => <DeletedPage me={me} />,
  '/crm/transfers': (me) => <TransfersPage me={me} />,
  '/crm/confirmations': (me) => <ConfirmationsPage me={me} />,
  '/crm/promotions': (me) => <PromotionsPage me={me} />,
  '/crm/reports': (me) => <ReportsPage me={me} />,
  '/crm/import': (me) => <ImportPage me={me} />,
}
