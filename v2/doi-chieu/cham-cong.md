# Đối chiếu: Chấm công (chấm công của tôi, đơn chấm công & nghỉ, chấm công hằng ngày, giờ dạy giáo viên)

Nguồn Laravel: `app/Http/Controllers/MyAttendanceController.php`, `app/Http/Controllers/StaffAttendanceController.php`,
phần chấm công của `app/Http/Controllers/PayrollController.php` (`payroll.timesheets.*`), `app/Services/StaffAttendance/`
(`StaffAttendanceService`, `Approvals/StaffAttendanceRequestApprovalSource`), model `StaffAttendance`,
`StaffAttendanceRequest`, `TeacherTimesheet`, `TimesheetSyncLog`, màn `resources/js/Pages/MyAttendance/*`,
`resources/js/Pages/StaffAttendance/*`, `resources/js/Pages/Payroll/Timesheets/*`, `routes/web.php` (nhóm `cham-cong`,
`staff-attendance`, `payroll/timesheets`), `app/Support/Navigation/SidebarMenu.php` (workspace `timesheets` và mục "Xin
duyệt"). Đối chiếu tại commit `838936c9`.

v2: schema `hr` (migration `00170_hr_attendance.sql`: `hr.staff_attendances`, `hr.staff_attendance_requests`,
`hr.teacher_timesheets`, `hr.timesheet_sync_logs`), Go `v2/api/internal/attendance`, web `v2/web/src/features/attendance`.
Cài đặt GPS (tọa độ, bán kính, giờ làm, phút cho phép muộn) đọc từ `core.branch_checkin` (màn Cơ sở & chi nhánh); ảnh khuôn
mặt lưu ở kho file (`files.Service`, module `attendance`). Mọi route bọc `iam.Require` / `iam.RequireAny` theo đúng
middleware `can:` của Laravel.

Trạng thái: **Đã làm** · **Khác** (đã làm, khác có chủ đích, xem ghi chú) · **Làm một phần** · **Chưa làm**.

## Chấm công của tôi & đơn (MyAttendanceController, `can:portal.staff`)

| Laravel | v2 (API + màn) | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `my-attendance.home` GET `/cham-cong` (`home`) | `GET /api/v1/attendance/me` · màn `/cham-cong` (Chấm công → Chấm công của tôi) | Đã làm | Đồng hồ theo giờ máy chủ, thẻ cơ sở (địa chỉ, bán kính, phút cho phép muộn), giờ phải có mặt hôm nay (giờ hành chính hoặc ca dạy sớm nhất / muộn nhất, danh sách ca), thẻ "Hôm nay" (giờ vào / ra, ảnh, khoảng cách, trạng thái, nhắc đi muộn + mã biên bản). Chưa gán cơ sở / cơ sở chưa cài tọa độ / kỳ lương đã khóa → thông báo như Laravel, ẩn nút. Link "Quên chấm công?", "Xin nghỉ". |
| `my-attendance.punch` POST `/cham-cong` (`punch`, `throttle:20,1`) | `POST /api/v1/attendance/me/punch` (multipart: `kind`, `latitude`, `longitude`, `accuracy`, ảnh `file`/`photo`) · hộp thoại chấm công | Đã làm | Kiểm GPS, bán kính (khoảng cách Haversine như Laravel), ảnh (jpg/png/webp, tối đa 6 MB như `photo max:6144`), chưa chấm vào thì không chấm ra, chấm ra lần nữa thay ảnh cũ; tính phút muộn / về sớm, ghi nhật ký `audit`. Giới hạn 20 lần / phút / người. Màn mở camera trước (`getUserMedia`), chụp ảnh thu nhỏ ≤ 720 px; không mở được camera thì dùng ô chụp ảnh `capture="user"`. Ngoài bán kính: báo trước ngay trên hộp thoại, máy chủ vẫn kiểm lại. |
| `my-attendance.history` GET `/cham-cong/lich-su` (`history`) | `GET /api/v1/attendance/me/history?month=` · màn `/cham-cong/lich-su` | Đã làm | Chuyển tháng (không quá tháng hiện tại), thẻ ngày đi làm / đi muộn (lần · phút) / ngày nghỉ có phép, dòng phụ đi muộn có đơn / về sớm / thiếu giờ ra, từng ngày công, nghỉ phép đã duyệt (`StaffAttendanceService::summary`). |
| `my-attendance.requests` GET `/cham-cong/don` (`requests`) | `GET /api/v1/attendance/me/requests` · màn `/cham-cong/don` (Xin duyệt → Đơn chấm công & nghỉ) | Đã làm | 3 loại đơn (bổ sung công, xin đi muộn / về sớm, xin nghỉ phép), đơn đã gửi (rút đơn chờ duyệt), `?type=` mở sẵn form. "Yêu cầu khác theo vai trò" (`ROLE_REQUESTS`) đủ 6 mục theo quyền: đề xuất sửa giáo trình, xin điều chỉnh tiến độ, hoàn tiền & khất nợ, hủy hóa đơn, nhờ hỗ trợ / giao việc, gửi ticket hỗ trợ. |
| `my-attendance.requests.store` POST `/cham-cong/don` (`storeRequest`) | `POST /api/v1/attendance/me/requests` | Đã làm | Kiểm và thông báo như `StaffAttendanceService::submit`: loại đơn, ngày, giờ HH:MM, lý do ≤ 1000, nghỉ tối đa 31 ngày / đơn, bổ sung công chỉ cho ngày đã qua + phải có giờ vào hoặc ra, giờ ra sau giờ vào, trùng đơn chờ duyệt, kỳ lương đã khóa. |
| `my-attendance.requests.cancel` POST `/cham-cong/don/{id}/rut` | `POST /api/v1/attendance/me/requests/{id}/cancel` | Đã làm | Chỉ đơn của mình, đang chờ duyệt. |
| `staff-attendance.photo` GET `/staff-attendance/{id}/photo/{kind}` (`photo`) | `GET /api/v1/files/{id}` (kho file) | Khác | Ảnh chấm công là file của kho file; quyền xem do `files.RegisterReader` của phân hệ (chính chủ, hoặc người có `staff_checkin.view` trong phạm vi chi nhánh) — cùng điều kiện `StaffAttendanceController::canSee`. |
| `Route::redirect` `/m`, `/m/lich-su`, `/m/xin-duyet` | màn `/m*` chuyển hướng (thay địa chỉ, không thêm lịch sử) | Đã làm | |
| `Route::redirect` `/m/can-duyet` → `/approvals` | `/m/can-duyet` → `/approvals` | Đã làm | |

## Duyệt đơn (StaffAttendanceRequestApprovalSource, `staff_checkin.approve` — chỉ Admin)

| Laravel | v2 (API + màn) | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| Nguồn duyệt `staff_attendance_requests` trong hộp `approvals.index` | `attendance.requestSource` trong hộp `/approvals` (nhóm Nhân sự) | Đã làm | Duyệt / từ chối ngay trong hộp bằng `Approve` / `Reject` của phân hệ (phạm vi chi nhánh, không tự duyệt đơn của mình); dòng hiện loại đơn, thời gian, lý do, công hiện tại của ngày. Trang tạm `/approvals/staff-attendance` và API `/attendance/approvals` bỏ; URL cũ chuyển hướng sang `/approvals?group=nhan-su`. |
| `approve` (`StaffAttendanceService::approve`) | `POST /api/v1/attendance/approvals/{id}/approve` | Đã làm | Bổ sung công → tạo / sửa dòng công (`source = request`) và tính lại muộn / sớm; xin đi muộn / về sớm → gắn `late_excused`, hủy biên bản đi muộn (qua `Penalties.Excused`); nghỉ phép → dùng cho tổng hợp. Thông báo `notify.Send` cho người gửi (link `/cham-cong/don`), nhật ký `audit`. Kỳ lương đã khóa → từ chối. |
| `reject` | `POST /api/v1/attendance/approvals/{id}/reject` `{reason}` | Đã làm | Bắt buộc lý do ≤ 1000; thông báo cho người gửi. |

## Chấm công hằng ngày (StaffAttendanceController, `can:staff_checkin.view`)

| Laravel | v2 (API + màn) | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `staff-attendance.index` GET `/staff-attendance` (`index`) | `GET /api/v1/attendance/daily?view=day\|month&date=&month=&branch_id=&status=&search=&page=` · màn `/staff-attendance` | Đã làm | Tab "Theo ngày" (4 thẻ: nhân sự / đã chấm / đi muộn / nghỉ phép; giờ phải có mặt, vào (ảnh), ra, khoảng cách, trạng thái, nghỉ phép, mã biên bản) và "Tổng hợp tháng"; lọc cơ sở trong phạm vi (`scope_branch` chỉ thấy chi nhánh mình), trạng thái, tìm tên / mã; cảnh báo cơ sở chưa cài tọa độ. |
| `staff-attendance.show` GET `/staff-attendance/{id}` (`show`) | `GET /api/v1/attendance/daily/{id}` · hộp thoại chi tiết khi bấm dòng | Khác | Cùng nội dung (ảnh vào / ra, giờ, khoảng cách, sai số, link bản đồ, giờ phải có mặt, ghi chú, biên bản) nhưng là hộp thoại trên danh sách thay vì trang riêng. Link biên bản trỏ `/penalties?search=<mã>` (màn của phân hệ Lương & Phạt). |

## Giờ dạy giáo viên (PayrollController, `payroll.timesheets.*`)

| Laravel | v2 (API + màn) | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `payroll.timesheets.teachers` GET (`teacherTimesheets`) | `GET /api/v1/timesheets/teachers?month=&status=&type=&search=&branch_id=&class_id=&user_id=&day=&page=` (`attendance_staff.view` hoặc `payroll.view_own`) · màn `/payroll/timesheets/teachers` | Đã làm | Người có `attendance_staff.view`: ca dạy của lớp trong phạm vi (`classes` cho lớp của mình / chi nhánh / tất cả), lịch dạy theo ngày + "Xác nhận chấm công", lọc chi nhánh / lớp / giáo viên, nhắc buổi dạy thay chờ duyệt, chọn nhiều ca "Chốt bảng công (N)". Chỉ `payroll.view_own`: ca của chính mình. Thẻ giáo viên + "Buổi thiếu chấm công" (link "Chấm công tay" điền sẵn form). Phạm vi nhân sự `attendance_staff` (`staffInScope`, rà soát 19/10/2026): thẻ giáo viên chỉ nhân sự trong phạm vi (`?user_id=` chi nhánh khác không lộ tên / mã), buổi thiếu chỉ tính lớp trong phạm vi, ô lọc Giáo viên chỉ nhân sự trong phạm vi (`teachingStaff`). Thẻ kỳ lương + khóa thao tác khi kỳ đã khóa (qua `PayrollLock`). |
| `payroll.timesheets.review` POST `/timesheets/teachers/{id}/review` | `POST /api/v1/timesheets/teachers/{id}/review` `{decision, rejection_reason}` | Đã làm | Từ chối bắt buộc lý do; ngoài phạm vi lớp → 403; kỳ khóa → lỗi như Laravel. |
| `payroll.timesheets.bulk-review` POST | `POST /api/v1/timesheets/teachers/bulk-review` `{ids}` | Đã làm | Tối đa 500 ca, bỏ qua ca không còn chờ / kỳ khóa / ngoài phạm vi, thông báo số đã chốt / bỏ qua. |
| `payroll.timesheets.sessions.confirm` POST `/timesheets/sessions/{id}/confirm` | `POST /api/v1/timesheets/sessions/{id}/confirm` | Đã làm | Tạo / duyệt ca theo lịch (`source = schedule`); buổi hủy / chưa phân công / chưa tới ngày → lỗi như Laravel. |
| `payroll.timesheets.adjust` PUT `/timesheets/teachers/{id}/adjust` (`attendance_staff.manual_record`) | `PUT /api/v1/timesheets/teachers/{id}/adjust` · hộp thoại "Chỉnh tay" | Đã làm | Giờ vào / ra, phút muộn / về sớm, có báo trước, lý do ≥ 5 ký tự; ghi người / lúc chỉnh, nhật ký có phần thay đổi. |
| `payroll.timesheets.manual` GET / POST (`manualTimesheet` / `storeTimesheet`, `attendance_staff.manual_record`) | `GET` / `POST /api/v1/timesheets/manual` · màn `/payroll/timesheets/manual` | Đã làm | Tìm nhân sự không dấu, chi nhánh → lớp, ngày dạy (không cho ngày tương lai), giờ, muộn / sớm, loại ca + đơn giá giờ (tùy chọn), lý do bắt buộc; khớp buổi học theo giờ (`matchSession`), chặn trùng ca (`findDuplicate`) với thông báo như Laravel; ô chọn nhân sự chỉ nhân sự trong phạm vi `attendance_staff` (`teachingStaff` → `staffInScope`); kỳ lương khóa → cảnh báo và khóa nút lưu. |
| `payroll.timesheets.appsheet` GET (`attendance_staff.view`) | màn `/payroll/timesheets/appsheet` | Đã làm | Nhúng ứng dụng AppSheet cũ (cùng URL), nút mở tab mới, toàn màn hình. Không có API. |
| `payroll.timesheets.sync-history` GET (`attendance_staff.sync`) | `GET /api/v1/timesheets/sync-history?from=&to=&status=&page=` · màn `/payroll/timesheets/sync-history` | Đã làm | Như Laravel: chưa có tích hợp máy chấm công nào ghi `timesheet_sync_logs` → màn báo "chưa kết nối", nút "Đồng bộ ngay" tắt; lọc ngày / trạng thái, xem lỗi từng đợt. |
| `payroll.timesheets.sync-history.errors` GET | `GET /api/v1/timesheets/sync-history/{id}/errors` | Đã làm | CSV UTF-8 có BOM `loi-dong-bo-cham-cong-<id>.csv`. |

## Phần khác

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| Menu `SidebarMenu` workspace "Chấm công" (7 tab) + "Xin duyệt → Đơn chấm công & nghỉ" | `nav.ts` | Đã làm | Đúng thứ tự, nhãn; "Giờ dạy giáo viên" neo `attendance_staff.view` / `portal.teacher` / `portal.assistant` như `can` của Laravel. Đơn chờ duyệt nằm trong hộp "Cần duyệt → Việc cần duyệt" như Laravel. Mục "Chấm công" trên menu tài khoản topbar chưa thêm. |
| `StaffAttendanceService::syncLatePenalty` (biên bản đi muộn tự động, thông báo) | `attendance.Penalties` (Late / Excused / Refs / Violations) ← `attendancePayroll` (`cmd/menglish/wiring.go`) | Đã làm | Chấm vào muộn / duyệt đơn bổ sung lập hoặc cập nhật biên bản đi muộn của Lương & Phạt trong cùng giao dịch; sửa giờ hết muộn hoặc duyệt đơn xin đi muộn / nghỉ thì tự hủy biên bản; thông báo khi lập biên bản; số biên bản lỗi trên Tổng quan đếm từ bảng `penalties`. |
| `PayrollPeriod::isLockedFor` (kỳ lương đã duyệt / chi trả khóa chấm công) | `attendance.PayrollLock` (LockedRanges / Period) ← `attendancePayroll` | Đã làm | Mọi thao tác ghi kiểm khóa theo kỳ lương đã duyệt / chi trả của phân hệ Lương. |
| `TeacherTimesheet` tính tiền (`effectiveHourlyRate`, `payOutcome`, `applyLateRules`, `sessionPay`) | phân hệ Lương (`payroll.WithAttendance` ← `payrollAttendance`) | Đã làm | Bảng lương đọc ca dạy hợp lệ + ngày công (`ValidTimesheets` / `Summary` / `ChangedSince`) và tính tiền ở phân hệ Lương. |
| Cổng giáo viên check-in ca dạy (ghi `teacher_timesheets` source `checkin`) | `attendance.Service.RecordCheckin` ← `teacherWork` | Đã làm | Check-in ở `/teacher/home` ghi ca chờ duyệt (bỏ qua buổi đã chấm tay / đã check-in, ngày thuộc kỳ lương đã khóa). |
| `KpiAutoCounter` (đi muộn nhân sự > 10 phút, GV đến muộn, vắng không phép, nghỉ có phép) | `attendance.Service.KpiSources()` (`staff_late`, `teacher_late`, `teacher_absent_unexcused`, `teacher_leave`) | Đã làm | Nối vào `kpi` trong `main.go`. |
| Tổng quan (ô ngày công / đi muộn / phép, `MyWorkBoard`) | `attendance.Service.DashboardWork()` | Đã làm | Đi muộn = ca dạy muộn + ngày chấm công muộn không có đơn (bỏ ngày đã có ca dạy muộn); số biên bản lỗi từ `Penalties.Violations` (bảng `penalties` của Lương & Phạt). |
| `DailyReportMetrics`, `TeachingQuality` dùng chấm công | `attendance.DashboardWork()` (Tổng quan) | Làm một phần | Chất lượng giảng dạy trên Tổng quan đã đọc ngày công / đi muộn / phép / vi phạm. Báo cáo ngày chưa có dòng GV đi muộn / về sớm / chưa check-in (bảng giờ dạy) và TA đi muộn (chấm công) trong mục Số giáo viên / Số trợ giảng — chưa có provider báo cáo của Chấm công. |
| Nghỉ lễ dời lịch ca dạy (`TODO(hr)` của Lớp học) | — | Chưa làm | Không thuộc phạm vi màn chấm công. |
| Nhập dữ liệu chấm công / giờ dạy cũ từ Laravel | — | Chưa làm | `import-legacy` chưa chép các bảng này. |
