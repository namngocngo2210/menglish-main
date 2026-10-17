import type { ReactNode } from 'react'
import type { Me } from './api'
import { StudentsPage } from './features/students/StudentsPage'
import { ClassesPage } from './features/classes/ClassesPage'
import { ScheduleConfigPage } from './features/classes/ScheduleConfigPage'
import { ItemsPage } from './features/inventory/ItemsPage'
import { MaterialOrdersPage } from './features/inventory/MaterialOrdersPage'
import { StockPage } from './features/inventory/StockPage'
import { ExpensesPage } from './features/finance/ExpensesPage'
import { RevenuePage } from './features/finance/RevenuePage'
import { AttendanceReviewPage } from './features/kpi/AttendanceReviewPage'
import { CriteriaPage as KpiCriteriaPage } from './features/kpi/CriteriaPage'
import { KpiBoardPage } from './features/kpi/KpiBoardPage'
import { MinePage as KpiMinePage } from './features/kpi/MinePage'
import { MonthlyPage as KpiMonthlyPage } from './features/kpi/MonthlyPage'
import { ApprovalsPage } from './features/tasks/ApprovalsPage'
import { CreateTaskPage } from './features/tasks/CreateTaskPage'
import { TAAssignPage } from './features/tasks/TAAssignPage'
import { TAPortalPage } from './features/tasks/TAPortalPage'
import { TasksPage } from './features/tasks/TasksPage'
import { PlacementTestsPage } from './features/placement/TestsPage'
import { TeacherTrialsPage, TrialSchedulePage } from './features/placement/TrialPages'
import { TeacherClassPage } from './features/teacher/ClassPage'
import { GeneralReportPage as TeacherReportPage } from './features/teacher/GeneralReportPage'
import { HomePage as TeacherHomePage } from './features/teacher/HomePage'
import { SubmissionsPage as TeacherSubmissionsPage } from './features/teacher/SubmissionsPage'
import { ActivityLogsPage } from './features/platform/ActivityLogsPage'
import { NotificationsPage } from './features/platform/NotificationsPage'
import { SearchPage } from './features/platform/SearchPage'
import { AllPage, JournalPage, ReportsPage } from './features/reports'
import { CreateCustomerPage } from './features/crm/CustomerForm'
import { CustomersRoute } from './features/crm/CustomersPage'
import { ImportPage } from './features/crm/ImportPage'
import { DeletedPage, LostPage, WaitingPage, WonPage } from './features/crm/ListPages'
import { PipelinePage } from './features/crm/PipelinePage'
import { PromotionsPage } from './features/crm/PromotionsPage'
import { ReportsPage as CrmReportsPage } from './features/crm/ReportsPage'
import { ConfirmationsPage, TransfersPage } from './features/crm/WorkflowPages'
import { AcademicPage } from './features/dashboard/AcademicPage'
import { DashboardPage } from './features/dashboard/DashboardPage'
import { seesDashboard } from './features/dashboard/logic'
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
import { OutboxPage } from './features/mail/OutboxPage'
import { ApprovePage as TuitionApprovePage } from './features/tuition/ApprovePage'
import { BillPage as TuitionBillPage } from './features/tuition/BillPage'
import { CancellationsPage as TuitionCancellationsPage } from './features/tuition/CancellationsPage'
import { ConfigPage as TuitionConfigPage } from './features/tuition/ConfigPage'
import { SepayPage as TuitionSepayPage } from './features/tuition/SepayPage'
import { ImportPage as TuitionImportPage } from './features/tuition/ImportPage'
import { HistoryPage as TuitionHistoryPage } from './features/tuition/HistoryPage'
import { OverduePage as TuitionOverduePage } from './features/tuition/OverduePage'
import { ReceiptsRoute } from './features/tuition/ReceiptFormPage'
import { RefundsPage as TuitionRefundsPage } from './features/tuition/RefundsPage'
import { StudentsPage as TuitionStudentsPage } from './features/tuition/StudentsPage'

