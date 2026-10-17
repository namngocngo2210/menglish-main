# Đối chiếu: Lớp học

Phân hệ v2: `v2/api/internal/classes` (Go, sqlc `classesdb`) và `v2/web/src/features/classes` (React); màn Tiếp nhận & Xếp lớp
nằm ở `v2/web/src/features/students/EnrollmentsPage.tsx`. Migration: `00080` (lớp, ca học tuần, buổi học), `00081` (lượt
xếp lớp, điểm danh học viên, view `academic.class_roster`, FK `students.current_class_id` và `material_orders.class_id`).
Mọi route `/api/v1/...` bọc `iam.Require` / `iam.RequireAny` đúng quyền `can:` của `routes/web.php`.

Ký hiệu: **Đã làm** = đủ hành vi như Laravel; **Làm một phần** = phần còn thiếu ghi ở cột Ghi chú;
**Chưa làm** = chưa có ở v2.

## Lớp học (`ClassManagementController`, nhóm `classes.*` — cả nhóm cần `class.view`)

| Laravel (route name / method) | v2 (API + màn) | Trạng thái | Ghi chú |
|---|---|---|---|
| `classes.index` GET /classes | GET /classes · màn `/classes` | Đã làm | Chip trạng thái (mặc định ẩn Đã hủy), chip chương trình / cấp độ, tìm tên / mã, lọc chi nhánh, thống kê nhanh, sĩ số so với ngưỡng khai giảng, tiến độ buổi, "Việc tiếp theo" (ClassLifecycle). Phạm vi theo `ClassModel::visibleTo` (Toàn hệ thống / chi nhánh / lớp mình dạy kể cả dạy thay một buổi / cấp riêng). Tài khoản cổng học viên bị chặn như `ensureCanBrowseClasses`. Bước Big Test trong "Việc tiếp theo": TODO(academic). |
| `classes.create` GET /classes/create | GET /classes/form-options · màn `/classes/new` | Đã làm | Chi nhánh, khóa học (điền sẵn học phí), trình độ, phòng theo chi nhánh kèm lớp đang dùng phòng, GV chính / GVNN (người có `class.teach`). |
| `classes.store` POST /classes | POST /classes (201) | Đã làm | Kiểm tra và thông báo như Laravel; mã tự sinh `SLUG-XXXX`, mã trùng thêm `-1`, `-2`…; GV phải có `class.teach`; phòng đúng chi nhánh; TKB render sẵn (`schedule_sessions`): bỏ ngày nghỉ lễ, kiểm xung đột phòng / nhân sự, kích hoạt lớp. Khóa học gắn theo tên chương trình (`course_id`). Nhật ký nghiệp vụ "Tạo mới lớp học" (`audit.Record`, đối tượng `class`). |
| `classes.check-availability` POST | POST /classes/check-availability | Đã làm | Phòng / GV / trợ giảng / GVNN đang bận trong khung giờ. Màn tạo lớp v2 chưa render TKB trước khi tạo (cấu hình lịch tuần ở Lịch & TKB lớp sau khi tạo), nên route này hiện chỉ có API. |
| `classes.edit` GET /classes/{id}/edit | GET /classes/form-options?class_id= · màn `/classes/{id}/edit` | Đã làm | Giá trị hiện tại (`values`), chương trình / cấp độ cũ cố định, trợ giảng cố định chỉ với lớp cũ còn trợ giảng. |
| `classes.update` PUT /classes/{id} | PUT /classes/{id} | Đã làm | Đổi GV / GVNN / trợ giảng / phòng / chi nhánh áp lên buổi sắp tới chưa có dữ liệu thực tế, kiểm xung đột; chuyển sang chi nhánh ngoài phạm vi bị chặn. |
| `classes.foreign-teacher` POST /classes/{id}/foreign-teacher | POST /classes/{id}/foreign-teacher · hộp thoại "Gán GVNN" trên tab Lịch & buổi học | Đã làm | Gán / gỡ ("none") GVNN cho buổi sắp tới chưa điểm danh, kiểm trùng lịch, tùy chọn đặt làm GVNN mặc định. |
| `classes.destroy` DELETE /classes/{id} | DELETE /classes/{id} | Đã làm | Xóa mềm; chặn khi còn học viên đang xếp lớp hoặc buổi chưa diễn ra (409, cùng câu thông báo). Khác Laravel: chỉ đếm lượt xếp lớp còn hiệu lực (`left_at` trống). |
| `classes.show` GET /classes/{id}?tab= | GET /classes/{id}?tab= · màn `/classes/{id}` | Làm một phần | Thanh bước vòng đời, việc tiếp theo, sĩ số, tiến độ, buổi kế tiếp. Tab Tổng quan (GVNN 7 ngày tới), Học viên (lớp chính + liên kết), Lịch & buổi học, Điểm danh & báo cáo buổi (trạng thái điểm danh từng buổi). Trợ giảng 7 ngày tới và trợ giảng của từng buổi = người được giao việc gắn lớp (`ops.work_tasks`, như Laravel). Chưa có: báo cáo buổi, tab Học thuật (Big Test, giáo trình) — TODO(academic); tab Sự vụ — TODO(incidents). |
| `classes.trial-booking` GET | — | Chưa làm | Lịch học thử thuộc CRM: TODO(crm). |
| `classes.profile`, `academic-overview`, `academic-list`, `academic-detail` | — | Chưa làm | Ở Laravel chỉ là chuyển hướng sang `classes.index` / `classes.show`; v2 dùng thẳng `/classes`, `/classes/{id}?tab=academic`. |
| `classes.qa-observation`, `checklist`, `evaluate-observation` | NAV ẩn `/classes/qa-observation`, `/classes/checklist`, `/classes/evaluate-observation` | Đã làm | Chuyển sang màn Chất lượng lớp (xem [chat-luong-lop.md](chat-luong-lop.md)). |

