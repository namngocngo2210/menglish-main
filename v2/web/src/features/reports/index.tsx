// Màn "Báo cáo định kỳ" (/reports/...): chọn màn nhập theo đường dẫn con; /reports mở báo cáo chính của vai trò.
import { usePath } from '../../router'
import { DailyPage } from './DailyPage'
import { AcademicMonthlyPage, AcademicQuarterlyPage, TeacherMonthlyPage } from './MonthlyPages'
import { MyPage } from './MyPage'
import { AcademicWeeklyPage, WeeklyKpiPage } from './WeeklyPages'

export { AllPage } from './AllPage'
export { JournalPage } from './JournalPage'

export function ReportsPage() {
  switch (usePath()) {
    case '/reports/daily':
      return <DailyPage />
    case '/reports/weekly-kpi':
      return <WeeklyKpiPage />
    case '/reports/academic-weekly':
      return <AcademicWeeklyPage />
    case '/reports/academic-monthly':
      return <AcademicMonthlyPage />
    case '/reports/academic-quarterly':
      return <AcademicQuarterlyPage />
    case '/reports/teacher-monthly':
      return <TeacherMonthlyPage />
    default:
      return <MyPage />
  }
}
