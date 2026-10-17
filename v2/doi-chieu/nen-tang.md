# Đối chiếu Laravel → v2: nền tảng (khung giao diện, nhật ký vận hành, thông báo, tìm kiếm chung)

Đối chiếu với Laravel tại commit `41070de11611b387ddaf66ab769c32ad5af34c9a` (dòng `platform` trong `v2/doi-chieu-laravel.txt`).

## Khung giao diện

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| `SidebarMenu::definition()`: khu → workspace (label, icon), thứ tự Phê duyệt → Cá nhân | `WORKSPACES` trong `web/src/nav.ts`, `buildSidebar` | Đã làm | Giữ nguyên tên, biểu tượng, thứ tự. Workspace chỉ hiện khi có ít nhất một mục người dùng được mở; hiện mới có mục của iam và nền tảng, module khác thêm mục `NAV` với `group` = tên workspace. |
| `SidebarMenu::settingsDefinition()`, `SettingsNav.vue`, link "Cài đặt" cuối sidebar | `SETTINGS_SECTIONS`, `settingsNav`, `components/shell/PageNav.tsx` | Đã làm | Nhóm `Tổ chức & Đào tạo`, `Lương`, `Học phí`, `Hệ thống`; nhóm khác viết `Cài đặt › <nhóm>`. |
| `Sidebar.vue`, `SidebarLink.vue`: thu gọn, mục đang mở tô sáng, ngăn kéo trên điện thoại | `components/shell/Sidebar.tsx` | Đã làm | Thu gọn cả sidebar (nhớ `sidebar_collapsed`) và từng khu (nhớ `nav_collapsed_sections`); `aria-current` cho mục đang mở; md = chỉ biểu tượng, lg = đầy đủ. |
| `WorkspaceTabs.vue` (tab của workspace) | `WorkspaceTabs` trong `PageNav.tsx` | Làm một phần | Có tab; chưa có nút hành động (`actions`) của workspace vì các màn đó thuộc module khác. |
| `Topbar.vue`: nút menu, tiêu đề, ô tìm kiếm, chuông, menu tài khoản | `components/shell/Topbar.tsx` | Đã làm | Ô tìm kiếm mở bảng Ctrl K thay vì form GET. |
| Menu tài khoản: hồ sơ, hướng dẫn, chấm công, đăng xuất (`AppShell::for`) | Menu "Tài khoản": Tài khoản của tôi, Đăng xuất | Làm một phần | Hướng dẫn sử dụng và Chấm công thuộc module khác, thêm khi có màn. |
| Link "Tổng quan" (dashboard) đầu sidebar | Mục `/` "Tài khoản của tôi" | Chưa làm | Chưa có bảng điều khiển ở v2. |
| `AppLayout.vue`: chân trang, nhảy tới nội dung | `components/Shell.tsx` | Đã làm | Chân trang kèm môi trường + phiên bản. |
| `ToastHost.vue`, `ConfirmDialogHost.vue`, `RemoteModalHost.vue` | `Alert`, `Dialog` trong `components/ui.tsx` | Chưa làm | v2 báo lỗi / xác nhận tại chỗ; chưa có toast chung. |
| `SidebarMenu::quickCreateDefinition()` | — | Chưa làm | Laravel không còn dùng trên giao diện. |
| Biểu tượng Material Symbols | Font tự host `web/src/assets/fonts/material-symbols-outlined.woff2`, `<Icon />` | Đã làm | Không dùng CDN (CSP chỉ cho `'self'`). |
| Cổng học viên: chuông dẫn tới hộp thư của cổng | — | Chưa làm | Thuộc module cổng học viên. |

