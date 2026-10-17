# Đối chiếu: Báo cáo nhân sự & Nhật ký Học vụ (Laravel → v2)

Phạm vi: báo cáo định kỳ theo vai trò (ngày / tuần / tháng / quý), báo cáo chữ tự do, Nhật ký Học vụ, Tổng hợp báo cáo &
nhật ký (kiểm tra / yêu cầu bổ sung), theo dõi chưa nộp / nộp trễ, nhắc hạn.

Nguồn Laravel: `app/Http/Controllers/StaffReportController.php`, `routes/web.php` (nhóm `reports.`), `app/Models/StaffReport.php`,
`app/Models/StaffReportFollowup.php`, `app/Support/StaffType.php`, `app/Support/ReportPeriod.php`, `app/Support/DailyReportDue.php`,
`app/Services/Reports/*`, `app/Console/Commands/{RemindDailyReports,RemindMonthlyReports,DailyReportDigest}Command.php`,
`resources/js/Pages/Reports/*`, các migration `staff_reports`.

v2:
- API `v2/api/internal/reports/`, bảng `ops.staff_reports` + `ops.staff_report_followups` (migration `00060`).
- Web `v2/web/src/features/reports/`.
- Menu: "Báo cáo của tôi → Báo cáo định kỳ"; "Nhân sự & KPI → Tổng hợp báo cáo & nhật ký / Nhật ký Học vụ".

## Route / màn

