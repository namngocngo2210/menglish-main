# Chấm công theo lịch dạy

Trạng thái: **Hoàn thành** — duyệt và triển khai 23/10/2026

## 1. Bối cảnh

Yêu cầu của chủ dự án:

- Chức năng chấm công chỉ hiển thị / bấm được khi nhân sự có giờ dạy hôm đó: có buổi dạy, dạy thay, học bù.
- Nhân sự full-time chấm công mọi ngày, trừ ngày nghỉ (module Ngày nghỉ).
- Học vụ / Admin chấm công hộ giáo viên part-time theo lô, chỉ trên các ngày GV đó có lịch — để GV **được tính lương** (chốt 22/10/2026).

Hệ thống đang có hai loại chấm công:

| | (A) Chấm công ngày | (B) Giờ dạy theo ca |
|---|---|---|
| Bản ghi | `staff_attendances` | `teacher_timesheets` |
| Người làm | Mọi nhân sự (`portal.staff`), ảnh + GPS | GV / TA check-in từng ca trên cổng GV; Học vụ chấm tay / xác nhận theo lịch |
| Dùng cho | Đi muộn → biên bản; lịch sử công | **Lương part-time** (số ca hợp lệ), KPI giảng dạy |
| Code | `StaffAttendanceService`, `MyAttendanceController` | `TeacherPortalController::checkin`, `PayrollController` (Bảng giờ dạy) |

Hiện trạng:

- (A) không chặn theo lịch: GV / TA vẫn bấm được ngày không có buổi (chỉ không tính muộn). Ngày nghỉ cũng không chặn.
- (B) Học vụ chỉ xác nhận **từng buổi** (`confirmScheduledSession`, tạo ca `SOURCE_SCHEDULE` hợp lệ) hoặc chấm tay từng ca.
  Màn Bảng giờ dạy đã có danh sách "buổi thiếu công" của một GV trong tháng (`missingSessions`, theo `forStaff` nên
  gồm cả dạy thay / học bù), mỗi dòng chỉ có link sang form chấm tay.
- Dạy thay và học bù đã nằm trong lịch: `ClassSession::forStaff()` lấy theo GV / GVNN / TA **của từng buổi**, buổi bù là
  `TYPE_MAKEUP`. Không cần thêm dữ liệu.

## 2. Quyết định

