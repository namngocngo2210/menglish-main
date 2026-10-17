# Đối chiếu: KPI nhân sự (tiêu chí, phiếu KPI tháng / quý, KPI của tôi, rà soát điểm danh, bảng KPI tự động)

Nguồn Laravel: `app/Http/Controllers/KpiController.php`, `app/Services/Kpi/KpiSheetService.php`,
`app/Services/Kpi/KpiAutoCounter.php`, `app/Services/KpiBoardService.php`, `WorkTaskController::kpiDashboard`,
`app/Models/{KpiCriterion,KpiEvaluation,KpiEvaluationItem}.php`, `app/Console/Commands/CreateKpiSheetsCommand.php`,
`routes/web.php` (nhóm `kpi.*`, `tasks.kpi-dashboard`), `resources/js/Pages/Kpi/*.vue`, `resources/js/Pages/Tasks/KpiDashboard.vue`,
các migration `*kpi*`. Đối chiếu tại commit `20f88811`.

v2: bảng `hr.kpi_criteria`, `hr.kpi_sheets`, `hr.kpi_items` (migration `00131_hr_kpi.sql`, gồm bộ tiêu chí mẫu Học vụ / GV
part-time / Học thuật như các migration seed của Laravel), Go `v2/api/internal/kpi` (+ `kpi/kpisrc` dùng chung cho nguồn số
liệu), web `v2/web/src/features/kpi`. Route dưới `/api/v1/kpi`, mỗi route bọc `iam.Require` / `iam.RequireAny` đúng quyền của
route Laravel.

Trạng thái: **Đã làm** · **Khác** (đã làm, có khác biệt có chủ đích, xem ghi chú) · **Làm một phần** · **Chưa làm**.