| Laravel (route / method) | v2 (API + màn) | Trạng thái | Ghi chú |
|---|---|---|---|
| `reports.my` GET `/reports/my` (`myReports`) | GET `/api/v1/reports/my` · màn `/reports` | Đã làm | Vai trò có màn có cấu trúc cho kỳ chính → tự mở màn đó (Học vụ → ngày, Học thuật → tuần, GV → tháng). Vai trò khác: danh sách báo cáo chữ (10/trang), băng hạn nộp tháng (Chủ nhật cuối tháng) cho GV / trợ giảng. |
| `reports.my.store` POST `/reports/my` (`reportStore`) | POST `/api/v1/reports/my` · hộp "Nộp báo cáo" | Đã làm | Kiểm tra tiêu đề / nội dung / lớp trong phạm vi như Laravel. Báo Admin qua thông báo trong ứng dụng (`staff_report_submitted`). |
| `reports.periodic.daily` GET `/reports/daily` (`daily`) | GET `/api/v1/reports/daily?date=&refresh=` · `/reports/daily` | Làm một phần | Đủ khung: chọn ngày, trước 10:00 tự mở hôm qua nếu chưa nộp, danh sách ngày còn thiếu (14 ngày), lịch sử, báo cáo cũ, trạng thái kiểm tra, ghi chú theo khối, nháp ghi chú ở trình duyệt, "Cập nhật số liệu". **Số liệu tự điền** đọc qua `MetricsProvider.Daily`: "Data mới nhận" (theo nguồn, đã liên hệ lần đầu, tách chi nhánh) và "Số học sinh test" (bài test / số hẹn) lấy từ CRM + test đầu vào (`reports.NewMetrics`) theo phạm vi khách người viết được xem. Khối "Vận hành lớp học" của Học vụ (lớp kế hoạch theo chi nhánh, lớp thực tế, danh sách lớp kèm nhãn Đã dạy / Chưa điểm danh / Nghỉ / hủy / Nghỉ lễ / Học bù / phòng, giáo viên đứng lớp + dạy thay, trợ giảng, tình hình lớp theo điểm danh) lấy từ phân hệ Lớp học (`classes.ReportMetrics`); mục học phí / ticket vẫn "Chờ phân hệ … chuyển sang hệ thống mới." |
| `reports.periodic.daily.store` POST `/reports/daily` (`dailyStore`) | POST `/api/v1/reports/daily` | Làm một phần | Lưu / nộp lại cùng ngày (cập nhật bản cũ), chụp số liệu lúc nộp, nộp trễ (sau 09:00 hôm sau), nộp lại sau "yêu cầu bổ sung" chuyển về chờ kiểm tra. **Tạo việc follow-up** qua phân hệ Công việc (`tasks.Service.ReportTasks`): việc một lần, hạn 18:00, người nhận như màn Giao việc; người được giao nhận thông báo "Bạn được giao việc: …". |
| `reports.periodic.weekly-kpi` GET `/reports/weekly-kpi` (`weeklyKpi`) | GET `/api/v1/reports/weekly-kpi?week=&refresh=` · `/reports/weekly-kpi` | Làm một phần | Tuần ISO, 9 chỉ số quy mô (`MetricsProvider.WeeklyScale`), bảng KPI (`KpiCriteria` + `KpiEvidence`), ghi chú tuần. Chưa có phân hệ KPI nên bảng KPI trống. Chỉ số "Data mới nhận" / "HV mới chốt" lấy từ CRM; chỉ số về lớp (lớp đang chạy, học sinh đang học, giáo viên, chuyển lớp, học bù) lấy từ phân hệ Lớp học; chỉ số học phí vẫn "Chờ phân hệ…". |
| `reports.periodic.weekly-kpi.store` POST | POST `/api/v1/reports/weekly-kpi` | Đã làm | Lưu ghi chú + chụp bảng KPI lúc nộp. |
| `reports.periodic.academic-weekly` GET (`academicWeekly`) | GET `/api/v1/reports/academic-weekly?week=` · `/reports/academic-weekly` | Làm một phần | 3 bảng (đã làm / dự kiến / yêu cầu đang quản lý), 9 tuần gần nhất, dòng gợi ý "Thêm từ hệ thống". Số liệu tuần và dòng gợi ý qua `MetricsProvider.WeeklyAcademic` — hiện trống. Ticket CSVC / buổi chưa điểm danh (mục 5.1): TODO(gộp). |
| `reports.periodic.academic-weekly.store` POST | POST `/api/v1/reports/academic-weekly` | Đã làm | Kiểm tra từng dòng (nội dung bắt buộc, tiến độ 0/25/50/75/100, link https, deadline), thông báo lỗi "Mục, dòng N: …" như Laravel; dựng nội dung chữ. |
| `reports.periodic.academic-monthly` GET / POST (`academicMonthly*`) | GET/POST `/api/v1/reports/academic-monthly` · `/reports/academic-monthly` | Làm một phần | Các ô đánh giá (tối đa 10000 ký tự), báo cáo tuần trong tháng. Họp giáo viên (`TeacherMeetings`) chờ phân hệ Chất lượng lớp: hiện ghi chú "Chờ phân hệ…". |
| `reports.periodic.academic-quarterly` GET / POST (`academicQuarterly*`) | GET/POST `/api/v1/reports/academic-quarterly` · `/reports/academic-quarterly` | Đã làm | Quý "2026-Q4", các ô đánh giá. |
| `reports.periodic.teacher-monthly` GET / POST (`teacherMonthly*`) | GET/POST `/api/v1/reports/teacher-monthly` · `/reports/teacher-monthly` | Làm một phần | Nháp / nộp, phần chung, từng lớp (số buổi, tiến độ, chuyên cần / bài tập gợi ý từ số liệu, checklist học thuật, cần hỗ trợ), hạn Chủ nhật cuối tháng, báo cáo chấm công trong cùng màn. Danh sách lớp giáo viên giữ trong tháng (lớp đang học mình là GV / trợ giảng / GVNN, hoặc có buổi mình đứng), chuyên cần (%) của tháng và số buổi đã đứng lớp qua `MetricsProvider.TeacherClasses` = `classes.ReportMetrics`; tỉ lệ bài tập chờ phân hệ Bài tập (trống). Link "Đề xuất điều chỉnh giáo trình" (`syllabus.teacher-propose`): TODO(gộp). |
| `reports.periodic.teacher-attendance.store` POST | POST `/api/v1/reports/teacher-attendance` | Đã làm | Ghi chú chấm công tháng. |
| `reports.journal` GET (`journal`) | GET `/api/v1/reports/journal` · `/reports/journal` | Đã làm | 15/trang, lọc mức độ / trạng thái, phạm vi dữ liệu (của mình; quản lý theo chi nhánh; Admin tất cả), "Sự việc của" cho Admin / Quản lý ghi thay Học vụ. Mã KPI lấy từ `KpiCriteria` (trống tới khi có KPI). Lớp liên quan qua `ClassDirectory` = `classes.ReportClasses` (lớp mình thấy, chưa hủy, "Mã · Tên"). |
| `reports.journal.store` POST | POST `/api/v1/reports/journal` | Đã làm | Tiêu đề lấy dòng đầu, kiểm tra mã KPI thuộc bộ đang áp dụng. Sự việc khẩn báo Admin + Quản lý cơ sở (`staff_journal_urgent`). |
| `reports.journal.followup` POST | POST `/api/v1/reports/journal/{id}/followup` | Đã làm | Mới → Đang theo dõi khi có follow-up đầu. |
| `reports.journal.status` POST | POST `/api/v1/reports/journal/{id}/status` | Đã làm | |
| `reports.journal.serious` POST (Admin) | POST `/api/v1/reports/journal/{id}/serious` | Đã làm | Chỉ Admin (super admin). Ghi nhật ký thao tác (`staff_journal.serious`). |
| `reports.all` GET (`allReports`, `staff_report.view_all`) | GET `/api/v1/reports/all` · `/reports/all` | Đã làm | Lọc loại / trạng thái kiểm tra / người / chi nhánh / khoảng ngày, thống kê (kể cả "chưa nộp hôm qua"), tab "Chưa nộp" (ngày còn thiếu từng người), `?open=` mở chi tiết. Quản lý không phải Admin chỉ thấy nhân sự chi nhánh mình. |
| `reports.detail` GET (`reportDetail`) | GET `/api/v1/reports/{id}/detail` · hộp chi tiết | Đã làm | Các dạng: nhật ký, ngày (mới / cũ), KPI tuần (mới / cũ), tuần Học thuật, chữ; nút "báo cáo kế tiếp". |
| `reports.review` POST (Admin) | POST `/api/v1/reports/{id}/review` | Đã làm | Đánh dấu / bỏ đánh dấu, tự chuyển báo cáo kế tiếp. Báo người viết "Đã kiểm tra: …" (`staff_report_reviewed`). |
| `reports.request-changes` POST (Admin) | POST `/api/v1/reports/{id}/request-changes` | Đã làm | Bắt buộc ghi chú. Báo người viết "Cần bổ sung: …" (`staff_report_returned`); nộp lại thì Admin nhận "Đã bổ sung: …". |
| `reports.review-bulk` POST (Admin) | POST `/api/v1/reports/review-bulk` | Đã làm | "Đã đánh dấu N báo cáo đã kiểm tra." |
| `reports:remind-daily` (08:00) | `Service.DailyReminders` + `RunReminderLoop` | Làm một phần | Tính ai chưa nộp ngày cần nộp (bỏ Chủ nhật và ngày nghỉ toàn hệ thống / chi nhánh qua `catalogs.Service.ReportCalendar`; nghỉ phép chờ phân hệ HR). Gửi `daily_report_due`, chạy lại không gửi trùng (`notify.SendOnce`). |
| `reports:remind-monthly` (08:00) | `Service.MonthlyReminders` | Đã làm | Mốc hạn −3 / −1 / đúng ngày (Chủ nhật cuối tháng). Gửi `monthly_report_due`, không gửi trùng. |
| `reports:daily-digest` (09:05) | `Service.DailyDigest` | Đã làm | Tổng hợp chưa nộp / nộp trễ hôm qua gửi mọi Admin (`staff_report_digest`), không gửi trùng. |

