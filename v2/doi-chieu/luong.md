# Đối chiếu: Lương & Phạt (bảng lương theo kỳ, phiếu lương, Lương của tôi, xếp hạng KPI & Thưởng, cấu hình lương, vi phạm & phạt)

Nguồn Laravel: `app/Http/Controllers/PayrollController.php` (trừ các màn `payroll.timesheets.*` thuộc phân hệ Chấm công),
`app/Http/Controllers/PenaltyController.php`, `app/Models/{PayrollPeriod,PayrollRecord,Penalty,CommissionItem,CommissionAdjustment,CommissionTier,TeacherHourlyRate,TeacherRate}.php`,
`app/Services/{PayrollFormulaService,SalesCommissionService,TeachingShareService}.php`,
`app/Console/Commands/{RemindPayrollCalendarCommand,RemindPenaltyDueCommand}.php`, `routes/web.php` (nhóm `payroll.*`,
`penalties.*`, `portal.my-salary`), `routes/console.php`, `resources/js/Pages/Payroll/*.vue`, `resources/js/Pages/Payroll/Config/*.vue`,
`resources/js/Pages/Penalties/Index.vue`, các migration `*payroll*` / `*penalt*` / `*commission*` / `*teacher_hourly_rates*`.
Đối chiếu tại commit `20f88811`.

v2: bảng `hr.payroll_periods`, `hr.payroll_records`, `hr.teacher_rates`, `hr.teacher_hourly_rates`, `hr.commission_tiers`,
`hr.commission_items`, `hr.commission_adjustments` (migration `00180_hr_payroll.sql`, gồm 3 mốc hoa hồng mặc định
0–5 HS 3% / 6–10 HS 4% / từ HS 11 5%) và `hr.penalties` (`00181_hr_penalties.sql`). Go `v2/api/internal/payroll`, web
`v2/web/src/features/payroll`. Route đăng ký đường dẫn đầy đủ (không `Route("/payroll")`) để phân hệ Chấm công gắn
`/payroll/timesheets/*` song song; mỗi route bọc `iam.Require` / `iam.RequireAny` đúng quyền của route Laravel.

Trạng thái: **Đã làm** · **Khác** (đã làm, có khác biệt có chủ đích, xem ghi chú) · **Làm một phần** · **Chưa làm**.

