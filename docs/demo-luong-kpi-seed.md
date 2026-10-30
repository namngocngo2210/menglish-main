# Dữ liệu demo: chấm công, lương và KPI tự đếm

## Bối cảnh

- `DemoPayrollSeeder` (`php artisan demo:luong`) đã có chấm công điện thoại, giờ dạy, biên bản, KPI, bảng lương tháng trước /
  tháng này, nhưng không nằm trong `DatabaseSeeder` → staging reset bằng `migrate:fresh --seed` (24/10/2026) không có dữ liệu
  lương (`staff_attendances = 0`, chỉ 3 vai trò có bộ KPI).
- Chạy đủ `demo:luong` vẫn thiếu nguồn cho phần lớn tiêu chí KPI tự đếm (`KpiAutoCounter`), đo trên CSDL seed mới:

| Nguồn | Trước khi sửa |
|---|---|
| `test_result`, `test_progress`, `class_attendance_rate`, `homework_rate` | Tháng trước: không GV / TA nào có số; tháng này 1–2 người. Lớp K27 / K28 demo không có học viên |
| `monthly_report_on_time` | Tháng trước 0% cho mọi GV (không ai có báo cáo tháng) |
| `teacher_absent_unexcused`, `teacher_leave` | Luôn 0 |
| `crm_sla_late`, `daily_report_late` (Sale) | 0 cho mọi người |
| `tuition_no_reminder`, `crm_sla_late`, `material_stock` (Học vụ) | 0 cả 2 tháng; `receipt_rejected` tháng trước 0 |
| `student_pass_rate` (Trưởng Học thuật) | Tháng trước không có số |

## Quyết định

1. **`DatabaseSeeder` gọi `DemoPayrollSeeder`** trong khối demo (local / staging / `SEED_DEMO`), bỏ qua khi chạy unit test
   (`app()->runningUnitTests()`) để các test seed `DatabaseSeeder` không chậm thêm và không đổi số liệu Phase 1–4.
2. **Seeder mới `DemoKpiSourcesSeeder`** (cạnh `DemoAcademicKpiSeeder`), chỉ thêm dữ liệu, đi qua controller / service thật với
   đồng hồ tua, idempotent theo học viên demo đầu tiên (`HV-DEMO-KPI-…`):
   - Học viên cho lớp K27 (tháng trước) / K28 (tháng này): điểm danh từng buổi đã qua (`attendanceStore`), mini test
     (`scoresStore`), bài tập về nhà + bài nộp (`homeworkStore`, `submitHomework`). Lớp của GV "tốt" chuyên cần / BTVN cao, điểm
     tăng; lớp của GV "kém" thấp, điểm giảm.
   - Báo cáo giảng dạy tháng (`teacherMonthlyStore`) cho GV full-time / part-time các tháng trong kỳ KPI trước: đa số nộp trước
     12h mùng 2, người kém nộp trễ.
   - Nghỉ dạy: GVNN nghỉ 1 buổi K27 có đơn nghỉ được duyệt (nghỉ có phép); GV full-time kém vắng 1 buổi K27 không đơn (nghỉ
     không phép → loại trừ KPI tháng).
   - Sale / Học vụ: lead mới quá hạn liên hệ lần đầu (`SlaBreachService`, như `CrmSlaService`), học phí đến hạn chưa có lần
     nhắc (người kém) / đã nhắc (người tốt), order in ấn xử lý trễ + kiểm kê sách lệch, phiếu thu bị trả về tháng trước.
   - Sự cố buổi dạy K28 cố định (`TEACHING_ISSUES`: đi muộn, nghỉ có / không phép) để số liệu tháng này không phụ thuộc lịch.
3. **Thứ tự**: phiếu KPI tháng trước chốt **sau** khi có đủ nguồn. `planKpi` không chốt tháng trước nữa; `completeLastMonth`
   chạy `DemoKpiSourcesSeeder` sau lớp K27 rồi mới chốt (ngày 3 tháng này, sau hạn báo cáo tháng). CSDL đã chạy `demo:luong`
   trước đây: seeder mới chỉ thêm dữ liệu, không sửa phiếu đã chốt.
