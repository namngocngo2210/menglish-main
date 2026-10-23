# File test import Kho syllabus

Bộ file để tự tay thử 2 luồng import trên staging / local. Dùng kèm dữ liệu demo (`DemoSyllabusLibrarySeeder`): đăng nhập
Học thuật `academiclead@menglish.edu.vn`.

## Import 1 chặng (form Sheet 1)

Vào **Giáo trình → Kho syllabus → Cambridge Starters (demo) → tab Chặng & buổi**: nút **Thêm chặng từ file** (chặng mới)
hoặc **Import từ file** trên từng chặng (cập nhật chặng đó).

| File | Nút | Kết quả mong đợi |
|---|---|---|
| `chang-moi-starters.xlsx` | Thêm chặng từ file | Xem trước: 6 buổi thêm, 2 unit mới (Unit 7, 8), cảnh báo bỏ qua 1 dòng ví dụ nền xám. Xác nhận → **Chặng 4: Ôn thi Starters** (buổi 19–24): Học / Mini test / Big test, link PPT (buổi 1 lấy từ ô hyperlink, buổi 3 chữ “link PPT” bị coi là trống), mốc Order in ấn / mini test / Big Test. |
| `cap-nhat-starters-chang-1.xlsx` | Import từ file ở Chặng 1 | 5 buổi sửa, 1 buổi xóa (“Ôn tập Unit 1–2”). Cảnh báo có lớp đang học chặng này và có buổi đang có tài liệu / đề xuất sửa. Xác nhận → tên buổi thêm “(bản sửa)”, số buổi các chặng sau đánh lại. |
| `chang-co-loi.xlsx` | Thêm chặng từ file | Không xác nhận được; lỗi theo dòng: chưa có UNIT, ô UNIT sai dạng, loại buổi “Thi cuối kỳ”, link PPT “slide buoi 4”, mốc thông báo “Gửi phụ huynh”. |

## Import cả giáo trình (zip / xlsx)

Vào **Giáo trình → Kho syllabus → Import giáo trình mới**.

| File | Kết quả mong đợi |
|---|---|
| `giao-trinh-flyers.zip` | Hợp lệ: mã `MAU-FLYERS`, 2 chặng (Chặng 1 báo trước 3 buổi), 3 unit, 8 buổi đủ cột Sheet 1, 4 tài liệu (1 chung, 2 chặng 1, 1 chặng 2). Import lần 2 báo trùng mã — đổi mã trong sheet “Giáo trình” để thử lại. |
| `giao-trinh-mau-cu.xlsx` | Hợp lệ dù theo mẫu cũ (không có cột Sheet 1, cột nội dung tên cũ “Hoạt động”): mã `MAU-CU-01`, 2 chặng, 3 buổi, loại buổi mặc định Học. |
| `giao-trinh-co-loi.zip` | Không import được; lỗi: Báo trước (buổi) 99, loại buổi “Thi cuối kỳ”, mốc “Gửi phụ huynh”, link PPT sai, trùng buổi số 1, thư mục `tai-lieu/chang-9/` không có chặng. |

## Tạo lại file

Các file sinh từ `Database\Seeders\Support\SyllabusSampleFiles` (`SyllabusSampleFilesTest` kiểm tra kết quả ở trên). Sau khi sửa
form / file mẫu, tạo lại:

```bash
php artisan tinker --execute="(new Database\Seeders\Support\SyllabusSampleFiles)->writeAll(base_path('docs/mau-test-kho-syllabus'));"
```
