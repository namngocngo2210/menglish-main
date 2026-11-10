# Tìm giáo viên dạy thay (TK-2026-0057)

Màn **Lớp học → Dạy thay** (`/day-thay`, route `cover.board`). Backend: `CoverService`, `CoverRequestController`, bảng `cover_requests` / `cover_registrations`.

## Luồng
1. Giáo viên (`cover.request`) bấm **Tìm GV dạy thay** trên buổi sắp tới của mình (GV chính hoặc GV nước ngoài), có thể nhập lý do. Admin / Học vụ (`cover.approve`) cũng tạo được.
2. Mọi giáo viên (`class.teach`) thấy yêu cầu đang mở và bấm **Đăng ký dạy thay**. Trợ giảng và học viên không có quyền → 403. Tên lớp, danh sách học viên **chỉ hiện** với người duyệt, người đề xuất và GV đã được duyệt.
3. Admin / Học vụ chọn một người đã đăng ký → **Duyệt**: `class_sessions.teacher_id` chuyển sang GV dạy thay.
4. **Mở lại** (chọn người khác) hoặc **Hủy** trả buổi về GV cũ; mở lại thì đăng ký tiếp tục.
5. Buổi đã bắt đầu thì không đăng ký / duyệt được. Chưa ai được duyệt đến giờ học: Học vụ tự điểm danh (quy trình vận hành).

## Thông báo (`AdminNotification`)
- `cover_opened` → mọi GV (trừ GV của buổi), tiêu đề chỉ ghi ngày giờ, không lộ tên lớp.
- `cover_registered` → Admin / Học vụ.
- `cover_approved` → GV được duyệt, GV đề xuất và GV gốc.
- `cover_reopened` / `cover_cancelled` → GV đã được duyệt trước đó.

## Test
`tests/Feature/CoverRequestTest.php` (quy tắc), `tests/Feature/CoverBoardTest.php` (trang + thông báo), `resources/js/lib/cover.test.js` (nút hiển thị theo vai trò).
