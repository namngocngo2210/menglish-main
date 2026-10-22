# Đối chiếu v2 với Laravel — tổng hợp

Mỗi phân hệ có một bảng đối chiếu riêng trong thư mục này: từng route / màn hình / lệnh nền của Laravel, chỗ tương ứng ở
v2 và trạng thái. File Laravel làm nguồn và commit đã đối chiếu tới nằm ở `../doi-chieu-laravel.txt`; `../tools/parity.sh`
báo mảng nào lệch khi Laravel có commit mới.

Trạng thái: **Đã làm** (giống Laravel) · **Khác** (đã làm, khác có chủ đích, có ghi chú) · **Làm một phần** (thiếu phần ghi
trong cột ghi chú) · **Chưa làm** (lý do kèm theo, thường là chờ phân hệ chưa chuyển).

Số dòng theo trạng thái tính tới lần nối các phân hệ đã gộp (Chấm công ↔ Lương, hai cổng, Khảo sát, Giáo trình & Big Test, hộp
Việc cần duyệt, số liệu Tổng quan / báo cáo / KPI; đếm mọi bảng trong file).

| Phân hệ | Bảng | Đã làm | Khác | Một phần | Chưa làm | Còn thiếu chính |
| --- | --- | ---: | ---: | ---: | ---: | --- |
| Chấm công chi nhánh | `../doi-chieu-laravel.txt` (`core.branch_checkin`) | toàn bộ | | | | Không còn (vị trí trên bản đồ, tìm địa chỉ, dán tọa độ như `BranchController`) |
| Nền tảng (khung giao diện, nhật ký, thông báo, tìm kiếm) | [nen-tang.md](nen-tang.md) | 27 | 0 | 7 | 6 | Toast chung, hoàn tác nhật ký |
| Tổng quan (Dashboard) & Báo cáo & sự vụ lớp | [tong-quan.md](tong-quan.md) | 42 | 5 | 3 | 3 | Báo cáo trực lớp (chưa có ở v2) |
| Danh mục & cấu hình | [danh-muc.md](danh-muc.md) | 46 | 0 | 14 | 3 | Tra phòng trống, cấu hình SePay |
| Học viên | [hoc-vien.md](hoc-vien.md) | 9 | 0 | 7 | 3 | Lệnh nền bảo lưu / khai giảng / sinh nhật (cần học phí), ghi ngày bắt đầu học từ điểm danh |
| Lớp học | [lop-hoc.md](lop-hoc.md) | 31 | 0 | 7 | 3 | Buổi phụ đạo, tên lớp ở danh sách order học liệu |
| Tồn kho & Order học liệu | [ton-kho.md](ton-kho.md) | 24 | 0 | 2 | 3 | Cảnh báo tồn ở màn duyệt phiếu thu, import dữ liệu kho cũ |
| Công việc | [cong-viec.md](cong-viec.md) | 15 | 6 | 1 | 9 | Báo cáo trực lớp, buổi phụ đạo, nhu cầu nhân sự, việc chăm sóc tháng đầu / SLA CRM |
| Test đầu vào & học thử | [test-dau-vao.md](test-dau-vao.md) | 24 | 0 | 5 | 5 | Hẹn test / chấm điểm trên hồ sơ khách CRM, khách học thử ở màn điểm danh, nhập file đề cũ |
| Báo cáo ngày / tuần / tháng | [bao-cao.md](bao-cao.md) | 24 | 0 | 5 | 3 | Báo cáo trực lớp, buổi bổ trợ, import báo cáo cũ |
| CRM | [crm.md](crm.md) | 21 | 0 | 15 | 6 | Phiếu thu / học phí khi chốt, in hồ sơ, lệnh quét khách quá hạn (`crm:scan-stale-leads`) |
| Hồ sơ nhân sự | [ho-so-nhan-su.md](ho-so-nhan-su.md) | 9 | 0 | 1 | 2 | Thông báo hợp đồng sắp hết hạn, thẻ hồ sơ rút gọn |
| Gửi email (hàng đợi, mẫu thư, quên mật khẩu) | [email.md](email.md) | 11 | 5 | 0 | 7 | Email học phí / nhắc nợ / SePay (phân hệ học phí), khách tồn đọng, Zalo ZNS (ngoài phạm vi) |
| Kho file | [kho-file.md](kho-file.md) | 10 | 2 | 0 | 1 | Nhập file cũ từ Laravel, file của phân hệ chưa chuyển (minh chứng, CV, file nghe bài tập…) |
| Thu - Chi | [thu-chi.md](thu-chi.md) | 8 | 1 | 2 | 0 | Không còn phần chờ phân hệ khác |
| KPI nhân sự | [kpi.md](kpi.md) | 24 | 7 | 0 | 1 | Nguồn `crm_sla_late` (máy SLA của CRM chưa có ở v2, hiện điền tay) |
| Học phí (hồ sơ, phiếu thu, công nợ, hoàn phí, hủy hóa đơn, SePay, nhập Excel) | [hoc-phi.md](hoc-phi.md) | 34 | 10 | 0 | 1 | Nhập dữ liệu học phí cũ |
| Cổng Phụ huynh & Học sinh (trang chủ, học phí, nộp bài, phát âm, hộp thư, khảo sát, góp ý, App Shell) | [cong-hoc-vien.md](cong-hoc-vien.md) | 17 | 7 | 1 | 0 | Buổi phụ đạo riêng của học viên (chưa có ở v2) |
| Cổng giáo viên (check-in, bài tập, mini test, nhận xét, chấm bài, báo cáo giảng dạy) | [cong-giao-vien.md](cong-giao-vien.md) | 18 | 1 | 1 | 0 | Danh sách bổ trợ / buổi phụ đạo (chưa có ở v2), khóa `syllabus_unit_id` của điểm mini test |
| Chấm công (của tôi, đơn & duyệt, hằng ngày, giờ dạy giáo viên, chấm tay, đồng bộ) | [cham-cong.md](cham-cong.md) | 28 | 2 | 1 | 2 | Nhập dữ liệu cũ, nghỉ lễ dời lịch ca dạy, số liệu chấm công trong báo cáo ngày |
| Ticket hỗ trợ (ticket, trao đổi, email, hàng chờ Tổng quan) | [ticket.md](ticket.md) | 12 | 7 | 0 | 1 | Nhập ticket cũ |
| Hộp "Việc cần duyệt" + badge sidebar | [can-duyet.md](can-duyet.md) | 12 | 2 | 1 | 1 | Nguồn báo cáo trực lớp (chưa có ở v2), tên lớp của việc chờ xác nhận |
| Khảo sát & Hướng dẫn sử dụng | [khao-sat.md](khao-sat.md) | 8 | 3 | 0 | 1 | Nhập khảo sát cũ |
| Chất lượng lớp (dự giờ, đánh giá dự giờ, checklist, họp giáo viên) & Dự án học thuật | [chat-luong-lop.md](chat-luong-lop.md) | 23 | 10 | 0 | 1 | Nhập dữ liệu cũ |
| Lương & Phạt (bảng lương, phiếu lương, Lương của tôi, xếp hạng KPI, cấu hình lương, vi phạm & phạt) | [luong.md](luong.md) | 28 | 10 | 2 | 1 | Biên bản SLA chăm sóc / CRM (máy SLA của CRM chưa có), file PDF minh chứng, xuất xlsx / bản in Blade |
| Giáo trình & Big Test (tài liệu, soạn syllabus, giao chặng, order đề, bài giảng, Big Test, đề xuất / giãn tiến độ) | [giao-trinh.md](giao-trinh.md) | 48 | 6 | 4 | 1 | Zalo ZNS thật, danh sách bổ trợ / buổi phụ đạo (chưa có ở v2), tên bài theo buổi trong giao việc trợ giảng |

