import type { ReactNode } from 'react'
import type { Me } from './api'
import { StudentsPage } from './features/students/StudentsPage'
import { ClassesPage } from './features/classes/ClassesPage'
import { ScheduleConfigPage } from './features/classes/ScheduleConfigPage'
import { ItemsPage } from './features/inventory/ItemsPage'
import { MaterialOrdersPage } from './features/inventory/MaterialOrdersPage'
import { StockPage } from './features/inventory/StockPage'
import { ExpensesPage } from './features/finance/ExpensesPage'
import { AcademicPage as QualityAcademicPage } from './features/quality/AcademicPage'
import { ChecklistPage as QualityChecklistPage } from './features/quality/ChecklistPage'
import { OperationsPage as QualityOperationsPage } from './features/quality/OperationsPage'
import { ProjectReportPage } from './features/quality/ProjectReportPage'
import { ProjectsPage } from './features/quality/ProjectsPage'
import { TeacherMeetingsPage } from './features/quality/TeacherMeetingsPage'
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
import { LegacySubmissionsRedirect, SubmissionsPage as TeacherSubmissionsPage } from './features/teacher/SubmissionsPage'
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
import { ApprovalsPage as ApprovalInboxPage } from './features/approvals/ApprovalsPage'
import { SurveysPage } from './features/surveys/SurveysPage'
import { GuidePage } from './features/guide/GuidePage'
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
import { AppShellPage as PortalAppShellPage } from './features/portal/AppShellPage'
import { FeedbackPage as PortalFeedbackPage } from './features/portal/FeedbackPage'
import { HomePage as PortalHomePage } from './features/portal/HomePage'
import { HomeworkPage as PortalHomeworkPage } from './features/portal/HomeworkPage'
import { isPortalOnly } from './features/portal/logic'
import { PortalNotificationsPage } from './features/portal/NotificationsPage'
import { PaymentReportsPage } from './features/portal/PaymentReportsPage'
import { PronunciationPage as PortalPronunciationPage } from './features/portal/PronunciationPage'
import { SurveyPage as PortalSurveyPage } from './features/portal/SurveyPage'
import { ApprovalsPage as AttendanceApprovalsPage } from './features/attendance/ApprovalsPage'
import { DailyPage as StaffAttendancePage } from './features/attendance/DailyPage'
import { HistoryPage as AttendanceHistoryPage } from './features/attendance/HistoryPage'
import { ManualPage as TimesheetManualPage } from './features/attendance/ManualPage'
import { MyAttendancePage } from './features/attendance/MyAttendancePage'
import { legacyMobileTarget, Redirect } from './features/attendance/parts'
import { RequestsPage as AttendanceRequestsPage } from './features/attendance/RequestsPage'
import { AppSheetPage, SyncHistoryPage } from './features/attendance/SyncPages'
import { TeacherTimesheetsPage } from './features/attendance/TeacherTimesheetsPage'
import { TicketsPage } from './features/tickets/TicketsPage'
import { RatesPage as PayrollRatesPage, SettingsPage as PayrollSettingsPage, TiersPage as PayrollTiersPage } from './features/payroll/ConfigPages'
import { PenaltiesPage } from './features/payroll/PenaltiesPage'
import { PeriodsRoute as PayrollPeriodsRoute } from './features/payroll/PeriodsPage'
import { LeaderboardPage as PayrollLeaderboardPage, MySalaryPage } from './features/payroll/PortalPages'
import { RecordRoute as PayrollRecordRoute } from './features/payroll/RecordPage'

