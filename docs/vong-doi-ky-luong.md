# Vòng đời kỳ lương (TK-0016)

Luồng: **Đang tính → Đã chốt → Đã gửi GV xem → Đã trả** (`PayrollPeriod::TRANSITIONS`, `App\Services\Payroll\PayrollPeriodLifecycle`). Mọi chuyển trạng thái kiểm tra ở máy chủ trong transaction có khóa dòng kỳ lương; chuyển trạng thái không hợp lệ trả 422. Lịch sử ở bảng `payroll_period_transitions` (ai, lúc nào), hiện trên màn Bảng lương.

| Trạng thái | Thao tác tiếp theo | Quyền | Phạm vi khóa |
|---|---|---|---|
| **Đang tính** (`draft`) | Chốt bảng lương → Đã chốt | `payroll.approve` | Được đồng bộ, tính lại và sửa phiếu lương; nhân sự chưa xem được phiếu của mình. |
| **Đã chốt** (`closed`) | Mở lại để tính lại → Đang tính; hoặc Gửi GV xem → Đã gửi GV xem | `payroll.approve` | Đóng băng số tiền: không tính lại hoặc sửa phiếu. Dữ liệu nguồn vẫn sửa được; chưa tất toán phạt / hoa hồng hoặc gửi thông báo. |
| **Đã gửi GV xem** (`published`) | Đánh dấu đã trả → Đã trả | `payroll.mark_paid` | Khóa phiếu và dữ liệu nguồn của kỳ (chấm công, phạt, đơn giá…). Nhân sự xem được phiếu của mình; phạt được đóng dấu đã trừ, hoa hồng được tất toán và thông báo được gửi. |
| **Đã trả** (`paid`) | Hoàn tất | — | Giữ nguyên khóa; không mở lại. Trả ngoài ngày 10–15 tháng sau chỉ cảnh báo. |

Chỉ chốt sau khi đã tính ít nhất một lần, qua hạn chốt công / lỗi, không có lần tính nền đang chờ, không còn KPI chờ chốt, dữ liệu khớp lần tính gần nhất và không có nhân sự bị khấu trừ lớn hơn thu nhập (thực lĩnh trước giới hạn âm). Nếu dữ liệu nguồn đổi sau khi chốt, phải **Mở lại → Đồng bộ & Tính lại → Chốt bảng lương** rồi mới **Gửi GV xem**. Mỗi lần chuyển trạng thái được ghi lịch sử cùng người thao tác và thời gian. Không có thao tác mở lại sau khi đã gửi GV xem hoặc đã trả.

Chuyển đổi dữ liệu cũ: `draft` và `reviewing` → `draft`; `approved` → `published`; `paid` giữ nguyên. Kỳ `approved` trước đây đã khóa dữ liệu, trừ phạt, tất toán hoa hồng và hiện trong **Lương của tôi**, nên phải chuyển sang **Đã gửi GV xem**; chuyển về **Đã chốt** sẽ mở khóa dữ liệu đã tất toán. Chỉ các kỳ đổi giá trị trạng thái mới có dòng lịch sử chuyển đổi, với ghi chú “Chuyển đổi trạng thái cũ (TK-0016)”.

## Khấu trừ vượt thu nhập (TK-0017)

Tính lương vẫn tạo phiếu và giữ thực lĩnh tối thiểu **0 đ** để số chi trả / tổng quỹ lương không âm. Phần thiếu được tính từ tổng thu nhập trừ tổng khấu trừ của phiếu hiện tại, không bị mất khi sửa tay hoặc tính lại. Bảng lương luôn cảnh báo tên từng nhân sự và số tiền thiếu, đánh dấu dòng tương ứng; phiếu lương cũng hiện phần thiếu. Cảnh báo chỉ hiển thị các phiếu trong phạm vi người xem, còn điều kiện chốt kiểm tra toàn bộ phiếu của kỳ.

Khi còn phần thiếu, **Chốt bảng lương** bị khóa và máy chủ từ chối thao tác. Bước tiếp trên màn kỳ lương nêu số nhân sự thực lĩnh âm của **toàn kỳ**, kể cả khi đang lọc hoặc chỉ có quyền xem một số phiếu. Người không có quyền điều chỉnh lương thấy “Chờ Kế toán xử lý N nhân sự thực lĩnh âm”.

