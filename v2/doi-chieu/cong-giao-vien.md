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
  `/teacher/submissions`, `/teacher/general-report`, `/teacher/big-test-report`).
- Phân hệ khác nối qua interface trong `teacher/deps.go`, adapter ở `cmd/menglish/wiring.go`: `Work` = Chấm công + Lương
  (check-in ca dạy, bảng công tháng, lương tạm tính theo đơn giá, vi phạm), `Syllabus` = Giáo trình (Unit / bài học của
  lớp). `SupportList` (danh sách hỗ trợ khi mini test thấp) còn mặc định "chưa có": danh sách bổ trợ / buổi phụ đạo
  (`SupportListService`) chưa có ở v2. Test tích hợp `cmd/menglish/wiring_test.go`.
- Đã nối với Cổng Phụ huynh & Học sinh (`cmd/menglish/portal_teacher.go`): `Pronunciation` = bản ghi âm của cổng học viên
  (tab Phát âm), `StudentPortal` = nguồn bài tập / bài nộp / nhận xét / mini test của cổng học viên, `SetFileAccess` = học
  viên / phụ huynh mở file bài nộp / file nghe. Test tích hợp `cmd/menglish/portal_teacher_test.go`.

## Thay đổi so với Laravel

- **Bài nộp gắn kho file.** File nộp bài và file nghe bài tập nằm trong `core.files` (kho riêng tư, mở qua
  `/api/v1/files/<id>` có kiểm quyền: người nộp, giáo viên chấm của lớp, hoặc cổng học viên). Laravel lưu đường dẫn trên
  disk `public`.