// Trang theo đường dẫn mục menu (NAV trong nav.ts). Trang con (vd. /students/<id>) dùng chung mục cha và tự đọc
// phần còn lại của đường dẫn bằng usePath(). Module mới: thêm mục vào NAV và một dòng ở đây.
export const PAGES: Record<string, (me: Me) => ReactNode> = {
  '/': (me) => (seesDashboard(me) ? <DashboardPage me={me} /> : <AccountPage me={me} />),
  '/dashboard': (me) => (seesDashboard(me) ? <DashboardPage me={me} /> : <AccountPage me={me} />),
  '/profile': (me) => <AccountPage me={me} />,
  '/academic/dashboards': () => <AcademicPage />,
  '/academic/reports': () => <AcademicPage />,
  '/academic/incidents': () => <AcademicPage />,
  '/users': (me) => <UsersPage me={me} />,
  '/roles': (me) => <RolesPage me={me} />,
  '/branches': (me) => <BranchesPage me={me} />,
  '/students': (me) => <StudentsPage me={me} />,
  '/classes': (me) => <ClassesPage me={me} />,
  '/schedule-config': () => <ScheduleConfigPage />,
  '/material-orders': () => <MaterialOrdersPage />,
  '/inventory/items': (me) => <ItemsPage me={me} />,
  '/inventory/stock': (me) => <StockPage me={me} />,
  '/finance/expenses': (me) => <ExpensesPage me={me} />,
  '/finance/reports/provisional-revenue': () => <RevenuePage />,
  '/kpi/criteria': () => <KpiCriteriaPage />,
  '/kpi/monthly': () => <KpiMonthlyPage />,
  '/kpi/mine': () => <KpiMinePage />,
  '/kpi/attendance-review': (me) => <AttendanceReviewPage me={me} />,
  '/tasks/kpi-dashboard': () => <KpiBoardPage />,
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
  '/system-config/mail-outbox': () => <OutboxPage />,
  '/tasks': (me) => <TasksPage me={me} />,
  '/tasks/new': (me) => <CreateTaskPage me={me} />,
  '/tasks/ta-assign': (me) => <TAAssignPage me={me} />,
  '/tasks/approvals': (me) => <ApprovalsPage me={me} />,
  '/portal/ta-tasks': (me) => <TAPortalPage me={me} />,
  '/trial-bookings': (me) => <TrialSchedulePage me={me} />,
  '/placement-tests': (me) => <PlacementTestsPage me={me} />,
  '/placement-tests/rubric-guide': (me) => <PlacementTestsPage me={me} />,
  '/teacher/trial-guests': () => <TeacherTrialsPage />,
  '/teacher/home': () => <TeacherHomePage />,
  '/teacher/classes': () => <TeacherClassPage />,
  '/teacher/submissions': () => <TeacherSubmissionsPage />,
  '/teacher/general-report': () => <TeacherReportPage />,
  '/activity-logs': () => <ActivityLogsPage />,
  '/notifications': (me) => <NotificationsPage me={me} />,
  '/search': (me) => <SearchPage me={me} />,
  '/reports': () => <ReportsPage />,
  '/reports/all': () => <AllPage />,
  '/reports/journal': () => <JournalPage />,
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
  '/crm/reports': (me) => <CrmReportsPage me={me} />,
  '/crm/import': (me) => <ImportPage me={me} />,
  '/tuition/students': (me) => <TuitionStudentsPage me={me} />,
  '/tuition/history': (me) => <TuitionHistoryPage me={me} />,
  '/tuition/overdue': (me) => <TuitionOverduePage me={me} />,
  '/tuition/receipts': (me) => <ReceiptsRoute me={me} />,
  '/tuition/receipts/approve': (me) => <TuitionApprovePage me={me} />,
  '/tuition/bill': (me) => <TuitionBillPage me={me} />,
  '/tuition/invoices/cancellations': (me) => <TuitionCancellationsPage me={me} />,
  '/tuition/config': (me) => <TuitionConfigPage me={me} />,
  '/tuition/refunds': (me) => <TuitionRefundsPage me={me} />,
  '/tuition/sepay': (me) => <TuitionSepayPage me={me} />,
  '/tuition/import': (me) => <TuitionImportPage me={me} />,
}