## Phụ thuộc phân hệ khác

`reports.NewService(pool, log, reports.Deps{...})` ở `cmd/menglish/main.go` truyền `Metrics: reports.NewMetrics(crmSvc.ReportSource())`,
`Tasks: taskSvc.ReportTasks()`, `Calendar: catalogSvc.ReportCalendar()`; `Classes` còn bản mặc định:

| Interface | Phân hệ nguồn | Dùng cho | Hiện tại |
|---|---|---|---|
| `MetricsProvider.Daily` | Lớp học / buổi học, CRM, Học phí, Test đầu vào, Ticket / Order, Dự án | khối số liệu báo cáo ngày (Học vụ / Học thuật) | "Data mới nhận", "Số học sinh test" có số (CRM + test đầu vào, `crm.Service.ReportSource`); mục khác "Chờ phân hệ …" |
| `MetricsProvider.WeeklyScale` | Lớp học, CRM, Học phí | 9 chỉ số quy mô báo cáo tuần Học vụ | "Data mới nhận", "HV mới chốt" có số (CRM); mục lớp học "Chờ phân hệ …" |
| `MetricsProvider.WeeklyAcademic` | Lớp học, Giáo trình, Ticket | số liệu + dòng gợi ý báo cáo tuần Học thuật | trống |
| `MetricsProvider.KpiCriteria` / `KpiEvidence` | KPI (chưa có) | bảng KPI tuần, mã KPI của nhật ký | trống (nhật ký không gắn được mã KPI) |
| `MetricsProvider.TeacherClasses` | Lớp học / điểm danh / bài tập | lớp trong báo cáo tháng GV | trống + ghi chú |
| `MetricsProvider.TeacherMeetings` | Chất lượng lớp (họp GV) | báo cáo tháng Học thuật | trống + ghi chú |
| `TaskService` | Công việc (`ops.work_tasks`) | tạo việc follow-up từ báo cáo ngày, người nhận việc | đã nối (`tasks.Service.ReportTasks`) |
| `ClassDirectory` | Lớp học (`academic.classes`) | "Lớp liên quan" ở nhật ký / báo cáo chữ, kiểm tra lớp trong phạm vi | không có lớp |
| `Calendar` | Ngày lễ (`core.holidays`), nghỉ phép (HR) | ngày không phải nộp báo cáo ngày | ngày nghỉ đã nối (`catalogs.Service.ReportCalendar`); nghỉ phép chờ phân hệ HR |

