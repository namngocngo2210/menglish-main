# Đối chiếu: Học phí (công nợ, phiếu thu, hóa đơn, hoàn phí, nhắc nợ, SePay, nhập Excel)

Nguồn Laravel: `app/Http/Controllers/TuitionController.php`, `app/Http/Controllers/SepayWebhookController.php`,
phần học phí của `CrmController` (`tuitionBill`, Chốt & Xếp lớp), `StudentProfileController` (tab học phí),
`SystemConfigController::updateSepayConfig` / tab SePay của `bankAccounts`, model `StudentTuition`, `TuitionReceipt`,
`InvoiceConfiguration`, `InvoiceCancellation`, `TuitionRefundRequest`, `TuitionContactLog`, `SepayConfiguration`,
`SepayTransaction`, dịch vụ `app/Services/Tuition/*`, `TuitionImportService`, `StudentDeferralService`, `app/Support/TransferMemo.php`,
`TuitionBranchScope.php`, `Money.php`, lệnh `tuition:*` / `students:*-deferrals`, màn `resources/js/Pages/Tuition/*`.
Đối chiếu tại commit `9c9baaa8` (file Laravel giống `5caf8ba9`).

v2: schema `finance` (migration `00120_finance_tuition.sql` … `00124_finance_tuition_import.sql`), Go `v2/api/internal/tuition`,
web `v2/web/src/features/tuition`. Route nghiệp vụ dưới `/api/v1/tuition`, bọc `iam.Require("tuition.view")` như middleware
`can:tuition.view` của nhóm route Laravel, cộng quyền riêng của từng thao tác (cột Laravel). Phạm vi chi nhánh theo
`TuitionBranchScope` = mức phạm vi module `tuition` (`Access.BranchLimit / CoversBranch`). Webhook SePay giữ đúng đường dẫn cũ
ngoài `/api/v1` (`server.Deps.Root`, Caddy `handle /hook/*`).

Trạng thái: **Đã làm** · **Khác** (đã làm, khác có chủ đích, xem ghi chú) · **Làm một phần** · **Chưa làm**.

