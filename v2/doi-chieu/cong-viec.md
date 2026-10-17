# Đối chiếu: Công việc (Tạo đầu việc / Danh sách đầu việc / Cổng TA)

Nguồn Laravel: `app/Http/Controllers/WorkTaskController.php`, `app/Models/WorkTask.php`,
`app/Console/Commands/MarkOverdueTasksCommand.php`, `app/Services/WorkTasks/Approvals/WorkTaskApprovalSource.php`,
`app/Support/Approvals/AdminOnlyApprovals.php`, `routes/web.php` (khối `tasks.*` và `portal.ta-tasks`),
`resources/js/Pages/Tasks/*.vue`. Đối chiếu tại commit `41070de1`.

v2: bảng `ops.work_tasks` (migration `00050_ops_work_tasks.sql`), Go `v2/api/internal/tasks`, web
`v2/web/src/features/tasks`. Mọi route nằm dưới `/api/tasks`, bọc `iam.Require("work_task.view")` như middleware
`can:work_task.view` của nhóm route Laravel; route con thêm quyền riêng như bảng dưới.

Trạng thái: **Đã làm** · **Khác** (đã làm, có khác biệt có chủ đích, xem ghi chú) · **Chưa làm** (lý do kèm theo).

## Route

| Laravel route / method | v2 API + màn | Trạng thái | Ghi chú |
|---|---|---|---|
| `GET /tasks` `tasks.index` → `index` | `GET /api/tasks` · màn `/tasks` (Danh sách đầu việc) | Đã làm | Tab "Việc của tôi" / "Việc tôi giao", lọc tìm kiếm, trạng thái, từ ngày – đến ngày, người thực hiện (tab việc tôi giao), sắp xếp như Laravel (quá hạn → bị chặn → chờ xác nhận → đang làm → mới → xong → hủy, rồi mới tạo trước). Thẻ số: việc của tôi, việc tôi giao, quá hạn, chờ xác nhận, cần duyệt (Admin). Phạm vi dữ liệu own / branch / all theo `work_task.scope_*` (own = người giao / người thực hiện / người chuyển lại; branch = thêm việc có chi nhánh hoặc người thực hiện thuộc chi nhánh của tôi). Phân trang 10 / 20 / 50 / 100 mỗi trang (mặc định 10). |
| `GET /tasks/create` `tasks.create` → `create` | `GET /api/tasks/options` · màn `/tasks/new` (Tạo đầu việc) | Đã làm | `RequireAny(work_task.create, work_task.request)`. Danh sách người nhận: có `create` → mọi nhân sự trong phạm vi (scope branch: chi nhánh của tôi + bản thân + Admin); chỉ `request` → Admin và người có `work_task.approve`. Nút chuyển "Giao cho: Nhân sự / Trợ giảng (theo ca)" khi có `work_task.assign`. Tiêu đề màn "Tạo đầu việc" / "Đề xuất việc cho Admin / Học vụ" theo quyền. |
| `POST /tasks` `tasks.store` → `store` | `POST /api/tasks` | Đã làm | Kiểm tra như Laravel: tiêu đề bắt buộc ≤255, hạn bắt buộc, giờ hạn mặc định 18:00, việc lặp lại mặc định hàng tuần, người nhận phải nằm trong danh sách được giao (thông báo "Bạn chỉ được giao việc cho Admin, Quản lý cơ sở, Học vụ hoặc Học thuật."). Nhận `class_id` như Laravel (`nullable|exists:classes,id` → "Lớp học đã chọn không tồn tại."); form "Gắn lớp (nếu có)" liệt kê lớp đang học mình thấy, nhãn "Tên (Mã)" (`tasks.Classes`, do `classes.TaskClasses` cài). Danh sách / chi tiết hiện nhãn lớp "Tên lớp · buổi". |
| `POST /tasks/{id}/status` `tasks.status.update` → `updateStatus` | `POST /api/tasks/{id}/status` · form trong chi tiết + menu thao tác ở danh sách | Đã làm | Chỉ người giao / người thực hiện / người duyệt. Bảng chuyển trạng thái giống `WorkTask::allowedTransitions`: chỉ Admin chuyển sang Hoàn thành từ Chờ xác nhận, không tự duyệt việc mình giao cho mình, Hủy chỉ người giao hoặc Admin, Bị chặn / Hủy bắt buộc lý do. "Chuyển lại cho người giao" (`hand_back`) khi việc bị chặn: việc về người giao, trạng thái Mới, giữ lý do, ghi `handed_back_from`, nối "[Ghi chú khi chuyển lại – Tên]: …" vào mô tả. Hoàn thành việc lặp lại → tự tạo lần kế (ngày / tuần / tháng, tháng không tràn ngày cuối), không tạo trùng. Khóa dòng `FOR UPDATE`, tăng `version`. |
| `GET /tasks/{id}` `tasks.show` → `show` | `GET /api/tasks/{id}` · modal xem nhanh trong danh sách + trang `/tasks/<id>` khi mở thẳng link | Khác | Mở được khi việc nằm trong phạm vi xem của tôi, ngoài phạm vi trả 404 (không lộ việc tồn tại). Mã nghiệp vụ hiển thị "Công việc #no" (cột `no` tăng dần) thay cho id số của Laravel; đường dẫn dùng uuid. |
| `GET /tasks/ta-assign` `tasks.ta-assign` → `taAssignForm` | `GET /api/tasks/ta-assign/options` · màn `/tasks/ta-assign` | Đã làm | `Require(work_task.assign)`. Trợ giảng = người có quyền đối tượng `portal.assistant` (vai trò hoặc quyền riêng), scope branch chỉ trợ giảng có chi nhánh chính thuộc chi nhánh của tôi. Cảnh báo gửi muộn sau 15:30 chạy theo đồng hồ. "Gắn lớp?": lớp đang học / sắp khai giảng mình thấy ("Tên (Mã) - lịch học") và buổi học thật 7 ngày trước → 30 ngày tới (không hủy, không phụ đạo), lọc theo ngày giao trên trình duyệt, nhãn "Buổi N (dd/mm) · HH:MM-HH:MM"; lớp không có buổi hôm đó thì gõ tên buổi. |
| `POST /tasks/ta-assign` `tasks.ta-assign.store` → `taAssignStore` | `POST /api/tasks/ta-assign` | Đã làm | Nhiều đầu việc theo ca Trước / Trong / Sau giờ học, tạo `kind = ta_daily`, hạn mặc định theo ca (14:00 / 18:00 / 21:30) khi không chọn buổi học; chọn buổi: Trước giờ học = giờ bắt đầu, Trong = giờ kết thúc, Sau = kết thúc + 60 phút (tối đa 23:59), chi nhánh theo buổi khi không chọn. Kiểm tra "Gắn lớp" như Laravel (chưa chọn lớp, lớp ngoài phạm vi, buổi không thuộc lớp / sai ngày, chưa chọn buổi). Tên buổi "Buổi N (dd/mm)" theo thứ tự buổi chính khóa; tên bài theo giáo trình chờ phân hệ Giáo trình. Gửi sau 15:30 hoặc ngày đã qua: vẫn lưu, trả `late = true` và câu báo gửi muộn (thông báo cho Admin: TODO notify). |
| `POST /tasks/{id}/complete` `tasks.complete` → `completeTask` | `POST /api/tasks/{id}/complete` · nút "Báo xong" ở cổng TA | Khác | Có link ảnh minh chứng (http/https) → Hoàn thành ngay; không có → Chờ xác nhận. **Ảnh minh chứng chỉ nhận URL**: chưa có upload file (chờ module files; cột `completion_proof_file_id` đã có sẵn, chưa FK). Người duyệt không phải người thực hiện phải nhìn thấy việc trong phạm vi mới báo xong hộ (Laravel chỉ kiểm tra quyền duyệt). |
| `GET /tasks/manual-approvals` `tasks.manual-approvals` → `manualApprovals` | `GET /api/tasks/approvals` · màn `/tasks/approvals` (Cần duyệt → Hoàn thành công việc) | Khác | Liệt kê việc Chờ xác nhận không có ảnh minh chứng, lọc tìm kiếm + người thực hiện, tách link trong ghi chú báo cáo. Chỉ Admin xem được danh sách (người khác thấy thông báo). **Phần báo cáo trực lớp chờ xác nhận: chờ module lớp.** Menu chỉ hiện với Admin có `work_task.create` như SidebarMenu. |
| `POST /tasks/{id}/approve` `tasks.approve` → `approveTask` | `POST /api/tasks/{id}/approve` | Đã làm | Chỉ Admin (`AdminOnlyApprovals`), không tự duyệt việc mình làm, chỉ việc đang Chờ xác nhận; ghi `confirmed_by/at`, `completed_at`, nối "[Ghi chú duyệt]: …"; việc lặp lại → tạo lần kế. |
| `POST /tasks/{id}/reject` `tasks.reject` → `rejectTask` | `POST /api/tasks/{id}/reject` | Đã làm | Chỉ Admin, bắt buộc lý do ("Vui lòng nhập lý do từ chối / yêu cầu bổ sung."), trả về Đang thực hiện, lưu `rejection_reason`. |
| `GET /tasks/classes-dashboard` `tasks.classes-dashboard` | — | Chưa làm – chờ module lớp/KPI | Bảng lớp / buổi học theo ngày dựa trên lớp và lịch học. |
| `GET /tasks/class-reports/create` `tasks.class-reports.create` | — | Chưa làm – chờ module lớp/KPI | Báo cáo trực lớp gắn buổi học. |
| `POST /tasks/class-reports` `tasks.class-reports.store` | — | Chưa làm – chờ module lớp/KPI | |
| `POST /tasks/class-reports/{id}/approve` | — | Chưa làm – chờ module lớp/KPI | |
| `POST /tasks/class-reports/{id}/reject` | — | Chưa làm – chờ module lớp/KPI | |
| `GET /tasks/schedule-config` `tasks.schedule-config` | — | Chưa làm – chờ module lớp/KPI | Cấu hình lịch trực theo lớp / ca. |
| `POST /tasks/schedule-config` `tasks.schedule-config.update` | — | Chưa làm – chờ module lớp/KPI | |
| `GET /tasks/support-sessions` `tasks.support-sessions` | — | Chưa làm – chờ module lớp/KPI | Buổi hỗ trợ / phụ đạo gắn lớp và học viên. |
| `POST /tasks/support-sessions` `tasks.support-sessions.store` | — | Chưa làm – chờ module lớp/KPI | |
| `POST /tasks/support-sessions/{id}/complete` | — | Chưa làm – chờ module lớp/KPI | |
| `POST /tasks/hr-demand` `tasks.hr-demand.save` | — | Chưa làm – chờ module lớp/KPI | Nhu cầu nhân sự theo lớp. |
| `GET /tasks/kpi-dashboard` `tasks.kpi-dashboard` | `GET /api/v1/kpi/board` (+ `/export`) · màn `/tasks/kpi-dashboard` (Nhân sự & KPI → KPI tự động) | Khác | Làm trong phân hệ KPI (`internal/kpi`, xem [kpi.md](kpi.md)): giữ chân / chuyên cần / công việc theo phạm vi Công việc, kỳ từ tháng → đến tháng; số việc có hạn / đã xong qua `tasks.KpiBoard`. Tỉ lệ bài tập chờ phân hệ Bài tập; file xuất là CSV thay `.xlsx`. |
| `GET /portal/ta-tasks` `portal.ta-tasks` → `taPortal` | `GET /api/tasks/ta-portal` · màn `/portal/ta-tasks` (Cổng Giáo viên → Nhiệm vụ hôm nay của TA) | Khác | Tiến độ trong ngày (xong/tổng, %), chọn ngày, người giao / duyệt chọn trợ giảng trong phạm vi (ngoài phạm vi → 403), cảnh báo số việc quá hạn từ ngày trước, 3 nhóm ca thu gọn được (mở sẵn ca đầu còn việc), thẻ việc: hạn, người giao, trễ bao nhiêu giờ, lý do bị chặn / yêu cầu làm lại. Mục "Lớp trực": buổi không hủy trong ngày mình dạy / trợ giảng hoặc của lớp có việc giao trong ngày (tên lớp, giờ, phòng, chi nhánh, nhãn Học bù); thẻ việc gắn lớp hiện "Mã lớp · buổi", phòng và giờ ca học của buổi trong ngày. Nhãn "Đang chờ Admin xác nhận báo cáo trực lớp" chờ màn báo cáo trực lớp. Menu hiện theo quyền đối tượng `portal.teacher` / `portal.assistant` (quyền neo nhóm Cổng Giáo viên). |

