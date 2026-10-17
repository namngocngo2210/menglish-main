# Đối chiếu: Tổng quan (Dashboard) và Báo cáo & sự vụ lớp

Nguồn Laravel: `app/Http/Controllers/DashboardController.php`, `app/Http/Controllers/AcademicDashboardController.php`,
`app/Support/Dashboard/MyWorkBoard.php`, `app/Support/Dashboard/TeachingQuality.php`, `app/Support/DailyReportDue.php`,
`resources/js/Pages/Dashboard.vue`, `resources/js/Pages/Dashboard/*.vue`, `resources/js/Components/Dashboard/BarList.vue`,
`resources/js/Pages/Academic/Reports.vue`, `resources/js/Pages/Academic/Incidents.vue`,
`resources/js/Components/Academic/SectionTabs.vue`, `resources/js/Components/Academic/RecordList.vue`, link "Tổng quan" và
mục "Báo cáo & sự vụ lớp" trong `app/Support/Navigation/SidebarMenu.php`. Đối chiếu tại commit `de426202` (dòng `tong-quan`
trong `../doi-chieu-laravel.txt`).

v2: Go `v2/api/internal/dashboard` (chỉ đọc, không có bảng riêng, không migration), web `v2/web/src/features/dashboard`.
Gói `dashboard` không đọc bảng của phân hệ khác: mỗi nguồn là một interface nhỏ trong `dashboard/sources.go`
(`Leads`, `Tasks`, `Classes`, `Students`, `Reports`, `Orders`, `Branches`, `Finance`, `StaffWork`, `Tickets`, `Projects`,
`Payroll`, `Syllabus`), do chính phân hệ cài
(`crm.DashboardLeads`, `tasks.DashboardTasks`, `classes.DashboardClasses`, `students.DashboardStudents`,
`reports.DashboardReports`, `inventory.DashboardOrders`, `iam.Service`, `tuition.DashboardFinance`,
`attendance.DashboardWork`, `tickets.DashboardTickets`, `projects.DashboardMilestones`, `payroll.Service`,
`syllabus.Dashboard`) với câu SQL trong `<phân hệ>/dashboard.sql`, nối
trong `cmd/menglish/main.go`. Phạm vi dữ liệu dùng đúng hàm phạm vi của phân hệ nguồn (CRM `scopeOf`, lớp `visible`,
chi nhánh của bảng điều hành theo `dashboard.scope_all`).

Trạng thái: **Đã làm** · **Khác** (đã làm, khác có chủ đích, xem ghi chú) · **Làm một phần** · **Chưa làm** (lý do kèm theo).

## Route và màn hình

