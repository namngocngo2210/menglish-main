# Đối chiếu: Chất lượng lớp & Dự án học thuật

Nguồn Laravel: `app/Http/Controllers/ObservationController.php`, `ClassChecklistController.php`,
`TeacherMeetingReportController.php`, `AcademicProjectController.php`, model `QaObservation`, `AcademicObservation`,
`ClassChecklist`, `TeacherMeetingReport`, `AcademicProject`, `AcademicProjectMilestone`, `AcademicProjectUpdate`,
`app/Services/Sla/AcademicProjectSlaService.php` (gọi từ `EnforceSlaCommand`), phần dự giờ / mốc dự án của
`app/Services/Reports/DailyReportMetrics.php`, `WeeklyReportMetrics.php`, `app/Services/Kpi/KpiAutoCounter.php`
(`academic_deliverable_on_time`), `app/Support/Dashboard/MyWorkBoard.php` (hạn mốc dự án), `routes/web.php` (nhóm
`class-quality.*`, `academic-projects.*`, chuyển hướng `classes.qa-observation` / `checklist` / `evaluate-observation`),
`resources/js/Pages/ClassQuality/*.vue`, `resources/js/Pages/AcademicProjects/*.vue`, migration
`2026_10_24_090000_create_class_quality_tables.php`, `2026_10_27_090000_create_academic_projects_tables.php`.

v2: migration `00220_academic_class_quality.sql` (`academic.class_observations` gộp dự giờ vận hành + đánh giá dự giờ theo
cột `kind`, `academic.class_checklists`, `academic.teacher_meeting_reports`) và `00221_syllabus_projects.sql`
(`syllabus.projects`, `project_members`, `milestones`, `project_updates`); Go `v2/api/internal/quality`,
`v2/api/internal/projects`; web `v2/web/src/features/quality`. Route API dưới `/api/v1/class-quality` (bọc
`iam.Require("class_quality.view")`, ghi thêm quyền từng màn) và `/api/v1/academic-projects` (bọc
`iam.Require("academic_project.view")`, thao tác quản lý thêm `academic_project.manage`, báo cáo thêm
`academic_project.view_all`) — đúng middleware `can:` của Laravel.

Trạng thái: **Đã làm** · **Khác** (đã làm, có khác biệt có chủ đích, xem ghi chú) · **Làm một phần** · **Chưa làm**.