## Ngoài route

| Laravel | v2 | Trạng thái | Ghi chú |
|---|---|---|---|
| `tasks:mark-overdue` (`MarkOverdueTasksCommand`, cron) | `Service.MarkOverdue` + `RunOverdueLoop` chạy 10 phút/lần trong tiến trình `menglish` | Khác | Cùng luật: quá `due_date + due_time` (mặc định 23:59) thì Quá hạn; bỏ qua việc `ta_daily`, Hoàn thành, Chờ xác nhận, Đã hủy; chạy lại không đổi gì thêm (idempotent). Chạy bằng ticker thay vì lệnh cron. Báo người nhận "Công việc quá hạn: …" khi việc chuyển Quá hạn (cùng giao dịch). |
| Việc lặp lại (`WorkTask::spawnNextOccurrence`) | `spawnNext` trong luồng đổi trạng thái / duyệt | Đã làm | |
| Thông báo (`TaskAssigned`, `TaskStatusChanged`, `TaskHandedBack`, báo gửi muộn …) | `tasks/notify.go` gọi `notify.Send` trong giao dịch của thao tác | Đã làm | Giao việc, đổi trạng thái (theo nhánh của Laravel), chờ xác nhận, trả về / gỡ chặn, chuyển lại người giao, giao việc TA muộn, quá hạn; không báo chính người thao tác. |
| Nhật ký (audit) | `audit.Middleware` ghi dòng chung cho mọi thao tác ghi (đường dẫn `/tasks` thuộc nhóm Công việc) + `slog` | Đã làm | Laravel cũng không `Audit::describe` riêng cho công việc. |
| Hộp duyệt chung `approvals.index` (`WorkTaskApprovalSource`) | — | Chưa làm – chờ module duyệt chung | `GET /api/tasks/approvals` và `CountAwaitingConfirmation` có sẵn để làm nguồn. |
| Việc do module khác tạo: chăm sóc tháng đầu (CRM / `FirstMonthCareService`), SLA (`SlaBreachService`, `BigTestSlaService`), bổ sung hàng (`RestockTaskService`), theo dõi báo cáo nhân sự | Nhập bù hàng: `tasks.Service.Restocker` (kho gọi trong giao dịch xuất / nhập). Việc follow-up báo cáo ngày: `tasks.Service.ReportTasks`. Cột `student_id`, `care_milestone`, `big_test_id`, `kind`, `sla_breached_at` đã có | Làm một phần | Đã làm: nhập bù (một việc mở cho mỗi chi nhánh + mặt hàng, giao Admin đầu tiên, hạn SLA `stock.restock_task` lúc 17:00, mọi Admin nhận "Việc mới: …", tồn lên trên 0 thì tự hoàn thành) và việc follow-up báo cáo ngày. Chưa làm: chăm sóc tháng đầu, SLA, Big Test (chờ các phân hệ đó). Khóa ngoại `student_id` → `academic.students`, `merchandise_item_id` → `ops.inventory_items` (migration 00101); `class_id`, `big_test_id`, `completion_proof_file_id` chờ phân hệ lớp / giáo trình / file. |
| `MyWorkBoard` (bảng việc ở dashboard) | — | Chưa làm – thuộc dashboard | |

