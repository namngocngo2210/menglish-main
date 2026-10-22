# Đối chiếu: Danh mục & tổ chức (giai đoạn 1)

Phân hệ v2: `v2/api/internal/catalogs` (Go) và `v2/web/src/features/catalogs` (React).
Migration: `00020`–`00024`. Mọi route `/api/v1/...` đều bọc `iam.Require` đúng quyền `can:` của `routes/web.php`.

Ký hiệu: **Đã làm** = đủ hành vi như Laravel; **Làm một phần** = phần còn thiếu ghi ở cột Ghi chú;
**Chưa làm** = chưa có ở v2.

## Khóa học & Bảng giá (`CourseController`)

| Laravel (route name / method) | v2 (API + màn) | Trạng thái | Ghi chú |
|---|---|---|---|
| `courses.index` GET /courses | GET /courses · màn `/courses` | Làm một phần | Có lọc (tìm kiếm / trình độ / trạng thái), thống kê, phân trang. Cột "Lớp" (`classes_count`) luôn là 0: TODO(classes). |
| `courses.store` POST /courses | POST /courses · hộp thoại Thêm | Đã làm | Mã tự sinh `CS0001` dưới khóa tư vấn (advisory lock), không dùng bảng `document_sequences`. |
| `courses.update` PUT /courses/{id} | PUT /courses/{id} · hộp thoại Sửa | Đã làm | Có thông báo đổi học phí như bản Laravel. |
| `courses.toggle` PATCH /courses/{id}/toggle | POST /courses/{id}/toggle | Đã làm | Đổi PATCH thành POST cho đồng bộ với các route bật/tắt khác của v2. |
| `courses.destroy` DELETE /courses/{id} | DELETE /courses/{id} | Làm một phần | Chưa chặn xóa khi khóa đang có lớp liên kết: TODO(classes). |

## Trình độ (`CourseLevelController`)

| Laravel (route name / method) | v2 (API + màn) | Trạng thái | Ghi chú |
|---|---|---|---|
| `course-levels.index` GET /course-levels | GET /course-levels · màn `/course-levels` | Làm một phần | Có lọc, thống kê, nhóm, cấp độ test đầu vào. Số lớp và số học viên đang dùng luôn là 0: TODO(classes). |
| `course-levels.store` POST | POST /course-levels | Làm một phần | Chưa gắn được giáo trình (`syllabus_curriculum_id`): phân hệ giáo trình chưa có ở v2; gửi lên thì báo lỗi rõ ràng. |
| `course-levels.update` PUT /{id} | PUT /course-levels/{id} | Làm một phần | Như trên về giáo trình. |
| `course-levels.reorder` POST /reorder | POST /course-levels/reorder | Đã làm | Màn dùng nút lên / xuống thay cho kéo thả; chỉ cho sắp xếp khi không lọc. |
| `course-levels.destroy` DELETE /{id} | DELETE /course-levels/{id} | Làm một phần | Chặn khi có khóa học gắn trình độ (409). Chưa chặn theo số lớp: TODO(classes). |
| (không có route riêng; sửa `is_active` qua form) | POST /course-levels/{id}/toggle | Đã làm | Thêm ở v2 cho nút Tạm ngưng / Kích hoạt; cùng quyền `level.update`. |

## Phòng học & loại phòng (`RoomController`, `RoomTypeController`)

| Laravel (route name / method) | v2 (API + màn) | Trạng thái | Ghi chú |
|---|---|---|---|
| `rooms.index` GET /rooms | GET /rooms · màn `/rooms` | Làm một phần | Phạm vi chi nhánh (`DataScope::branchIds('room')`), màn chặn khi chưa gán chi nhánh, chi nhánh cố định, số đếm tab. Danh sách lớp đang dùng phòng luôn rỗng: TODO(classes). |
| `rooms.create` GET /rooms/create | hộp thoại Thêm phòng | Đã làm | Trang Blade thay bằng hộp thoại. |
| `rooms.store` POST /rooms | POST /rooms | Đã làm | Chặn chi nhánh ngoài phạm vi (403). |
| `rooms.edit` GET /rooms/{room}/edit | GET /rooms/{id} · hộp thoại Sửa | Đã làm | |
| `rooms.update` PUT /rooms/{room} | PUT /rooms/{id} | Làm một phần | Chưa chép tên phòng mới sang lớp / buổi học (`RoomService::syncName`): TODO(classes). |
| `rooms.destroy` DELETE /rooms/{room} | DELETE /rooms/{id} | Làm một phần | Chưa chặn xóa phòng đang có lớp sử dụng: TODO(classes). |
| `rooms.availability` GET /rooms/availability | — | Chưa làm | Tra cứu phòng trống cần lịch lớp / buổi học (`academic.classes`, `class_sessions`) của phân hệ lớp học. |
| `rooms.types.index` GET /rooms/types | GET /rooms/types · tab `/rooms/types` | Đã làm | |
| `rooms.types.store` POST /rooms/types | POST /rooms/types | Đã làm | |
| `rooms.types.update` PUT /rooms/types/{roomType} | PUT /rooms/types/{id} | Đã làm | |
| `rooms.types.toggle` POST /rooms/types/{roomType}/toggle | POST /rooms/types/{id}/toggle | Đã làm | |
| `rooms.types.destroy` DELETE /rooms/types/{roomType} | DELETE /rooms/types/{id} | Đã làm | Loại đang có phòng thì 409. |