## Kỳ lương và phiếu lương

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `payroll.periods.index` GET `/payroll/periods` (`can:payroll.view`) | `GET /api/v1/payroll/periods?search=&status=&page=` · màn `/payroll/periods` (Lương & Phạt → Bảng lương theo kỳ) | Đã làm | 12 kỳ / trang, tìm theo mã / tên, lọc trạng thái, số nhân sự / giờ dạy / tổng chi, tháng / năm mặc định cho kỳ mới. |
| `payroll.periods.store` POST (`can:payroll.create`) | `POST /api/v1/payroll/periods` (201 `{id, message}`) · hộp thoại "Khởi tạo kỳ lương" | Đã làm | Kiểm tháng 1–12, năm 2025–2100, trùng kỳ ("Đã tồn tại bảng lương cho tháng m/y."); mã `PR-Y-MM`, tên "Bảng lương Tháng m/y"; tạo xong tính ngay trong cùng transaction. |
| `payroll.periods.show` GET `/payroll/periods/{id}` | `GET /api/v1/payroll/periods/{id}?search=&type=&kpi=&page=` · màn `/payroll/periods/<id>` | Khác | Lịch chốt (KPI cuối tháng, công + lỗi cuối tháng + N ngày theo SLA `payroll.close_after`, trả lương 10–15 tháng sau), bước tiếp theo, cảnh báo còn người chưa chốt KPI, nhân sự chưa có phiếu (chỉ người xem toàn hệ thống), thống kê, lọc loại nhân sự / trạng thái KPI, 20 phiếu / trang, sửa nhanh "Buổi GVNN". Phạm vi xem theo DataScope `payroll` (của tôi / chi nhánh / toàn hệ thống). Khác: chỉ nhận id kỳ (uuid), không nhận mã kỳ như Laravel. |
| `payroll.periods.fulltime` / `academic` / `operations` GET | `GET /api/v1/payroll/periods/{id}/departments/{fulltime\|academic\|operations}` · màn `/payroll/periods/<id>/departments/<khối>` | Khác | Bảng lương theo khối + tổng. v2 chưa có cột "khối" của hồ sơ nhân sự: nhân sự khác vai trò GV / Học thuật mặc định khối Vận hành. |
| `payroll.periods.export` GET (xlsx) | `GET /api/v1/payroll/periods/{id}/export?department=` | Khác | CSV UTF-8 có BOM (mở thẳng bằng Excel) thay cho .xlsx, cùng cột với bản Laravel; tên file `bang-luong-pr-y-mm.csv`. |
| `payroll.periods.calculate` POST (`can:payroll.calculate`) | `POST /api/v1/payroll/periods/{id}/calculate` | Đã làm | "Đồng bộ & Tính lại": khóa dòng kỳ, kỳ đã duyệt / đã chi trả không tính lại; gỡ và gắn lại biên bản phạt, khoản thu hồi, khoản hoa hồng; giữ khoản nhập tay. Mốc "đã tính lúc" dùng giờ của DB. |
| `payroll.periods.approve` POST (`can:payroll.approve`) | `POST /api/v1/payroll/periods/{id}/approve` | Đã làm | Chặn theo thứ tự: chưa tới ngày chốt, dữ liệu đổi sau lần tính (chấm công, biên bản, đơn giá, tham số, KPI, phiếu thu, chăm sóc tháng đầu, lương cơ bản), còn người chưa chốt KPI. Duyệt: phiếu → confirmed, biên bản → deducted, chốt sổ khoản thu hồi và hoa hồng, thông báo "Phiếu lương … đã được duyệt" tới từng người (`payroll_approved`), nhật ký `payroll_period.approve`. |
| `payroll.periods.mark-paid` POST (`can:payroll.mark_paid`) | `POST /api/v1/payroll/periods/{id}/mark-paid` | Đã làm | Chỉ kỳ đã duyệt; ngoài khung 10–15 tháng sau chỉ cảnh báo (`warning`). |
| `payroll.records.show` GET `/payroll/records/{id}` | `GET /api/v1/payroll/records/{id}` · màn `/payroll/records/<id>` | Khác | 4 mẫu phiếu (GV Part-time, GV Full-time, Học vụ, Học thuật; tư vấn / nhân sự khác dùng mẫu Full-time), khoản cộng / trừ do server dựng, buổi dạy hợp lệ, đi muộn, KPI Học vụ theo nhóm, mốc hoa hồng đang áp dụng, khoản trả / hoãn, tái tục, phạt, thu hồi. Ngoài phạm vi → 404. Khác: "In phiếu lương / Xuất PDF" dùng bản in của trình duyệt (không có bản in Blade `printHtml`); chưa có liên kết phiếu KPI giảng dạy. |
| `payroll.records.adjust` POST (`can:payroll.edit`) | `POST /api/v1/payroll/records/{id}/adjust` | Đã làm | Bậc giữ HS (15.000 / 20.000 / 25.000 đ), KPI nhập tự do, buổi có GVNN, thuế TNCN, khoản cộng / trừ tự do (bắt buộc tên), ghi chú; tính lại thực lĩnh, nhật ký đổi giá trị. Kỳ đã khóa → 422. |
| `payroll.records.update` POST (`can:payroll.edit`) | `POST /api/v1/payroll/records/{id}/update` | Đã làm | Lương buổi có GVNN (chờ BA chốt) + ghi chú. |
| Công thức Q3 (`PayrollFormulaService`) | `calc.go`, `formula.go` | Đã làm | Part-time: buổi × đơn giá buổi / giờ riêng (phiên bản hiệu lực) hoặc giờ × đơn giá hồ sơ / mặc định 250.000 đ, quy tắc đi muộn (báo trước → theo phút dạy; dưới ngưỡng → trừ phút × đơn giá; từ ngưỡng → không tính buổi), giữ HS × bậc, buổi GVNN; không trừ BH / CĐ / thuế. Full-time: lương cơ bản + KPI (Học vụ tự động theo quỹ × điểm phiếu KPI đã duyệt; Admin chỉ xem %; còn lại nhập tay) + hoa hồng + tái tục + khoản tự do − BHXH − Công đoàn − thuế − phạt − thu hồi − khấu trừ. Học thuật kiêm giảng dạy: % học phí buổi (`TeachingShareService`) + KPI giảng dạy. Thực lĩnh ≥ 0. |
| Phiếu trước Q3 (công thức cũ) | | Làm một phần | Phiếu cũ vẫn hiện dòng cộng / trừ theo cột đã lưu; không tính lại theo công thức cũ, không có danh sách `commissionReceipts` của phiếu cũ, mốc hoa hồng theo doanh thu (bản cũ) không chuyển. |
| Thưởng tái tục (`renewalFor`) | `calc.go` | Đã làm | Theo số HS nghỉ của lớp GV chính (bảng mốc + % ngoài bảng, mốc "chờ BA chốt" = 0). |
| Làm tròn | | Khác | Tiền làm tròn tới đồng ở từng khoản. |