## Khác biệt có chủ đích

- Đếm "quá hạn" ở cổng TA (`CountOverdueBefore`) bỏ qua cả việc Đã hủy.
- Nhãn ca (Trước / Trong / Sau giờ học) chỉ hiện với việc `ta_daily` hoặc việc có buổi học.
- Ảnh minh chứng chỉ là link; chỉ lưu metadata (`completion_proof_url`, `completion_proof_file_id` chờ module files).
- Tên buổi khi gắn việc là "Buổi N (dd/mm)" (chưa có tên bài theo giáo trình).
- Mã nghiệp vụ "Công việc #no".
- Laravel không có bình luận, checklist hay nhiều người thực hiện cho một việc, v2 cũng không thêm.

## Kiểm thử

- Go: `internal/tasks/rules_test.go` (bảng chuyển trạng thái, nhãn, trễ giờ, lần lặp kế, giao TA muộn, tách link) và
  `internal/tasks/integration_test.go` (quyền tạo / đề xuất và danh sách người nhận, tab + đếm + lọc, luồng trạng thái +
  duyệt + lặp lại, chuyển lại + hủy, phạm vi chi nhánh, giao TA + cổng TA + báo xong, đánh dấu quá hạn), `internal/tasks/classes_test.go` (giờ hạn theo buổi, tên buổi);
  gắn lớp / buổi học / lớp trực: `internal/classes/links_integration_test.go` (`TestTaskClasses`).
- Web: `src/features/tasks/logic.test.ts` (logic thuần + menu theo quyền).
