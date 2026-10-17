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
(`Leads`, `Tasks`, `Classes`, `Students`, `Reports`, `Orders`, `Branches`, `Finance`, `StaffWork`), do chính phân hệ cài
(`crm.DashboardLeads`, `tasks.DashboardTasks`, `classes.DashboardClasses`, `students.DashboardStudents`,
`reports.DashboardReports`, `inventory.DashboardOrders`, `iam.Service`) với câu SQL trong `<phân hệ>/dashboard.sql`, nối
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
| `GET /academic/dashboards/reports` `academic.dashboards.reports` → `reports` | `GET /api/v1/academic/dashboards/reports?tab=` · màn `/academic/dashboards` (+ `/reports`) | Làm một phần | `iam.Require(class.update)` như `can:class.update`. Thiếu báo cáo trực lớp, đề xuất giãn tiến độ, Big Test (xem bảng dưới). |
| `GET /academic/dashboards/incidents` `academic.dashboards.incidents` → `incidents` | `GET /api/v1/academic/dashboards/incidents?severity=&status=&branch_id=&class_id=` · màn `/academic/dashboards/incidents` | Làm một phần | `class.update`. Thiếu ticket hỗ trợ và nhật ký học vụ cũ (`academic_records`). |
| `GET /academic/reports` `academic.reports`, `GET /academic/incidents` `academic.incidents` (URL cũ) | Màn `/academic/reports`, `/academic/incidents` | Đã làm | Mở cùng màn, không có mục menu riêng. |
| SidebarMenu: link "Tổng quan" đầu sidebar | `NAV` `/` "Tổng quan" (icon `dashboard`) | Đã làm | Chỉ hiện với người mở được Tổng quan; người khác thấy "Tài khoản của tôi". |
| SidebarMenu: "Báo cáo & sự vụ lớp" (khu Báo cáo › Đào tạo & Chất lượng lớp, neo `class.update`) | `NAV` `/academic/dashboards` | Đã làm | Các mục còn lại của workspace (dự giờ, checklist, họp giáo viên, dự án học thuật) ở [chat-luong-lop.md](chat-luong-lop.md). |

## Tổng quan (`Dashboard.vue`)

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| Tiêu đề "Tổng quan", mô tả "Bảng điều hành toàn trung tâm" khi `dashboard.operations` | `DashboardPage` | Đã làm | |
| Lời chào (`welcome`): tên, vai trò rút gọn, chi nhánh, nút "Lương của tôi", "Nhiệm vụ hôm nay" | `welcomeOf(me)` dựng từ `/auth/me` | Khác | Dựng trên trình duyệt thay vì từ máy chủ. Nút "Nhiệm vụ hôm nay" → `/portal/ta-tasks` khi có `work_task.view`. **"Lương của tôi" chưa có** (chờ phân hệ Lương; `TODO(gộp)` trong `logic.ts`). |
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
| Hàng chờ: báo cáo trực lớp chờ Admin xác nhận | — | Chưa làm | "Chờ phân hệ Báo cáo trực lớp". |
| Hàng chờ: ticket hỗ trợ đang mở | — | Chưa làm | "Chờ phân hệ Ticket hỗ trợ". |
| "Việc quá hạn cần xử lý" (5 việc), link danh sách `tasks.index?tab=assigned&status=overdue` | `overdue_tasks`, link `/tasks?tab=assigned&status=overdue` | Đã làm | Danh sách đầu việc đọc `?tab=` và `?status=` từ URL. Mỗi việc mở `/tasks/<id>`. |

## Bảng Học thuật (`academicDashboard`)

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| Thẻ lớp đang chạy trong phạm vi | `Classes.RunningVisible` | Đã làm | |
| Đề xuất giáo trình / giãn tiến độ chờ duyệt, Big Test 14 ngày tới (thẻ, hàng chờ, hai danh sách) | `academic_pending`, thẻ / hàng chờ `pending` | Chưa làm | "Chờ phân hệ Giáo trình & Big Test". |
| Lịch hẹn 7 ngày tới (không có lịch chăm sóc CRM) + việc của tôi trong tuần | `agenda(withCrmCare=false)`, `my_tasks` | Làm một phần | Lịch Big Test chờ phân hệ Giáo trình (ghi chú trong thẻ lịch). |