Kế toán có quyền `payroll.edit` đối soát và chọn một trong hai cách:

- **Chuyển phạt sang kỳ sau**: nút **Chuyển sang kỳ sau** cạnh biên bản trên cảnh báo bảng lương / danh sách phạt của phiếu lương / màn **Biên bản**. Nhập lý do bắt buộc (tối đa 500 ký tự). Quyết định phạt và số tiền được giữ nguyên.
- **Thêm khoản cộng / giảm khoản trừ nhập tay trên phiếu**. Không áp dụng khoản cộng cho **Trợ giảng**: phụ cấp tự do và lương buổi có GVNN luôn bằng 0; KPI nhập tay không được tính. Trợ giảng vẫn có thể giảm khoản trừ nhập tay đã nhập. Không sửa trực tiếp số tiền phạt từ biên bản trên phiếu.

Sau khi xử lý, người có quyền `payroll.calculate` bấm **Đồng bộ & Tính lại** ở **kỳ nguồn**, kiểm tra không còn phần thiếu; người có quyền `payroll.approve` **chốt lại**. Nếu kỳ đích đã tính trước đó thì cũng cần tính lại kỳ đích. Hệ thống không tự chuyển hoặc xóa phần phạt vượt thu nhập.

### Quy tắc chuyển kỳ trừ phạt

`POST /penalties/{id}/defer` (`penalties.defer`) yêu cầu `payroll.edit`, cùng quyền điều chỉnh phiếu lương; không yêu cầu quyền chốt lỗi / quyết phạt. Chỉ biên bản `fined` được chuyển. Biên bản đã nộp trực tiếp (`paid`), đã trừ lương (`deducted`), đã hủy, đã đóng vụ hoặc chưa quyết phạt đều bị từ chối.

Phạm vi dữ liệu giống khi điều chỉnh phiếu lương: **Toàn hệ thống** được chuyển mọi khoản phạt; **Chi nhánh** chỉ được chuyển phạt của nhân sự thuộc các chi nhánh của mình (chi nhánh chính hoặc bổ sung), không được chuyển phạt của chính mình; **Của tôi** không được chuyển phạt vì phiếu lương của chính mình không được điều chỉnh khi kỳ chưa khóa. Các nút chuyển kỳ chỉ hiển thị khi người xem được phép.

Kỳ nguồn là kỳ của phiếu đang gắn biên bản; nếu chưa gắn thì lấy kỳ chưa khóa đầu tiên đang nhận biên bản theo `Penalty::scopeDeductibleFor`. Không có kỳ nhận thì báo “Biên bản chưa thuộc kỳ lương đang tính nào”. Kỳ nguồn **Đã chốt** cần người có quyền `payroll.approve` bấm **Mở lại**; kỳ **Đã gửi GV xem / Đã trả** đã khóa, không chuyển được. Nếu kỳ đích đã tồn tại thì phải ở trạng thái **Đang tính** (`draft`); kỳ đích đã chốt / gửi GV xem / trả đều bị từ chối.

Ngày trừ mới (`deduct_from_date`) là ngày ngay sau cuối kỳ nguồn, không thay đổi `violation_date`. Kỳ đích chứa ngày đó có thể chưa được tạo: biên bản sẽ được nhận khi kỳ được tạo / tính; nếu không có kỳ chứa ngày thì chuyển tiếp vào kỳ mở đầu tiên sau ngày này. Khi kỳ đích còn mở thì các kỳ mở muộn hơn không lấy khoản phạt trước. Có thể chuyển tiếp từ kỳ đó sang kỳ sau nữa.

Thao tác lưu thời gian, người chuyển, lý do (`deferred_at`, `deferred_by`, `defer_reason`), nối lịch sử vào ghi chú và gỡ liên kết phiếu nguồn. Màn Biên bản hiển thị ngày bắt đầu trừ cùng người, ngày và lý do chuyển. Số khấu trừ trên phiếu nguồn chỉ đổi sau **Đồng bộ & Tính lại**; việc gỡ biên bản khiến kỳ nguồn báo dữ liệu đã đổi và chặn chốt trước khi tính lại.