1. **Quy tắc ngày được chấm công áp cho (A)**:
   - Part-time (theo `PayrollFormulaService::profile()` — cùng quy tắc xếp loại lương): chỉ chấm được ngày có ít nhất một
     buổi chưa hủy mà mình là GV / GVNN / TA (`forStaff`).
   - Nhân sự khác (full-time): chấm mọi ngày, trừ ngày nghỉ áp cho cơ sở đang chấm (nghỉ toàn hệ thống hoặc nghỉ riêng của
     cơ sở, dùng lại `SessionScheduleService::holidayDates()`).
   - Áp ở server khi bấm chấm công **và** khi gửi đơn "Bổ sung công" cho ngày đó. Đơn xin nghỉ / xin đi muộn không đổi.
   - Màn "Chấm công của tôi" vẫn mở được nhưng **khóa nút** và ghi lý do ("Hôm nay bạn không có buổi dạy" / "Hôm nay là
     ngày nghỉ: …"). Không ẩn menu: ẩn phải truy vấn lịch dạy ở mọi request (sidebar).
2. **Chấm công hộ theo lô làm trên (B)**: trên Bảng giờ dạy của một GV, danh sách "buổi thiếu công" có ô chọn + nút
   "Xác nhận theo lịch (N buổi)". Mỗi buổi được chọn đi qua đúng quy tắc của `confirmScheduledSession` (logic tách ra
   service dùng chung) → ca `SOURCE_SCHEDULE`, trạng thái **hợp lệ**, tính vào lương.
3. Không thêm bảng / cột / quyền mới.
   - Batch dùng quyền `attendance_staff.manual_record` (đã cấp cho Admin, Quản lý cơ sở, Học vụ), vì tạo ca được trả lương
     hàng loạt.
   - Không giới hạn batch chỉ cho part-time: GV full-time quên check-in cũng cần. Màn này vốn là theo từng GV.
   - Màn mặc định lọc theo GV đang chọn.

### Phương án đã cân nhắc

- *Batch tạo bản ghi chấm công ngày (A) cho part-time*: không ảnh hưởng lương, không giải quyết được việc GV quên check-in → loại.
- *Ẩn hẳn menu Chấm công ngày không có lịch*: thêm truy vấn mỗi request, lợi ích nhỏ → khóa nút thay vì ẩn.

## 3. Thiết kế chi tiết

### 3.1. Quy tắc ngày chấm công (A)

`StaffAttendanceService`:

```php
/** Lý do không được chấm công ngày $date ở $branch (null = được chấm). */
public function punchBlockReason(User $user, ?Branch $branch, CarbonInterface $date): ?string
```

- Part-time và không có buổi `forStaff($user->id)` chưa hủy trong ngày → `"Hôm nay bạn không có buổi dạy / dạy thay / học bù nên không cần chấm công."`
- Full-time và `$branch` có ngày nghỉ → `"Hôm nay là ngày nghỉ ({tên ngày nghỉ}), không chấm công."`
- `punch()`: gọi sau khi chọn được cơ sở gần nhất → `ValidationException` (`kind`).
- `submit()` (đơn Bổ sung công): kiểm tra với cơ sở chính (`users.branch_id`) → `ValidationException` (`date_from`).
- `MyAttendanceController::home()`: thêm prop `blocked` (lý do hoặc null). Với full-time nhiều cơ sở: chỉ khóa khi **mọi**
  cơ sở làm việc đều nghỉ (máy chủ vẫn kiểm tra lại theo cơ sở thực tế lúc bấm).
- `Pages/MyAttendance/CheckIn.vue`: `blocked` → `UiAlert` + khóa nút Chấm vào / Chấm ra.
- Truy vấn: 1 query `exists` trên `class_sessions` (đã có index theo `date`) + 1 query `holidays`. Không N+1.

### 3.2. Xác nhận theo lịch hàng loạt (B)

Service mới `App\Services\TeachingShifts\ScheduleConfirmation`:

```php
/** Ghi / duyệt ca của $staff cho buổi $session theo giờ lịch. Trả về lý do bỏ qua, hoặc null nếu đã ghi. */
public function confirm(ClassSession $session, User $staff, User $reviewer): ?string
```

- Quy tắc lấy nguyên từ `confirmScheduledSession`: buổi chưa hủy, đã tới ngày, ngoài kỳ lương khóa. Ca đã check-in / chấm
  tay → duyệt ca đó; chưa có (hoặc ca cũ bị từ chối) → tạo ca `SOURCE_SCHEDULE` hợp lệ với giờ theo lịch.
- Khác bản cũ: người được ghi công là `$staff` (người đang xem trên Bảng giờ dạy, phải thuộc `forStaff` của buổi), không mặc
  định `session.teacher_id`, để dạy thay / TA part-time cũng được.
- `confirmScheduledSession` chuyển sang gọi service (hành vi giữ nguyên, `$staff` = GV của buổi).

Route mới:

```
POST /payroll/timesheets/sessions/bulk-confirm   payroll.timesheets.sessions.bulk-confirm   can:attendance_staff.manual_record
body: user_id, session_ids[] (tối đa 100)
```

- Mỗi buổi phải thuộc `timesheetClasses()` của người thao tác (phạm vi chi nhánh), nếu không → 403.
- Tất cả trong một transaction. Buổi bị bỏ qua (khóa kỳ, tương lai, đã có công…) liệt kê trong thông báo, giống luồng check-in.
- Ghi audit (`activity`) một dòng cho cả lô: người thao tác, GV, số buổi.
- `Pages/Payroll/Timesheets/Teachers.vue` (khối buổi thiếu công): ô chọn từng buổi + chọn tất cả + nút gửi. Link chấm tay
  từng buổi giữ nguyên.

## 4. Kiểm thử

- Unit `punchBlockReason`: part-time có buổi chính / dạy thay / học bù / buổi hủy / không buổi; full-time ngày thường, nghỉ
  toàn hệ thống, nghỉ riêng cơ sở khác / cơ sở mình.
- Feature: `punch` bị chặn và báo lý do; đơn Bổ sung công bị chặn; trang chấm công có `blocked`.
- Feature batch: tạo ca hợp lệ cho các buổi chọn; bỏ qua buổi tương lai / kỳ khóa / đã có công; duyệt ca check-in đang
  chờ; 403 khi buổi ngoài chi nhánh hoặc thiếu quyền; xác nhận đơn lẻ cũ vẫn chạy (test sẵn có).
- Guard-rail: `AllRoutesSmokeTest`, `BranchIsolationRouteSweepTest`, `RbacNoHardcodedRolesTest`. Sau khi thêm route chạy
  `ziggy:generate`.
- v2: khu vực `cham-cong` trong `v2/doi-chieu-laravel.txt` sẽ bị cảnh báo parity (không chặn), cần port sau.

## 5. Rủi ro

- **Part-time chưa phân công đúng trên buổi** (dạy thay chưa đổi GV của buổi) sẽ bị khóa chấm công ngày. Cách xử lý: Học vụ
  sửa GV của buổi hoặc nhân sự gửi đơn; thông báo lỗi ghi rõ.
- **Xếp loại part-time theo vai trò / hợp đồng**: TA chưa nhập hợp đồng và lương cơ bản = 0 bị coi là part-time (đúng
  quy tắc lương hiện tại).
- **Buổi kèm 1-1 (`SupportSession`) không được tính là có giờ dạy** (chốt 22/10/2026).

## 6. Kế hoạch triển khai

Thực hiện trực tiếp trong phiên (chủ dự án yêu cầu "làm tiếp" 23/10/2026), TDD từng task, review cả nhánh ở cuối.

**Sprint 1 — quy tắc ngày chấm công**

1. Test `MyAttendanceTest` (viết trước, phải fail):
   - `test_part_time_teacher_can_only_check_in_on_days_with_sessions`: part-time không buổi → lỗi `kind` chứa "không có buổi dạy";
     có buổi dạy thay (`teacher_id` của buổi) / học bù (`TYPE_MAKEUP`) / là TA của buổi → chấm được; buổi `cancelled` → vẫn chặn.
   - `test_full_time_staff_cannot_check_in_on_holidays`: nghỉ toàn hệ thống → chặn ("ngày nghỉ"); nghỉ riêng cơ sở khác → chấm được.
   - `test_check_in_page_shows_why_punching_is_blocked`: prop `blocked` có lý do; ngày bình thường `blocked` = null.
   - `test_correction_request_is_blocked_on_days_without_work`: đơn Bổ sung công của part-time cho ngày không buổi → lỗi `date_from`.
   - Sửa `test_teacher_without_branch_checks_in_at_any_branch`: thêm một buổi dạy hôm đó cho GV part-time.
2. `StaffAttendanceService::punchBlockReason(User $user, ?Branch $branch, CarbonInterface $date): ?string`
   (inject `PayrollFormulaService`, `SessionScheduleService`). Part-time = `profile($user)['employee_type'] === PayrollRecord::TYPE_PARTTIME`.
   Gọi trong `punch()` (cơ sở gần nhất) và `submit()` cho `TYPE_CORRECTION` (cơ sở chính).
3. `MyAttendanceController::home()` thêm prop `blocked` (full-time nhiều cơ sở: chỉ khóa khi mọi cơ sở làm việc đều nghỉ);
   `CheckIn.vue`: `blocker` trả về `props.blocked` sau các lý do sẵn có.
4. Chạy `MyAttendanceTest`, `TeachingShiftTest`, `DemoPayrollSeederTest` (nếu có) → pass → commit.

**Sprint 2 — xác nhận theo lịch hàng loạt**

1. Test `Phase3TimesheetTest`:
   - `test_bulk_confirm_creates_valid_schedule_timesheets_for_selected_sessions`: tạo ca `SOURCE_SCHEDULE` `valid` cho
     buổi đã chọn, `user_id` = GV đang xem (kể cả buổi dạy thay); bỏ qua buổi tương lai / kỳ lương khóa / đã có công (thông báo liệt kê).
   - `test_bulk_confirm_approves_pending_checkin_instead_of_duplicating`.
   - `test_bulk_confirm_requires_manual_record_permission_and_branch_scope`: thiếu quyền → 403; buổi lớp chi nhánh khác → 403.
2. `App\Services\TeachingShifts\ScheduleConfirmation::confirm(ClassSession $session, User $staff, User $reviewer): ?string`
   (trả lý do bỏ qua). `PayrollController::confirmScheduledSession` gọi service với `$staff` = GV của buổi.
3. Route `POST payroll/timesheets/sessions/bulk-confirm` → `PayrollController::bulkConfirmSessions`
   (`can:attendance_staff.manual_record`, `user_id`, `session_ids` tối đa 100), transaction, 1 dòng `activity` cho cả lô;
   `ziggy:generate`.
4. `Pages/Payroll/Timesheets/Teachers.vue` khối "buổi thiếu công": ô chọn + chọn tất cả + nút "Xác nhận theo lịch (N buổi)".
5. Chạy `Phase3TimesheetTest`, `AllRoutesSmokeTest`, `BranchIsolationRouteSweepTest` → pass → commit, push, MR.

## 7. Sprint

| Sprint | Nội dung | Trạng thái |
|---|---|---|
| 1 | `punchBlockReason` + áp vào chấm công / đơn Bổ sung công + khóa nút trên màn Chấm công, kèm test | **Xong** (23/10) |
| 2 | `ScheduleConfirmation` service + refactor `confirmScheduledSession` + route / UI xác nhận hàng loạt, kèm test | **Xong** (23/10) |
