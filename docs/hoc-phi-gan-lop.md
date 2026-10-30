# Gắn lớp cho khoản học phí

`student_tuitions.class_id` xác định lớp nhận doanh thu của phiếu thu thuộc khoản học phí, qua
`PayrollFormulaService::classRevenue`. Xếp lớp, liên kết lớp, chốt / gán lớp CRM, Chờ xếp lớp và nhập danh sách
lớp đều dùng `App\Services\Tuition\TuitionClassLinker` để đồng bộ lớp của học phí.

## Khoản học phí chưa dùng hết

Lấy số buổi đã dùng từ `SessionLedger::tuitionUsage([$studentId], $asOf)`: tổng buổi đã dùng của học viên được
phân bổ theo **id khoản tăng dần**, khoản cũ dùng hết mới tới khoản sau. Khoản chưa dùng hết khi số buổi được
phân bổ **nhỏ hơn** `$tuition->sessionSize()`. Số tiền và trạng thái khoản học phí không ảnh hưởng điều kiện này.

## Quy tắc khi xếp lớp và chuyển lớp

- **A — Xếp / liên kết lớp:** mọi khoản chưa dùng hết có `class_id IS NULL` được gắn vào lớp chính
  (`current_class_id`) sau xếp lớp; nếu vẫn chưa có lớp chính thì dùng lớp vừa xếp. Khoản NULL đã dùng hết và
  khoản đã gắn lớp giữ nguyên. Liên kết lớp song song vẫn gắn khoản NULL vào lớp chính.
- **B — Chuyển lớp chính X → Y từ ngày D:** tính buổi tại **D lúc 00:00**, chỉ tính các buổi trước D. Mọi khoản
  chưa dùng hết tại mốc này, có lớp X hoặc NULL, chuyển sang Y. Khoản đã dùng hết trước D giữ lớp cũ để bảo toàn
  lịch sử / doanh thu X; khoản của lớp khác (ví dụ lớp song song) giữ nguyên. Nút Xếp lớp đổi lớp chính cũng áp
  dụng B với D = hôm nay, rồi áp dụng A. Nhập danh sách dùng ngày rời lớp đã được kiểm tra của luồng nhập.
- **C — Chỉ đổi `class_id`:** cập nhật qua query builder, không chạy hook `saving`; giữ nguyên mọi cột còn lại,
  gồm tiền niêm yết / sau ưu đãi / đã đóng / công nợ, giảm giá, phí, `status`, `debt_status`, `session_count`,
  `transfer_memo` và `updated_at`. Không tính lại lương tại đây. Kỳ lương đã khóa giữ nguyên; kỳ nháp nhận
  doanh thu theo lớp mới khi người dùng chạy **Tính lại** như bình thường.

## Bù dữ liệu cũ một lần sau triển khai

```bash
docker/menglish.sh <env> artisan tuitions:link-classes --dry-run
docker/menglish.sh <env> artisan tuitions:link-classes
```

Thay `<env>` bằng `staging` hoặc `production`. Chạy thử trước, rà bảng khoản học phí / mã học viên / từ lớp /
đến lớp / lý do rồi mới chạy thật. Lệnh không được lên lịch định kỳ; có thể chạy lại, dữ liệu đã xử lý không
tạo thay đổi trùng.

Lệnh chỉ xét học viên còn lớp chính và áp dụng:

1. Khoản NULL chưa dùng hết tại thời điểm chạy → lớp chính hiện tại (A).
2. Khoản NULL đã dùng hết → lớp học viên thuộc về tại **ngày tạo khoản**: `enrolled_at <= ngày tạo`,
   `left_at IS NULL` hoặc `left_at > ngày tạo`. Ưu tiên lớp chính hiện tại hoặc lượt mang trạng thái
   `transferred` (lớp chính cũ), sau đó lượt còn hiệu lực (`pending` / `completed`), ngày vào lớp mới nhất
   và id lượt mới nhất khi trùng ngày. Không xác định được thì giữ NULL và báo **Bỏ qua**.
3. Khoản chưa dùng hết tại thời điểm chạy, gắn lớp khác lớp chính, có lượt `transferred` ở lớp đó và không còn
   lượt xếp lớp hiệu lực (`pending` / `completed`) ở đó → lớp chính hiện tại. Khoản đã dùng hết và khoản thuộc
   lớp song song còn hiệu lực giữ nguyên. Học viên không có lớp chính (ví dụ thôi học) giữ nguyên toàn bộ.

Xem trước và chạy thật dùng cùng kế hoạch; `--dry-run` không ghi dữ liệu. Xử lý từng nhóm 100 học viên, giao
dịch riêng cho mỗi nhóm khi chạy thật; chỉ ghi `class_id`. Lệnh console đọc toàn hệ thống theo quy ước hiện
có (`DataScopeGuard` chỉ bật trong yêu cầu web), không cần bỏ phạm vi người xem riêng.