## Khung giờ ca dạy (`TeachingShiftController`)

| Laravel (route name / method) | v2 (API + màn) | Trạng thái | Ghi chú |
|---|---|---|---|
| `teaching-shifts.index` GET | GET /teaching-shifts · màn `/teaching-shifts` | Làm một phần | Có quy tắc Thứ 2–6, 90 phút, giờ lệch. Danh sách lớp có buổi lệch khung 60 ngày tới luôn rỗng: TODO(classes). |
| `teaching-shifts.store` POST | POST /teaching-shifts | Đã làm | Kiểm đúng 90 phút, giờ lệch đi theo cặp. Màn tự điền giờ kết thúc +90 phút. |
| `teaching-shifts.update` PUT /{teachingShift} | PUT /teaching-shifts/{id} | Đã làm | |
| `teaching-shifts.toggle` POST /{teachingShift}/toggle | POST /teaching-shifts/{id}/toggle | Đã làm | |
| `teaching-shifts.destroy` DELETE /{teachingShift} | DELETE /teaching-shifts/{id} | Đã làm | |
| Dữ liệu mặc định (migration Laravel) | migration 00022 | Đã làm | 11 khung giờ, idempotent. |

## Ngày nghỉ lễ (`HolidayController`)

| Laravel (route name / method) | v2 (API + màn) | Trạng thái | Ghi chú |
|---|---|---|---|
| `holidays.index` GET | GET /holidays · màn `/holidays` | Đã làm | |
| `holidays.create` / `holidays.edit` GET | hộp thoại Thêm / Sửa · GET /holidays/{id} | Đã làm | |
| `holidays.store` POST | POST /holidays | Làm một phần | Mã tự sinh `HOL-YYYY-NNN`. Chưa hủy buổi học trùng ngày nghỉ và xếp bù (`HolidayRescheduleService::apply`): TODO(classes). |
| `holidays.update` PUT | PUT /holidays/{id} | Làm một phần | Như trên. |
| `holidays.destroy` DELETE | DELETE /holidays/{id} | Làm một phần | Chưa khôi phục buổi học bị hủy (`HolidayRescheduleService::release`): TODO(classes). |
| `HolidaySeeder` | migration 00024 | Đã làm | 3 ngày nghỉ mẫu; ngày nghỉ theo chi nhánh chỉ gắn Q1 / Q7 nếu đã có. |
| (dùng chung) | `Service.HolidaysBetween` | Đã làm | Cho phân hệ lớp học / chấm công gọi. |

## Danh mục hệ thống (`SystemCategoryController`)

| Laravel (route name / method) | v2 (API + màn) | Trạng thái | Ghi chú |
|---|---|---|---|
| `system-categories.index` GET | GET /system-categories · màn `/system-categories` | Đã làm | Tab theo nhóm, tìm kiếm, gợi ý mã và thứ tự kế tiếp. |
| `system-categories.create` / `edit` GET | hộp thoại Thêm / Sửa | Đã làm | |
| `system-categories.store` POST | POST /system-categories | Đã làm | |
| `system-categories.update` PUT | PUT /system-categories/{id} | Đã làm | |
| `system-categories.destroy` DELETE (ngừng dùng) | POST /system-categories/{id}/deactivate | Đã làm | Laravel không xóa mà ngừng dùng; v2 đặt tên route đúng nghĩa. |
| `system-categories.reactivate` POST | POST /system-categories/{id}/reactivate | Đã làm | |
| `SystemCategorySeeder` | migration 00021 | Đã làm | 23 mục, `ON CONFLICT DO NOTHING`. |
| (dùng chung) | `Service.ActiveCategories` | Đã làm | Cho CRM / nhân sự lấy danh sách đang dùng. |

## Cấu hình SLA (`SystemConfigController::sla*`, `config/sla.php`, `SlaSetting`)

| Laravel (route name / method) | v2 (API + màn) | Trạng thái | Ghi chú |
|---|---|---|---|
| `system-config.sla` GET | GET /system-config/sla · màn `/system-config/sla` | Đã làm | 27 luật lấy từ `config/sla.php` (xuất ra `sla/sla.json` bằng `sla/export.php`), đồng bộ vào `hr.sla_rules` mỗi lần `migrate`. |
| `system-config.sla.update` PUT /sla/{key} | PUT /system-config/sla/{key} | Đã làm | Ngưỡng (giờ "HH:MM" với đơn vị time), bật/tắt, biên bản phạt, mức phạt, bậc thang. |
| `system-config.sla.settings` POST | POST /system-config/sla/settings | Đã làm | Số tháng cộng dồn tái phạm (1–60). |
| `Sla::rule()` (đọc luật lúc chạy) | `catalogs.Service.SlaRule(ctx, key)` | Đã làm | Các phân hệ CRM / nhân sự gọi hàm này. Việc tính vi phạm (`SlaBreachService`…) thuộc phân hệ khác. |