## Màn và route — Chất lượng lớp

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `class-quality.operations` GET `/class-quality/operations` (`operations`) | `GET /api/v1/class-quality/operations?teacher_id=&rating=&page=` · màn `/class-quality/operations` (Báo cáo › Đào tạo & Chất lượng lớp › Dự giờ vận hành) | Khác | Danh sách mới nhất trước, lọc giáo viên / xếp loại, hộp "Chi tiết lượt dự giờ" chỉ hiện mục đã ghi. Form chỉ cho chọn giáo viên đang có lớp hoạt động, lớp lọc theo giáo viên (một lớp → tự chọn), giữ lựa chọn cũ khi sửa bản ghi của lớp / giáo viên đã nghỉ. Phạm vi: lớp trong phạm vi Lớp học (`ClassModel::visibleTo` → `classes.VisibleClassIDs`). Bộ lọc sai giá trị bị bỏ qua thay vì lỗi. |
| `class-quality.operations.store` / `update` / `destroy` (`can:class_quality.observe_operations`) | `POST` / `PUT` / `DELETE /api/v1/class-quality/operations[/{id}]` | Khác | Kiểm dữ liệu và thông báo tiếng Việt như `validatedOperations` (ngày không sau hôm nay, giáo viên dạy lớp đó, xếp loại bắt buộc, mục nội dung ≤ 5000). Lỗi trả **một** thông báo (422) thay vì lỗi theo từng ô. Lớp ngoài phạm vi → 404 như `visibleOperations`. Nhật ký `audit.Record` (phân hệ Học viên & Lớp học). |
| `class-quality.academic` GET `/class-quality/academic` (`academic`) | `GET /api/v1/class-quality/academic?month=&status=&search=&page=` · màn `/class-quality/academic` | Đã làm | 3 thẻ (lớp có học trong tháng / đã dự giờ / chưa dự giờ), lọc tháng / trạng thái dự giờ / tìm mã, tên lớp; % chuyên cần gợi ý từ điểm danh của lớp trong tháng (`attendanceRates`). |
| `class-quality.academic.save` PUT (`can:class_quality.observe_academic`) | `PUT /api/v1/class-quality/academic` | Đã làm | Một đánh giá / lớp / tháng (upsert); bỏ "Đã dự giờ" thì xóa ngày, người dự giờ, 6 tiêu chí và ghi chú; tỉ lệ 0–100. |
| `class-quality.checklist` GET (`ClassChecklistController@index`) | `GET /api/v1/class-quality/checklist?month=&search=&page=` · màn `/class-quality/checklist` | Đã làm | Bảng Có / Không / N-A cho 6 mục + ghi chú học phí; dòng có thiếu sót (mục đến hạn = Có mà việc cần làm = Không) tô đỏ và có biểu tượng "Thiếu: …", tính lại ngay khi chọn. |
| `class-quality.checklist.save` PUT (`can:class_quality.checklist`) | `PUT /api/v1/class-quality/checklist` | Đã làm | Lưu cả trang một lần; lớp không học trong tháng / ngoài phạm vi → 422. Thêm giới hạn 200 lớp / lần. |
| `class-quality.teacher-meetings` GET | `GET /api/v1/class-quality/teacher-meetings?week=&teacher_id=&status=&page=` · màn `/class-quality/teacher-meetings` | Đã làm | Lọc tuần (chọn ngày bất kỳ), giáo viên, tình trạng; hộp chi tiết; form 3 phần Thông tin chung / Nội dung chuyên môn / Đề xuất & Hành động, hiện "Tuần dd/mm/yyyy - dd/mm/yyyy" và chương trình của lớp. |
| `class-quality.teacher-meetings.store` / `update` / `destroy` (`can:class_quality.teacher_meeting`) | `POST` / `PUT` / `DELETE /api/v1/class-quality/teacher-meetings[/{id}]` | Khác | Kiểm dữ liệu như `validated` (tuần quy về thứ Hai, người chọn phải là giáo viên, lớp thuộc giáo viên, ghi chú lớp bắt buộc). Một thông báo lỗi như dòng dự giờ. |
| `classes.qa-observation`, `classes.checklist`, `classes.evaluate-observation` (redirect) | NAV ẩn `/classes/qa-observation`, `/classes/checklist`, `/classes/evaluate-observation` | Đã làm | Thay địa chỉ sang màn mới (không thêm lịch sử trình duyệt). |
| SidebarMenu: Báo cáo › Đào tạo & Chất lượng lớp (Dự giờ vận hành, Đánh giá dự giờ, Checklist học phí & feedback, Họp giáo viên, Dự án học thuật) | `NAV` group `Đào tạo & Chất lượng lớp` | Đã làm | 4 mục neo `class_quality.view`, "Dự án học thuật" (`/academic-projects/report`) neo `academic_project.view_all`, đúng thứ tự sau "Báo cáo & sự vụ lớp". |