## Phần dùng chung chưa có ở v2

Các dòng "Chưa làm" / "Làm một phần" phần lớn chờ những phân hệ sau:

- **Học phí & tài chính**: phiếu thu, công nợ, nhắc thu, SePay. Chốt khách CRM đã tạo học viên và xếp lớp nhưng chưa lập học phí.
  Học phí đã có (`hoc-phi.md`); các bảng phân hệ khác ghi "chờ học phí" cần đối chiếu lại khi gộp.
- **Kho file**: đã có (`kho-file.md`); còn nhập file cũ từ Laravel và nối vào các phân hệ chưa chuyển.
- **Gửi email**: đã có hàng đợi email (`internal/notify/mail`), xác nhận lịch học thử, email vận hành khi nộp bài, quên
  mật khẩu. Email ticket đã có (`ticket.md`); còn email học phí (phân hệ học phí gọi `mail.Enqueue`), Zalo ZNS.
- **Đã nối giữa các phân hệ** (adapter ở `cmd/menglish/wiring.go`, decorator số liệu báo cáo ở `main.go`): Chấm công ↔ Lương
  & Phạt, Giáo trình / Lương / Chấm công vào hai cổng, Khảo sát vào Cổng PH/HS, hộp "Việc cần duyệt" đủ nguồn (trừ báo cáo
  trực lớp), số liệu Giáo trình & Big Test / Học phí / Ticket / Công việc / Order học liệu trên Tổng quan, báo cáo và KPI.
- **Chưa có ở v2 (không thuộc phân hệ nào đã chuyển)**: báo cáo trực lớp (`ClassReport`), danh sách bổ trợ / buổi phụ đạo
  (`SupportListService`, `support_sessions`), máy SLA của CRM (`sla_events`: phạt CRM, nguồn KPI `crm_sla_late`), việc chăm
  sóc tháng đầu tự tạo (`FirstMonthCareService`), Zalo ZNS.
- **Import dữ liệu nghiệp vụ cũ**: `import-legacy` mới chép chi nhánh, tài khoản, vai trò, quyền và hồ sơ nhân sự.
