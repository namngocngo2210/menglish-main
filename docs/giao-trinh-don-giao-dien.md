# Dọn giao diện mục Giáo trình

Trạng thái: **Hoàn thành** — 23/10/2026

## 1. Bối cảnh

Phản hồi của chủ dự án: giao diện phần giáo trình đang rối, các màn có thể lồng ghép vào nhau.

Hiện trạng (13 màn `resources/js/Pages/Syllabus/*`, rải ở 4 nhóm sidebar: Giáo trình, Big Test, Phê duyệt, Xin duyệt):

- Workspace **Giáo trình** (`SidebarMenu::definition()`, id `syllabus`) có tab theo thứ tự `Tài liệu | Soạn syllabus |
  Giao chặng | Chặng đang dạy & Order Test | Xem bài giảng | Dự án soạn sách & chương trình` — thanh tab này
  đã đủ để chuyển giữa các màn (giữ nguyên).
- `/syllabus` (`syllabus.index`) luôn mở màn **Tài liệu** (màn quản lý), kể cả với giáo viên.
- Đầu trang mỗi màn có 2–3 nút chéo sang màn khác (Tài liệu → Soạn / Xem như GV, Soạn → Giao chặng, Đề xuất →
  Trạng thái / Tiến độ…), trùng với thanh tab của workspace.
- Nút "Quay lại" (`:back`) của nhiều màn ngang hàng trỏ về Tài liệu; `TeacherAdjust` trỏ về `syllabus.versions` (màn
  duyệt); `TeacherView` trỏ về Tài liệu dù giáo viên không có việc gì ở đó.
- Màn Soạn syllabus (`Builder.vue`): ô soạn chặng / unit / buổi nằm đầu trang, mở qua `?edit_lesson=…#editor` → mỗi lần
  "Sửa" trang nhảy lên đầu, mất vị trí trong cây. Có 2 nút "Tạo giáo trình mới" và thanh đáy "Quay lại / Tiếp tục".

## 2. Phương án đã cân nhắc

| | Mô tả | Kết luận |
|---|---|---|
| **A. Dọn, không gộp màn** | Giữ route/trang; bỏ nút chéo, sửa back, ô soạn thành modal | **Chọn** — rủi ro thấp, ~1 sprint |
| B. Gộp theo đối tượng | Trang chi tiết giáo trình có tab (Cấu trúc / Tài liệu / Lớp / Đề xuất) + màn "Lớp tôi dạy" cho GV | Để sau, khi A chưa đủ |
| C. Workspace kiểu IDE | Cây bên trái, chi tiết bên phải | Loại — chi phí cao, lệch quy ước modal/Inertia của repo |

## 3. Quyết định

Phạm vi: phía Học thuật + Giáo viên. **Không** đổi sidebar, route, schema, quyền. **Không** đụng Big Test (trừ sửa 1 nút back
sai).

### 3.1. Sidebar — giữ nguyên

Workspace **Giáo trình** và các tab bên trong (nhãn, thứ tự, quyền) giữ nguyên; chỉ chỉnh bên trong từng màn. Nhóm
Phê duyệt / Xin duyệt cũng giữ nguyên.

### 3.2. Trang mặc định `/syllabus`

`syllabus.index` chuyển hướng: có `syllabus.manage` → `syllabus.documents` (tab đầu tiên, như hiện tại); còn lại →
`syllabus.teaching-stages` (giáo viên không còn rơi vào màn quản lý tài liệu).

### 3.3. Quy tắc đầu trang & Quay lại

- Đầu trang chỉ chứa **thao tác của chính trang** (Tạo, Tải lên, Import, Gửi). Điều hướng ngang hàng do thanh tab đảm
  nhận.
- `:back` chỉ cho **trang con** (Import → Soạn syllabus). Trang ngang hàng không có back.

| Trang | Bỏ | Giữ |
|---|---|---|
| `Documents` | nút "Soạn syllabus", "Xem như giáo viên" | Tải lên; nút xem / tải / xóa từng dòng |
| `Builder` | nút "Giao chặng"; nút "Tạo giáo trình mới" cạnh ô chọn giáo trình; thanh đáy "Quay lại / Tiếp tục"; `:back` | Import, Tạo giáo trình (đầu trang + empty state) |
| `Assignments` | "Màn GV xem giáo trình" | Xem log, Thiết lập chặng mới |
| `TeacherView` | `:back` | Tìm kiếm, Đề xuất sửa |
| `TeacherPropose` | `:back`, "Xem trạng thái đề xuất", "Xin điều chỉnh tiến độ" | Gửi đề xuất |
| `TeacherAdjust` | `:back` (trỏ sai về màn duyệt), "Duyệt yêu cầu tiến độ" | Gửi yêu cầu |
| `Versions` | `:back`, "Gửi đề xuất" | — |
| `AdjustmentRequests` | "Gửi yêu cầu mới" | — |
| `BigTestSchedules` | `:back` về Tài liệu (sai workspace) | phần còn lại |

### 3.4. Soạn syllabus — soạn trong modal

- Khối `#editor` chuyển vào `UiModal` (`:show="!!editor"`, `dismiss-url` = `?curriculum=X`), cùng mẫu
  `selected` + `dismiss-url` của `Versions` / `AdjustmentRequests`. Server vẫn tính prop `editor` từ query như cũ.
- Nút Sửa / Thêm (chặng, unit, buổi) bỏ `#editor`, dùng `preserve-scroll` → giữ vị trí cây khi mở / đóng / lưu.
- Lưu xong controller redirect về builder không kèm tham số editor → modal tự đóng.

## 4. Rủi ro

- Test đang assert nút cũ (`SyllabusModuleTest`, `Phase2MockupSyllabusTest`, `Phase2AcceptanceTest`,
  `AcademicSystemTest`, `SyllabusStageProgressionTest`) → cập nhật theo nút / back mới.
- Người dùng quen nút chéo cũ → thanh tab của workspace (đã có) thay thế; không mất chức năng nào.
- `syllabus.index` đổi từ render sang redirect → link / bookmark cũ vẫn chạy.

## 5. Sprint

### Sprint 1 — Điều hướng & soạn trong modal (xong)

- [x] `/syllabus` redirect theo quyền (3.2), test 2 trường hợp.
- [x] Bỏ nút chéo / back theo bảng 3.3.
- [x] Builder: ô soạn thành modal, `preserve-scroll` (3.4); test prop `editor` với `?edit_lesson=`.
- [x] Cập nhật test cũ; chạy test Syllabus + `NavigationPermissionTest`, `AllRoutesSmokeTest`, `ZiggyRoutesTest`.

Ghi chú khi làm:

- `AdjustmentRequests` cũng có `:back` về Tài liệu → bỏ theo quy tắc 3.3.
- Nút "Tạo giáo trình mới" thứ hai trong Builder thực ra là nút ở empty state → giữ.
- Prop `canReview` của `TeacherAdjust` không còn dùng → bỏ khỏi controller.
- v2 (`v2/doi-chieu-laravel.txt`) theo dõi các màn Syllabus: thay đổi chỉ là giao diện + 1 redirect, port sang v2 khi đồng bộ đợt sau.

## 6. Để sau (ngoài phạm vi)

- Gộp màn theo đối tượng (phương án B).
- Tải tài liệu ngay tại chặng trong cây Cấu trúc.
- Dọn workspace Big Test.
