# Kỳ lương kiểm thử TK-2026-0036

Dùng tài khoản test để QA vòng đời lương mà không khóa công/phạt của nhân sự thật.
Lệnh chỉ chạy khi `APP_ENV=staging`; production, local và testing đều bị từ chối.

```sh
php artisan payroll:test-period --emails=qa-gv@example.test,qa-cm@example.test
```

Email bắt buộc, phải tồn tại và là tài khoản nhân sự đang hoạt động; không tự tạo tài khoản.
Chọn tài khoản sạch; nhân sự ngoài giáo viên cần cấu hình lương có hiệu lực từ tháng 01/2026.
Lệnh từ chối nếu tháng 01–02/2026 có công/phạt/chấm công ngày của người ngoài danh sách
hoặc có kỳ lương thường chồng ngày. Không dùng email nhân sự thật.

- A: `PR-2026-TEST-A`, 01–31/01/2026, draft; có ca workshop hợp lệ cho giáo viên,
  đã tính lương và chốt KPI còn chờ theo nguồn (điểm/khoản thủ công bằng 0).
- B: `PR-2026-TEST-B`, 01–28/02/2026, draft; dành cho kiểm tra chuyển kỳ.
- Chạy lại không tạo trùng; kỳ đã qua draft được bỏ qua.

QA TC-VD-04..07: mở A, kiểm tra chỉ có người trong danh sách, Chốt → Gửi GV xem → Đã trả.
Kiểm tra phiếu lương bằng tài khoản test; kiểm tra công/phạt nhân sự thật vẫn ghi được.
TC-PHAT-05: sau khi chốt A, bổ sung công/phạt của tài khoản test và kiểm tra khoản chuyển
sang B theo quy tắc hiện có; tính lại B, chốt KPI trước khi chốt B.
Không đổi ngày vi phạm ngoài cửa sổ ghi nhận; kỳ test không bổ sung quy tắc chuyển công mới.

```sh
php artisan payroll:test-period --cleanup
```

Cleanup chỉ xóa kỳ có danh sách khác NULL và mã `PR-2026-TEST-%`, phiếu lương/lịch sử đi kèm,
cùng ca do lệnh gắn marker trong `notes`. Công/phạt nhập tay khi QA phải tự dọn riêng.
Cảnh báo: kỳ test vẫn xuất hiện trong báo cáo và gửi thông báo khi publish; chỉ dùng staging.
Kỳ có danh sách NULL giữ nguyên hành vi lương thật; không sửa danh sách/mã/marker bằng tay.
