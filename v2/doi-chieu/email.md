# Đối chiếu: Gửi email

Phân hệ v2: `v2/api/internal/notify/mail` (hàng đợi, bộ gửi, mẫu thư), `v2/api/internal/notify/mail/mailapi` (màn quản
trị), `v2/web/src/features/mail` (React). Migration `00150_mail_outbox` (bảng `core.mail_outbox`).

Ký hiệu: **Đã làm** = đủ hành vi như Laravel; **Khác** = đã làm, khác có chủ đích (ghi chú); **Làm một phần** = phần
còn thiếu ghi ở cột Ghi chú; **Chưa làm** = chưa có ở v2.

## Cách gửi (khác Laravel)

Laravel gửi đồng bộ ngay trong request (`Mail::send` / `Mail::html` / `Mail::raw`), lỗi thì ghi log rồi bỏ qua. v2 xếp thư
vào `core.mail_outbox` **trong cùng transaction nghiệp vụ** (`mail.Enqueue(ctx, tx, mail.Message{…})`), nên thao tác bị
hủy thì không có thư; vòng gửi nền (`mail.Service.RunLoop`, 5 giây một lần, chạy từ `cmd/menglish`) lấy thư đến hạn bằng
`FOR UPDATE SKIP LOCKED`, dựng mẫu lúc gửi rồi gửi qua SMTP đang lưu trong `core.settings`.

- Lỗi tạm thời: thử lại sau 1, 2, 4… phút (tối đa 1 giờ), mặc định 8 lần; thư cho phụ huynh 3 lần.
- Máy chủ từ chối hẳn (mã 550–554): dừng ngay, trạng thái "Lỗi".
- Thư có `Fallback`: lỗi hẳn thì gửi thông báo trong ứng dụng thay thế (vd. nhắc người phụ trách gửi Zalo).
- `MAIL_BLOCK_OUTGOING`: chặn mọi thư ra ngoài, kể cả khi SMTP lưu trong cơ sở dữ liệu; thư chuyển "Bị chặn".
- Thư nhạy cảm (link đặt lại mật khẩu): không hiện nội dung ở màn quản trị, gửi xong / bị chặn thì xóa dữ liệu thư.
- Thư đã gửi giữ 30 ngày rồi tự xóa.

## Hòm thư và cấu hình

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| `SystemSetting::getSmtpConfig` / `applyDynamicMailConfig` (đọc `mail_*` trong `system_settings`) | `mail.LoadSMTP(ctx, db, box)` | Khác | Đọc các khóa `mail_*` trong `core.settings`; mật khẩu giải mã bằng `secretbox` (`APP_ENCRYPTION_KEY`), không bao giờ trả về trình duyệt. Khi chưa lưu thì dùng mặc định `smtp.gmail.com:587` TLS, `noreply@menglish.edu.vn`, "MEnglish Support"; **không** đọc biến môi trường `MAIL_*` như Laravel. |
| `config/mail.php` `block_outgoing` (`MAIL_BLOCK_OUTGOING`, mặc định bật ở `staging`) | `config.MailBlockOutgoing` (`MAIL_BLOCK_OUTGOING`) | Khác | Mặc định bật ở mọi môi trường trừ `MENGLISH_ENV=production` (dev, test, beta, staging đều chặn). |
| `system-config.ticket-emails.test` POST — `sendTestTicketEmail` | `POST /system-config/ticket-emails/test` (`support_ticket.update`) · khối "Gửi thử nghiệm (Test Email)" ở màn `/system-config/ticket-emails` | Đã làm | Gửi ngay (không qua hàng đợi) bằng SMTP đang lưu, cùng tiêu đề / nội dung thư, cùng thông báo kết quả, gợi ý lỗi SMTP và lưu ý email người gửi. Ô email để trống thì gửi tới mọi email nhận Ticket. Môi trường chặn gửi thì báo lỗi rõ ràng. |
| Lỗi gửi chỉ ghi log | Màn **Hàng đợi email** `/system-config/mail-outbox` (`mail_config.manage`): `GET /mail-outbox`, `GET /mail-outbox/{id}`, `POST /mail-outbox/{id}/retry`, `POST /mail-outbox/{id}/cancel` | Mới ở v2 | Thống kê theo trạng thái, lọc trạng thái / loại thư, tìm theo người nhận / tiêu đề, xem nội dung (bản chữ), gửi lại (đếm lại lượt thử), hủy. Gửi lại / hủy ghi nhật ký thao tác (`mail.retry`, `mail.cancel`). |

