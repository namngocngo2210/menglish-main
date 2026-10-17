# Đối chiếu: Cổng giáo viên

Laravel: `TeacherPortalController` (home, checkin, attendance, homework, scores, remarks, orderTest, bigTestReport,
generalReport), `StudentPortalController::teacherSubmissions` / `markSubmission` / `submitHomework` / `updateHomework` /
`deleteHomework`, `Pages/Teacher/*.vue`, `Pages/Portal/TeacherSubmissions.vue`, model `Homework`, `HomeworkSubmission`,
`MiniTestScore`, `StudentRemark`, `routes/web.php` (nhóm `teacher.*`, `portal.teacher.submissions*`), `SidebarMenu`
(workspace `teacher_portal`), `KpiBoardService` (tỉ lệ bài tập), `Support/Dashboard/TeachingQuality` (bài về nhà, mini test).

v2:

- API: `api/internal/teacher` (route `/api/v1/teacher/*`), migration `00210_academic_homeworks.sql`,
  `00211_academic_mini_test_scores.sql`, `00212_academic_remarks.sql`. Điểm danh dùng lại `classes`
  (`/classes/<id>/attendance`), khách học thử dùng lại `placement` (`SessionGuests`), file qua `internal/files`.
- Giao diện: `web/src/features/teacher` (màn `/teacher/home`, `/teacher/classes/<id>/homework|scores|remarks`,
  `/teacher/submissions`, `/teacher/general-report`).
- Phân hệ chưa chuyển nối qua interface trong `teacher/deps.go` (mặc định "chưa có", `TODO(gộp)`): `Work` (Lương &
  chấm công: check-in, giờ dạy, bảng công), `Syllabus` (unit / bài học), `SupportList` (danh sách hỗ trợ khi mini test thấp),
  `Pronunciation` (bài phát âm của cổng học viên).

## Thay đổi so với Laravel

- **Bài nộp gắn kho file.** File nộp bài và file nghe bài tập nằm trong `core.files` (kho riêng tư, mở qua
  `/api/v1/files/<id>` có kiểm quyền: người nộp, giáo viên chấm của lớp, hoặc cổng học viên). Laravel lưu đường dẫn trên
  disk `public`.
- **Học viên nộp bài qua API xuất ra** `teacher.Service.StudentPortal()` (Submit / Update / Delete / danh sách bài, nhận
  xét, mini test) để cổng học viên gọi. Khác Laravel: không tự điền ghi chú / tên file mặc định; bài đã chấm không sửa / xóa
  được (409).
- **Nhận xét theo buổi học** (`remark_sheets` theo `session_id`), có trạng thái nháp / đã lưu; học viên chỉ thấy bản đã lưu.
  Laravel lưu nhận xét theo lớp + ngày. Dữ liệu nhận xét cũ chưa nhập.
- **Mini test lưu một dòng / học sinh / bài test** (upsert), điểm 4 kỹ năng Nghe / Nói / Đọc / Viết, có điểm tối đa;
  dưới 7/10 hiện ở "Cần chú ý" trên trang chủ.
- **Mọi thao tác ghi nhật ký** (`homework.create|update|delete`, `mini_test_score.create|update`, `remark_sheet.save`,
  `homework_submission.create|update|delete|mark`).
- **Check-in** kiểm ca được phân công và khung giờ trước khi gọi phân hệ chấm công. Chưa có phân hệ thì API trả 409
  `work_pending`, trang chủ báo "Vui lòng check-in trên hệ thống cũ" và không cho chọn ca. Không nhận tham số cũ `class_ids`.

## Bảng đối chiếu

