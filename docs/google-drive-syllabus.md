# Quyền xem file Google Drive của syllabus

Thiết kế: `docs/kho-syllabus.md` §7.4. Khi Học thuật **giao chặng** cho lớp, hệ thống tự mở quyền **chỉ xem** các file /
thư mục Google Drive có link trong syllabus của chặng (Link PPT, PPT GVNN, Quizizz nếu là Drive, đề Mini test / Kiểm tra từ,
Link mục lục chặng) cho **GV chính + GVNN** của lớp, bằng **email đăng nhập hệ thống**. Khi **chặng đóng** (Big Test xong,
đóng tay, chuyển chặng), GV bị đổi khỏi lớp hoặc link bị xoá khỏi syllabus, hệ thống tự thu hồi quyền — trừ khi người đó
vẫn cần file cho một chặng đang mở khác. Người đã có quyền trên file từ trước (Học thuật tự chia sẻ) không bao giờ bị thu hồi.

File được bật "Người xem không tải xuống, in, sao chép" (`copyRequiresWriterPermission`).

## Cài đặt (1 lần, do quản trị hệ thống làm)

1. **Google Cloud Console** → tạo (hoặc chọn) project → *APIs & Services* → *Enable APIs* → bật **Google Drive API**.
2. *IAM & Admin* → *Service Accounts* → **Create service account** (vd. `menglish-syllabus`), không cần cấp role project.
3. Mở service account → *Keys* → *Add key* → *Create new key* → **JSON** → tải file khóa về.
4. **Google Drive**: Học thuật để toàn bộ tài liệu syllabus trong 1 thư mục (hoặc Shared Drive) và **chia sẻ quyền
   Chỉnh sửa (Editor)** cho email của service account (dạng `menglish-syllabus@<project>.iam.gserviceaccount.com`).
   Với Shared Drive: thêm service account làm thành viên **Content manager**.
5. **Server**: chép file khóa JSON lên server (ngoài thư mục code, quyền đọc chỉ cho user chạy app), mount vào container
   app + scheduler, rồi thêm vào `.env` của môi trường:

   ```
   GOOGLE_DRIVE_SA_KEY_PATH=/run/secrets/google-drive-sa.json
   ```

   Khóa là bí mật: không commit, không gửi qua chat. Đổi `.env` xong thì deploy lại / restart app + scheduler.

## Vận hành

- Lệnh `php artisan syllabus:sync-drive-access` chạy **mỗi phút** (scheduler) — mở quyền thiếu, thu hồi quyền thừa.
- Trang lớp → tab **Syllabus** → bảng **Quyền xem Google Drive**: từng GV × file, trạng thái (Đang chờ mở / Đã mở quyền
  xem / Lỗi / Đã đóng) và lý do lỗi. Nút **Đồng bộ lại** (Học thuật) thử lại ngay, kể cả quyền đã lỗi đủ 5 lần.
- Lỗi thường gặp:
  - *"The user does not have sufficient permissions"* / *không đủ quyền*: file chưa chia sẻ quyền Chỉnh sửa cho service
    account (bước 4).
  - Email GV không phải tài khoản Google (Gmail / Google Workspace): đổi email đăng nhập của GV sang tài khoản Google.
- Lỗi lần đầu của mỗi quyền → Học thuật nhận 1 thông báo.