## Màn và route

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `kpi.criteria` GET `/kpi/criteria` (`can:kpi.view`) | `GET /api/v1/kpi/criteria?role=` · màn `/kpi/criteria` (Cài đặt → Tổ chức & Đào tạo → Tiêu chí KPI) | Đã làm | Chọn vai trò (7 vai trò KPI, mặc định Học vụ), nhóm tiêu chí với số tiêu chí / trọng số / quỹ đang áp dụng, cảnh báo tổng trọng số ≠ 100%, quỹ KPI Học vụ (`payroll_academic_kpi_fund`, mặc định 2.000.000 đ), chu kỳ chấm, xếp loại A–E, thưởng KPI tối đa theo xếp loại. Tiêu chí có nguồn tự động chưa chuyển sang v2 hiện dòng "Chờ phân hệ … chuyển sang hệ thống mới: người chấm điền tay số liệu." Thêm / sửa / xóa chỉ hiện khi có `kpi.manage`. |
| `kpi.criteria.store` POST (`can:kpi.manage`) | `POST /api/v1/kpi/criteria` | Đã làm | Kiểm dữ liệu và thông báo như `criterionRules` + `normalizeCriterion` (nhóm mới thay nhóm chọn, cách đo, đơn vị, tối đa 8 bậc, ngưỡng 0–9999, % điểm 0–100, tỉ lệ thẳng 0,01–1000%, nguồn số liệu, loại trừ → bậc [0,100], đếm đúng 2 bậc 100/50 → `max_full` / `max_half` + nhãn ngưỡng). Ghi nhật ký `kpi.criteria.create`. |
| `kpi.criteria.update` PUT | `PUT /api/v1/kpi/criteria/{id}` | Đã làm | Gồm "Đang áp dụng". Nhật ký ghi phần thay đổi. |
| `kpi.criteria.destroy` DELETE | `DELETE /api/v1/kpi/criteria/{id}` | Đã làm | Xóa mềm như `SoftDeletes`; phiếu đã duyệt vẫn giữ dòng của tiêu chí cũ. |
| `kpi.criteria.cycle` POST | `POST /api/v1/kpi/criteria/cycle` | Đã làm | Chu kỳ 1 / 3 tháng (`kpi_cycle_<role>`), thưởng theo xếp loại 0–1.000.000.000 đ (`kpi_grade_fund_<role>`, null / 0 = không thưởng). Lưu ở `core.settings` thay cho `system_settings`. |
| `kpi.monthly` GET (`can:kpi.view`) | `GET /api/v1/kpi/monthly?period=&role=&branch_id=&page=` · màn `/kpi/monthly` (Nhân sự & KPI → Phiếu KPI tháng) | Đã làm | 20 nhân sự / trang, kỳ lương 12 tháng gần nhất, lọc vai trò / cơ sở trong phạm vi `kpi` (của tôi / chi nhánh / toàn hệ thống). Học thuật kiêm nhiệm giảng dạy có thêm dòng "KPI giảng dạy (kiêm nhiệm)". Phiếu quý hiện ở cả 3 tháng, cột thưởng ghi "Loại X · …". Bấm dòng mở phiếu trong hộp thoại. |
| `kpi.evaluate` GET `/kpi/evaluate/{userId}` | `GET /api/v1/kpi/evaluate/{userId}?period=&track=` · hộp thoại phiếu | Đã làm | Như `Kpi/Evaluate.vue`: số hệ thống + bản ghi (gạch dòng bản ghi không tính), ô điền tay, "Không phát sinh", gợi ý số lần từ Nhật ký Học vụ theo mã KPI ("Điền N"), mức đạt / tiền thưởng / xếp loại tính ngay khi điền (`features/kpi/logic.ts` cùng công thức `levelFor`), điều kiện loại trừ → tổng 0, điều kiện chặn (order giao sau giờ dùng) → mục tối đa 50%, phiếu tối đa loại B. Nhân sự ngoài phạm vi → 404. |
| `kpi.evaluate.store` POST (`can:kpi.confirm`) | `POST /api/v1/kpi/evaluate/{userId}` | Khác | Chỉ còn **Duyệt** / **Không duyệt** như luồng phiếu hiện tại (`decideSheet`); bỏ nhánh `draft` / `confirm` của phiếu đánh giá cũ (`KpiEvaluation` trước khi có phiếu tháng). Duyệt cần đủ số liệu và từ ngày cuối kỳ ("Duyệt KPI từ ngày cuối tháng dd/mm." / "…quý…"); Không duyệt bắt buộc lý do ≤ 1000 ký tự; không tự chấm mình (403 `self_evaluation`); phiếu đã duyệt không sửa được. Số tự động chốt vào `hr.kpi_items.evidence` lúc duyệt. Kỳ lương đã chốt khóa phiếu qua `kpi.PayrollLock` — phân hệ Lương chưa có nên chưa kỳ nào khóa (`TODO(gộp)`). Bỏ ghi chú "đã phạt" của Laravel (chờ phân hệ Lương & Phạt). |
| `kpi.mine` GET (`can:view-own-kpi`) | `GET /api/v1/kpi/mine?period=&track=` · màn `/kpi/mine` (Của tôi → KPI của tôi) | Khác | Như `Kpi/Mine.vue` (tiền / tỉ lệ, xếp loại, thưởng, trạng thái, lý do không duyệt, bản ghi từng tiêu chí, chọn kỳ tháng / quý, phiếu giảng dạy). Route bọc `RequireAny("notification.view", "kpi.view")` (mọi nhân sự đăng nhập); vai trò không có tiêu chí → 403 `no_kpi`, màn hiện "Bạn chưa có tiêu chí KPI" (Laravel chặn bằng Gate `view-own-kpi`). Menu hiện khi người dùng có vai trò KPI. |
| `kpi.attendance-review` GET (`can:kpi.view`) | `GET /api/v1/kpi/attendance-review?date=&class_id=` · màn `/kpi/attendance-review` (Nhân sự & KPI → Rà soát điểm danh) | Đã làm | Điểm danh theo ngày / lớp trong phạm vi Lớp học của người xem (`classes.KpiAttendance`), thống kê có mặt / muộn / vắng / có phép. |
| `kpi.attendance-review.update` POST (`can:kpi.confirm`) | `POST /api/v1/kpi/attendance-review/{id}` | Khác | Duyệt / Trả lại, ghi người / giờ rà soát và nhật ký (`student_attendance`). Lượt ngoài phạm vi → 404. Laravel cập nhật thêm số buổi đã học của học viên; v2 không có cột này (số buổi tính từ điểm danh khi cần). |
| `tasks.kpi-dashboard` GET (`WorkTaskController::kpiDashboard`, `can:work_task.view`) | `GET /api/v1/kpi/board?month=&month_to=&user_id=&page=` · màn `/tasks/kpi-dashboard` (Nhân sự & KPI → KPI tự động) | Khác | Như `KpiBoardService`: giáo viên / trợ giảng / Học vụ đang làm, phạm vi Công việc (Của tôi → chỉ mình, Chi nhánh → nhân sự có chi nhánh trong phạm vi), kỳ từ tháng → đến tháng (đảo nếu ngược), giữ chân / chuyên cần (bỏ lượt bị trả lại) / công việc. Số lớp + điểm danh qua `classes.KpiBoard`, công việc qua `tasks.KpiBoard`. **Tỉ lệ bài tập** qua `teacher.KpiBoard` (`kpi.WithHomework`). Menu neo `work_task.create` như Laravel. |
| `tasks.kpi-dashboard?export=1` | `GET /api/v1/kpi/board/export` | Khác | CSV UTF-8 có BOM `kpi-YYYY-MM[-YYYY-MM].csv`, cùng cột với file Excel của Laravel (thay `.xlsx`). |

