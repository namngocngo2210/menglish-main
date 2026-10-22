# Đối chiếu: Ticket hỗ trợ

Nguồn Laravel: `app/Http/Controllers/SupportTicketController.php`, `app/Models/SupportTicket.php`,
`app/Models/TicketMessage.php`, `app/Services/NotificationService.php` (`notifyTicket*`), `routes/web.php` (nhóm
`tickets.*`), `resources/js/Pages/SupportTickets/*`, `resources/views/emails/ticket-notification.blade.php`. Đối chiếu tại
commit `5caf8ba9`.

v2: migration `00190_ops_tickets.sql` (bảng `ops.tickets`, `ops.ticket_messages`), Go `v2/api/internal/tickets`, web
`v2/web/src/features/tickets`. Mọi route dưới `/api/v1/tickets`, quyền như middleware Laravel.

Trạng thái: **Đã làm** · **Khác** (đã làm, khác có chủ đích, xem ghi chú) · **Làm một phần** · **Chưa làm**.

## Màn và route

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `tickets.index` GET `/tickets` (`support_ticket.view`) | `GET /tickets?status=&category=&q=&page=` · màn `/tickets` (Người dùng → Ticket hỗ trợ) | Đã làm | Phạm vi `support_ticket.scope_*` (`SupportTicket::scopeVisibleTo`, rà soát 19/10/2026 `b73d3ae6`): Của tôi = ticket mình tạo / được giao, Chi nhánh = thêm ticket do nhân sự chi nhánh mình tạo, Toàn hệ thống = mọi ticket; áp cho danh sách, thẻ số liệu và mọi thao tác theo id (xem, trả lời, đổi trạng thái, phân công, mở lại, tệp đính kèm, nút mở ở Nhật ký sự vụ) — ngoài phạm vi → 403. 4 thẻ thống kê (tổng, mở, đang xử lý, đã giải quyết) cùng phạm vi; tìm theo mã / tiêu đề / mô tả; 15 dòng một trang. Menu neo `portal.staff` như `SidebarMenu`. |
| `tickets.create` GET `/tickets/create` (`support_ticket.create`) | `GET /tickets/form` · màn `/tickets/new` | Khác | Laravel mở modal trên danh sách; v2 là trang riêng (nút "Tạo ticket" trên danh sách). Danh sách người xử lý chỉ trả khi có `support_ticket.assign`. |
| `tickets.store` POST (`support_ticket.create`) | `POST /tickets` (JSON hoặc multipart: trường `payload` + file) | Khác | Kiểm dữ liệu và câu báo lỗi như `validate()` (tiêu đề ≤ 255, danh mục, mức ưu tiên, mô tả bắt buộc, người xử lý phải có `support_ticket.update` — "Chỉ phân công ticket cho nhân sự có quyền xử lý ticket."). Mã `submission_token` / cùng tiêu đề + mô tả trong 10 phút → trả lại ticket đã tạo (200, `duplicate`) như `findSubmittedTicket`. Mã ticket `TK-<năm>-<số 4 chữ số>` theo bộ đếm năm như Laravel. Mô tả tối đa 20.000 ký tự (Laravel không giới hạn). Tối đa 4 file mỗi lần gửi (giới hạn chung của form multipart v2). |
| `tickets.show` GET `/tickets/{id}` (`support_ticket.view`) | `GET /tickets/{id hoặc mã}` · màn `/tickets/<id hoặc mã>` | Khác | Như `authorizeTicketParticipant`: người tạo, người được giao hoặc người quản lý. Ghi chú nội bộ chỉ hiện với người xử lý (`userCanSeeInternalNotes`). Laravel mở modal; v2 là trang riêng. |
| `tickets.attachment` GET `/tickets/{id}/attachment?path=` | Kho file v2: `GET /files/{id}` (reader `ticket_attachment`) | Khác | File lưu ở kho file (`core.files`, subject = tin nhắn); tải về chỉ khi xem được ticket và (với ghi chú nội bộ) xem được ghi chú — cùng điều kiện với Laravel. Không còn tham số `path`. |
| `tickets.messages.store` POST (`support_ticket.view`) | `POST /tickets/{id}/messages` | Đã làm | Phản hồi / ghi chú nội bộ (chỉ người xử lý), kèm file. Như Laravel: người khác người tạo trả lời ticket đang "Mở" → "Đang xử lý"; người tạo phản hồi ticket "Đã giải quyết" → mở lại "Đang xử lý". |
| `tickets.status.update` POST (`support_ticket.close`) | `POST /tickets/{id}/status` | Đã làm | Cùng 4 trạng thái, ghi `resolved_at`, cùng thông báo. |
| `tickets.reopen` POST (`support_ticket.view`) | `POST /tickets/{id}/reopen` | Đã làm | Người tạo (hoặc người có `support_ticket.close`) mở lại ticket đã giải quyết / đóng → "Đang xử lý". |
| `tickets.assign` POST (`support_ticket.assign`) | `POST /tickets/{id}/assign` | Đã làm | Người nhận phải có `support_ticket.update` và đang hoạt động; người phân công chưa ở mức Toàn hệ thống chỉ chọn được nhân sự thuộc chi nhánh mình (Admin luôn có trong danh sách) — `handlerCandidates`. |
| Dán ảnh từ clipboard (`pasted_images` base64) | Ô soạn: dán ảnh → thêm vào danh sách file | Khác | v2 không nhận base64: ảnh dán được đặt tên `anh-chup-man-hinh-<n>.png` và gửi như file thường (cùng kiểm loại / dung lượng 15 MB). |