| Laravel | v2 API + màn | Trạng thái | Ghi chú |
|---|---|---|---|
| `GET /` → chuyển về `dashboard` | Màn `/` | Khác | v2 hiện Tổng quan ngay ở `/` (không chuyển hướng). `/dashboard` (URL cũ) vẫn mở Tổng quan. |
| `GET /dashboard` `dashboard` → `DashboardController::__invoke` | `GET /api/v1/dashboard?month=YYYY-MM` · màn `/` (`DashboardPage`) | Đã làm | `iam.RequireAny(portal.staff, dashboard.operations, dashboard.academic)`: mọi nhân sự (quyền đối tượng `portal.staff`), học viên / phụ huynh nhận 403. Trả `is_operations`, `role_dashboard`, `daily_report`, `teaching` như props Inertia. |
| `isPortalStudentOnly()` → chuyển sang `portal.student.home` | `/` của học viên / phụ huynh là "Tài khoản của tôi" | Khác | v2 chưa có cổng học viên; khi có thì đổi `PAGES['/']` trong `routes.tsx`. |
| Trang tài khoản (trước đây ở `/` của v2) | Màn `/profile` | Khác | Menu "Tài khoản" trên topbar mở `/profile`; sidebar của học viên / phụ huynh có mục "Tài khoản của tôi". |
| `GET /academic/dashboards/reports` `academic.dashboards.reports` → `reports` | `GET /api/v1/academic/dashboards/reports?tab=` · màn `/academic/dashboards` (+ `/reports`) | Làm một phần | `iam.Require(class.update)` như `can:class.update`. Thiếu báo cáo trực lớp (xem bảng dưới). |
| `GET /academic/dashboards/incidents` `academic.dashboards.incidents` → `incidents` | `GET /api/v1/academic/dashboards/incidents?severity=&status=&branch_id=&class_id=` · màn `/academic/dashboards/incidents` | Làm một phần | `class.update`. Có ticket hỗ trợ khẩn / cao (xem `ticket.md`); thiếu nhật ký học vụ cũ (`academic_records`). |
| `GET /academic/reports` `academic.reports`, `GET /academic/incidents` `academic.incidents` (URL cũ) | Màn `/academic/reports`, `/academic/incidents` | Đã làm | Mở cùng màn, không có mục menu riêng. |
| SidebarMenu: link "Tổng quan" đầu sidebar | `NAV` `/` "Tổng quan" (icon `dashboard`) | Đã làm | Chỉ hiện với người mở được Tổng quan; người khác thấy "Tài khoản của tôi". |
| SidebarMenu: "Báo cáo & sự vụ lớp" (khu Báo cáo › Đào tạo & Chất lượng lớp, neo `class.update`) | `NAV` `/academic/dashboards` | Đã làm | Các mục còn lại của workspace (dự giờ, checklist, họp giáo viên, dự án học thuật) ở [chat-luong-lop.md](chat-luong-lop.md). |

## Tổng quan (`Dashboard.vue`)

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| Tiêu đề "Tổng quan", mô tả "Bảng điều hành toàn trung tâm" khi `dashboard.operations` | `DashboardPage` | Đã làm | |
| Lời chào (`welcome`): tên, vai trò rút gọn, chi nhánh, nút "Lương của tôi", "Nhiệm vụ hôm nay" | `welcomeOf(me)` dựng từ `/auth/me` | Khác | Dựng trên trình duyệt thay vì từ máy chủ. Nút "Lương của tôi" → `/portal/my-salary` khi có `payroll.view` / `payroll.view_own`; "Nhiệm vụ hôm nay" → `/portal/ta-tasks` khi có `work_task.view`. |
| Thứ tự khối: chất lượng giảng dạy lên đầu cho giáo viên / trợ giảng, sau bảng việc với vai trò khác | `DashboardPage` | Đã làm | |

## Băng "Báo cáo ngày" (`DashboardController::dailyReport`, `DailyReportDue`)

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| Chỉ người nộp báo cáo vận hành ngày (`DailyReportDue::appliesTo`) | `reports.DashboardReports().DailyDue` (theo `structuredKinds` của vai trò) | Đã làm | |
| Ngày còn thiếu trong 14 ngày trước (bỏ ngày nghỉ, ngày phép), hạn 09:00 sáng hôm sau, mới trước | `missingDays` của phân hệ Báo cáo, `daily_report.missing` | Đã làm | Dùng chung cách tính với màn Báo cáo ngày nên hai nơi không lệch. Hiện tối đa 5 ngày + "và N ngày khác"; hạn của ngày gần nhất khi chưa quá hạn. |
| Báo cáo Admin yêu cầu bổ sung kèm ghi chú | `daily_report.returned` | Đã làm | |
| "Hôm nay: đã nộp / chưa nộp (hạn 09:00 sáng mai)", nút "Nộp báo cáo <ngày>" / "Viết báo cáo hôm nay" | `today_submitted`, `today_href`, `bannerOf` | Đã làm | Link `/reports/daily?date=YYYY-MM-DD`. |

