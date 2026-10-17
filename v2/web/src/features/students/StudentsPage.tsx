import type { Me } from '../../api'
import { usePath } from '../../router'
import { EnrollmentsPage } from './EnrollmentsPage'
import { parseStudentPath } from './logic'
import { StudentProfilePage } from './StudentProfilePage'
import { StudentsListPage } from './StudentsListPage'

// Trang gốc /students: danh sách, /students/enrollments, /students/<id>[/scoped].
export function StudentsPage({ me }: { me: Me }) {
  const route = parseStudentPath(usePath())
  switch (route.page) {
    case 'enrollments':
      return <EnrollmentsPage me={me} />
    case 'profile':
      return <StudentProfilePage key={`${route.id}-${route.scoped}`} me={me} id={route.id} scoped={route.scoped} />
    default:
      return <StudentsListPage />
  }
}