## "Việc của bạn" (`MyWorkBoard`, `MyWork.vue`, `AgendaCard.vue`, `MyTasksCard.vue`)

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| Phạm vi: khách và việc mình phụ trách / chi nhánh / toàn hệ thống (theo `lead.scope_*`) | `crm.scopeOf` | Đã làm | Nhãn "Khách và việc bạn phụ trách", tên chi nhánh, "Chi nhánh của bạn", "Toàn hệ thống". |
| Thẻ số liệu (tối đa 5): hẹn test hôm nay, học thử 7 ngày tới, gọi lại hôm nay, việc của tôi đến hạn tuần này (kèm số quá hạn), khách chốt trong tháng, học phí quá hạn | `personal` trong `board.go` | Làm một phần | Học phí quá hạn chờ phân hệ Học phí (`Finance.OverdueTuitions`). |
| Lịch hẹn 7 ngày tới: test đầu vào, học thử, gọi lại, support sau chốt (buổi 1, buổi 4–5, ngày 30; ẩn khi đã tick checklist), Big Test, mốc dự án học thuật | `Leads.Agenda`, `buildAgenda` | Làm một phần | Tối đa 40 lịch, nhóm theo ngày ("Hôm nay", "Ngày mai", "Thứ …"), lịch đã qua làm mờ. Hạn mốc dự án học thuật lấy từ `projects.DashboardMilestones` (xem [chat-luong-lop.md](chat-luong-lop.md)); Big Test chờ phân hệ (ghi chú trong thẻ). |
| Hàng chờ "Cần xử lý" (có số > 0 lên trước): khách chưa liên hệ >24h, khách chờ xếp lớp (`class.update`), học phí quá hạn, kỳ lương chưa chốt | `personalQueues` | Làm một phần | Học phí, kỳ lương: "Chờ phân hệ Học phí / Lương", không bấm được. |
| Hàng chờ duyệt (`ApprovalInboxService`) | — | Khác | Ở Laravel chỉ Admin có mục duyệt, mà Admin luôn thấy bảng điều hành, nên bảng cá nhân không bao giờ hiện; v2 bỏ. Hộp duyệt chung chưa có ở v2. |
| "Việc của tôi trong tuần" (6 việc, hạn trong tuần hoặc quá hạn), "Xem tất cả (N)" | `Tasks.Mine`, `my_tasks` | Đã làm | Link `/tasks?tab=mine`; mỗi việc mở `/tasks/<id>`. |

## Chất lượng giảng dạy (`TeachingQuality`, `TeachingQuality.vue`, `BarList.vue`)

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| Chọn tháng (tháng này + 6 tháng trước), tháng sai → tháng này | `pickMonth`, `monthOptions` | Đã làm | Tháng giữ trên URL (`?month=`). |
| Giáo viên / trợ giảng: lớp đang giữ, học sinh, HS mới / nghỉ, chuyên cần theo lớp (biểu đồ thanh) | `Classes.TeachingOf`, `ClassStats` | Đã làm | Trung bình có trọng số theo số học sinh như `totals()`. |
| Bài về nhà, điểm trung bình mini test | — | Chưa làm | "Chờ cổng Giáo viên (bài tập, mini test)": cột hiện "—". |
| Ngày công, đi muộn, nghỉ phép, vi phạm (`workStats`) | `StaffWork` (mặc định không có) | Chưa làm | "Chờ phân hệ Chấm công & KPI"; `TODO(gộp)` nối `internal/kpi`. |
| Người theo dõi giáo viên (`kpi.view`, bảng điều hành, Học thuật): biểu đồ + bảng theo giáo viên chính | `Classes.TeamClasses`, `teamRows` | Làm một phần | Cột chấm công chờ phân hệ như trên. |
| Học thuật / Admin / Quản lý: báo cáo tháng của giáo viên (đã nộp / chưa nộp, số lớp cần hỗ trợ, xem chi tiết) | `Reports.TeacherMonthly`, `teacher_reports` | Đã làm | Hộp thoại chi tiết: tiến độ, khó khăn, đề xuất, ghi chú từng lớp. |
| Order học thuật trong tháng (10), "Xem tất cả" | `Orders.Academic`, `orders_url` `/material-orders` | Đã làm | |
| "Viết báo cáo tháng" cho giáo viên | `report_url` `/reports/teacher-monthly?month=` | Đã làm | |