## Bảng điều hành Admin / Quản lý (`operationsDashboard`, `RoleWidgets.vue`)

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| Phạm vi: Admin toàn hệ thống (`dashboard.scope_all`), Quản lý theo chi nhánh của mình; nhãn phạm vi | `BranchLimit("dashboard")` | Đã làm | |
| Thẻ "Doanh thu tháng", "Phiếu thu chờ duyệt" | `Finance` = `tuition.Service.DashboardFinance()` | Đã làm | Doanh thu tháng và phiếu chờ duyệt đọc từ phiếu thu của Học phí. |
| Thẻ học viên đang học, lead mới trong tháng, lớp đang chạy, việc quá hạn | `Students`, `Leads`, `Classes`, `Tasks` | Đã làm | Việc gắn chi nhánh hoặc người nhận có chi nhánh chính thuộc phạm vi; nhiệm vụ hằng ngày của TA không tính quá hạn. |
| Hàng chờ: việc chờ xác nhận, hẹn test hôm nay (khi xem được khách) | `Tasks.Operations`, `Leads.Summary` | Đã làm | |
| Hàng chờ: báo cáo trực lớp chờ Admin xác nhận | — | Chưa làm | Báo cáo trực lớp (`ClassReport`) chưa có ở v2; hàng chờ ghi "Chờ phân hệ Báo cáo trực lớp". |
| Hàng chờ: ticket hỗ trợ đang mở | `dashboard.Tickets` ← `tickets.DashboardTickets()` | Đã làm | "Ticket đang mở", gợi ý "%d ticket chưa có người xử lý", link `/tickets`. |
| "Việc quá hạn cần xử lý" (5 việc), link danh sách `tasks.index?tab=assigned&status=overdue` | `overdue_tasks`, link `/tasks?tab=assigned&status=overdue` | Đã làm | Danh sách đầu việc đọc `?tab=` và `?status=` từ URL. Mỗi việc mở `/tasks/<id>`. |

## Bảng Học thuật (`academicDashboard`)

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| Thẻ lớp đang chạy trong phạm vi | `Classes.RunningVisible` | Đã làm | |
| Đề xuất giáo trình / giãn tiến độ chờ duyệt, Big Test 14 ngày tới (thẻ, hàng chờ, hai danh sách) | `Syllabus` = `syllabus.Dashboard()` (`Board`) | Đã làm | Thẻ "Đề xuất giáo trình / giãn tiến độ chờ duyệt" (gợi ý "N đề xuất sửa giáo trình · M giãn tiến độ"), "Big Test trong 14 ngày tới"; hàng chờ kết quả Big Test chờ duyệt (`/syllabus/big-tests/results`), đề xuất sửa giáo trình (`/syllabus/versions?status=pending`), order đề Big Test chờ duyệt (`/syllabus/big-tests/distribution`); danh sách "Đề xuất chờ duyệt" (5 yêu cầu giãn tiến độ: lớp — giáo viên, lý do; "Duyệt đề xuất") và "Big Test sắp tới" (8 đợt: tên, lớp, giờ ngày/tháng; "Lịch Big Test"). |
| Lịch hẹn 7 ngày tới (không có lịch chăm sóc CRM) + việc của tôi trong tuần | `agenda(withCrmCare=false)`, `my_tasks` | Đã làm | Đợt Big Test trên lịch (tiêu đề = lớp, phụ đề = tên đợt, link `/syllabus/big-tests/schedules`) qua `Syllabus.Agenda`. |

