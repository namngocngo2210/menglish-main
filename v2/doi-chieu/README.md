# Đối chiếu v2 với Laravel — tổng hợp

Mỗi phân hệ có một bảng đối chiếu riêng trong thư mục này: từng route / màn hình / lệnh nền của Laravel, chỗ tương ứng ở
v2 và trạng thái. File Laravel làm nguồn và commit đã đối chiếu tới nằm ở `../doi-chieu-laravel.txt`; `../tools/parity.sh`
báo mảng nào lệch khi Laravel có commit mới.

Trạng thái: **Đã làm** (giống Laravel) · **Khác** (đã làm, khác có chủ đích, có ghi chú) · **Làm một phần** (thiếu phần ghi
trong cột ghi chú) · **Chưa làm** (lý do kèm theo, thường là chờ phân hệ chưa chuyển).

Số dòng theo trạng thái tính tới lần gộp cuối của PR #152.

| Phân hệ | Bảng | Đã làm | Khác | Một phần | Chưa làm | Còn thiếu chính |
| --- | --- | ---: | ---: | ---: | ---: | --- |
| Chấm công chi nhánh | `../doi-chieu-laravel.txt` (`core.branch_checkin`) | toàn bộ | | | | Không còn (vị trí trên bản đồ, tìm địa chỉ, dán tọa độ như `BranchController`) |
| Nền tảng (khung giao diện, nhật ký, thông báo, tìm kiếm) | [nen-tang.md](nen-tang.md) | 26 | 0 | 6 | 8 | Dashboard "Tổng quan", toast chung, hoàn tác nhật ký, email |
| Danh mục & cấu hình | [danh-muc.md](danh-muc.md) | 45 | 0 | 14 | 4 | Tra phòng trống, cấu hình SePay, gửi thử email ticket |
| Học viên | [hoc-vien.md](hoc-vien.md) | 9 | 0 | 7 | 3 | Lệnh nền bảo lưu / khai giảng / sinh nhật (cần học phí), ghi ngày bắt đầu học từ điểm danh |
| Lớp học | [lop-hoc.md](lop-hoc.md) | 26 | 0 | 8 | 7 | Dự giờ & chất lượng lớp, cổng giáo viên (chấm công, bài tập, điểm), giãn tiến độ theo giáo trình |
| Tồn kho & Order học liệu | [ton-kho.md](ton-kho.md) | 23 | 0 | 2 | 4 | Cảnh báo tồn ở màn duyệt phiếu thu, import dữ liệu kho cũ, số liệu kho trong báo cáo |
| Công việc | [cong-viec.md](cong-viec.md) | 11 | 5 | 1 | 14 | Báo cáo trực lớp, cấu hình lịch trực, buổi phụ đạo, nhu cầu nhân sự, bảng KPI, hộp duyệt chung |
| Test đầu vào & học thử | [test-dau-vao.md](test-dau-vao.md) | 23 | 0 | 6 | 5 | Hẹn test / chấm điểm trên hồ sơ khách CRM, khách học thử ở màn điểm danh, nhập file đề cũ |
| Báo cáo ngày / tuần / tháng | [bao-cao.md](bao-cao.md) | 18 | 0 | 7 | 7 | Băng nhắc ở Dashboard, KPI tự đếm, tổng quan Học vụ, import báo cáo cũ |
| CRM | [crm.md](crm.md) | 21 | 0 | 15 | 6 | Phiếu thu / học phí khi chốt, in hồ sơ, lệnh quét khách quá hạn (`crm:scan-stale-leads`) |
| Hồ sơ nhân sự | [ho-so-nhan-su.md](ho-so-nhan-su.md) | 9 | 0 | 1 | 2 | Thông báo hợp đồng sắp hết hạn, thẻ hồ sơ rút gọn |
| Kho file | [kho-file.md](kho-file.md) | 10 | 2 | 0 | 1 | Nhập file cũ từ Laravel, file của phân hệ chưa chuyển (minh chứng, CV, file nghe bài tập…) |

## Phần dùng chung chưa có ở v2

Các dòng "Chưa làm" / "Làm một phần" phần lớn chờ những phân hệ sau:

- **Học phí & tài chính**: phiếu thu, công nợ, nhắc thu, SePay. Chốt khách CRM đã tạo học viên và xếp lớp nhưng chưa lập học phí.
- **Kho file**: đã có (`kho-file.md`); còn nhập file cũ từ Laravel và nối vào các phân hệ chưa chuyển.
- **Gửi email**: xác nhận lịch hẹn, email ticket, cảnh báo vận hành. Hiện chỉ có thông báo trong ứng dụng.
- **Dashboard, KPI, cổng giáo viên, cổng học viên, giáo trình**.
- **Import dữ liệu nghiệp vụ cũ**: `import-legacy` mới chép chi nhánh, tài khoản, vai trò, quyền và hồ sơ nhân sự.