## Hoa hồng tuyển sinh, thu chi, KPI, tổng quan

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `SalesCommissionService::commissionableReceipts` / `syncItemsForPeriod` | `commission.go` (`hr.commission_items`) | Khác | Phiếu thu đã duyệt, số dương, không phải XFER-IN, thuộc khoản học phí đầu tiên của học viên chốt từ khách CRM (người nhận = `commission_user_id` hoặc người phụ trách); % theo mốc chứa thứ tự chốt trong tháng, mốc hiệu lực tại cuối kỳ; căn cứ bỏ phụ thu và phần phí khác. Khác: ngày chốt lấy `crm.leads.converted_at` (Laravel lấy ngày chốt của FirstMonthCare). |
| Gate kép (`gateFor`, `resolveForPeriod`) | `gateOf`, `resolveItems` | Đã làm | Đủ 30 ngày từ ngày chốt và 3 mốc chăm sóc tháng đầu (checklist CRM hoặc việc chăm sóc đã xong); chưa đủ → hoãn kèm lý do, trả ở kỳ sau. |
| Thu hồi khi duyệt hoàn phí (`suggestedClawbackAmount`, `recordRefundDecision`) | `tuition.Commissions` = `payroll.Service.Commissions()` | Đã làm | Gợi ý min(tiền hoàn, căn cứ đã trả) × % khoản đã trả gần nhất; duyệt có thu hồi → hủy khoản còn hoãn, tạo khoản điều chỉnh âm trừ ở kỳ kế tiếp. |
| Thu hồi khi hủy hóa đơn (`recordCancellationClawback`) | `Commissions.CancellationClawback` | Đã làm | Khoản chưa trả → hủy; đã trả trong kỳ đã duyệt → khoản thu hồi kỳ sau, trả về số tiền / người hưởng cho thông báo của Học phí. |
| Dòng "chi lương tự động" của Thu - Chi | `finance.PayrollSource` = `payroll.Service.Finance()` | Đã làm | Tổng thực lĩnh kỳ lương đã duyệt / đã chi trả của tháng, lọc chi nhánh chính của nhân sự. |
| Khóa phiếu KPI theo kỳ lương | `kpi.PayrollLock` = `payroll.Service.Locked` | Đã làm | Ngày thuộc kỳ đã duyệt / đã chi trả. |
| Tổng quan: "Kỳ lương chưa chốt" | `dashboard.Payroll` = `payroll.Service.OpenPeriods` | Đã làm | Hàng đợi hiện khi có `payroll.view`. |

## Lương của tôi, xếp hạng KPI & Thưởng

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `portal.my-salary` GET `/portal/my-salary?period_id=` | `GET /api/v1/portal/my-salary?period_id=` · màn `/portal/my-salary` (Của tôi → Lương của tôi) | Khác | Chỉ kỳ đã duyệt / đã chi trả; so với kỳ trước, khoản cộng (cột số lượng) / trừ, biên bản trừ vào kỳ, buổi dạy hợp lệ (lọc lớp). Kỳ không có phiếu → 404. Route `RequireAny(payroll.view_own, portal.staff, payroll.view)`. Khác: "Tải phiếu lương" dùng bản in của trình duyệt; biên bản không ghi lớp. |
| `payroll.kpi-leaderboard` GET (`can:kpi.view`) | `GET /api/v1/payroll/kpi-leaderboard?period=&month=&year=&branch_id=&kpi_page=&sales_page=` · màn `/payroll/kpi-leaderboard` (Nhân sự & KPI) | Khác | Giữ HS (GV Part-time) và hoa hồng tư vấn (mốc, còn bao nhiêu HS lên mốc, thực thu, hoa hồng), 20 dòng / bảng / trang. Phạm vi `kpi.scope_*` (rà soát 19/10/2026, `kpiScopedStaff`): Của tôi = chính mình, Chi nhánh = mình + nhân sự chi nhánh mình, ô chi nhánh chỉ chi nhánh trong phạm vi; số tiền (bậc, thưởng KPI, thực thu, hoa hồng) chỉ người có `payroll.view` thấy (`show_money`). Khác: lọc chi nhánh theo chi nhánh chính của người nhận (nhân sự nhiều chi nhánh tính ở chi nhánh đầu). |

