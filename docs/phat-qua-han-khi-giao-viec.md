# Phạt quá hạn khi giao việc (TK-2026-0078)

## Form "Giao việc mới"
Dành cho việc **Phát sinh**: dưới "Hạn hoàn thành" có ô **Phạt khi quá hạn** (Bật/Tắt, mặc định **Bật** cho việc phát sinh mới; việc lặp không dùng ô này). Bật → bắt buộc nhập **Số tiền phạt (> 0)**;
để trống / ≤ 0 bị chặn cả ở trình duyệt (`required`, `min=1`) lẫn server (`WorkTaskController::store`, lỗi ở `muc_phat_qua_han`).

## Việc lặp và cấu hình theo chuỗi (TK-2026-0090)

- Chọn **Lặp đi lặp lại** trong form giao việc sẽ ẩn hai trường phạt và hiện hướng dẫn cấu hình sau khi tạo.
  Server luôn lưu `phat_khi_qua_han = false`, `muc_phat_qua_han = null` cho việc lặp, kể cả khi gửi trực tiếp hai trường này.
- Mỗi việc lặp thuộc một chuỗi `work_task_series` qua `work_tasks.series_id`; lượt kế tiếp giữ nguyên `series_id`.
  Chuỗi lưu `penalty_amount`, `configured_by`, `configured_at`. Chưa có `configured_at` = **Tạm dừng – chưa cấu hình**:
  lượt hiện tại vẫn xử lý bình thường, nhưng hoàn thành không sinh lượt kế tiếp và quá hạn không lập phạt.
- Người có quyền `work_task.assign` mở **Công việc → Cấu hình phạt việc lặp** (`/tasks/penalty-series`),
  nhập một số tiền từ **0 đến 100.000.000**, bấm **Lưu** theo từng chuỗi. Dữ liệu hiển thị và quyền sửa theo phạm vi xem việc của lượt mới nhất.
  **0 vẫn là đã cấu hình**, tiếp tục sinh lượt nhưng không phạt. Lần lưu ghi người cấu hình, thời gian và nhật ký trước/sau.
- Lưu cấu hình tự tiếp tục chuỗi: còn bất kỳ lượt chưa hoàn thành/chưa hủy (kể cả Quá hạn, Bị chặn, Chờ xác nhận) thì không sinh thêm.
  Nếu không còn lượt đang mở, tạo **đúng một** lượt từ lượt mới nhất, tăng ngày hạn từng kỳ (ngày/tuần/tháng, tháng không tràn ngày)
  đến ngày đầu tiên **≥ hôm nay**. Không tạo bù những kỳ đã bỏ lỡ; lưu lại khi đã có lượt mở không tạo trùng.
  Sau khi đã cấu hình, hoàn thành lượt vẫn tăng hạn một kỳ như trước.
- Job quá hạn đọc **trực tiếp mức phạt hiện tại của chuỗi**, bỏ qua hai trường phạt trên từng lượt.
  Mỗi lượt chịu cùng một mức cố định, **không có bậc/tăng phạt** theo số lần vi phạm trước đó; sửa mức áp dụng cho các lần job xử lý tiếp theo.
  Chưa cấu hình hoặc mức ≤ 0 thì không phạt. Miễn GVNN, Học thuật và Học vụ; bỏ qua việc SLA/chăm sóc và tối đa một biên bản `task_overdue` cho mỗi lượt, kể cả biên bản đã hủy.
- Sau triển khai, migration gom các việc lặp cũ theo `(title, assignee_id, frequency)` vào chuỗi **chưa cấu hình**.
  **Tất cả chuỗi cũ tạm dừng; Admin phải vào màn cấu hình để tiếp tục**. Không đổi việc phát sinh, không phạt hồi tố, không tạo lượt trong migration.
  `WorkTaskSeeder` cấu hình chuỗi demo với mức 0 để vẫn sinh lượt mà không tự thêm phạt vào luồng demo.

## Nguồn phạt lưu trên từng việc (`work_tasks`)
| Cột | Ý nghĩa |
|---|---|
| `phat_khi_qua_han` (bool, mặc định false ở DB/server; form giao việc mới tick sẵn Bật) | Việc giao tay: có phạt khi quá hạn. Việc cũ = false (không phạt hồi tố). |
| `muc_phat_qua_han` (decimal, null) | Việc giao tay: số tiền phạt cố định. |
| `sla_rule_key` (string, null) | Việc SLA tự sinh: mốc SLA đã tạo việc (`SlaBreachService::open`, `care.first_month`). Bật/tắt phạt, mức, bậc phạt đọc **trực tiếp từ Cấu hình SLA**. |

## Job quá hạn (`tasks:mark-overdue`, mỗi 15 phút)
- Việc Mới / Đang thực hiện / Bị chặn đã qua hạn → Quá hạn, báo người được giao một lần.
  Việc Bị chặn **không bao giờ lập phạt**; Chờ xác nhận, Đã hoàn thành, Đã hủy và nhiệm vụ TA hằng ngày không có hạn vẫn bỏ qua.
- Việc giao tay, phạt **tắt** → chỉ đổi trạng thái "Quá hạn", không biên bản (AC-S15).
- Việc giao tay, phạt **bật** → tạo biên bản `fined` ("Đã chốt phạt", `auto_source = task_overdue`) đúng số tiền; bảng lương trừ qua
  `Penalty::scopeDeductibleFor` theo ngày vi phạm (AC-S14), **không có hạn nộp**.
  Đây là trạng thái chốt: không nộp trực tiếp, chuyển kỳ, khắc phục hay đóng miễn phạt; chỉ có thể **Hủy** khi còn `fined`, có quyền `violation.cancel` và kỳ lương chưa khóa.
  Hủy gỡ liên kết phiếu lương và tính lại kỳ đang mở để bỏ khoản trừ; kỳ đã chốt cần Mở lại trước, kỳ đã gửi GV xem / đã trả không thể hủy.
  GVNN không bị phạt (giống SLA); Học thuật (`academic_lead`) / Học vụ (`academic_staff`) chỉ tính KPI `task_on_time`, không lập phạt quá hạn việc.
- Việc SLA (`sla_rule_key` / `care_milestone`) → job này **bỏ qua**; biên bản do `SlaBreachService::breach` / `FirstMonthCareService::enforceSla`
  theo Cấu hình SLA (mốc tắt phạt → không biên bản, AC-S08).
- Không phạt/trừ hai lần: mỗi việc tối đa một biên bản `task_overdue` (kể cả đã hủy), chạy lại không tạo thêm; việc SLA không bao giờ qua nhánh giao tay.

## Câu hỏi BA mở: việc giao tay có bậc phạt (Lần 1/2/3)?
Đề xuất: **không** — mỗi việc giao tay một mức cố định do người giao nhập. Bậc phạt chỉ áp cho việc SLA (đếm theo `Sla::ladderResetMonths`).
Nếu BA muốn bậc cho việc giao tay: đếm theo (người nhận, tháng) số biên bản `task_overdue` không hủy và cấu hình bậc ở Cấu hình SLA; chưa làm.
