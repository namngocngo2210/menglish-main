import type { ReactNode } from 'react'
import type { Me } from './api'
import { ApprovalsPage } from './features/tasks/ApprovalsPage'
import { CreateTaskPage } from './features/tasks/CreateTaskPage'
import { TAAssignPage } from './features/tasks/TAAssignPage'
import { TAPortalPage } from './features/tasks/TAPortalPage'
import { TasksPage } from './features/tasks/TasksPage'
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
  '/tasks': (me) => <TasksPage me={me} />,
  '/tasks/new': (me) => <CreateTaskPage me={me} />,
  '/tasks/ta-assign': (me) => <TAAssignPage me={me} />,
  '/tasks/approvals': (me) => <ApprovalsPage me={me} />,
  '/portal/ta-tasks': (me) => <TAPortalPage me={me} />,
}
