# Vòng đời kỳ lương (TK-0016)

Luồng: **Đang tính → Đã chốt → Đã gửi GV xem → Đã trả** (`PayrollPeriod::TRANSITIONS`, `App\Services\Payroll\PayrollPeriodLifecycle`). Mọi chuyển trạng thái kiểm tra ở máy chủ trong transaction có khóa dòng kỳ lương; chuyển trạng thái không hợp lệ trả 422. Lịch sử ở bảng `payroll_period_transitions` (ai, lúc nào), hiện trên màn Bảng lương.

| Trạng thái | Thao tác tiếp theo | Quyền | Phạm vi khóa |
|---|---|---|---|
| **Đang tính** (`draft`) | Chốt bảng lương → Đã chốt | `payroll.approve` | Được đồng bộ, tính lại và sửa phiếu lương; nhân sự chưa xem được phiếu của mình. |
| **Đã chốt** (`closed`) | Mở lại để tính lại → Đang tính; hoặc Gửi GV xem → Đã gửi GV xem | `payroll.approve` | Đóng băng số tiền: không tính lại hoặc sửa phiếu. Dữ liệu nguồn vẫn sửa được; chưa tất toán phạt / hoa hồng hoặc gửi thông báo. |
| **Đã gửi GV xem** (`published`) | Đánh dấu đã trả → Đã trả | `payroll.mark_paid` | Khóa phiếu và dữ liệu nguồn của kỳ (chấm công, phạt, đơn giá…). Nhân sự xem được phiếu của mình; phạt được đóng dấu đã trừ, hoa hồng được tất toán và thông báo được gửi. |
| **Đã trả** (`paid`) | Hoàn tất | — | Giữ nguyên khóa; không mở lại. Trả ngoài ngày 10–15 tháng sau chỉ cảnh báo. |

Chỉ chốt sau khi đã tính ít nhất một lần, qua hạn chốt công / lỗi, không có lần tính nền đang chờ, không còn KPI chờ chốt và dữ liệu khớp lần tính gần nhất. Nếu dữ liệu nguồn đổi sau khi chốt, phải **Mở lại → Đồng bộ & Tính lại → Chốt bảng lương** rồi mới **Gửi GV xem**. Mỗi lần chuyển trạng thái được ghi lịch sử cùng người thao tác và thời gian. Không có thao tác mở lại sau khi đã gửi GV xem hoặc đã trả.

Chuyển đổi dữ liệu cũ: `draft` và `reviewing` → `draft`; `approved` → `published`; `paid` giữ nguyên. Kỳ `approved` trước đây đã khóa dữ liệu, trừ phạt, tất toán hoa hồng và hiện trong **Lương của tôi**, nên phải chuyển sang **Đã gửi GV xem**; chuyển về **Đã chốt** sẽ mở khóa dữ liệu đã tất toán. Chỉ các kỳ đổi giá trị trạng thái mới có dòng lịch sử chuyển đổi, với ghi chú “Chuyển đổi trạng thái cũ (TK-0016)”.
