# Tìm giáo viên dạy thay (TK-2026-0057)

Màn **Lớp học → Dạy thay** (`/day-thay`, route `cover.board`). Backend: `CoverService`, `CoverRequestController`, bảng `cover_requests` / `cover_registrations`.

## Luồng
1. Giáo viên (`cover.request`) bấm **Tìm GV dạy thay** trên buổi sắp tới của mình (GV chính hoặc GV nước ngoài), có thể nhập lý do. Admin / Học vụ (`cover.approve`) cũng tạo được.
2. Mọi giáo viên (`class.teach`) thấy yêu cầu đang mở và bấm **Đăng ký dạy thay**. Trợ giảng và học viên không có quyền → 403. Tên lớp, danh sách học viên **chỉ hiện** với người duyệt, người đề xuất và GV đã được duyệt.
3. Admin / Học vụ chọn một người đã đăng ký → **Duyệt**: `class_sessions.teacher_id` chuyển sang GV dạy thay.
4. **Mở lại** (chọn người khác) hoặc **Hủy** trả buổi về GV cũ; mở lại thì đăng ký tiếp tục.
5. Buổi đã bắt đầu thì không đăng ký / duyệt được. Chưa ai được duyệt đến giờ học: Học vụ tự điểm danh (quy trình vận hành).

## Phạm vi dữ liệu & vận hành (PR3)
- Quyền xem theo **đúng buổi được duyệt** (chủ dự án 10/11): duyệt vẫn chỉ đổi `class_sessions.teacher_id`, nhưng buổi dạy thay đã duyệt (`ClassSession::approvedCoverFor`: `teacher_id` = người đó + `cover_requests.status = approved` cho đúng người) **không còn mở cả lớp**. `ClassModel::visibleTo` mặc định bỏ buổi dạy thay (`forStaffExceptCover`) nên mọi màn toàn lớp (báo cáo, dashboard, KPI, việc, tìm kiếm…) đóng với GV dạy thay; chỉ các màn theo buổi gọi `visibleTo($user, true, true)` rồi tự thu hẹp: trang lớp (`classes.show`: chỉ các buổi được duyệt, không có tab Học thuật), báo cáo buổi (`classes.session-report`: buổi khác → 403), học viên của lớp có buổi đó (`Student::visibleTo`), bài giảng \"hôm nay\" (`TeacherLessonController`, không xem bài khác của chặng), slide / tài liệu gắn đúng bài của buổi (`SessionLessonService::coverLessonIdsFor`, cần chặng đang mở). Chưa duyệt → không thấy gì; **Mở lại / Hủy** trả `teacher_id` về GV cũ → mất quyền ngay.
- Check-in, điểm danh, nhận xét: cổng GV phân quyền theo buổi (`?session=`), ghi dưới tên GV dạy thay. Lương: `TeachingShareService` không đổi (ca `sub` khi `teacher_id` buổi ≠ GV lớp).
- Không đổi với GV chính / trợ giảng / GVNN của lớp, Admin, Học vụ và quyền theo buổi khác (GVNN / trợ giảng gán theo buổi). Sau khi buổi kết thúc không thêm / bớt quyền so với trước: vẫn chỉ đúng buổi đó (không có mốc hết hạn riêng theo ngày).
- Nhắc Học vụ: `php artisan cover:remind-unfilled` (mỗi 15 phút) — yêu cầu còn `open`, buổi đã đến giờ mà chưa ai được duyệt → thông báo `cover_unfilled` cho người có `attendance_student.record_any` xem được lớp, 1 lần / yêu cầu (`cover_requests.attendance_reminded_at`).

## Thông báo (`AdminNotification`)
- `cover_opened` → mọi GV (trừ GV của buổi), tiêu đề chỉ ghi ngày giờ, không lộ tên lớp.
- `cover_registered` → Admin / Học vụ.
- `cover_approved` → GV được duyệt, GV đề xuất và GV gốc.
- `cover_reopened` / `cover_cancelled` → GV đã được duyệt trước đó.

## Test
`tests/Feature/CoverRequestTest.php` (quy tắc), `tests/Feature/CoverBoardTest.php` (trang + thông báo), `tests/Feature/CoverPerSessionScopeTest.php` (ma trận quyền theo buổi), `tests/Feature/CoverScopeTest.php` (duyệt / mở lại / hủy), `resources/js/lib/cover.test.js` (nút hiển thị theo vai trò).

## TK-2026-0111 — điểm vào nút & nghỉ phép đã duyệt
- Nút **Tìm GV dạy thay** trên lịch dạy tuần của GV (`/teacher/home`, buổi sắp tới của mình) dẫn tới `/day-thay?propose=<buổi>` và mở sẵn hộp thoại. Admin / Học vụ (`cover.approve`) thấy mọi buổi sắp tới còn lên lịch trong phạm vi của mình ở màn Dạy thay và tạo yêu cầu cho bất kỳ buổi nào.
- **Giả định (cần chủ dự án xác nhận):** khi đơn **nghỉ phép** (`leave`) được duyệt, hệ thống tự mở yêu cầu dạy thay (`CoverService::openForLeave`) cho mọi buổi sắp tới còn lên lịch của GV (chính / GVNN) trong khoảng nghỉ; buổi đã có yêu cầu hoạt động hoặc đã bắt đầu thì bỏ qua. Người đề xuất ghi là người duyệt đơn. Duyệt / mở lại / hủy vẫn do Admin / Học vụ.
- AC-S07/S09 (GV dạy thay thấy thông tin): chọn cách đọc an toàn nhất — chỉ sau khi được duyệt, chỉ đúng buổi đó (xem Phạm vi dữ liệu ở trên); trước duyệt chỉ thấy ngày giờ.
- Chưa làm: nút trên trang điểm danh / màn đơn nghỉ (chỉ lịch tuần + màn Dạy thay); trạng thái "Sắp tới" của buổi không đổi theo yêu cầu dạy thay.