## Mẫu thư

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| `App\Mail\ParentNotice` + `emails/parent-notice.blade.php` | Mẫu `notice` (`templates/notice.html` + bản chữ), `mail.ParentNotice(...)` | Đã làm | Cùng bố cục, màu, chữ; đường dẫn bắt đầu bằng `/` được nối với `PUBLIC_URL`. |
| `emails/ticket-notification.blade.php` | Mẫu `ticket` (`templates/ticket.html`), `mail.Ticket`, `mail.OperationalAlert` | Đã làm | Màu trạng thái / mức ưu tiên như Laravel; nội dung có số tiền thì in chấm hàng nghìn. |
| `Mail::raw` (thư chữ thuần) | Mẫu `text` (`mail.Text`) | Đã làm | |
| Thư đặt lại mật khẩu mặc định của Laravel (`ResetPassword` notification, chữ trong `lang/vi.json`) | Mẫu `password_reset` | Đã làm | Tiếng Việt theo `lang/vi.json`. |

## Thư nghiệp vụ

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| `ParentMessenger::send` / `recipient` / `dayLabel` | `mail.ParentNotice`, `mail.ParentRecipient`, `mail.DayLabel` | Khác | Bỏ email học viên tự sinh (`@student.menglish.edu.vn`) như Laravel. Laravel biết ngay gửi được hay không; v2 xếp thư nên trả "đã gửi email" ngay, lỗi hẳn thì gửi thông báo thay thế cho người phụ trách (xem dưới). |
| `AppointmentConfirmation::forTrial` + `deliver` (khi đặt lịch học thử) | `placement/confirm.go` (`POST /trial-bookings`, trả `confirmation`) | Khác | Cùng nội dung xác nhận. Khách có email: xếp thư (3 lần thử), nhật ký khách "Đã gửi email xác nhận lịch hẹn tới …"; thư lỗi hẳn thì người phụ trách khách (không có thì người xếp lịch) nhận thông báo "Gửi xác nhận lịch hẹn qua Zalo: …" kèm nội dung để sao chép. Khách chưa có email: nhắc gửi Zalo ngay, như Laravel. Popup sao chép nội dung trên hồ sơ khách chờ nút đặt học thử ở màn CRM. |
| `AppointmentConfirmation::forTest` (`crm.customers.schedule-test`) | — | Chưa làm | Hẹn test trên hồ sơ khách chưa chuyển (xem `test-dau-vao.md`). Gọi `mail.ParentNotice` khi chuyển. |
| `NotificationService::notifyHomeworkOrTestSubmission` (email vận hành khi nộp bài) | `placement/notify.go` `notifySubmission` → `mail.OperationalAlert(EventHomework, …)` | Đã làm | Gửi tới danh sách email nhận Ticket khi bật sự kiện "homework"; cùng tiêu đề, nội dung, mã `TEST-<số>`, nút "Xem Báo cáo Điểm số". |
| `sendOperationalAlertEmail` (danh sách nhận, bật / tắt từng sự kiện) | `mail.OperationalAlert`, `mail.TicketRecipients`, `mail.EventEnabled` | Đã làm | Dùng cho mọi email vận hành. |
| `notifyTicketCreated` / `notifyTicketMessage` / `notifyTicketStatusChanged` (email ticket) / `notifyTicketAssigned` (`Mail::raw`) | — | Chưa làm | v2 chưa có phân hệ Ticket. Mẫu `ticket`, `TicketRecipients`, `EventEnabled` đã sẵn. |
| `scanAndSyncStaleLeads` (email khách tồn đọng) | — | Chưa làm | Lệnh `crm:scan-stale-leads` chưa chuyển; gọi `mail.OperationalAlert(EventStaleLead, …)`. |
| `notifyTransactionReceipt` (email phiếu thu) | — | Chưa làm | Thuộc phân hệ học phí: `mail.OperationalAlert(mail.EventTransaction, …)`. |
| `notifyDebtReminderByMilestone` (email nhắc nợ) | — | Chưa làm | Thuộc phân hệ học phí: `mail.OperationalAlert(mail.EventOverdueDebt, …)`. |
| `Tuition\PaymentConfirmation::notify` (xác nhận đã nhận học phí cho phụ huynh) | — | Chưa làm | Thuộc phân hệ học phí: `mail.Enqueue(ctx, tx, mail.ParentNotice(…))` với `Fallback` như xác nhận lịch hẹn. |
| `SepayWebhookController` (email báo chuyển khoản) | — | Chưa làm | Thuộc phân hệ học phí. |
| Zalo ZNS | — | Chưa làm | Ngoài phạm vi. v2 hiện nhắc nhân viên gửi Zalo thủ công qua thông báo trong ứng dụng. |