## Dịch vụ, lệnh nền, số liệu tự động

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `kpi:create-sheets` (00:20 hằng ngày) → `KpiSheetService::ensureSheets` | `kpi.Service.EnsureSheets` + `RunSheetLoop` (mỗi giờ, trong `cmd/menglish`) | Khác | Tạo phiếu "Chờ duyệt" cho nhân sự có vai trò đang có tiêu chí (phiếu quý ở tháng đầu quý, phiếu giảng dạy cho Học thuật kiêm nhiệm), chạy lại không tạo trùng. Chạy theo vòng lặp trong tiến trình thay cho scheduler (`TODO(gộp)` chuyển sang bộ lập lịch chung). |
| `KpiSheetService::sheet` (mức đạt, tổng, loại trừ, xếp loại, thưởng, quý tính từng tháng) | `kpi.buildSheet` | Đã làm | Test đơn vị theo các ví dụ của Laravel (`model_test.go`). Phiếu đã duyệt dùng số đã chốt, giữ tiêu chí đã xóa / tắt. |
| `KpiAutoCounter` — `daily_report_late` | `reports.Service.KpiSources` | Đã làm | Báo cáo ngày nộp sau 09:00 hôm sau + ngày phải nộp mà không nộp (Học vụ / Học thuật). |
| `monthly_report_on_time` | `reports.Service.KpiSources` | Đã làm | Tỉ lệ tháng nộp báo cáo tháng trước 12:00 ngày mùng 2; tháng chưa tới hạn chưa tính. |
| `care_overdue` | `tasks.Service.KpiSources` | Đã làm | Mốc chăm sóc tháng đầu quá hạn ("Buổi 1 / Buổi 4–5 / Đủ 30 ngày"). |
| `task_on_time` | `tasks.Service.KpiSources` | Đã làm | Hệ số trễ theo giờ (đúng hạn 1; ≤ 4 giờ 0,5; ≤ 24 giờ 0,25; hơn 0); ít hơn 3 việc thì gộp thêm tháng trước như `factorRate`. |
| `material_stock` | `inventory.Service.KpiSources` | Đã làm | Order học liệu xử lý trễ hạn + kiểm kê lệch. |
| `academic_order_on_time` | `inventory.Service.KpiSources` | Đã làm | Hệ số theo giờ còn lại trước giờ dùng (≥ 24 giờ 1; 12–24 giờ 0,5; 6–12 giờ 0,25); giao sau giờ dùng / quá giờ dùng chưa giao = 0 và là điều kiện chặn; order tạo sát giờ dùng không tính. |
| `class_attendance_rate` | `classes.Service.KpiSources` | Khác | Chuyên cần các lớp giáo viên giữ trong kỳ, trung bình theo sĩ số; bản ghi ghi "Lớp X: y% (n học sinh)". |
| `tuition_no_reminder`, `receipt_rejected` | — | Chưa làm | Chờ phân hệ Học phí; tiêu chí dùng nguồn này người chấm điền tay (ghi chú "Chờ phân hệ Học phí…"). |
| `crm_sla_late` | — | Chưa làm | Chờ SLA / phạt CRM. Điền tay. |
| `teacher_absent_unexcused`, `teacher_leave`, `teacher_late` | — | Chưa làm | Chờ Cổng giáo viên (giờ dạy, đơn nghỉ). Điền tay. |
| `staff_late` | — | Chưa làm | Chờ Chấm công. Điền tay. |
| `test_result`, `test_progress`, `student_pass_rate` | — | Chưa làm | Chờ Big Test / mini test. Điền tay (tỉ lệ %). |
| `homework_rate` | `teacher.Service.KpiSources` | Đã làm | Bài nộp ÷ (bài giao có hạn trong kỳ × sĩ số) các lớp giáo viên giữ, trung bình theo sĩ số (xem `cong-giao-vien.md`). |
| `academic_deliverable_on_time` | `projects.Service.KpiSources()` | Đã làm | Mốc dự án học thuật đến hạn trong kỳ, hệ số trễ theo ngày như `deliverableRows` (xem [chat-luong-lop.md](chat-luong-lop.md)). |
| `WeeklyReportMetrics::kpiGroups` (bảng KPI báo cáo tuần, mã KPI nhật ký) | `kpi.ReportMetrics` bọc `reports.MetricsProvider` (`KpiCriteria`, `KpiEvidence`) | Đã làm | Mục đếm số lần có nguồn ở v2 là "tự động" (đếm cùng cách phiếu KPI); còn lại báo cáo tuần đếm sự vụ nhật ký theo mã. |
| Nhật ký Học vụ gắn mã KPI → gợi ý số lần trên phiếu | `kpi.Journal` = `reports.Service.KpiJournal` | Đã làm | |
| `PayrollFormulaService` / `PayrollController::kpiLeaderboard` đọc phiếu KPI | — | Chưa làm | Thuộc phân hệ Lương. |