## Nhật ký vận hành

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| Bảng `activity_log` (spatie/laravel-activitylog) | `core.audit_log` (migration `00010`) | Đã làm | Thêm cột `action` (mã thao tác cho máy đọc), `branch_id`, `request_id`, `method`, `path`. |
| `App\Support\Audit`: `describe`, `logNameFor`, `eventLabel`, `descriptionFor` | `audit.Record`, `audit.Module*`, `audit.EventLabel`, `audit.RegisterSubject` | Đã làm | Laravel tự ghi qua trait `LogsActivity` của model; v2 gọi `audit.Record` trong transaction của thao tác, mô tả viết sẵn như `Audit::describe`. |
| `Audit::config` / `undoableAttributes` (cấu hình từng model) | — | Chưa làm | Chỉ dùng cho hoàn tác. |
| `App\Support\SensitiveData` | `audit.IsSensitiveKey`, `audit.Mask` | Đã làm | Che `***` cả trong dữ liệu trước / sau, payload và query string. |
| `AuditOperationMiddleware`: ghi dòng chung cho request ghi chưa có nhật ký | `audit.Middleware`, `audit.RegisterPath` | Đã làm | Bỏ `/auth/login`, `/auth/logout`, `/notifications`; đường dẫn `/auth/` không lưu dữ liệu gửi lên. |
| Ghi nhật ký thao tác quản trị: chi nhánh (thêm, sửa, bật / tắt, chấm công), tài khoản (tạo, sửa, vai trò, khóa / mở, đặt lại mật khẩu, đặt lại 2FA), quyền vai trò, phân quyền cá nhân | `internal/iam/admin.go`, `internal/iam/audit.go` | Đã làm | Mô tả giữ như Laravel; ghi trong transaction nên thao tác lỗi không để lại nhật ký. |
| `ActivityLogController::index`: phạm vi own / branch / all, lọc nhóm module, phân hệ, người, loại, khoảng ngày, tìm kiếm, phân trang, thống kê nhanh | `GET /activity-logs`, `GET /activity-logs/options`, `auditapi` | Đã làm | Quyền `activity_log.view`; tìm theo mô tả, phân hệ, đối tượng, tên / email / mã người làm, hoặc đúng id / mã request. |
| `ActivityLogController::export` (Excel) | `GET /activity-logs/export` | Làm một phần | Xuất CSV UTF-8 có BOM (mở bằng Excel), cùng cột, tối đa 20.000 dòng, chặn công thức; chưa xuất `.xlsx`. |
| `ActivityLogController::undo` (quyền `activity_log.undo`) | — | Chưa làm | Cần cấu hình trường được hoàn tác của từng bảng; làm khi các module nghiệp vụ đã chuyển. |
| `Pages/ActivityLogs/Index.vue`: thẻ thống kê, lọc nhanh, bảng, panel "Chi tiết đối chiếu" (trước / sau, chi tiết kỹ thuật) | `web/src/features/platform/ActivityLogsPage.tsx` | Làm một phần | Đủ trừ nút Hoàn tác / Khôi phục. |

