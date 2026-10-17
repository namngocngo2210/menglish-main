# Đối chiếu: Thu - Chi (sổ khoản chi vận hành, doanh thu tạm tính)

Nguồn Laravel: `app/Http/Controllers/FinanceController.php`, `app/Models/OperatingExpense.php`, `routes/web.php` (nhóm
`finance.*`), `resources/js/Pages/Finance/Expenses/Index.vue`, `resources/js/Pages/Finance/Reports/ProvisionalRevenue.vue`,
migration `2026_09_12_140000_create_operating_expenses_table.php`. Đối chiếu tại commit `20f88811`.

v2: bảng `finance.expenses` (migration `00130_finance_expenses.sql`), Go `v2/api/internal/finance`, web
`v2/web/src/features/finance`. Mọi route nằm dưới `/api/v1/finance`, bọc `iam.Require("finance.view")` như middleware
`can:finance.view` của nhóm route Laravel; thêm / sửa / xóa khoản chi thêm `iam.Require("tuition.create")`.

Trạng thái: **Đã làm** · **Khác** (đã làm, có khác biệt có chủ đích, xem ghi chú) · **Làm một phần** · **Chưa làm**.

## Màn và route

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `finance.expenses.index` GET `/finance/expenses` (`expenses`) | `GET /api/v1/finance/expenses?month=&branch_id=&search=` · màn `/finance/expenses` (Thu - Chi → Khoản chi vận hành) | Làm một phần | 4 thẻ (tổng chi so với tháng trước, chi lương tự động, chi tự nhập, cơ cấu chuyển khoản / tiền mặt), lọc 6 kỳ tháng gần nhất, chi nhánh trong phạm vi `finance` (mức Chi nhánh không có "Tất cả" và không chọn được chi nhánh khác; chưa gán chi nhánh → 403 `no_branch`), tìm theo nội dung / ghi chú / người lập. **Dòng chi lương tự động** lấy qua `finance.PayrollSource` — phân hệ Lương chưa chuyển nên luôn ẩn kèm thông báo "Chờ phân hệ Lương…" (`TODO(gộp)`). |
| `finance.expenses.store` POST (`storeExpense`, `can:tuition.create`) | `POST /api/v1/finance/expenses` | Khác | Kiểm dữ liệu và thông báo như Laravel (ngày chi, nội dung ≤ 255, số tiền ≥ 1.000 đ, hình thức chi, chi nhánh trong phạm vi, ghi chú ≤ 1000, phân loại). Số tiền lưu `bigint` đồng (Laravel `decimal(15,2)`), làm tròn đến đồng. Ghi nhật ký `audit.Record` (subject `operating_expense`). |
| `finance.expenses.update` PUT | `PUT /api/v1/finance/expenses/{id}` | Đã làm | Khoản chi (chi nhánh cũ hoặc mới) ngoài phạm vi chi nhánh → 403 như `abort(403)` của Laravel; không có → 404. Nhật ký ghi phần thay đổi. |
| `finance.expenses.destroy` DELETE | `DELETE /api/v1/finance/expenses/{id}` | Đã làm | Xóa mềm (`deleted_at`) như `SoftDeletes`; có hộp xác nhận. |
| `finance.expenses.export` GET | `GET /api/v1/finance/expenses/export` | Đã làm | CSV UTF-8 có BOM như `StreamedResponse` của Laravel, ngày tăng dần, cùng tiêu đề và cột (STT, ngày chi, nội dung, số tiền, hình thức, chi nhánh, người lập, ghi chú). |
| `finance.reports.revenue` GET `/finance/reports/provisional-revenue` (`provisionalRevenue`) | `GET /api/v1/finance/reports/provisional-revenue?month=&branch_id=` · màn `/finance/reports/provisional-revenue` (Doanh thu → Doanh thu tạm tính) | Làm một phần | Doanh thu tạm tính = Tổng thu (phiếu đã duyệt) − Chi vận hành; nguồn thu học phí / phụ thu, cơ cấu chuyển khoản / tiền mặt, so với tháng trước, cơ cấu chi (lương / mặt bằng / giáo trình / khác), ma trận chi nhánh với nhãn biên lợi nhuận (Tốt / Đạt mục tiêu / Ổn định / Cần tối ưu) và dòng tổng. **Phiếu thu** đọc qua `finance.RevenueSource` — phân hệ Học phí đang chuyển song song nên mặc định "Chờ phân hệ Học phí…" và tổng thu 0 (`TODO(gộp)`: nối `internal/tuition`). Chi lương như dòng trên. |
| `finance.reports.revenue.export` GET | `GET /api/v1/finance/reports/provisional-revenue/export` | Đã làm | CSV ma trận chi nhánh theo đúng bộ lọc + dòng tổng cộng. |

## Phần khác

| Laravel | Trạng thái | Ghi chú |
| --- | --- | --- |
| Model `OperatingExpense` (activity log) | Đã làm | `audit.RegisterSubject("operating_expense")`; đường dẫn `/finance` thuộc phân hệ Học phí & Thu chi của nhật ký. |
| Phạm vi dữ liệu `TuitionBranchScope::FINANCE` | Đã làm | Mức phạm vi của module `finance` trong danh mục quyền (`iam.Access.Level("finance")`). |
| Số liệu phiếu thu (`revenueReceiptQuery`) | Chưa làm | Chờ gộp phân hệ Học phí (`finance.RevenueSource`). |
| Chi lương tự động (`PayrollPeriod` approved / paid) | Chưa làm | Chờ phân hệ Lương (`finance.PayrollSource`). |