// Trang theo đường dẫn mục menu (NAV trong nav.ts). Trang con (vd. /students/<id>) dùng chung mục cha và tự đọc
// phần còn lại của đường dẫn bằng usePath(). Module mới: thêm mục vào NAV và một dòng ở đây.
export const PAGES: Record<string, (me: Me) => ReactNode> = {
  '/': (me) => home(me),
  '/dashboard': (me) => home(me),
  '/profile': (me) => <AccountPage me={me} />,
  '/academic/dashboards': () => <AcademicPage />,
  '/academic/reports': () => <AcademicPage />,
  '/academic/incidents': () => <AcademicPage />,
  '/class-quality/operations': (me) => <QualityOperationsPage me={me} />,
  '/class-quality/academic': (me) => <QualityAcademicPage me={me} />,
  '/class-quality/checklist': (me) => <QualityChecklistPage me={me} />,
  '/class-quality/teacher-meetings': (me) => <TeacherMeetingsPage me={me} />,
  '/academic-projects': (me) => <ProjectsPage me={me} />,
  '/academic-projects/report': (me) => <ProjectReportPage me={me} />,
  '/classes/qa-observation': () => <Redirect to="/class-quality/operations" />,
  '/classes/checklist': () => <Redirect to="/class-quality/checklist" />,
  '/classes/evaluate-observation': () => <Redirect to="/class-quality/academic" />,
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
  '/teacher/submissions': (me) => <TeacherSubmissionsPage me={me} />,
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
  '/payment-reports': (me) => <PaymentReportsPage me={me} />,
  '/portal/home': () => <PortalHomePage />,
  '/portal/student-homework': (me) => <PortalHomeworkPage me={me} />,
  '/portal/pronunciation': () => <PortalPronunciationPage />,
  '/portal/notifications': () => <PortalNotificationsPage />,
  '/portal/survey': () => <PortalSurveyPage />,
  '/portal/feedback': () => <PortalFeedbackPage />,
  '/portal/app-shell': () => <PortalAppShellPage />,
  // Đường dẫn cũ của màn chấm bài → /teacher/submissions
  '/portal/teacher-submissions': () => <LegacySubmissionsRedirect />,
  '/cham-cong': () => <MyAttendancePage />,
  '/cham-cong/lich-su': () => <AttendanceHistoryPage />,
  '/cham-cong/don': (me) => <AttendanceRequestsPage me={me} />,
  '/approvals/staff-attendance': () => <AttendanceApprovalsPage />,
  '/staff-attendance': () => <StaffAttendancePage />,
  '/payroll/timesheets/teachers': () => <TeacherTimesheetsPage />,
  '/payroll/timesheets/manual': () => <TimesheetManualPage />,
  '/payroll/timesheets/appsheet': () => <AppSheetPage />,
  '/payroll/timesheets/sync-history': () => <SyncHistoryPage />,
  '/m': () => <Redirect to={legacyMobileTarget(window.location.pathname)} />,
  '/tickets': (me) => <TicketsPage me={me} />,
  '/approvals': (me) => <ApprovalInboxPage me={me} />,
  '/surveys': (me) => <SurveysPage me={me} />,
  '/huong-dan': (me) => <GuidePage me={me} />,
  '/payroll/periods': (me) => <PayrollPeriodsRoute me={me} />,
  '/payroll/records': (me) => <PayrollRecordRoute me={me} />,
  '/penalties': () => <PenaltiesPage />,
  '/payroll/kpi-leaderboard': () => <PayrollLeaderboardPage />,
  '/portal/my-salary': () => <MySalaryPage />,
  '/payroll/config/settings': () => <PayrollSettingsPage />,
  '/payroll/config/teacher-rates': () => <PayrollRatesPage />,
  '/payroll/config/commission-tiers': () => <PayrollTiersPage />,
}

// Trang "/": nhân sự → Tổng quan; tài khoản cổng (học viên / phụ huynh) → Trang chủ cổng học viên như Laravel
// (DashboardController chuyển sang portal.student.home); còn lại → Tài khoản của tôi.
function home(me: Me) {
  if (seesDashboard(me)) return <DashboardPage me={me} />
  if (isPortalOnly(me)) return <PortalHomePage />
  return <AccountPage me={me} />
}
