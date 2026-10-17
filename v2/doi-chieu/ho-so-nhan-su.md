# Đối chiếu: Hồ sơ nhân sự (tab "Hồ sơ" và "Hợp đồng & Lương" của Người dùng)

Laravel: `UserController` (show, formPage, update, canViewSensitive), `UserRequest`, `User::contractExpiryStatus`,
`Users/UserFields.vue`, `Users/Show.vue`. v2: bảng `iam.staff_profiles`, `internal/iam/staff_profile.go`,
màn `web/src/pages/admin/StaffProfile.tsx` (trong hộp Tài khoản ở màn Người dùng).

| Laravel | v2 (API + màn) | Trạng thái | Ghi chú |
|---|---|---|---|
| Xem hồ sơ trên `users.show`: CCCD, liên lạc khẩn cấp, quê quán, nơi ở, tốt nghiệp, chứng chỉ, level giảng dạy | `GET /api/v1/users/{id}/profile` (quyền `user.view`, cùng phạm vi màn Người dùng), mục "Hồ sơ nhân sự" | Đã làm | |
| Ẩn trường nhạy cảm khi không `canViewSensitive` (`payroll.view` + phạm vi phiếu lương "tất cả") | `iam.CanViewSensitive`, API trả null + `can_view_sensitive` | Đã làm | |
| Hợp đồng: loại, ngày bắt đầu / kết thúc, trạng thái (Chưa cập nhật / Đang hiệu lực / Sắp hết hạn / Đã hết hạn), "Còn N ngày" | `contract_status`, `contract_remaining`, số ngày cảnh báo lấy SLA `hr.contract_warning` (mặc định 30) | Đã làm | |
| Lương cơ bản, thù lao giờ (chỉ người xem được lương) | `base_salary`, `hourly_rate` (VND nguyên) | Đã làm | Laravel lưu decimal(15,2); v2 làm tròn về đồng |
| Kiêm nhiệm giảng dạy (chỉ vai trò Học thuật) | `academic_teaching`, tự tắt khi không có vai trò Học thuật | Đã làm | |
| Sửa hồ sơ trên form Người dùng (`users.update`, rule của `UserRequest`, thông báo lỗi) | `PUT /api/v1/users/{id}/profile` (quyền `user.update`), form "Sửa hồ sơ" | Đã làm | Độ dài tối đa, ngày, lương không âm, lương để trống = 0 |
| Người không xem được lương sửa form: bỏ qua CCCD, lương cơ bản, thù lao giờ | Như Laravel, và bỏ qua thêm quê quán, nơi ở, liên lạc khẩn cấp | Làm một phần | Khác có chủ ý: Laravel ẩn 3 trường này ở màn xem nhưng vẫn cho sửa trên form; v2 ẩn và khóa cả hai cho nhất quán |
| Ghi nhật ký "Cập nhật tài khoản nhân viên" | Nhật ký vận hành "Cập nhật hồ sơ nhân sự", CCCD tự che `***` | Đã làm | |
| Tải lên / tải về file hợp đồng (`contract_file`, `users.contract.download`) | — | Chưa làm | Chờ phân hệ lưu file |
| Cảnh báo hợp đồng sắp hết hạn (thông báo 1 lần / hợp đồng, đánh dấu trên danh sách người dùng) | — | Chưa làm | Có sẵn `ContractStatus` và index `contract_end_date`; cần job quét + `notify.Send` |
| Thẻ hồ sơ rút gọn (popup nhân sự, lớp đang phụ trách) | — | Chưa làm | Cần phân hệ lớp học |
| Nhập từ Laravel | `import-legacy` bước `staff_profiles` (cột hồ sơ trên bảng `users`) | Đã làm | Bỏ qua tài khoản không có dữ liệu hồ sơ |