## "Việc của bạn" (`MyWorkBoard`, `MyWork.vue`, `AgendaCard.vue`, `MyTasksCard.vue`)

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| Phạm vi: khách và việc mình phụ trách / chi nhánh / toàn hệ thống (theo `lead.scope_*`) | `crm.scopeOf` | Đã làm | Nhãn "Khách và việc bạn phụ trách", tên chi nhánh, "Chi nhánh của bạn", "Toàn hệ thống". |
| Thẻ số liệu (tối đa 5): hẹn test hôm nay, học thử 7 ngày tới, gọi lại hôm nay, việc của tôi đến hạn tuần này (kèm số quá hạn), khách chốt trong tháng, học phí quá hạn | `personal` trong `board.go` | Đã làm | Học phí quá hạn đọc `Finance.OverdueTuitions` (phạm vi học phí của người xem, bỏ hồ sơ đang tạm dừng nhắc). |
| Lịch hẹn 7 ngày tới: test đầu vào, học thử, gọi lại, support sau chốt (buổi 1, buổi 4–5, ngày 30; ẩn khi đã tick checklist), Big Test, mốc dự án học thuật | `Leads.Agenda`, `buildAgenda` | Đã làm | Tối đa 40 lịch, nhóm theo ngày ("Hôm nay", "Ngày mai", "Thứ …"), lịch đã qua làm mờ. Hạn mốc dự án học thuật lấy từ `projects.DashboardMilestones` (xem [chat-luong-lop.md](chat-luong-lop.md)); đợt Big Test từ `Syllabus.Agenda` (khi xem được Big Test: `syllabus.view` + quyền học thuật). |
| Hàng chờ "Cần xử lý" (có số > 0 lên trước): khách chưa liên hệ >24h, khách chờ xếp lớp (`class.update`), học phí quá hạn, kỳ lương chưa chốt | `personalQueues` | Đã làm | Học phí quá hạn từ `Finance.OverdueTuitions`; kỳ lương chưa chốt từ `Payroll.OpenPeriods` (link `/payroll/periods` khi có `payroll.view`). |
| Hàng chờ duyệt (`ApprovalInboxService`) | — | Khác | Ở Laravel chỉ Admin có mục duyệt, mà Admin luôn thấy bảng điều hành, nên bảng cá nhân không bao giờ hiện; v2 bỏ (hộp "Việc cần duyệt" ở `/approvals`, xem [can-duyet.md](can-duyet.md)). |
| "Việc của tôi trong tuần" (6 việc, hạn trong tuần hoặc quá hạn), "Xem tất cả (N)" | `Tasks.Mine`, `my_tasks` | Đã làm | Link `/tasks?tab=mine`; mỗi việc mở `/tasks/<id>`. |

## Chất lượng giảng dạy (`TeachingQuality`, `TeachingQuality.vue`, `BarList.vue`)

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| Chọn tháng (tháng này + 6 tháng trước), tháng sai → tháng này | `pickMonth`, `monthOptions` | Đã làm | Tháng giữ trên URL (`?month=`). |
| Giáo viên / trợ giảng: lớp đang giữ, học sinh, HS mới / nghỉ, chuyên cần theo lớp (biểu đồ thanh) | `Classes.TeachingOf`, `ClassStats` | Đã làm | Trung bình có trọng số theo số học sinh như `totals()`. |
| Bài về nhà, điểm trung bình mini test | `teacher.Service.Dashboard` (`Deps.Homework`) | Đã làm | Xem `cong-giao-vien.md`. |
| Ngày công, đi muộn, nghỉ phép, vi phạm (`workStats`) | `StaffWork` = `attendance.DashboardWork()` | Đã làm | Đi muộn = ca dạy muộn + ngày chấm công muộn không có đơn; vi phạm = biên bản của Lương & Phạt (xem [cham-cong.md](cham-cong.md)). |
| Người theo dõi giáo viên (`kpi.view`, bảng điều hành, Học thuật): biểu đồ + bảng theo giáo viên chính | `Classes.TeamClasses`, `teamRows` | Đã làm | Cột ngày công / đi muộn / phép / vi phạm từ `StaffWork.Stats`. |
| Học thuật / Admin / Quản lý: báo cáo tháng của giáo viên (đã nộp / chưa nộp, số lớp cần hỗ trợ, xem chi tiết) | `Reports.TeacherMonthly`, `teacher_reports` | Đã làm | Hộp thoại chi tiết: tiến độ, khó khăn, đề xuất, ghi chú từng lớp. |
| Order học thuật trong tháng (10), "Xem tất cả" | `Orders.Academic`, `orders_url` `/material-orders` | Đã làm | |
| "Viết báo cáo tháng" cho giáo viên | `report_url` `/reports/teacher-monthly?month=` | Đã làm | |