## Tài khoản ngân hàng (`SystemConfigController::bankAccounts*`)

| Laravel (route name / method) | v2 (API + màn) | Trạng thái | Ghi chú |
|---|---|---|---|
| `system-config.bank-accounts` GET | GET /system-config/bank-accounts · màn `/system-config/bank-accounts` | Làm một phần | Có danh sách, tài khoản mặc định, xem trước VietQR. Chưa có khối cấu hình SePay trên màn. |
| `system-config.bank-accounts.store` POST | POST /system-config/bank-accounts | Đã làm | Tài khoản đầu tiên tự làm mặc định. |
| `system-config.bank-accounts.update` PUT | PUT /system-config/bank-accounts/{id} | Đã làm | Không cho ngừng dùng tài khoản mặc định. |
| `system-config.bank-accounts.destroy` DELETE | DELETE /system-config/bank-accounts/{id} | Đã làm | Xóa tài khoản mặc định thì đẩy tài khoản khác lên làm mặc định. |
| `system-config.bank-accounts.default` POST | POST /system-config/bank-accounts/{id}/default | Đã làm | |
| `system-config.sepay.update` POST /sepay | — | Chưa làm | Cấu hình SePay (khóa API, webhook) gắn với luồng đối soát thanh toán của phân hệ tài chính; để phân hệ đó làm. |

## Nhắc nợ học phí (`SystemConfigController::debtReminders*`)

| Laravel (route name / method) | v2 (API + màn) | Trạng thái | Ghi chú |
|---|---|---|---|
| `system-config.debt-reminders` GET | GET /system-config/debt-reminders · màn `/system-config/debt-reminders` | Đã làm | |
| `system-config.debt-reminders.store` POST | POST /system-config/debt-reminders | Đã làm | Thêm / sửa theo mã mốc; kiểm biến mẫu tin; mỗi thời điểm một mốc. |
| `system-config.debt-reminders.settings` POST | POST /system-config/debt-reminders/settings | Đã làm | Thiết lập nhanh: đồng bộ mốc `NHAC-TRUOC` / `NHAC-LAI`, ngưỡng quá hạn bắt buộc liên hệ. Việc gửi nhắc thật thuộc phân hệ tài chính. |

## Email nhận Ticket (`SystemConfigController::ticketEmails*`)

| Laravel (route name / method) | v2 (API + màn) | Trạng thái | Ghi chú |
|---|---|---|---|
| `system-config.ticket-emails` GET (`can:mail_config.manage`) | GET /system-config/ticket-emails (`mail_config.manage`) · màn `/system-config/ticket-emails` | Đã làm | Cả màn (email nhận thông báo vận hành toàn hệ thống + SMTP) và 3 API chỉ dành cho `mail_config.manage` như routes/web.php; menu neo `portal.staff` (SidebarMenu STAFF); nút "Cấu hình Email nhận" ở màn Ticket theo `mail_config.manage`. Không bao giờ trả mật khẩu. |
| `system-config.ticket-emails.update` POST | POST /system-config/ticket-emails | Đã làm | Mật khẩu SMTP mã hóa (`secretbox`) trong `core.settings.secret`; đổi máy chủ / tài khoản phải nhập lại mật khẩu. |
| `system-config.ticket-emails.test` POST /ticket-emails/test | POST /system-config/ticket-emails/test · khối "Gửi thử nghiệm (Test Email)" | Đã làm | Gửi ngay qua SMTP đang lưu, cùng thông báo kết quả và gợi ý lỗi; xem [email.md](email.md). |

## Khác

| Laravel (route name / method) | v2 (API + màn) | Trạng thái | Ghi chú |
|---|---|---|---|
| `system-config.hosting` GET /hosting | — | Chưa làm | Thông tin máy chủ PHP / Laravel, không còn đúng với v2. |
| `Audit::describe` ở các controller trên | `catalogs/audit.go` (`audit.Record` trong giao dịch) + `audit.Middleware` | Đã làm | Ngày nghỉ, khung giờ ca dạy, danh mục hệ thống có nhật ký riêng như Laravel; khóa học / trình độ / phòng có dòng chung của middleware (nhóm Hệ thống). |
| Ô sidebar (`SidebarMenu`) | `NAV` trong `v2/web/src/nav.ts` | Đã làm | Nhóm "Tổ chức & Đào tạo", "Học phí", "Hệ thống" với đúng quyền như Laravel. |