## Màn và route — Dự án học thuật

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `academic-projects.index` GET | `GET /api/v1/academic-projects?search=&type=&status=&owner_id=&page=` · màn `/academic-projects` (Giáo trình › Dự án soạn sách & chương trình) | Đã làm | Người có `view_all` / `manage` thấy mọi dự án; thành viên / người phụ trách / người nhận mốc chỉ thấy dự án của mình. Tiến độ %, mốc xong / tổng, mốc trễ, mốc tiếp theo. |
| `academic-projects.create` / `edit` (trang form, mở modal) | Hộp thoại trên danh sách / chi tiết | Khác | Không có trang form riêng: tạo / sửa trong hộp thoại (thành viên dạng ô tích có ô tìm không phân biệt dấu). Tạo xong mở trang chi tiết. |
| `academic-projects.store` / `update` (`can:academic_project.manage`) | `POST /api/v1/academic-projects` (201 `{id, message}`), `PUT /{id}` | Khác | Mã `DA-YYYY-NNN` (khóa tư vấn khi sinh mã như `DocumentCodeGenerator`), người phụ trách + người nhận mốc tự thành thành viên, báo thành viên mới (`academic_project_member`). `kickoff_link` chỉ nhận http(s). Lỗi trả một thông báo 422. |
| `academic-projects.show` GET `/{id}` | `GET /api/v1/academic-projects/{id}` · màn `/academic-projects/<id>` | Làm một phần | Thẻ tiến độ / mốc xong / mốc trễ / deadline (còn / quá n ngày), cảnh báo "Đang lên kế hoạch" / "Đã chốt tiến độ … ân hạn" / "Tạm dừng", bảng mốc (Việc của bạn, Trễ hạn / Sắp đến hạn), báo cáo tiến độ (link sản phẩm, khó khăn, phản hồi), thông tin dự án, họp thống nhất. **Biên bản trễ deadline** đọc qua `projects.Penalties` — phân hệ Lương & biên bản đang chuyển song song nên danh sách rỗng, kèm `penalties_pending` (`TODO(gộp)`). Dự án không xem được → 404. Bảng mốc trên điện thoại cuộn ngang thay vì thẻ riêng. |
| `academic-projects.destroy` DELETE | `DELETE /api/v1/academic-projects/{id}` | Đã làm | Xóa mềm (`deleted_at`), có hộp xác nhận. |
| `academic-projects.lock` POST | `POST /api/v1/academic-projects/{id}/lock` | Khác | Chỉ khi đang lên kế hoạch và có ít nhất một mốc; báo người tham gia (`academic_project_locked`). Laravel trả flash lỗi → v2 trả 409 với cùng câu thông báo. |
| `academic-projects.status` POST (`pause` / `resume` / `complete` / `cancel`) | `POST /api/v1/academic-projects/{id}/status` `{action}` | Khác | Chuyển trạng thái như `changeStatus`; trạng thái không cho phép → 409 (Laravel flash lỗi). Tạm dừng / Hoàn thành / Hủy có xác nhận, Tiếp tục chạy ngay như Laravel. |
| `academic-projects.milestones.store` / `update` / `destroy` | `POST /{id}/milestones`, `PUT` / `DELETE /milestones/{id}` | Đã làm | Kiểm dữ liệu như `validatedMilestone` (deadline sau ngày bắt đầu, khối lượng 0,01–99999, đơn vị ≤ 30, người nhận còn quyền dự án). Đổi hạn thì nhắc lại; đổi trạng thái ghi / bỏ thời điểm hoàn thành; giao mốc mới báo người nhận (`academic_project_milestone`). |
| `academic-projects.updates.store` POST | `POST /api/v1/academic-projects/{id}/updates` | Đã làm | Thành viên / người nhận / người phụ trách hoặc người quản lý; dự án đã đóng → 409. Khối lượng đã xong, "Đã hoàn thành mốc", tối đa 5 link http(s), khó khăn → báo người phụ trách "Khó khăn cần hỗ trợ — …" hoặc "Cập nhật tiến độ — …". |
| `academic-projects.updates.respond` POST | `POST /api/v1/academic-projects/updates/{id}/respond` | Đã làm | Chỉ người quản lý hoặc người phụ trách (403); báo người gửi (`academic_project_response`). |
| `academic-projects.report` GET (`can:academic_project.view_all`) | `GET /api/v1/academic-projects/report?month=&status=` · màn `/academic-projects/report` | Làm một phần | 6 thẻ, tiến độ từng dự án (cập nhật gần nhất, khó khăn mở), theo thành viên (đúng hạn / trễ / đang trễ / cập nhật trong tháng), 15 khó khăn gần nhất, tháng hiện tại + 6 tháng trước. Số / danh sách **biên bản trễ deadline** chờ phân hệ Lương (`penalties_pending`, hiện thông báo). |
| SidebarMenu: Giáo trình › Dự án soạn sách & chương trình | `NAV` `/academic-projects` group `Giáo trình` | Đã làm | Neo `academic_project.view`; trang chi tiết dùng chung mục cha. |