- **Học viên nộp bài qua API xuất ra** `teacher.Service.StudentPortal()` (Submit / Update / Delete / danh sách bài, nhận
  xét, mini test, bài nghe mẫu) để cổng học viên gọi (`portal.TeacherPortal`). Như Laravel bài đã chấm vẫn sửa / hủy được
  (giữ điểm); ghi chú mặc định "Con đã hoàn thành bài tập…" do cổng học viên điền, không tự đặt tên file khi không đính kèm.
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
| Widget trang chủ: lương tạm tính, giờ dạy, bảng công, vi phạm | `widgets` của `GET /teacher/home` ← `teacherWork.Month` | Đã làm | Mọi ca trong tháng trừ ca bị từ chối (như `where('status', '!=', 'rejected')`), lương tạm tính = Σ giờ × đơn giá giờ hiệu lực ngày dạy, số biên bản vi phạm của tháng. Khối "Cần chú ý" (mini test dưới 7). |
| `teacher.checkin` POST (`checkin`) | `POST /teacher/checkin` `{session_ids}` → `attendance.RecordCheckin` | Đã làm | Kiểm phân công, khung giờ, buổi đã check-in; ghi ca dạy chờ duyệt ở Chấm công (bỏ qua buổi đã chấm tay, ngày thuộc kỳ lương đã khóa). |
| `teacher.attendance` / `teacher.attendance.store` | Dùng lại `/classes/<id>/attendance?session=` (Lớp học) | Đã làm | Nút "Điểm danh" / "Điểm danh bù" trỏ sang màn của phân hệ Lớp học. |
| `teacher.homework` GET | `GET /teacher/classes/{id}/homework` · màn `/teacher/classes/<id>/homework` | Đã làm | 6 loại bài, hạn nộp, unit, đếm bài đã nộp / sĩ số. |
| `teacher.homework.store` / `.update` / `.destroy` | `POST /teacher/classes/{id}/homework`, `PUT` / `DELETE /teacher/homework/{hid}` | Đã làm | File nghe tải qua `POST /teacher/homework/audio` (kho file, ≤ 50 MB). |
| `teacher.scores` GET | `GET /teacher/classes/{id}/scores?unit=&name=&date=&student=` · màn `/teacher/classes/<id>/scores` | Đã làm | |
| Form nhập điểm cả lớp một lần (bản cũ) | — | Khác | v2 nhập từng học sinh như màn Laravel hiện tại. |
| `teacher.scores.store` | `POST /teacher/classes/{id}/scores` | Làm một phần | Unit chọn từ giáo trình của lớp (`Syllabus.Units`, tên bài "Unit N: …"; Unit không thuộc giáo trình → "Unit không thuộc giáo trình của lớp."); lớp chưa có giáo trình thì nhập tên tự do. Chưa lưu khóa `syllabus_unit_id` (cần migration). Đồng bộ danh sách hỗ trợ (`SupportList`) chưa có: danh sách bổ trợ chưa có ở v2. |
| `teacher.remarks` / `teacher.remarks.store` | `GET` / `POST /teacher/classes/{id}/remarks` · màn `/teacher/classes/<id>/remarks` | Đã làm | Chọn buổi 30 ngày gần nhất, lưu nháp / lưu, kèm trạng thái điểm danh và khách học thử. Học sinh được xếp buổi phụ đạo: chưa có (buổi phụ đạo chưa có ở v2). |
| `teacher.order-test` / `teacher.order-test.submit` | `GET` / `POST /teacher/order-test/{class}` · màn `/teacher/order-test/<lớp>` (phân hệ Giáo trình) | Đã làm | Xem [giao-trinh.md](giao-trinh.md). |
| `teacher.big-test-report` | `GET /teacher/big-test-report?page=` · màn `/teacher/big-test-report` (phân hệ Giáo trình) | Đã làm | Kết quả Big Test các lớp là GV chính hoặc GVNN, mới nhất trước, 20 dòng / trang; điểm 4 kỹ năng + tổng ("—" khi trống), "Đã duyệt" khi `status = approved`. Laravel không có mục menu (mở bằng URL), v2 cũng để ẩn. |
| `teacher.general-report` | `GET /teacher/general-report?period=` · màn `/teacher/general-report` (Báo cáo của tôi → Báo cáo giảng dạy) | Đã làm | Lớp đang dạy, số buổi điểm danh, số điểm đã nhập, giờ dạy / phiên chấm công và chi tiết ca của tháng (`Work.Month`). |
| `portal.teacher.submissions` (+ alias) | `GET /teacher/submissions?class_id=&type=` · màn `/teacher/submissions` (`/portal/teacher-submissions` cũ tự chuyển sang) | Đã làm | Màn duy nhất cho chấm bài (bản trùng của cổng học viên đã bỏ khi gộp). 6 tab bài nộp + tab Phát âm (bản ghi âm của cổng học viên). Giao diện như `Portal/TeacherSubmissions.vue`: chọn lớp dạy, tab có biểu tượng, "Danh sách đã nộp bài (n)", chấm ngay trên dòng (bài nộp: "Đánh dấu đã xem", điểm tùy chọn; phát âm: "Chấm", điểm /100 bắt buộc), bài đã chấm hiện "Đã chấm: …" / "Đã xem", nút "Xem giao diện Học sinh nộp bài" khi có `student.view`. Nhãn hạng mục và giờ nộp (dd/mm/YYYY HH:MM) như dữ liệu Laravel. |
| `portal.teacher.submissions.mark` | `POST /teacher/submissions/{id}/mark` | Đã làm | Điểm ≤ 20 ký tự, phản hồi ≤ 1000 ký tự, chỉ giáo viên chấm của lớp (`homework.grade`, ngoài lớp → 404). Bản ghi âm: điểm bắt buộc ("Vui lòng nhập điểm bài phát âm."), học viên nhận thông báo `pronunciation_reviewed` ở hộp thư cổng (thêm so với Laravel). |
| `submitHomework` / `updateHomework` / `deleteHomework` (cổng học viên) | `teacher.Service.StudentPortal()` ← `POST/DELETE /portal/homework*` | Đã làm | Cổng học viên kiểm quyền ghi (`authorizeStudent`) rồi gọi qua adapter; bài nộp + file + nhật ký trong một transaction; xem mục thay đổi. |
| KPI `homework_rate` | `teacher.Service.KpiSources` | Đã làm | Bài nộp ÷ (bài giao có hạn trong kỳ × sĩ số), trung bình theo sĩ số. |
| Bảng KPI tự động: cột tỉ lệ bài tập | `teacher.Service.KpiBoard` → `kpi.WithHomework` | Đã làm | |
| Tổng quan: bài về nhà, điểm TB mini test theo lớp | `teacher.Service.Dashboard` → `dashboard.Deps.Homework` | Đã làm | |
| SidebarMenu workspace `teacher_portal` | `NAV` `/teacher/home`, `/teacher/submissions`, `/teacher/general-report` | Đã làm | Neo `portal.teacher` / `portal.assistant`; chấm bài cần `homework.grade`. |
