# Tìm giáo viên dạy thay (TK-2026-0057)

Màn **Lớp học → Dạy thay** (`/day-thay`, route `cover.board`). Backend: `CoverService`, `CoverRequestController`, bảng `cover_requests` / `cover_registrations`.

## Luồng
1. Giáo viên (`cover.request`) bấm **Tìm GV dạy thay** trên buổi sắp tới của mình (GV chính hoặc GV nước ngoài), có thể nhập lý do. Admin / Học vụ (`cover.approve`) cũng tạo được.
2. Mọi giáo viên (`class.teach`) thấy yêu cầu đang mở và bấm **Đăng ký dạy thay**. Trợ giảng và học viên không có quyền → 403. Tên lớp, danh sách học viên **chỉ hiện** với người duyệt, người đề xuất và GV đã được duyệt.
3. Admin / Học vụ chọn một người đã đăng ký → **Duyệt**: `class_sessions.teacher_id` chuyển sang GV dạy thay.
4. **Mở lại** (chọn người khác) hoặc **Hủy** trả buổi về GV cũ; mở lại thì đăng ký tiếp tục.
5. Buổi đã bắt đầu thì không đăng ký / duyệt được. Chưa ai được duyệt đến giờ học: Học vụ tự điểm danh (quy trình vận hành).

## Phạm vi dữ liệu & vận hành (PR3)
- Không thêm scope mới: duyệt chỉ đổi `class_sessions.teacher_id`, nên quyền xem lớp / học viên / bài giảng đi qua các scope hiện có (`ClassModel::visibleTo`, `Student::visibleTo`, `SyllabusLessonSlide::isVisibleTo`, `SyllabusDocument::visibleTo`) vốn đã tính GV theo buổi. Chưa duyệt (chỉ đăng ký) → không thấy gì; **Mở lại / Hủy** trả `teacher_id` về GV cũ → mất quyền ngay.
- Check-in, điểm danh, nhận xét: cổng GV phân quyền theo buổi (`?session=`), ghi dưới tên GV dạy thay. Lương: `TeachingShareService` không đổi (ca `sub` khi `teacher_id` buổi ≠ GV lớp).
- Giới hạn đã biết: scope hiện có tính cả các buổi khác của cùng lớp (và sau khi buổi kết thúc vẫn xem được lớp đó, như mọi GV dạy thay trước đây); không tính lớp khác. Thu hẹp "chỉ đúng buổi đó" sẽ đổi hành vi dạy thay hiện có → cần chủ dự án quyết định.
- Nhắc Học vụ: `php artisan cover:remind-unfilled` (mỗi 15 phút) — yêu cầu còn `open`, buổi đã đến giờ mà chưa ai được duyệt → thông báo `cover_unfilled` cho người có `attendance_student.record_any` xem được lớp, 1 lần / yêu cầu (`cover_requests.attendance_reminded_at`).

## Thông báo (`AdminNotification`)
- `cover_opened` → mọi GV (trừ GV của buổi), tiêu đề chỉ ghi ngày giờ, không lộ tên lớp.
- `cover_registered` → Admin / Học vụ.
- `cover_approved` → GV được duyệt, GV đề xuất và GV gốc.
- `cover_reopened` / `cover_cancelled` → GV đã được duyệt trước đó.

## Test
`tests/Feature/CoverRequestTest.php` (quy tắc), `tests/Feature/CoverBoardTest.php` (trang + thông báo), `resources/js/lib/cover.test.js` (nút hiển thị theo vai trò).