## Lệnh nền và nguồn số liệu cho phân hệ khác

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `AcademicProjectSlaService::run` (lệnh `sla:enforce`) — nhắc mốc sắp đến hạn | `projects.Service.RunSla` chạy mỗi 5 phút (`RunSlaLoop` trong `cmd/menglish`) | Đã làm | Quy tắc `academic.milestone_remind` (mặc định 24 giờ) từ danh mục SLA; nhắc một lần / hạn (`reminded_at`), báo `academic_project_due`. |
| `AcademicProjectSlaService::breachLate` — lập biên bản trễ mốc | `RunSla` → `Penalties.Breach` | Làm một phần | Tính trễ sau 23:59 ngày deadline + ân hạn `academic.milestone_late`, chỉ dự án đã chốt, từ thời điểm bật (`sla_academic_project_live_since` trong `core.settings`). v2 không có sổ `sla_events`: cột `milestones.breached_at` đánh dấu đã lập. Biên bản đi qua `projects.Penalties` — hiện no-op nên chưa lập gì và lần quét sau thử lại (`TODO(gộp)`: `WithPenalties` khi có phân hệ Lương). |
| `KpiAutoCounter` nguồn `academic_deliverable_on_time` | `projects.Service.KpiSources()` gắn vào `kpi.AddSources` | Đã làm | Mốc đến hạn trong kỳ người này nhận (hoặc chưa giao, thuộc dự án mình phụ trách); hệ số trễ theo ngày (đúng hạn 1; 1–3 ngày 0,5; 4–7 ngày 0,25; ≥ 8 ngày 0). Bỏ khỏi danh sách "chờ phân hệ". |
| `DailyReportMetrics` — Buổi dự giờ (Học thuật) | `quality.ReportMetrics` bọc `reports.MetricsProvider` | Khác | Dòng "lớp · GV · xếp loại" dùng **nhãn** xếp loại (Laravel in mã). Lớp đã xóa không tính. |
| `DailyReportMetrics` — mốc dự án đến hạn / quá hạn / xong | `projects.ReportMetrics` | Đã làm | |
| `WeeklyReportMetrics` — Buổi dự giờ, mốc dự án, gợi ý "đã làm" / "kế hoạch" | `quality.ReportMetrics`, `projects.ReportMetrics` | Đã làm | Gợi ý dự giờ (`qa:<id>`, `obs:<id>`) và mốc (`milestone:<id>`, "Đã làm X/Y unit · Phụ trách · Quá hạn"), giới hạn 30 dòng chống trùng nguồn như Laravel. |
| Báo cáo tháng giáo viên — buổi họp giáo viên | `quality.ReportMetrics.TeacherMeetings` | Đã làm | Mục "meetings" không còn ghi "chờ phân hệ". |
| `MyWorkBoard` — lịch hạn mốc dự án, hiện ô khi có mốc chưa xong | `projects.DashboardMilestones()` → `dashboard.Deps.Projects` | Đã làm | Mục lịch loại "Hạn mốc dự án" (chỉ ngày); chưa nối thì lịch giữ ghi chú "Chờ phân hệ Dự án học thuật". |

## Phần khác

| Laravel | Trạng thái | Ghi chú |
| --- | --- | --- |
| Activity log của các model | Đã làm | `audit.RegisterSubject` cho dự giờ / checklist / họp giáo viên (phân hệ Học viên & Lớp học) và `academic_project`, `academic_project_milestone`, `academic_project_update` (phân hệ Giáo trình); ghi phần thay đổi. |
| Loại thông báo `NotificationService` | Đã làm | `notify.RegisterType` 6 loại của dự án (`academic_project_member`, `_locked`, `_milestone`, `_update`, `_response`, `_due`). |
| Quyền `class_quality.*`, `academic_project.*` (`permission_catalog.php`, migration quyền) | Đã làm | Dùng tên quyền sẵn có trong danh mục v2, không thêm quyền mới. |
| Nhập dữ liệu cũ (dự giờ, checklist, dự án) | Chưa làm | Chờ công cụ `import-legacy` mở rộng sang dữ liệu nghiệp vụ. |
