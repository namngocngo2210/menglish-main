import type { ReactNode } from 'react'
import type { Me } from './api'
import { ItemsPage } from './features/inventory/ItemsPage'
import { MaterialOrdersPage } from './features/inventory/MaterialOrdersPage'
import { StockPage } from './features/inventory/StockPage'
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
  '/material-orders': () => <MaterialOrdersPage />,
  '/inventory/items': (me) => <ItemsPage me={me} />,
  '/inventory/stock': (me) => <StockPage me={me} />,
}