4. Không làm: chăm sóc tháng đầu quá hạn tháng trước (`FirstMonthCareService::run` quét mọi học viên, tác dụng phụ lớn).
5. Sale **không có quyền `staff_report.submit`** (`config/access.php`) nên không nộp báo cáo ngày trên hệ thống: tiêu chí
   "Báo cáo ngày nộp muộn" của bộ KPI mẫu Sale chuyển sang điền tay (không giả lập luồng hệ thống không cho phép).
6. Lớp K27 / K28 có học viên → phiếu lương GV part-time / TA tính KPI giữ học sinh; Admin chọn bậc khi nhập tay
   (`retentionTier`: người tốt bậc cao, người kém bậc thấp) để kỳ tháng trước duyệt được.

## Kiểm thử

`DemoPayrollSeederTest`: mỗi tiêu chí tự đếm có ít nhất 1 người có số (khác rỗng / khác 0) ở tháng trước và tháng này (trừ
nguồn chưa tới hạn), người tốt cao hơn người kém, chạy lại không nhân bản. `DatabaseSeeder` không gọi demo lương khi test.

## Sprint

| Sprint | Nội dung | Trạng thái |
|---|---|---|
| 1 | Test đỏ: độ phủ nguồn KPI tự đếm + DatabaseSeeder | Xong |
| 2 | `DemoKpiSourcesSeeder`: học viên, điểm danh, mini test, BTVN, báo cáo tháng, nghỉ dạy | Xong |
| 3 | Sale / Học vụ: SLA CRM, học phí, học liệu / kiểm kê, phiếu thu bị trả về | Xong |
| 4 | Đổi thứ tự chốt KPI tháng trước, DatabaseSeeder, test xanh | Xong |
| 5 | Sau khi merge + deploy staging: chạy `docker/menglish.sh staging artisan demo:luong` | Chưa xong |

Đã chạy thử seed đầy đủ ở ngày 15 (test), 26/10, 02/11, 31/12: không lỗi; nguồn còn trống chỉ là báo cáo tháng của tháng này
(chưa tới hạn), chăm sóc tháng đầu tháng trước (ngoài phạm vi), dữ liệu tháng này khi mới sang tháng.

## Demo lương đứng lớp Học thuật (TK-2026-0018)

`DemoPayrollSeeder` bổ sung kỳ tháng trước cho tài khoản `academiclead@menglish.edu.vn`:

- Lớp `DEMO-CG-HT27` (**# Tiếng Anh Học thuật · K27 (CG)**): Học thuật dạy chính khóa các sáng thứ 4, Học vụ chấm tay
  và duyệt ca hợp lệ theo đồng hồ demo; 6 HS có lượt xếp lớp từ đầu kỳ.
- Một buổi dạy thay (`sub`) sáng chủ nhật ở `DEMO-CG-SPK27`, GV chính của lớp vẫn là người khác.
- Học phí gắn đúng hai lớp: 12.000.000đ / 24 buổi, ưu đãi 1.200.000đ, chưa thanh toán và còn nợ toàn bộ.
  Lương đứng lớp vẫn lấy **500.000đ/HS/buổi**, mặc định 40%, không bị giảm bởi ưu đãi hoặc công nợ.
- Phiếu Học thuật có **Lương đứng lớp > 0** và hai hàng lớp; bậc KPI giữ HS được điền để chốt kỳ demo được.
  Không bật cờ kiêm nhiệm: phiếu KPI giảng dạy riêng vẫn theo quy tắc hiện có.

Chạy lại không nhân bản ca, học viên, lượt xếp lớp hay học phí. Giữ quy ước hiện tại: đã có lớp K27 thì bỏ qua
phần tháng trước; kỳ đã chi trả hoặc kỳ không thuộc demo không bị sửa. CSDL đã seed K27 trước thay đổi này không
được bù lại phiếu đã khóa. Công thức chi tiết: [Lương đứng lớp Học thuật](luong-giao-vien.md#lương-đứng-lớp-học-thuật-tk-2026-0018).

`DemoPayrollSeederTest` kiểm tra tiền đứng lớp dương, hai hàng lớp có số buổi / HS / giá trị / tiền, có ca chính khóa
và dạy thay hợp lệ, học phí gắn lớp, và giữ nguyên dữ liệu khi seed lần hai. Test PHP cần chạy trong môi trường có PHP.