## Quên mật khẩu

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| `password.request` GET `/forgot-password` (`Auth/ForgotPassword.vue`) | Màn `/forgot-password`; link "Quên mật khẩu?" ở trang đăng nhập | Đã làm | Cùng chữ. |
| `password.email` POST — `PasswordResetLinkController::store` | `POST /auth/forgot-password` | Đã làm | Như Laravel: cùng một thông báo dù email có tài khoản hay không (hay vừa gửi), 60 giây mới gửi lại, 5 yêu cầu / phút / IP (`throttle:5,1`). Thêm: tài khoản bị khóa không nhận thư. |
| `password.reset` GET `/reset-password/{token}` (`Auth/ResetPassword.vue`) | Màn `/reset-password?token=…&email=…` | Đã làm | |
| `password.store` POST — `NewPasswordController::store` | `POST /auth/reset-password` | Khác | Link dùng một lần, hết hạn sau 30 phút (Laravel 60) theo tài liệu thiết kế; token lưu dạng băm trong Redis; link mới thay link cũ. Kiểm mật khẩu theo chính sách v2. Đặt lại xong thì mở khóa tạm và đăng xuất mọi thiết bị. |

## Chưa làm / cần khi gộp

- **Học phí** (`internal/tuition`): gọi `mail.Enqueue(ctx, tx, …)` trong transaction lập phiếu / nhắc nợ. Thư cho phụ huynh
  dùng `mail.ParentNotice` + `MaxAttempts: 3` + `Fallback`; email vận hành dùng `mail.OperationalAlert` với
  `EventTransaction` / `EventOverdueDebt`. Tài liệu ở đầu `internal/notify/mail/mail.go`.
- **Ticket**: khi có phân hệ, dùng mẫu `mail.TemplateTicket` + `mail.Ticket`, người nhận `mail.TicketRecipients`, kiểm sự
  kiện bằng `mail.EventEnabled(EventTicketCreated / EventTicketComment / EventTicketStatus)`.
- **Import dữ liệu cũ**: `import-legacy` chưa chép cấu hình SMTP (`system_settings.mail_*`); nhập lại trên màn Email
  nhận Ticket (mật khẩu phải nhập lại vì cách mã hóa khác).
- `*/models.go` của mọi gói sqlc được sinh lại vì có bảng mới. Khi gộp nhiều nhánh thì chạy lại `sqlc generate`.