## Màn và route

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `tuition.students` GET `/tuition/students` | `GET /tuition/students` · màn `/tuition/students` (Học phí → Công nợ học viên) | Đã làm | Lọc tìm / chi nhánh / lớp / trạng thái, thống kê, nhóm quá hạn nghiêm trọng / mới quá hạn / sắp đến hạn có phân trang, nút Nhập Excel / Lập phiếu thu. |
| `tuition.students.payments` | `GET /tuition/students/{id}/payments` (hộp lịch sử thu) | Đã làm | Mọi khoản + phiếu của học viên trong phạm vi. |
| `tuition.import` / `import.store` / `import.confirm` / `import.template` | `GET /tuition/import[?token=]`, `POST /tuition/import` (multipart `branch_id` + `excel_file`), `POST /tuition/import/confirm`, `GET /tuition/import/template` · màn `/tuition/import` | Khác | Cùng cột / tên cột thay thế, kiểm từng dòng và thông báo như `TuitionImportService` (công nợ dự kiến theo các dòng trước, mã giao dịch trùng trong file / đã có phiếu, tối đa 1000 dòng, 10MB), mỗi dòng một giao dịch, phiếu luôn **chờ duyệt**. Bản xem trước lưu ở `finance.tuition_import_batches` (chỉ người tải lên mở / xác nhận, xác nhận một lần) thay cho session. Không đọc `.xls` (định dạng cũ): báo lưu lại thành `.xlsx` như phần nhập khách CRM. Ghi nhật ký `tuition.import` + `tuition.receipt.import`. |
| `receipts.create` / `receipts.edit` (form) | `GET /tuition/receipts/form?student_id=&tuition_id=&receipt_id=` · màn `/tuition/receipts/new`, `/tuition/receipts/<id>/edit` | Đã làm | Thu theo buổi (sổ buổi) hoặc theo số tiền, khuyến mãi, phụ thu hàng hóa, VietQR theo tài khoản như `BankAccount::resolveFor`: tài khoản của hợp đồng (chỉ khi dùng được cho cơ sở) → tài khoản gắn cơ sở → tài khoản dùng chung, không bao giờ lấy tài khoản cơ sở khác; chưa chọn khoản học phí thì theo cơ sở của học viên (`bankByBranch`). Hộp "Tài khoản ngân hàng nhận học phí" (ngân hàng, số TK, chủ TK, nội dung CK, nhãn phạm vi tài khoản); không có tài khoản dùng được → cảnh báo "Chưa cấu hình tài khoản ngân hàng…", không tạo VietQR. Số hóa đơn giấy dự kiến, phiếu bị trả về gần nhất. Khác: v2 mỗi tài khoản gắn một cơ sở hoặc dùng chung (Laravel gắn nhiều cơ sở qua `bank_account_branch`). |
| `receipts.store` POST | `POST /tuition/receipts` (JSON, hoặc multipart `payload` + `proof_image`) | Đã làm | Kiểm số tiền / phụ thu / chiết khấu / vượt công nợ / mã giao dịch trùng (cả giao dịch SePay đã gạch) / minh chứng bắt buộc như Laravel. Ảnh minh chứng (jpg, png, webp, pdf ≤ 5120 kB) lưu ở kho file (`tuition_receipt_proof`, kiểm quyền khi mở). |
| `receipts.update` PUT | `PUT /tuition/receipts/{id}` (JSON) và `POST /tuition/receipts/{id}` (multipart kèm ảnh) | Đã làm | Chỉ người lập / super admin, chỉ phiếu nháp / bị trả về; gỡ minh chứng (`remove_proof`). |
| `receipts.approve` GET | `GET /tuition/receipts/approve` · màn `/tuition/receipts/approve` (Cần duyệt → Duyệt phiếu thu) | Đã làm | Lọc trạng thái / chi nhánh / hình thức / tìm, số liệu chờ duyệt / hôm nay, cảnh báo tồn kho hàng xuất. |
| `receipts.approve.action` (`can:tuition.approve`) | `POST /tuition/receipts/{id}/approve` | Đã làm | Cấp HĐĐT từ dải số, tính lại công nợ, xuất kho, báo người lập + xác nhận đã nhận tiền cho phụ huynh, email giao dịch. |
| `receipts.reject.action` (`can:tuition.reject`) | `POST /tuition/receipts/{id}/reject` | Đã làm | |
| `receipts.confirm-deposit` (`can:tuition.confirm_deposit`) | `POST /tuition/receipts/{id}/confirm-deposit` | Đã làm | |
| `tuition.history` / `history.export` | `GET /tuition/history`, `GET /tuition/history/export` · màn `/tuition/history` | Đã làm | CSV UTF-8 có BOM. |
| `invoices.cancellations` / `.export` | `GET /tuition/invoices/cancellations[/export]` · màn `/tuition/invoices/cancellations` (Cần duyệt → Hủy hóa đơn) | Đã làm | Số đếm theo trạng thái, chọn yêu cầu, điền sẵn từ phiếu (`cancel_invoice`). |
| `invoices.cancellations.store` (`can:invoice.request_cancel`) | `POST /tuition/invoices/cancellations` (JSON hoặc multipart `proof_image`) | Đã làm | Ảnh minh chứng ở kho file (`invoice_cancellation_proof`). Nút "Hủy số hóa đơn này" trên form phiếu đã cấp số giấy. |
| `invoices.cancellations.approve` / `.reject` (`can:invoice.approve_cancel`) | `POST /tuition/invoices/cancellations/{id}/approve` / `reject` | Khác | Hoàn tác công nợ, hủy phiếu, hoàn kho, nhật ký như Laravel. **Thu hồi hoa hồng** đi qua `tuition.Commissions` = `payroll.Service.Commissions()` (hủy khoản còn hoãn, tạo khoản điều chỉnh âm). Thêm thông báo `invoice_cancel_pending` / `invoice_cancel_processed` (Laravel chỉ có hộp duyệt chung). |
| `tuition.refunds` / `refunds.store` / `refunds.reject` | `GET /tuition/refunds`, `POST /tuition/refunds`, `POST /tuition/refunds/{id}/reject` · màn `/tuition/refunds` (Cần duyệt → Hoàn tiền & Khất nợ) | Đã làm | Hoàn phí / chuyển nhượng / khất nợ / bảo lưu, hạn xử lý, quyền duyệt theo loại hồ sơ. |
| `refunds.approve` | `POST /tuition/refunds/{id}/approve` (multipart ảnh bằng chứng) | Khác | Gợi ý / thu hồi hoa hồng qua `tuition.Commissions` = `payroll.Service.Commissions()`. Bảo lưu duyệt qua `students.ApproveDeferral`. Mã hồ sơ trong ghi chú dạng `#XXXXXX` (6 ký tự đầu id) thay cho số tự tăng. |
| `refunds.proof` GET | `GET /api/v1/files/<id>` (kho file, `refund_proof`) | Khác | Mở qua link ký của kho file, kiểm quyền bằng reader của Học phí (tuition.view + phạm vi chi nhánh). |
| `tuition.overdue` | `GET /tuition/overdue` · màn `/tuition/overdue` (Học phí → Quá hạn & Nhắc phí) | Đã làm | Nhóm đến hạn / quá hạn, thống kê theo chi nhánh / lớp, lần liên hệ gần nhất. |
| `overdue.remind` / `overdue.upcoming-remind` (`can:tuition.mark_contacted`) | `POST /tuition/overdue/{id}/remind`, `/upcoming-remind` | Khác | Cổng học viên (nếu có tài khoản) + email phụ huynh qua hàng đợi `notify/mail`; thư **đã xếp hàng** được tính là đã gửi (Laravel gửi đồng bộ). Thông báo cổng học viên chưa có link (chưa có cổng học viên ở v2). |
| `overdue.contacted` / `overdue.report-admin` | `POST /tuition/overdue/{id}/contacted`, `/report-admin` (`can:tuition.report_overdue`) | Đã làm | |
| `tuition.config` / `config.update` / `config.ranges.store` / `config.ranges.toggle` | `GET /tuition/config`, `POST /tuition/config`, `POST /tuition/config/ranges`, `POST /tuition/config/ranges/{id}/toggle` (`can:invoice_range.manage`) · màn `/tuition/config` (Cài đặt › Học phí → Dải số hóa đơn) | Đã làm | Dải mặc định cần `invoice_range.manage_default`; không chồng lấn, không lùi số đã cấp, cảnh báo sắp hết số, lịch sử cấp số; báo Quản lý chi nhánh / Admin khi đổi dải. |
| `crm.tuition-bill` GET `/crm/tuition-bill/{id}` | `GET /tuition/bill/{id}` (`lead.view` hoặc `tuition.view`) · màn `/tuition/bill/<id>` | Đã làm | Tài khoản nhận tiền theo `StudentTuition::resolveBankAccount` (hợp đồng → cơ sở → dùng chung, không lấy tài khoản cơ sở khác). |
| `sepay.webhook.gateway` POST `/hook/sepay-gateway/v1/add-payment`, `sepay.webhook.api` POST `/api/sepay/webhook` (throttle:120,1) | cùng đường dẫn, ngoài `/api/v1`, công khai | Khác | Cờ `SEPAY_WEBHOOK_ENABLED` (tắt → 503), cấu hình tắt → 403, HMAC-SHA256 (`X-SePay-Signature` / `X-Signature`, nhận tiền tố `sha256=`) hoặc API key (`X-API-Key` / `Authorization: Bearer|Apikey`), phương thức khác → 503; một lần theo mã SePay (Duplicate); tiền ra bỏ qua; chỉ gạch nợ tiền vào đúng tài khoản ngân hàng đã cấu hình; khớp nội dung CK gợi ý → mã học viên (HV-số / HV-ULID / HS…) → mã khách CRM (theo học viên đã chuyển đổi hoặc SĐT chuẩn hóa); chống ghi 2 lần với phiếu tay (cùng mã giao dịch → `duplicate_manual`, cùng số tiền ±3 ngày → `needs_review`); chỉ ghi phần còn nợ, phần thừa báo đối soát; phiếu đã duyệt nguồn `sepay` + HĐĐT; báo người đối soát, phụ huynh, email "[SePay Khớp Lệnh]". Khác: ghi giao dịch và gạch nợ **trong một giao dịch DB** (lỗi giữa chừng không để lại giao dịch "pending" mồ côi; SePay gửi lại thì xử lý lại); người lập phiếu là hệ thống (NULL) thay cho super admin; throttle giữ trong bộ nhớ tiến trình. |
| `sepay.transactions.recent` GET `/api/sepay/transactions` (`auth`, `can:tuition.view`) | cùng đường dẫn (phiên đăng nhập) và `GET /api/v1/tuition/sepay/transactions` | Đã làm | `{success, data}`; webhook tắt → 503. |
| `system-config.sepay.update` POST (`can:bank_account.manage`) + tab "Webhook SePay" / "Nhật ký giao dịch SePay" của `bank-accounts` | `GET /tuition/sepay`, `POST /tuition/sepay/config` (`bank_account.manage`) · màn `/tuition/sepay` (Cài đặt › Học phí → Webhook SePay) | Khác | Màn riêng thay cho tab trong màn Tài khoản ngân hàng (màn đó thuộc phân hệ Danh mục). Khóa bí mật / API key mã hóa trong DB, **không gửi xuống trình duyệt**; để trống = giữ khóa cũ (Laravel xóa API key khi để trống). Báo URL đã lưu khác tên miền đang mở. Định dạng dữ liệu giới hạn json / form / urlencoded. Nhật ký `tuition.sepay_config.update` không ghi khóa. |
| `student.tuition-request` (cổng học viên báo đã chuyển khoản) | `POST /portal/payment-reports`, `/payment-reports` | Khác | Làm ở phân hệ Cổng học viên (`internal/portal`); hàng duyệt là trang riêng `/payment-reports` thay hub Cần duyệt — xem [cong-hoc-vien.md](cong-hoc-vien.md). |