## Cấu hình lương

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `payroll.config.settings` GET / POST (`can:teacher_rate.manage`) | `GET/POST /api/v1/payroll/config/settings` · Cài đặt → Lương → Tham số lương | Đã làm | BHXH / Công đoàn 0–100%, quỹ KPI Học vụ, bảng thưởng tái tục, % ngoài bảng, ngưỡng đi muộn 1–120 phút, trừ 4.000–5.000 đ/phút, % học phí kiêm nhiệm; lưu ở `core.settings` (khóa `payroll_*`), nhật ký đổi giá trị. |
| `payroll.config.teacher-rates` GET / POST | `GET /api/v1/payroll/config/teacher-rates?teacher_id=&page=`, `POST …/teacher-rates` · Cài đặt → Lương → Đơn giá giáo viên | Đã làm | Bảng đơn giá theo cấp bậc (tối đa 50), danh sách GV + đơn giá hiện hành, lịch sử phiên bản. Phạm vi `attendance_staff` (`staffInScope`, rà soát 19/10/2026): danh sách GV / lịch sử chỉ nhân sự trong phạm vi, `?teacher_id=` ngoài phạm vi → 404. |
| `payroll.config.teacher-rates.personal` POST | `POST /api/v1/payroll/config/teacher-rates/personal` | Đã làm | Phiên bản đơn giá riêng (đ/buổi, đ/giờ, % học phí 0,1–100), loại GV, ngày hiệu lực không trùng, không ghi vào ngày thuộc kỳ đã khóa. Nhân sự ngoài phạm vi `attendance_staff` (kể cả id không tồn tại) → 404 như `storePersonalTeacherRate`. |
| `payroll.config.commission-tiers` GET / POST / PUT / DELETE, `config.renewal.store` POST (`can:commission_config.manage`) | `GET/POST /api/v1/payroll/config/commission-tiers`, `PUT/DELETE …/{id}`, `POST /api/v1/payroll/config/renewal-bonus` · Cài đặt → Lương → Mốc hoa hồng / Thưởng | Đã làm | Mốc theo thứ tự HS chốt, phiên bản hiệu lực (sửa = phiên bản mới từ ngày sau, kỳ trước giữ mốc cũ), xóa mốc chưa hiệu lực / ngừng mốc đang áp dụng từ hôm nay, xem mốc tại ngày, bảng thưởng tái tục. |