## Lịch & TKB lớp (`WorkTaskController::scheduleConfig`, `SessionScheduleService`)

| Laravel (route name / method) | v2 (API + màn) | Trạng thái | Ghi chú |
|---|---|---|---|
| `tasks.schedule-config` GET | GET /schedule-config · màn `/schedule-config` | Làm một phần | Lớp phụ trách (chưa có TKB / đã có TKB), tối đa 2 ca học tuần theo khung giờ ca dạy (Thứ 2–6 đúng khung, mỗi ca 90 phút), năm học, khoảng ngày, phòng học, buổi nghỉ lễ sắp tới + buổi bù, lớp lệch khung giờ. Chưa có báo cáo nhu cầu nhân sự (HR demand): TODO(hr). |
| `tasks.schedule-config.update` POST (`work_task.assign`) | POST /schedule-config | Đã làm | Sinh buổi từ hôm nay (không sinh lại quá khứ, giữ buổi có dữ liệu thực tế, bỏ ngày nghỉ lễ, tối đa 300 buổi), kiểm xung đột phòng / nhân sự, phòng đang có lớp đang học → chặn, phòng gán cho lớp chưa bắt đầu học → hỏi chuyển phòng (409 `room_transfer`, xác nhận gửi `transfer_room`), lớp Chờ lịch → Đang học, lớp Sắp khai giảng chỉ kích hoạt khi chọn. |
| Bật / tắt trạng thái lớp trên màn TKB (`toggleClassStatus`) | POST /schedule-config/toggle | Đã làm | Đang học ↔ Đã kết thúc; kết thúc giải phóng phòng; mở lại cảnh báo chưa có phòng. Chặt hơn Laravel: kiểm cả phạm vi xem lớp. |
| `tasks.support-sessions*`, `tasks.hr-demand.save` | — | Chưa làm | Buổi phụ đạo và nhu cầu nhân sự thuộc phân hệ Công việc: TODO(tasks), TODO(hr). |

## Ngày nghỉ lễ áp lên lịch học (`HolidayRescheduleService`)

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| Thêm / sửa ngày nghỉ (`HolidayController` + `apply`) | `catalogs.ClassHooks.HolidayApplied` (gọi từ POST/PUT /holidays) | Đã làm | Hủy buổi sắp tới chưa có dữ liệu thực tế trùng ngày nghỉ (toàn hệ thống / chi nhánh), xếp bù vào ca kế tiếp sau buổi cuối, kéo dài ngày kết thúc, đếm buổi cần xử lý tay; thông báo nối `summaryText`. Lịch hẹn học thử của buổi bị hủy chuyển Đã hủy ("Hủy: buổi học thử trùng nghỉ lễ <tên>"); gỡ ngày nghỉ thì lịch hẹn ở buổi bù chuyển về buổi gốc (bỏ lịch hẹn đã hủy trùng khách ở buổi gốc). |
| Xóa / thu hẹp ngày nghỉ | `HolidayReleased` (DELETE /holidays) và `restoreUncovered` | Đã làm | Khôi phục buổi đã hủy, gỡ buổi bù chưa diễn ra, kéo lại ngày kết thúc. |