## Lệnh nền

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `tuition:send-debt-reminders` 08:30 | `tuitionSvc.RunReminderLoop` (cmd/menglish), job `debt-reminders` 08:30 | Đã làm | Mốc từ Danh mục (mặc định T-7 / T-3 / T0 / T+3), mỗi (khoản, mốc, ngày) một lần (`finance.debt_reminder_logs`), bỏ khoản đang khất / bảo lưu. |
| `students:start-deferrals` 06:45 / `students:end-deferrals` 06:50 | job `start-deferrals` / `end-deferrals` | Đã làm | Đóng băng / mở lại công nợ và số buổi, đổi trạng thái học viên qua `tuition.StudentStatus`. |
| `tuition:check-cash-deposits` mỗi 15 phút | job `cash-deposits` | Đã làm | SLA nộp tiền mặt (`TuitionSlaService`). |
| `tuition:notify-refund-deadlines` 08:40 | job `refund-deadlines` | Đã làm | |

## Phần khác

| Laravel | Trạng thái | Ghi chú |
| --- | --- | --- |
| Chốt & Xếp lớp (`processClosingWizard`, `cancelClosing`, xếp lớp khách Chờ xếp lớp) | Đã làm | `tuition.Closing` bọc phần Lớp học: khoản học phí, phiếu thu đặt cọc / thu ngay, task "Nhắc thu học phí", bill. |
| Tab học phí hồ sơ học viên (`StudentProfileController`) | Đã làm | `StudentTuitionPanel` + `students.TuitionHooks`. |
| Sổ buổi (`SessionLedger`), học phí vào lớp giữa khóa | Đã làm | |
| Cấp số hóa đơn (`InvoiceConfiguration`), dải mặc định C26MEN | Đã làm | Khóa theo dải / ký hiệu (`pg_advisory_xact_lock`). |
| Xuất / hoàn kho hàng hóa (`StockService::syncReceipt`) | Đã làm | Qua `tuition.StockRecorder` (phân hệ Kho). |
| Nội dung chuyển khoản gợi ý (`TransferMemo`) | Đã làm | |
| Xác nhận đã nhận học phí (`PaymentConfirmation`) | Khác | Cổng học viên (khi học viên có tài khoản) + email phụ huynh qua hàng đợi; không có email → báo người phụ trách kèm nội dung Zalo. |
| Hộp duyệt chung (`Approvals/*ApprovalSource`, `AdminOnlyApprovals`) | Đã làm | Nguồn `receipt`, `invoice_cancellation`, `refund` của hộp "Việc cần duyệt" (`tuition/approvals.go`, xem `can-duyet.md`); thông báo theo loại (`fee_payment`, `invoice_cancel_pending`, `refund_request_pending`, `sepay_review`…) vẫn giữ. Nguồn `payment_report` chờ cổng học viên. |
| Nhật ký (activity log của các model) | Đã làm | `audit.Record`, subject `tuition_receipt`, `student_tuition`, `invoice_range`, `invoice_cancellation`, `tuition_refund_request`, `sepay_config`, `tuition_import`. |
| Doanh thu cho Thu chi (`revenueReceiptQuery`) | Đã làm | `tuitionSvc.RevenueSource()` → `finSvc.WithRevenue`. |
| Số liệu Tổng quan (doanh thu tháng, phiếu chờ duyệt, học phí quá hạn) | Đã làm | `tuitionSvc.DashboardFinance()` → `dashboard.Deps.Finance`; ô / hàng chờ có link `/tuition/overdue`, `/tuition/receipts/approve`. |
| Mục "Số lượng thu phí" báo cáo ngày Học vụ (`DailyReportMetrics`) | Khác | `tuitionSvc.ReportMetrics`; phạm vi theo chi nhánh của người báo cáo (gần đúng phạm vi Laravel). |
| Hoa hồng tư vấn (thu hồi khi hoàn phí / hủy hóa đơn) | Đã làm | `tuition.WithCommissions(payrollSvc.Commissions())` trong `main.go` (`SalesCommissionService` ở phân hệ Lương). |
| Nguồn KPI từ Học phí (vd. "Học phí quá hạn chưa có lần nhắc") | Đã làm | `tuition.Service.KpiSources()` (`tuition_no_reminder`, `receipt_rejected`), xem [kpi.md](kpi.md). |
| Nhập dữ liệu học phí cũ (`menglish import-legacy`) | Chưa làm | Mới chép chi nhánh / tài khoản / quyền / hồ sơ nhân sự. |

## Cấu hình / triển khai

- Env mới: `SEPAY_WEBHOOK_ENABLED` (mặc định `false`), `SEPAY_WEBHOOK_SECRET` (khóa HMAC ban đầu khi chưa lưu cấu hình) —
  ghi trong `v2/deploy/.env.beta.example`.
- Caddy `v2/deploy/Caddyfile.web`: `handle /hook/* { reverse_proxy api:8080 }` cho webhook SePay (đường dẫn `/api/sepay/*` đã đi
  qua `handle /api/*`).
- Ảnh minh chứng (phiếu thu, hủy hóa đơn, hoàn phí) ở kho file; cột `proof_file_id` chưa có FK tới `core.files` (bảng tạo ở
  migration 00140, sau dải 0012x).