## Vi phạm & Phạt

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `penalties.index` GET `/penalties` | `GET /api/v1/penalties?search=&status=&error_category=&step=&from=&to=&page=` · màn `/penalties` (Lương & Phạt → Vi phạm & Phạt) | Đã làm | Phạm vi `violation.scope_*` (`Penalty::constrainToViewer`, rà soát 19/10/2026): Toàn hệ thống xem mọi biên bản, Chi nhánh = biên bản mình vi phạm / mình lập + nhân sự vi phạm thuộc chi nhánh mình, Của tôi = mình vi phạm / mình lập; không có `violation.view` (nhân sự `portal.staff`) chỉ biên bản của mình; biên bản ngoài phạm vi → 404 ở mọi thao tác; ô chọn nhân sự / lớp chỉ trong chi nhánh (`staffVisibleTo`, `classesFor`); thẻ đếm (chờ giải trình, chờ chốt, đã chốt phạt, quá hạn), lọc theo bước / loại lỗi / trạng thái / ngày, nút theo bước tiếp theo, lỗi thường gặp theo vai trò. |
| `penalties.store` POST (`can:violation.create`) | `POST /api/v1/penalties` (multipart: trường + file `evidence`) | Đã làm | Bắt buộc bằng chứng ảnh / PDF ≤ 10MB (kho file), thời điểm vi phạm không ở tương lai và trong cửa sổ SLA `penalty.record_window` (mặc định 24h), loại lỗi theo lỗi thường gặp của vai trò (người chốt đi theo lỗi), mã `BB-YYYY-NNN`. Nhân sự / lớp ngoài phạm vi: "Nhân sự này không thuộc chi nhánh của bạn." / "Lớp này không thuộc chi nhánh của bạn." |
| `penalties.evidence` GET | Liên kết file của kho file (`evidence_url`) | Khác | Quyền xem: người vi phạm, người thấy biên bản theo phạm vi `violation` (ngoài phạm vi → 404). |
| `penalties.explain` POST | `POST /api/v1/penalties/{id}/explain` | Đã làm | Chỉ người vi phạm, ở bước chờ giải trình, 10–2000 ký tự. |
| `penalties.confirm` POST | `POST /api/v1/penalties/{id}/confirm` `{decision: fine\|error, amount, decision_note}` | Đã làm | Chốt lỗi / chốt mức phạt (≥ 1.000 đ, hạn nộp theo SLA `penalty.payment_due`, mặc định 2 ngày), đúng người chốt theo loại lỗi (HT chốt lỗi chuyên môn, CM chốt lỗi vận hành), không tự chốt biên bản của mình; quyết phạt gửi thông báo `penalty_fined`. |
| `penalties.mark-paid` / `resolve` / `cancel` / `remedy` POST | `POST /api/v1/penalties/{id}/mark-paid`, `/resolve`, `/cancel`, `/remedy` | Đã làm | Nộp trực tiếp chỉ khi còn hạn; đã vào vòng phạt không hủy được; biên bản đã trừ trong kỳ đã khóa không đổi được; khắc phục sau khi đã nộp / đã trừ. |
| Biên bản tự động của phân hệ khác (SLA chăm sóc, Big Test, CRM…) | `Service.CreateAutoPenalty`, `Service.AutoOccurrences`, `SyncLatePenalty`, `BreachSla`, `SyncBigTestLate` | Làm một phần | Đã nối (adapter ở `cmd/menglish/wiring.go`): đi muộn của Chấm công (lập / cập nhật / tự hủy), trễ mốc Dự án học thuật (`SlaBreachService::breach`: bậc tái phạm theo `LadderResetMonths`, báo người phụ trách + Admin), trả kết quả Big Test trễ của Giáo trình. Còn thiếu: SLA chăm sóc / CRM (máy SLA `sla_events` của CRM chưa có ở v2) và file PDF minh chứng của biên bản SLA. |

## Lệnh nền

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `penalties:remind-due` (08:30) | `Service.RunReminderLoop` → `RemindPenaltiesDue` | Đã làm | Hạn nộp hôm nay / ngày mai, 1 lần / biên bản / ngày (`notify.SendOnce`), loại `penalty_due_reminder`. |
| `payroll:remind-calendar` (08:35) | `Service.RunReminderLoop` → `RemindCalendar` | Đã làm | Ngày cuối tháng nhắc chốt KPI (`kpi.confirm`), các ngày chốt công / lỗi (`attendance_staff.view`, `violation.confirm_fine`, `payroll.approve`), ngày 10 tháng sau nhắc khung trả lương (`payroll.approve`, `payroll.mark_paid`); chống gửi trùng theo kỳ + mốc. |
| `payroll:seed-demo` | | Chưa làm | Lệnh dựng dữ liệu mẫu, không cần cho vận hành. |

## Nối với phân hệ khác

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| Ca dạy hợp lệ, chấm công hằng ngày, đơn nghỉ (`TeacherTimesheet`, `StaffAttendance`) | `payroll.Attendance` ← `payrollAttendance` (`WithAttendance` trong `main.go`) | Đã làm | Bảng lương đọc ca dạy hợp lệ (`ValidTimesheets`), ngày công / đi muộn / về sớm / thiếu giờ ra / nghỉ phép (`Summary`, tháng không có ngày công lẫn ngày nghỉ thì không ghi vào chi tiết) và cờ dữ liệu đổi sau khi tính (`ChangedSince`) của phân hệ Chấm công. |
| Cổng giáo viên: lương tạm tính, vi phạm tháng, khóa check-in theo kỳ lương (`teacher.Work`) | `Service.RateOn`, `Service.Violations` ← adapter `teacherWork`; khóa kỳ lương qua `attendance.PayrollLock` | Đã làm | Lương tạm tính = Σ giờ × đơn giá giờ hiệu lực ngày dạy; số biên bản vi phạm của tháng; check-in (ghi qua Chấm công) bỏ qua ngày thuộc kỳ lương đã khóa. |
| Học sinh có lớp hiện tại nhưng không có lượt xếp lớp | | Khác | Kiêm nhiệm giảng dạy chỉ tính học phí theo lượt xếp lớp (`academic.enrollments`). |