## Báo cáo đào tạo (`AcademicDashboardController::reports`, `Academic/Reports.vue`)

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| Tab con "Báo cáo đào tạo" / "Nhật ký sự vụ" (`SectionTabs.vue`) | `SectionTabs` trong `AcademicPage` | Đã làm | |
| Thẻ: lớp đang chạy / tổng, chuyên cần hôm nay, báo cáo ngày hôm nay | `Classes.Overview`, `Reports.DailyOn` | Làm một phần | Laravel cộng thêm báo cáo trực lớp trong ngày (chờ phân hệ). |
| Thẻ / ô "Đề xuất điều chỉnh tiến độ", "Khảo thí & Big Test" | `weekly_stats.pending` | Chưa làm | "Chờ phân hệ Giáo trình & Big Test". |
| Tab 1: báo cáo ngày Học vụ (15), tóm tắt lớp thực tế / kế hoạch, GV / TG, tuyển sinh, "Có task"; bản chữ cũ | `staff_daily_reports` | Đã làm | Bấm dòng xem nội dung. |
| Tab 1: báo cáo trực lớp theo buổi | `class_reports_pending` | Chưa làm | "Chờ phân hệ Báo cáo trực lớp". |
| Tab 2: báo cáo tuần Học thuật (10), báo cáo tuần KPI Học vụ (10) | `weekly_reports`, `weekly_kpi_reports` | Đã làm | |
| Tab 3: báo cáo tháng / quý (15) | `monthly_reports` | Đã làm | |

## Nhật ký sự vụ (`AcademicDashboardController::incidents`, `Academic/Incidents.vue`)

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| Lọc cơ sở, lớp, mức độ (khẩn / cao / trung bình / thấp), trạng thái (đang xử lý / đã giải quyết) | `IncidentFilter`, bộ lọc giữ trên URL | Đã làm | Mức nhật ký: khẩn ↔ khẩn, quan trọng ↔ cao, bình thường ↔ trung bình; "thấp" không có nhật ký nào. Cơ sở theo lớp, sự việc không gắn lớp theo chi nhánh chính của người ghi. |
| Nhật ký sự vụ (30 dòng): mã, cơ sở, lớp (link lớp), mức độ, người ghi, follow-up mới nhất, trạng thái | `Reports.Journals` | Đã làm | Link lớp mở `/classes/<id>` (tab Sự vụ của lớp chưa có). |
| Thẻ tổng, khẩn cấp, đang theo dõi, đã giải quyết | `total_incidents`, `urgent_count`, `open_count`, `resolved_count` | Làm một phần | Laravel đếm thêm ticket khẩn cấp (chờ phân hệ Ticket). |
| Ticket hỗ trợ khẩn / cao | `tickets_pending` | Chưa làm | "Chờ phân hệ Ticket hỗ trợ". |
| Nhật ký học vụ cũ (`academic_records`) | — | Chưa làm | Dữ liệu cũ chưa import sang v2. |
| Nút "Ghi sự vụ" (`staff_report.submit`) | `can_submit_journal` → `/reports/journal` | Đã làm | |

## Khác biệt có chủ đích

- Gói `dashboard` chỉ đọc qua interface; số liệu của phân hệ chưa chuyển (học phí, lương, chấm công & KPI, giáo trình &
  Big Test, ticket, báo cáo trực lớp, dự án học thuật, bài tập / mini test) trả `pending` kèm câu "Chờ phân hệ …" thay vì
  số 0, để không nhầm là không có việc.
- `/` là Tổng quan cho nhân sự, trang tài khoản ở `/profile`.
- Không có lệnh ghi nên không cần nhật ký thao tác / thông báo riêng.
