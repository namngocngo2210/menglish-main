# Phạt quá hạn khi giao việc (TK-2026-0078)

## Form "Giao việc mới"
Dưới "Hạn hoàn thành" có ô **Phạt khi quá hạn** (Bật/Tắt, mặc định Tắt). Bật → bắt buộc nhập **Số tiền phạt (> 0)**;
để trống / ≤ 0 bị chặn cả ở trình duyệt (`required`, `min=1`) lẫn server (`WorkTaskController::store`, lỗi ở `muc_phat_qua_han`).

## Nguồn phạt lưu trên từng việc (`work_tasks`)
| Cột | Ý nghĩa |
|---|---|
| `phat_khi_qua_han` (bool, mặc định false) | Việc giao tay: có phạt khi quá hạn. Việc cũ = false (không phạt hồi tố). |
| `muc_phat_qua_han` (decimal, null) | Việc giao tay: số tiền phạt cố định. |
| `sla_rule_key` (string, null) | Việc SLA tự sinh: mốc SLA đã tạo việc (`SlaBreachService::open`, `care.first_month`). Bật/tắt phạt, mức, bậc phạt đọc **trực tiếp từ Cấu hình SLA**. |

## Job quá hạn (`tasks:mark-overdue`, mỗi 15 phút)
- Việc giao tay, phạt **tắt** → chỉ đổi trạng thái "Quá hạn", không biên bản (AC-S15).
- Việc giao tay, phạt **bật** → tạo biên bản `fined` ("Đã chốt phạt", `auto_source = task_overdue`) đúng số tiền; bảng lương trừ qua
  `Penalty::scopeDeductibleFor` như mọi biên bản `fined` (AC-S14). GVNN không bị phạt (giống SLA).
- Việc SLA (`sla_rule_key` / `care_milestone`) → job này **bỏ qua**; biên bản do `SlaBreachService::breach` / `FirstMonthCareService::enforceSla`
  theo Cấu hình SLA (mốc tắt phạt → không biên bản, AC-S08).
- Không phạt/trừ hai lần: mỗi việc tối đa một biên bản `task_overdue` (kể cả đã hủy), chạy lại không tạo thêm; việc SLA không bao giờ qua nhánh giao tay.

## Câu hỏi BA mở: việc giao tay có bậc phạt (Lần 1/2/3)?
Đề xuất: **không** — mỗi việc giao tay một mức cố định do người giao nhập. Bậc phạt chỉ áp cho việc SLA (đếm theo `Sla::ladderResetMonths`).
Nếu BA muốn bậc cho việc giao tay: đếm theo (người nhận, tháng) số biên bản `task_overdue` không hủy và cấu hình bậc ở Cấu hình SLA; chưa làm.