## Thông báo và email

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `NotificationService::notifyTicketCreated` | `tickets/notify.go` `notifyCreated` | Đã làm | Thông báo trong ứng dụng tới người điều phối như `ticketDispatcherIds` (người có `support_ticket.assign` ở chi nhánh của người tạo, không có thì Admin; người tạo tự điều phối thì báo Admin; bỏ người tạo và tài khoản bị khóa), tiêu đề "[#mã] tiêu đề"; email mẫu `ticket` tới danh sách email nhận Ticket khi bật sự kiện "Ticket mới". |
| `notifyTicketMessage` | `notifyMessage` | Đã làm | "Re: [#mã] tiêu đề", tóm tắt nội dung (bỏ thẻ HTML, cắt ngắn). Ghi chú nội bộ chỉ báo người xử lý; email vẫn gửi danh sách nhận Ticket như Laravel. |
| `notifyTicketStatusChanged` | `notifyStatus` | Đã làm | "Re: [#mã] [trạng thái] tiêu đề" tới người tạo + người được giao. |
| `notifyTicketAssigned` (`Mail::raw`) | `notifyAssigned` | Khác | Thông báo trong ứng dụng như Laravel; email dùng mẫu HTML `ticket` (Laravel gửi thư chữ thuần) với tiêu đề "[MEnglish Phân công] Ticket #mã: tiêu đề". |
| Email đi qua hàng đợi | `mail.Enqueue` trong transaction | Khác | Xem `email.md`: thao tác hủy thì không có thư, lỗi tạm thì gửi lại. |

## Tổng quan, SLA, nhật ký

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| Dashboard: hàng chờ "Ticket đang mở" | `tickets.DashboardTickets()` → `dashboard.Tickets` | Đã làm | Số ticket mở / chưa có người xử lý ("%d ticket chưa có người xử lý"), link `/tickets`. |
| Nhật ký sự vụ: ticket theo mức độ / trạng thái / chi nhánh | `dashboard.Tickets.Incidents` (màn `/academic/dashboards/incidents`) | Đã làm | Ticket theo mức ưu tiên ứng với mức độ sự vụ, chi nhánh chính của người tạo; lọc theo lớp thì không có ticket (ticket không gắn lớp) như Laravel. Câu truy vấn `FacilityTickets` (ticket cơ sở vật chất còn mở) đã có nhưng chưa nối vào báo cáo ngày / tuần. |
| Báo cáo ngày Học vụ "Cơ sở vật chất" (`DailyReportMetrics::facilityDetails`), mục KPI `material_stock` của báo cáo tuần | `tickets.Service.ReportMetrics` bọc `reports.MetricsProvider` | Đã làm | Ticket loại Cơ sở vật chất mở / còn mở trong ngày (chi nhánh của người viết; ticket mới trong ngày ghi "(mới)"), đứng trước order đạo cụ; báo cáo tuần KPI: "Ticket CSVC <mã> · <tiêu đề>" làm dòng tham khảo. |
| SLA ticket | — | Không áp dụng | Laravel không có quy tắc SLA cho ticket (`config/sla.php` chỉ CRM). |
| Nhật ký thao tác (`AuditOperationMiddleware`, "Ticket hỗ trợ") | `audit.Record` (module "Ticket hỗ trợ", subject `ticket`) | Đã làm | Tạo, phản hồi, đổi trạng thái, mở lại, phân công. |
| Nhập ticket cũ (`support_tickets`, `ticket_messages`, file `public/uploads`) | — | Chưa làm | `import-legacy` chưa chép; cột `legacy_id` đã có. |