## Thông báo

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| Bảng `admin_notifications` | `core.notifications` (migration `00011`) | Đã làm | `message` → `body`; `data.link` tách thành cột `link` (chỉ đường dẫn trong ứng dụng); `is_read` → `read_at`; thêm `created_by`. |
| `AdminNotification::notifyUser` / `notifyUsers` (bỏ trùng, `exceptUserId`) | `notify.Send(ctx, db, userIDs, notify.Notification{...})` | Đã làm | Bỏ thêm tài khoản đã khóa; gửi trong transaction được. |
| Thông báo chung `user_id NULL` + `scopeForRecipient` (`notification.view_system`) | Gửi riêng từng người | Làm một phần | Laravel dùng chung trạng thái đã đọc cho cả nhóm; v2 tìm người nhận bằng `iam.UserIDsWithPermission` / `iam.AdminIDs` rồi gửi riêng nên ai đọc chỉ đổi dòng của mình. Lọc khách theo phạm vi CRM do module CRM làm khi chọn người nhận. |
| `NotificationService::superAdminIds`, `userIdsWithPermission` | `iam.AdminIDs`, `iam.UserIDsWithPermission(ctx, db, perm, branchID)` | Đã làm | Cùng thứ tự quyết định với `Access.Can`; chưa tính phân quyền cá nhân theo chi nhánh / lớp. |
| `getIconAttribute`, `getBadgeColorAttribute`, `getTypeLabelAttribute` | `notify.Info`, `notify.RegisterType`, `notify.Types` | Đã làm | Đủ 42 loại của Laravel; màu quy về 6 tông. |
| `AdminNotificationController::index` (lọc loại, chưa đọc, thống kê tổng / chưa đọc / lead tồn đọng) | `GET /notifications` | Đã làm | Quyền `notification.view`. |
| `dropdown` / `AppShell::notificationMenu` (số chưa đọc + tin mới nhất) | `GET /notifications/summary` | Đã làm | 6 tin như chuông của Laravel; giao diện hỏi lại mỗi phút. |
| `markAsRead`, `markAllAsRead` | `POST /notifications/{id}/read`, `POST /notifications/read-all` | Đã làm | Thông báo của người khác trả 404. |
| `scan` + lệnh `crm:scan-stale-leads` (quyền `notification.manage`) | — | Chưa làm | Thuộc module CRM (gọi `notify.Send` với loại `stale_lead_24h` / `stale_lead_care`). |
| Các hàm `notifyTicket*`, `notifyTransactionReceipt`, `notifyDebtReminderByMilestone`, `notifyTrial*`, `sendOperationalAlertEmail`… | `internal/notify/mail` (`mail.Enqueue`, `mail.OperationalAlert`) | Làm một phần | Mỗi module tự gọi `notify.Send` khi chuyển. Đã có hàng đợi email và email vận hành; đã nối nộp bài test và xác nhận học thử. Ticket, học phí, khách tồn đọng chờ phân hệ: xem [email.md](email.md). |
| `Layouts/Shell/NotificationMenu.vue` (chuông) | `web/src/features/platform/NotificationBell.tsx` | Đã làm | Số "9+", "Đã đọc tất cả", "Xử lý ngay", "Xem tất cả thông báo"; mở tin thì đánh dấu đã đọc. Chỉ hiện khi có `notification.view`. |
| `Pages/Notifications/Index.vue` | `web/src/features/platform/NotificationsPage.tsx` | Đã làm | Thẻ thống kê, lọc nhanh (Tất cả / Lead tồn đọng >24h / Chưa đọc), thêm lọc theo loại, thẻ thông tin lead, sao chép nội dung, đánh dấu đã đọc. Nút "Quét lại hệ thống" chưa có (xem dòng `scan`). |
| Màn gửi thông báo hàng loạt của Admin | — | — | Laravel không có. |

## Tìm kiếm chung

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| `SidebarMenu::searchScreens` (màn hình theo tên, chỉ màn được mở) | `searchScreens` trong `web/src/nav.ts` | Đã làm | So khớp không dấu ("chi nhanh" ~ "Chi nhánh"). |
| `GlobalSearchController` (ít nhất 2 ký tự, mỗi nhóm theo quyền và `visibleTo`, tối đa 20) | `GET /search`, `internal/search` (`Registry`, `Searcher`) | Đã làm | Module tự đăng ký nhóm; route mở cho người có quyền của ít nhất một nhóm (Laravel chỉ cần đăng nhập). Từ khóa quá 100 ký tự trả 422. |
| Nhóm khách CRM (`lead.view`), học viên (`student.view`), lớp học (`class.view`) | `crm.Searcher`, `students.Searcher` (đăng ký ở `main.go`) | Làm một phần | Khách và học viên đã có (phạm vi như danh sách của từng phân hệ). Nhóm lớp học chờ phân hệ lớp. |
| — | Nhóm người dùng (`user.view`), chi nhánh (`branch.view`) | Đã làm | Mới ở v2; người dùng cùng phạm vi màn Người dùng; bấm kết quả mở thẳng hộp chi tiết tài khoản. |
| Ô tìm kiếm trên topbar (form GET `/search`) | Bảng tìm nhanh Ctrl K (`CommandPalette.tsx`) | Đã làm | Mũi tên lên / xuống, Enter, Esc; dòng cuối "Xem tất cả kết quả". |
| `Pages/Search/Index.vue` | `web/src/features/platform/SearchPage.tsx` | Đã làm | Các trạng thái: từ khóa ngắn, chưa có quyền tìm kiếm, không có kết quả, kết quả theo nhóm. |
