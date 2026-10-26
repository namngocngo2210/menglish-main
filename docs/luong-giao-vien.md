# Luồng màn hình Giáo viên: lớp → chặng → buổi → báo cáo

## Bối cảnh (rà soát 26/10/2026 trên staging)

- **GV không có lối vào lớp của mình.** `/classes` và Trang lớp chạy đúng phạm vi (GV chỉ thấy lớp mình dạy), nhưng nhóm sidebar
  "Lớp học" neo theo `class.update` (GV không có) và Cổng GV không có mục lớp nào → GV chỉ mở được lớp có ca hôm nay (nút trên
  màn Check-in). Report / thống kê của lớp không có ca hôm nay không vào được.
- **"Xem bài giảng" (syllabus theo buổi + tài liệu + nút XÁC NHẬN chặng) bị gỡ khỏi menu** (e4bfc4f3, 23/10) → GV chỉ xác nhận
  qua thông báo. "Bài giảng hôm nay" cũng không có trong menu.
- **Chưa XÁC NHẬN chặng vẫn bấm "Đã dạy"** (staging: 2 lớp FAM0 dạy 4/6 buổi, chặng chưa xác nhận).
- Màn "Chặng đang dạy" chỉ có Order đề; danh sách lớp ở "Báo cáo giảng dạy" không bấm được.
- Giao việc Học thuật (bước 8–10 của `docs/Syllabus_Quy_trinh_xu_ly.xlsx`) chạy đúng: không đổi.

## Quyết định (chủ dự án 26/10/2026)

1. Cổng GV xếp theo thứ tự luồng: **Lớp của tôi** (`/classes?mine=1`) → **Bài giảng hôm nay** → **Syllabus & tài liệu**
   (XÁC NHẬN chặng) → Check-in & Điểm danh hôm nay → Chấm bài nộp → … `mine=1` lọc lớp mình dạy (GV chính / TG / GVNN / dạy
   thay theo buổi) — người quản lý kiêm dạy cũng xem được lớp của riêng mình. Trên Trang lớp / Danh sách lớp, GV thấy tab của
   Cổng GV (workspace đầu tiên mà người xem có tab).
2. Thẻ ở "Chặng đang dạy": nút **XÁC NHẬN** (chặng chưa xác nhận, người được xác nhận), **Bài giảng**, **Trang lớp**, kèm hạn
   xác nhận. Danh sách lớp ở "Báo cáo giảng dạy" mở Trang lớp.
3. **Bắt buộc XÁC NHẬN trước khi "Đã dạy"** (chặn ở server; màn Bài giảng hiện cảnh báo + nút XÁC NHẬN).
4. **Hạn xác nhận = 1 giờ trước buổi học đầu tiên của chặng** (buổi thường / học bù chưa hủy, từ ngày bắt đầu chặng). Giao chặng
   sát giờ (đã qua mốc) → hạn tính theo buổi kế tiếp. SLA mới `gv.syllabus_confirm` (nhóm Giáo viên, 1 giờ, Admin đổi / tắt được
   ở Cài đặt SLA), chạy trong `sla:enforce`: quá hạn chưa xác nhận → biên bản cho GV được giao chặng (bậc tái phạm như nhận xét
   trễ: nhắc nhở → 30.000đ → 60.000đ), báo GV + Admin + Học thuật. Không phạt hồi tố (chỉ hạn sau thời điểm bật SLA GV).
5. Giới hạn: đổi GV khi mốc SLA của chặng đã chốt (đã xác nhận đúng hạn / đã lập biên bản) thì GV mới không bị tính SLA lại cho
   chặng đó (vẫn bị chặn "Đã dạy" tới khi xác nhận).

## Kiểm thử

- Menu GV theo thứ tự; `?mine=1` chỉ trả lớp mình dạy; workspace Cổng GV trên `/classes`.
- "Chặng đang dạy" trả nút XÁC NHẬN / Bài giảng / Trang lớp; "Báo cáo giảng dạy" có link lớp.
- "Đã dạy" bị chặn khi chưa xác nhận.
- SLA: xác nhận đúng hạn → không biên bản; quá hạn → 1 biên bản (chạy lại không nhân đôi); giao sát giờ → hạn buổi kế tiếp;
  hạn trước thời điểm bật → không phạt.

## Sprint

| Sprint | Nội dung | Trạng thái |
|---|---|---|
| 1 | Test đỏ | Xong |
| 2 | Menu Cổng GV + `mine=1` + workspace + liên kết các màn | Xong |
| 3 | Chặn "Đã dạy" + SLA `gv.syllabus_confirm` | Xong |
| 4 | Test xanh, guard-rail (Navigation*, Ziggy, AllRoutesSmoke), cập nhật `docs/kho-syllabus.md` | Xong |
| 5 | Deploy staging, kiểm tra bằng tài khoản GV (`gv.cohuu2`) | Chưa xong |