| Laravel | v2 API + màn | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `teacher.home` GET (`home`) | `GET /teacher/home?week=` · màn `/teacher/home` (Check-in & Điểm danh hôm nay) | Đã làm | Ca hôm nay (đếm ngược hạn check-in, cảnh báo 15 phút), ca sắp tới, buổi cần điểm danh bù 30 ngày, lịch tuần, số khách học thử từng ca. |
| Widget trang chủ: lương tạm tính, giờ dạy, bảng công, vi phạm | `widgets` của `GET /teacher/home` | Làm một phần | Chờ Lương & chấm công (`Work.Month`); hiện "Chờ phân hệ…". Khối "Cần chú ý" (mini test dưới 7) đã làm. |
| `teacher.checkin` POST (`checkin`) | `POST /teacher/checkin` `{session_ids}` | Làm một phần | Kiểm phân công, khung giờ, buổi đã check-in. Ghi công chờ Lương & chấm công (`Work.CheckIn`). |
| `teacher.attendance` / `teacher.attendance.store` | Dùng lại `/classes/<id>/attendance?session=` (Lớp học) | Đã làm | Nút "Điểm danh" / "Điểm danh bù" trỏ sang màn của phân hệ Lớp học. |
| `teacher.homework` GET | `GET /teacher/classes/{id}/homework` · màn `/teacher/classes/<id>/homework` | Đã làm | 6 loại bài, hạn nộp, unit, đếm bài đã nộp / sĩ số. |
| `teacher.homework.store` / `.update` / `.destroy` | `POST /teacher/classes/{id}/homework`, `PUT` / `DELETE /teacher/homework/{hid}` | Đã làm | File nghe tải qua `POST /teacher/homework/audio` (kho file, ≤ 50 MB). |
| `teacher.scores` GET | `GET /teacher/classes/{id}/scores?unit=&name=&date=&student=` · màn `/teacher/classes/<id>/scores` | Đã làm | |
| Form nhập điểm cả lớp một lần (bản cũ) | — | Khác | v2 nhập từng học sinh như màn Laravel hiện tại. |
| `teacher.scores.store` | `POST /teacher/classes/{id}/scores` | Làm một phần | Unit lấy từ giáo trình (chờ Giáo trình, hiện nhập tên bài test tự do, mặc định "Mini Test"). Đồng bộ danh sách hỗ trợ qua `SupportList` (chờ Chất lượng lớp). |
| `teacher.remarks` / `teacher.remarks.store` | `GET` / `POST /teacher/classes/{id}/remarks` · màn `/teacher/classes/<id>/remarks` | Đã làm | Chọn buổi 30 ngày gần nhất, lưu nháp / lưu, kèm trạng thái điểm danh và khách học thử. Học sinh của buổi phụ đạo: TODO (chờ Công việc). |
| `teacher.order-test` / `teacher.order-test.submit` | — | Chưa làm | Chờ Giáo trình & Big Test. |
| `teacher.big-test-report` | — | Chưa làm | Chờ Big Test. |
| `teacher.general-report` | `GET /teacher/general-report?period=` · màn `/teacher/general-report` (Báo cáo của tôi → Báo cáo giảng dạy) | Làm một phần | Lớp đang dạy, số buổi điểm danh, số điểm đã nhập. Giờ dạy / bảng công chờ Lương & chấm công. |
| `portal.teacher.submissions` (+ alias) | `GET /teacher/submissions?class=&type=` · màn `/teacher/submissions` | Làm một phần | Tab 6 loại bài đã làm; tab Phát âm chờ cổng học viên (`Pronunciation`). |
| `portal.teacher.submissions.mark` | `POST /teacher/submissions/{id}/mark` | Đã làm | Điểm ≤ 20 ký tự, phản hồi ≤ 1000 ký tự, chỉ giáo viên chấm của lớp (`homework.grade`). |
| `submitHomework` / `updateHomework` / `deleteHomework` (cổng học viên) | `teacher.Service.StudentPortal()` | Khác | API Go cho cổng học viên gọi; xem mục thay đổi. |
| KPI `homework_rate` | `teacher.Service.KpiSources` | Đã làm | Bài nộp ÷ (bài giao có hạn trong kỳ × sĩ số), trung bình theo sĩ số. |
| Bảng KPI tự động: cột tỉ lệ bài tập | `teacher.Service.KpiBoard` → `kpi.WithHomework` | Đã làm | |
| Tổng quan: bài về nhà, điểm TB mini test theo lớp | `teacher.Service.Dashboard` → `dashboard.Deps.Homework` | Đã làm | |
| SidebarMenu workspace `teacher_portal` | `NAV` `/teacher/home`, `/teacher/submissions`, `/teacher/general-report` | Đã làm | Neo `portal.teacher` / `portal.assistant`; chấm bài cần `homework.grade`. |
