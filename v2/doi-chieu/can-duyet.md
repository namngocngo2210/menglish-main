# Đối chiếu: Hộp "Việc cần duyệt"

Nguồn Laravel: `app/Http/Controllers/ApprovalController.php`, `app/Providers/ApprovalServiceProvider.php`,
`app/Support/Approvals/*` (`ApprovalInboxService`, `ApprovableSource`, `QueryApprovalSource`, `ApprovalItem`,
`ApprovalResult`, `AdminOnlyApprovals`, `ControllerActionInvoker`), các adapter `app/Services/*/Approvals/*ApprovalSource.php`,
`app/Support/Navigation/AppShell.php` (badge), `resources/js/Pages/Approvals/*`, `resources/js/Layouts/Shell/SidebarLink.vue`.
Đối chiếu tại commit `5caf8ba9`.

v2: Go `v2/api/internal/approvals` (hộp, cache số đếm, duyệt hàng loạt) + adapter cạnh từng phân hệ
(`tuition/approvals.go`, `crm/approvals.go`, `classes/approvals.go`, `tasks/approvals.go`, truy vấn ở `<phân hệ>/approvals.sql`),
web `v2/web/src/features/approvals`, badge ở `components/shell/Sidebar.tsx`. Không có bảng mới.

Trạng thái: **Đã làm** · **Khác** (đã làm, khác có chủ đích, xem ghi chú) · **Làm một phần** · **Chưa làm**.

## Màn và route

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `approvals.index` GET `/approvals?group=` (`auth`) | `GET /approvals?group=` · màn `/approvals` (Cần duyệt → Việc cần duyệt, tab đầu) | Đã làm | Chỉ Admin (`ApprovalInboxService::allowsModule`), người khác 403 "Bạn không có quyền duyệt mục nào.". Chip nhóm Học phí / Đào tạo / Công việc / Nhân sự (slug `hoc-phi`, `dao-tao`, `cong-viec`, `nhan-su`), "Tất cả" kèm tổng; nhóm lạ = mọi nhóm. Mỗi nguồn tối đa 15 mục (`PER_SOURCE`), nguồn rỗng xuống cuối nhóm và không hiện; "Xem tất cả N mục ở màn gốc". Route bọc thêm `iam.RequireAny` các quyền neo của nguồn (quy ước v2). |
| `approvals.show` GET `/approvals/{source}/{id}` | `GET /approvals/{source}/{id}` · hộp thoại chi tiết trên màn hộp | Khác | Cùng dữ liệu (số tiền, các dòng thông tin, thời điểm gửi, cờ "Quá hạn xử lý"), nút Duyệt / Từ chối (nhập lý do) / Mở màn gốc. Laravel mở thẳng URL thì ra trang đầy đủ; v2 chỉ có hộp thoại. Mục không còn chờ → 404 `approval_not_found`. |
| `approvals.bulk` POST (`action`, `items[]`, `reason`, `single`) | `POST /approvals/bulk` (`action`, `items`, `reason`) | Đã làm | Mỗi mục xử lý riêng bằng đúng nghiệp vụ của màn gốc (`ApproveReceipt`, `RejectRefund`, `ApproveTransfer`…), lỗi nghiệp vụ hiện nguyên văn; tối đa 100 mục ("Mỗi lần xử lý tối đa 100 mục."), lý do từ chối bắt buộc ≤ 1000 ký tự. Thông báo "Đã duyệt x/y mục, z mục không xử lý được." / "Đã từ chối x/y mục." / một mục thì thông báo của màn gốc. Mục trùng / sai dạng / không còn chờ được báo từng dòng. Hộp chi tiết gửi 1 mục (thay `single=1`): lỗi thì giữ hộp mở. |
| Badge "Cần duyệt" ở sidebar (`AppShell` → `ApprovalInboxService::badge`) | `GET /approvals/badge` → `{count}` (null nếu không phải Admin) · `Sidebar.tsx` | Đã làm | `>99` hiện "99+", 0 thì ẩn; tooltip / nhãn đọc màn hình kèm số. Web giữ 60 giây rồi hỏi lại. |
| Cache số đếm 60 giây theo người + "phiên bản" chung | Redis `counts:approvals:<user>` (60 giây) + `approvals:version` | Khác | Laravel đổi phiên bản mỗi khi model của nguồn lưu / xóa (`watchedModels`). v2 đổi phiên bản sau mỗi lần xử lý qua hộp; thao tác ở màn gốc thì số đếm có thể trễ tối đa 60 giây. Redis lỗi thì đếm thẳng. |

## Nguồn duyệt

| Laravel (key — nhãn) | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `receipt` — Phiếu thu chờ duyệt | `tuition.receiptSource` | Đã làm | Duyệt / từ chối qua `ApproveReceipt` / `RejectReceipt`; mục: mã phiếu, học viên, số tiền, hình thức, người lập. |
| `invoice_cancellation` — Yêu cầu hủy hóa đơn | `tuition.cancellationSource` | Đã làm | Duyệt / từ chối như màn Hủy hóa đơn. |
| `refund` — Hoàn tiền & khất nợ | `tuition.refundSource` | Đã làm | Chỉ từ chối được ở hộp (duyệt cần nhập thêm thông tin ở màn gốc, như Laravel); cờ "Quá hạn xử lý" theo hạn của từng loại. |
| `payment_report` — Học viên báo đã đóng học phí | — | Chưa làm | Báo đóng học phí ở Cổng PH/HS đang chuyển song song (`TODO(gộp)` ở `cmd/menglish/main.go`). |
| `enrollment` — Xác nhận nhập học | `classes.enrollmentSource` | Đã làm | Chỉ link màn gốc (`/students/enrollments`); mục: "✓ Giáo trình · ✗ Nhóm Zalo". |
| `crm_confirmation` — Xác nhận chính thức (CRM) | `crm.confirmationSource` | Đã làm | Chỉ link màn gốc (`/crm/confirmations`). |
| `crm_branch_transfer` — Chuyển cơ sở (CRM) | `crm.transferSource` | Đã làm | Duyệt / từ chối qua `ApproveTransfer` / `RejectTransfer`; tiêu đề "Khách: Chi nhánh A → Chi nhánh B". |
| `syllabus_proposal` / `syllabus_adjustment` / `big_test_order` | — | Chưa làm | Phân hệ Giáo trình & Big Test đang chuyển song song (`TODO(gộp)`). |
| `work_task` — Xác nhận hoàn thành việc | `tasks.taskSource` | Làm một phần | Đếm / liệt kê / duyệt / trả về như màn Hoàn thành công việc; chưa hiện tên lớp của việc. |
| `class_report` — Báo cáo trực lớp | — | Chưa làm | Chờ phân hệ Chất lượng lớp (`TODO(gộp)`). |
| `staff_attendance_request` — Đơn chấm công & nghỉ | — | Chưa làm | Chờ phân hệ Chấm công (`TODO(gộp)`). |

Thêm nguồn khi gộp: viết `approvals.Source` (hoặc nhúng `approvals.LinkOnly` nếu chỉ link màn gốc) cạnh phân hệ, rồi
`approvalSvc.Add(...)` ở `cmd/menglish/main.go`; nhóm dùng hằng `approvals.GroupTuition / GroupAcademic / GroupWork /
GroupHR`.