## Phòng học dùng bởi lớp (`RoomService`)

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| Danh sách phòng kèm lớp đang dùng | `RoomClasses` → GET /rooms (cột "Lớp đang dùng") | Đã làm | |
| Đổi tên phòng | `RoomRenamed` (PUT /rooms/{id}, cùng transaction) | Đã làm | Lớp và buổi sắp tới theo tên mới. |
| Xóa phòng | `RoomDeleting` (DELETE /rooms/{id}) | Đã làm | Lớp đang học → chặn (409); lớp chưa bắt đầu học → bỏ liên kết, thông báo tên lớp. |
| Lớp lệch khung giờ ca dạy (`TeachingShiftController@index`) | `RegularSessions` → GET /teaching-shifts | Đã làm | 60 ngày tới, hiện trên màn Khung giờ ca dạy và Lịch & TKB lớp. |
| Khóa học / trình độ đếm lớp, chặn xóa | `CourseClassCounts`, `LevelUsage` | Đã làm | "Không thể xóa khóa học … vì đang có N lớp học liên kết!" (409). |

## Xếp lớp (`StudentProfileController` — Tiếp nhận & Xếp lớp)

| Laravel (route name / method) | v2 (API + màn) | Trạng thái | Ghi chú |
|---|---|---|---|
| `students.enrollments` GET | GET /class-enrollments · màn `/students/enrollments` | Làm một phần | Lượt xếp lớp trong phạm vi Học viên (15 / trang), lọc theo học viên / lớp, lớp chọn được kèm sĩ số. Băng "<n> khách chốt từ CRM đang chờ xếp lớp" + nút sang `/crm/waiting` (`crm.Service.WaitingCount`). Xếp lớp học viên còn khách CRM Chờ xếp lớp: kiểm khóa / cấp độ và chi nhánh đã chốt (`WaitingLeadPlacement`, `PlacementLevelMatcher::matchesClosed`), lượt xếp lớp gắn `lead_id`, khách chuyển Đã chốt + dòng chờ xác nhận. Ghép cấp độ test đầu vào chưa có. |
| `students.enrollments.store` POST (`student.assign_class`) | POST /class-enrollments | Đã làm | Kiểm học viên thôi học, cùng chi nhánh, đã có trong lớp, đủ sĩ số (khóa dòng lớp). Chuyển lớp: lượt ở lớp cũ hết hiệu lực từ hôm nay, đổi lớp chính. |
| `students.link-class` POST /{id}/link-class | POST /class-enrollments/link | Đã làm | Học song song, không đổi lớp chính (chưa có lớp chính thì lớp liên kết thành lớp chính). |
| `students.enrollments.update` PUT /enrollments/{id} | PUT /class-enrollments/{id} | Đã làm | Checklist giáo trình + nhóm Zalo; đủ hai mục và lớp đã khai giảng → học viên Chờ khai giảng chuyển Đang học (`ClassStartActivation`). Lệnh hằng ngày `students:start-studying` = `RunStartStudyingLoop` (mỗi giờ). |
| `crm.enrollments.confirm`, `crm.enrollments.reset-account` | — | Chưa làm | Thuộc CRM: TODO(crm). |
| Hồ sơ học viên → tab Lớp học | `StudentClasses` (GET /class-enrollments?student_id=) | Làm một phần | Lớp hiện tại / đã rời; lộ trình, chuyên cần, chăm sóc tháng đầu chưa có. |
| Phạm vi học viên theo lớp (`Student::scopeVisibleTo`) | `students.ClassHooks.VisibleClassIDs` + truy vấn học viên | Đã làm | Học viên chưa gán chi nhánh xét theo chi nhánh lớp đang học; mức "Của tôi" gồm lớp liên kết. |

## Điểm danh học viên (`TeacherPortalController::attendance`, `ClassDashboardService`)

