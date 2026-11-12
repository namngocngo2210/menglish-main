# Tự chấm công ca liền kề (R-14) và cổng chốt lương (R-13) — TK-2026-0102

Spec BA nói `cham_cong`; trong code đó là bảng `teacher_timesheets`.

## Ánh xạ tên

| Spec BA | Code |
|---|---|
| `trang_thai_doi_soat` (nhap / cho_doi_soat / da_chot) | `reconcile_status` (NULL = dòng cũ, coi như `da_chot`) |
| `nguon_du_lieu` = `gv_tu_check_in` / `tu_dong_ca_lien_ke` | `source` = `checkin` / `auto_adjacent` |
| `bi_xoa_boi_hoc_vu` | `bi_xoa_boi_hoc_vu` (+ `soft_deleted_by/at/reason`) |
| `khoa_tinh_luong` | `payroll_locked` |
| `nguoi_tao` (NULL = hệ thống) | `created_by` |
| buổi hủy / dời | `class_sessions.status = 'cancelled'` / có buổi con `rescheduled_from_id` |
| `can_day_thay` | buổi có `cover_requests.status` ∈ (`open`, `approved`) |

## Job tự chấm công

- Lệnh: `php artisan attendance:auto-adjacent-shifts` (chạy tay trên staging: `docker/menglish.sh staging artisan attendance:auto-adjacent-shifts`).
- Lịch: `routes/console.php` — mỗi 5 phút, `withoutOverlapping`. Idempotent.
- Điều kiện tạo dòng cho ca 2: GV chính có role `teacher_parttime`; ca 1 cùng ngày, cùng GV chính, `end_time` = `start_time` ca 2 (đúng từng phút, không dung sai); ca 1 có công `source = checkin` của chính GV (không `sub`, không `invalid`); ca 2 không hủy / dời / dạy thay; còn trong cửa sổ 24h từ giờ bắt đầu ca 2; kỳ lương chưa khóa; chưa có dòng nào (kể cả xóa mềm) của (GV, buổi).
- Dòng tạo ra: `source = auto_adjacent`, `status = pending_review` (chỉ vào lương sau khi Học vụ duyệt, như check-in), `reconcile_status = nhap`, `created_by = NULL`.
- Dọn: dòng `auto_adjacent` còn `nhap` bị xóa cứng khi ca 2 sau đó bị hủy / dời / có dạy thay / đổi GV chính hoặc ca 1 không còn là công check-in hợp lệ. Dòng đã chốt / xóa mềm / thuộc kỳ khóa không bị xóa.

## Xóa mềm

`POST payroll/timesheets/teachers/{id}/soft-delete` (quyền `attendance_staff.manual_record`, bắt buộc lý do) đặt `bi_xoa_boi_hoc_vu = true` và lập biên bản (Penalty `pending`, 0đ). Không xóa mềm dòng đã chốt (cần Admin mở khóa — làm ở card UI) hoặc kỳ đã chốt lương (409). Dòng xóa mềm bị loại khỏi mọi truy vấn qua global scope `academic_active` của `TeacherTimesheet` (`TeacherTimesheet::withSoftDeleted()` để bỏ lọc). Check-in lại → 409 "Học vụ đã xóa công ca này, liên hệ Học vụ".

## Chốt lương (R-13)

`PayrollPeriodLifecycle::transition(..., closed)` trong 1 transaction: gate KPI → gate công (400 + danh sách nếu còn dòng `reconcile_status` khác NULL/`da_chot`, bỏ qua xóa mềm và `invalid`) → cập nhật bảng lương → `payroll_locked = true` cho dòng `da_chot` (và dòng cũ NULL) của kỳ. "Mở lại" đặt `payroll_locked = false`. Cập nhật khóa dùng query builder nên không đổi `updated_at` (không làm kỳ bị coi là "đã thay đổi sau khi tính").