Đã gắn phân hệ Lớp học: `ClassDirectory` = `classes.ReportClasses`; `Metrics` = `classes.ReportMetrics(<provider gốc>, iam.LoadAccess)` —
một decorator bọc provider gốc (hiện `reports.Skeleton()`), chỉ thay `Daily` (khối vận hành lớp học), `WeeklyScale`
(chỉ số lớp) và `TeacherClasses`; các mục / phương thức khác chuyển nguyên cho provider gốc. Phân hệ khác (CRM, học
phí…) làm provider riêng theo cùng cách: bọc `reports.Skeleton()` (hoặc provider trước đó) rồi truyền vào
`classes.ReportMetrics`. `reports.Partial` (`Pending(part)`) báo phần nào còn thiếu nguồn để màn báo cáo tháng hiện
ghi chú ("classes", "meetings"). Khóa ngoại `ops.staff_reports.class_id` → `academic.classes`: `00110_academic_links_fk`.

Thông báo đã gửi qua `notify` (báo cáo mới / nộp lại sau bổ sung / đã kiểm tra / cần bổ sung, sự việc khẩn, việc follow-up, nhắc
nộp ngày / tháng, tổng hợp 09:05); nhật ký thao tác ghi khi xác nhận / bỏ lỗi nghiêm trọng.

## Chưa làm / thuộc phân hệ khác

| Laravel | Trạng thái | Ghi chú |
|---|---|---|
| Dashboard: băng "báo cáo ngày cần bổ sung", bảng báo cáo tháng GV (`DashboardController`) | Đã làm | Màn Tổng quan (`internal/dashboard`, xem [tong-quan.md](tong-quan.md)) đọc qua `reports.DashboardReports()`: ngày còn thiếu dùng chung `missingDays`, báo cáo bị yêu cầu bổ sung, hôm nay đã nộp chưa; báo cáo tháng giáo viên đã nộp theo tháng. |
| Hồ sơ: "đã nộp báo cáo kỳ này" (`ProfileController` → `submittedCurrentPeriod`) | Chưa làm | Đã có `Service.SubmittedCurrentPeriod` cho màn Hồ sơ dùng. |
| `academic.reports` / `/reports` (`AcademicDashboardController::reports`) | Làm một phần | Màn `/academic/dashboards` "Báo cáo & sự vụ lớp" (xem [tong-quan.md](tong-quan.md)): báo cáo ngày Học vụ, tuần Học thuật, tuần KPI, tháng / quý, nhật ký sự vụ. Báo cáo trực lớp, đề xuất giãn tiến độ / Big Test và ticket chờ phân hệ nguồn. |
| Tab "Sự vụ" của lớp (`ClassManagementController`, `IncidentsTab.vue`) | Chưa làm | Phân hệ Lớp học; đọc nhật ký theo `class_id`. |
| KPI tự đếm "nộp báo cáo ngày trễ" / "nộp báo cáo tháng" (`KpiAutoCounter`) | Chưa làm | Phân hệ KPI chưa có; dùng `isLate` / hạn tháng tương tự. |
| `teacher.general-report` | Chưa làm | Thuộc phân hệ Giáo viên. |
| Chuyển dữ liệu cũ từ Laravel `staff_reports` (id bigint) | Chưa làm | Bảng v2 dùng uuid; cần bước import khi chuyển dữ liệu. |

## Khác biệt có chủ ý

- Nhắc hạn chạy trong tiến trình API (`RunReminderLoop`, kiểm mỗi phút, nhớ đã chạy trong ngày ở bộ nhớ) thay cho
  Laravel scheduler. Nếu chạy nhiều bản API cần chuyển sang khóa chung (Redis) khi có phân hệ thông báo.
- Giới hạn thân request của module báo cáo là 2 MB (báo cáo dài), thay vì 64 KB mặc định.
- Múi giờ cố định UTC+7 cho kỳ báo cáo và hạn 09:00.