| Laravel (route name / method) | v2 (API + màn) | Trạng thái | Ghi chú |
|---|---|---|---|
| `teacher.attendance` GET /teacher/attendance/{classId} | GET /class-attendance/{id}?session=&date= · màn `/classes/{id}/attendance` | Làm một phần | Chọn buổi theo id / ngày (ưu tiên buổi mình được phân công), mặc định buổi gần nhất chưa điểm danh trong 30 ngày; danh sách lớp thật (lớp chính + liên kết, giữ học viên đã có điểm danh); điểm danh thay (Học vụ / Quản lý). Khách học thử của buổi: TODO(crm); học viên buổi phụ đạo: TODO(tasks). |
| `teacher.attendance.store` POST | POST /class-attendance/{id} | Đã làm | GV trong khung ±N giờ (SLA `gv.attendance_window`, mặc định 24h), Học vụ chặn buổi chưa tới ngày, buổi đã hủy chặn; "Vắng" / "Vắng có phép" bắt buộc lý do; học viên ngoài lớp bị chặn; ghi ngày bắt đầu học khi có mặt lần đầu; buổi chuyển "đã điểm danh". |
| `teacher.home`, `teacher.checkin`, homework / scores / reports | — | Chưa làm | Cổng giáo viên khác (chấm công, bài tập, điểm, báo cáo) thuộc phân hệ khác. |

## Tích hợp với phân hệ khác

| Nơi dùng | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| Order học liệu: chọn lớp, kiểm lớp tồn tại | `inventory.ClassLookup` (`ClassOptions`, `ClassExists`) | Làm một phần | Danh sách order chưa hiện tên lớp. |
| CRM Chốt & Xếp lớp / Gán lớp (`processClosingWizard`, `assignClass`) | `crm.StudentCreator` = `classes.CRMStudents` (+ `crm.ClassStarts`, `crm.ClosingChoiceSource`) | Làm một phần | Xem `crm.md`. Học phí / phiếu thu / task nhắc thu chờ phân hệ Học phí. |
| Học thử (`placement.Sessions`) | `classes.PlacementSessions` (Get, Lookup, TeachingScope) | Đã làm | Buổi học thật cho xếp học thử; phạm vi giáo viên = buổi mình dạy / dạy thay + lớp mình phụ trách. |
| Báo cáo (`reports.ClassDirectory`, `MetricsProvider`) | `classes.ReportClasses`, `classes.ReportMetrics` (bọc provider gốc) | Làm một phần | Xem `bao-cao.md`: vận hành lớp học của báo cáo ngày, chỉ số quy mô lớp của báo cáo tuần, lớp của giáo viên trong báo cáo tháng (chuyên cần, số buổi đã dạy). |
| Công việc (`tasks.Classes`) | `classes.TaskClasses` | Đã làm | Lớp gắn vào việc, buổi học để giao việc trợ giảng, lớp trực trong ngày ở portal trợ giảng. Xem `cong-viec.md`. |
| FK sang `academic.*` | `00110_academic_links_fk` | Đã làm | `crm.leads` (khóa chờ xếp lớp, học viên đã chốt), `crm.promotions.course_id`, `crm.enrollment_confirmations`, `crm.trial_bookings`, `academic.enrollments.lead_id`, `academic.students.lead_id`, `placement.submissions.student_id`, `ops.work_tasks` (`class_id`, `student_id`), `ops.staff_reports.class_id`, `ops.stock_movements.student_id`. |
| Nhật ký nghiệp vụ / thông báo | `audit.Record` khi tạo / sửa / xóa lớp, gán / gỡ GVNN; `notify.Send` báo GVNN được xếp dạy | Đã làm | Thông báo "Bạn được xếp dạy GVNN lớp <mã>", nội dung các ngày buổi (tối đa 6), link tab Lịch & buổi học; không báo người vừa thao tác. |
| Giãn tiến độ lớp (`ScheduleExtensionService`, dùng ở `SyllabusController`) | `classes.SyllabusClasses.ExtendSessions` (`classes/extension.go`), gọi khi duyệt yêu cầu điều chỉnh tiến độ | Đã làm | Thêm N buổi chính khóa theo ca lặp tuần (bỏ ngày lễ, chặn trùng phòng / nhân sự), lùi ngày kết thúc lớp; xem [giao-trinh.md](giao-trinh.md). |