## Báo cáo đào tạo (`AcademicDashboardController::reports`, `Academic/Reports.vue`)

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| Tab con "Báo cáo đào tạo" / "Nhật ký sự vụ" (`SectionTabs.vue`) | `SectionTabs` trong `AcademicPage` | Đã làm | |
| Thẻ: lớp đang chạy / tổng, chuyên cần hôm nay, báo cáo ngày hôm nay | `Classes.Overview`, `Reports.DailyOn` | Làm một phần | Laravel cộng thêm báo cáo trực lớp trong ngày (báo cáo trực lớp chưa có ở v2). |
| Thẻ / ô "Đề xuất điều chỉnh tiến độ", "Khảo thí & Big Test" | `weekly_stats` ← `Syllabus.Week` | Đã làm | Thẻ "Đề xuất điều chỉnh tiến độ chờ duyệt"; ô "Yêu cầu mới trong tuần" / "Đang chờ duyệt", "Big Test trong 7 ngày tới" / "Đề đã phân phối trong tuần" (tuần bắt đầu thứ Hai như `startOfWeek`). |
| Tab 1: báo cáo ngày Học vụ (15), tóm tắt lớp thực tế / kế hoạch, GV / TG, tuyển sinh, "Có task"; bản chữ cũ | `staff_daily_reports` | Đã làm | Bấm dòng xem nội dung. |
| Tab 1: báo cáo trực lớp theo buổi | `class_reports_pending` | Chưa làm | Báo cáo trực lớp chưa có ở v2 ("Chờ phân hệ Báo cáo trực lớp"). |
| Tab 2: báo cáo tuần Học thuật (10), báo cáo tuần KPI Học vụ (10) | `weekly_reports`, `weekly_kpi_reports` | Đã làm | |
| Tab 3: báo cáo tháng / quý (15) | `monthly_reports` | Đã làm | |

## Nhật ký sự vụ (`AcademicDashboardController::incidents`, `Academic/Incidents.vue`)

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| Lọc cơ sở, lớp, mức độ (khẩn / cao / trung bình / thấp), trạng thái (đang xử lý / đã giải quyết) | `IncidentFilter`, bộ lọc giữ trên URL | Đã làm | Mức nhật ký: khẩn ↔ khẩn, quan trọng ↔ cao, bình thường ↔ trung bình; "thấp" không có nhật ký nào. Cơ sở theo lớp, sự việc không gắn lớp theo chi nhánh chính của người ghi. |
| Nhật ký sự vụ (30 dòng): mã, cơ sở, lớp (link lớp), mức độ, người ghi, follow-up mới nhất, trạng thái | `Reports.Journals` | Đã làm | Link lớp mở `/classes/<id>` (tab Sự vụ của lớp chưa có). |
| Thẻ tổng, khẩn cấp, đang theo dõi, đã giải quyết | `total_incidents`, `urgent_count`, `open_count`, `resolved_count` | Đã làm | Đếm cả ticket khẩn cấp / đã giải quyết như Laravel. |
| Ticket hỗ trợ khẩn / cao | `tickets` (`tickets_pending` = null) | Đã làm | Theo mức độ đang lọc, chi nhánh chính của người tạo; lọc theo lớp thì không có ticket. |
| Nhật ký học vụ cũ (`academic_records`) | — | Chưa làm | Dữ liệu cũ chưa import sang v2. |
| Nút "Ghi sự vụ" (`staff_report.submit`) | `can_submit_journal` → `/reports/journal` | Đã làm | |

## Khác biệt có chủ đích

- Gói `dashboard` chỉ đọc qua interface; nguồn chưa gắn (hiện chỉ còn báo cáo trực lớp) trả `pending` kèm câu
  "Chờ phân hệ …" thay vì số 0, để không nhầm là không có việc.
- `/` là Tổng quan cho nhân sự, trang tài khoản ở `/profile`.
- Không có lệnh ghi nên không cần nhật ký thao tác / thông báo riêng.
