# Xem và vẽ trên PDF giáo trình

Trạng thái: **Chờ duyệt thiết kế** — 23/10/2026

## 1. Bối cảnh

- Giáo viên mở tài liệu giáo trình ở màn **Xem bài giảng** (`syllabus.teacher-view`, `Pages/Syllabus/TeacherView.vue`).
  Bấm một tài liệu → hộp thoại xem: PDF hiện bằng `<iframe>` (trình xem PDF của trình duyệt), ảnh / audio / video
  hiện trực tiếp, Word / PowerPoint / Excel không xem trực tuyến được (chỉ tải về nếu được phép).
- Học thuật chốt: **tài liệu giáo trình chỉ là PDF, ảnh, audio, video**; slide / Word xuất sang PDF trước khi tải lên.
  File `.xlsx` chỉ còn là file tổng của gói import syllabus (`docs/import-syllabus.md`, route import riêng), không phải
  tài liệu.
- Repo đã có `pdfjs-dist` và `Components/PlacementTests/PdfViewer.vue` (pdf.js vẽ từng trang PDF thành canvas).

Yêu cầu:

1. Trang Tài liệu chỉ nhận PDF, ảnh, audio, video.
2. Khi xem PDF, giáo viên **vẽ / highlight lên trang** như phần mềm đọc PDF để giảng bài (chiếu lên TV / máy chiếu,
   dùng chuột, ngón tay hoặc bút cảm ứng). **Không lưu vào giáo trình.**

## 2. Quyết định

1. **Nét vẽ chỉ nằm trong bộ nhớ trình duyệt**: đóng hộp thoại / đổi tài liệu / tải lại trang là mất. Không gọi
   server, không bảng mới, không sửa file PDF.
2. **Công cụ**: Xem (cuộn), Bút, Highlight, Hình (đường thẳng, mũi tên, chữ nhật, ellipse), Chữ, Tẩy, Laser; 5 màu,
   3 độ dày; Hoàn tác / Làm lại; Xoá nét một trang; Xoá tất cả; Toàn màn hình.
3. **Tự dựng lớp vẽ trên pdf.js sẵn có** (phương án A), không thêm thư viện.
4. **Siết định dạng tài liệu** ở `SyllabusDocument::ALLOWED_EXTENSIONS` (Laravel) và `DocumentPolicy` (v2). Tài liệu
   Office đã tải lên trước đây giữ nguyên.
5. Test logic thuần của lớp vẽ bằng **`node --test`** (có sẵn trong Node ≥ 20, không thêm dependency).

### Phương án đã cân nhắc

- **B. Chế độ chú thích có sẵn của pdf.js (`AnnotationEditorLayer`)**: có bút / highlight / chữ và kéo-sửa đối tượng,
  nhưng không có hình, laser, tẩy; giao diện theo CSS của pdf.js; API nội bộ đổi theo từng phiên bản → loại.
- **C. Fabric.js / Konva đè lên trang**: có sẵn chọn / kéo / sửa chữ, nhưng thêm ~300 KB dependency cho một màn và hai
  thư viện canvas phải đồng bộ toạ độ → loại.
- **Lưu nét vẽ trên máy (localStorage) hoặc trên server theo giáo viên**: không cần — nét vẽ chỉ phục vụ buổi giảng
  đang diễn ra.
- **Chọn rồi kéo / đổi kích thước đối tượng đã vẽ**: để sau nếu giáo viên cần; hiện sửa bằng Hoàn tác / Tẩy.

## 3. Thiết kế chi tiết

### 3.1. Định dạng tài liệu

- `SyllabusDocument::ALLOWED_EXTENSIONS` bỏ `doc, docx, ppt, pptx, xls, xlsx`. Upload trang Tài liệu và file trong gói
  import (`tai-lieu/`) đều đi qua hằng này nên cùng được siết.
- `Pages/Syllabus/Documents.vue`: `accept` chỉ còn PDF / ảnh / audio / video; dòng hướng dẫn: "Hỗ trợ PDF, ảnh, audio,
  video (tối đa 100MB). Slide / Word hãy xuất sang PDF trước khi tải lên."
- Tài liệu Office cũ: không xoá, không đổi. `getKindAttribute` / `inline_viewable` / `documentFile` giữ nguyên nên vẫn
  hiện trong danh sách và tải được nếu `downloadable`.
- v2: `v2/api/internal/syllabus/documents.go` `DocumentPolicy.Types` bỏ các đuôi Office; bump commit nhóm
  `giao-trinh` trong `v2/doi-chieu-laravel.txt`.

### 3.2. Thành phần giao diện

| File | Vai trò |
|---|---|
| `resources/js/lib/pdf.js` (mới) | `openPdf(src)`: lazy import pdf.js + worker, `isEvalSupported: false`; trả `{ task, doc }`. Tách từ `PdfViewer.vue`. |
| `Components/PlacementTests/PdfViewer.vue` | Dùng `openPdf`; hành vi giữ nguyên. |
| `resources/js/lib/annotations.js` (mới) | Logic thuần (không Vue / DOM): hằng màu & độ dày, `drawShape(ctx, shape, w, h)`, `hitShape(shape, point, w, h, tolerance)`, `createHistory()` (thêm / xoá / xoá trang, hoàn tác / làm lại). |
| `resources/js/lib/annotations.test.js` (mới) | `node --test`; script `"test:js": "node --test resources/js/lib/"` trong `package.json`. |
| `Components/Pdf/PdfAnnotator.vue` (mới) | Vẽ trang PDF + lớp vẽ, Pointer Events, ô chữ, laser, phím tắt; giữ trạng thái nét vẽ. |
| `Components/Pdf/AnnotatorToolbar.vue` (mới) | Thanh công cụ (công cụ, màu, độ dày, hoàn tác / làm lại, xoá tất cả, toàn màn hình). |
| `Pages/Syllabus/TeacherView.vue` | `kind === 'pdf'` → `<PdfAnnotator>` thay `<iframe>`. Ảnh / audio / video không đổi. |
| `Components/ui/UiModal.vue` | Prop tuỳ chọn `dirtyConfirm` (`{ title, message, confirmLabel, cancelLabel }`) thay lời hỏi mặc định "Đóng biểu mẫu?". Không truyền → như cũ. |

### 3.3. Mô hình nét vẽ

```js
// Một đối tượng; toạ độ theo tỉ lệ trang (0–1) để vẽ lại đúng chỗ khi đổi kích thước.
{ type: 'pen' | 'highlighter' | 'line' | 'arrow' | 'rect' | 'ellipse' | 'text',
  color, width, points: [[x, y], ...], text }
```

- `pages: Map<số trang, đối tượng[]>`; lịch sử chung cho cả tài liệu, mỗi bước `{ page, kind: 'add' | 'remove' |
  'clear', shapes }`. Thao tác mới xoá nhánh làm lại.
- `width` là bậc 1–3; nét vẽ quy ra pixel theo bề rộng trang hiện tại (to theo trang khi toàn màn hình). Với `text`,
  `width` là cỡ chữ.
- Highlight: alpha ~0.35, `globalCompositeOperation = 'multiply'`, nét to hơn bút.
- Laser không vào mô hình / lịch sử: vẽ trên lớp tạm bằng `requestAnimationFrame`, các điểm mờ dần và bỏ sau ~1,5 giây.
- Bảng màu (đen, đỏ, xanh dương, xanh lá, vàng) là dữ liệu nét vẽ, khai báo cố định trong `annotations.js`, không lấy
  theo theme.

### 3.4. Hiển thị và thao tác

- Mỗi trang: khung `relative` chứa canvas PDF + canvas vẽ (`absolute inset-0`) + lớp tạm (xem trước hình / laser).
  Trang vẽ theo bề rộng khung như `PdfViewer`; `ResizeObserver` trên khung → vẽ lại PDF và nét vẽ (debounce) khi vào /
  thoát toàn màn hình, xoay máy.
- Thanh công cụ `sticky top-0` trong khung xem. Phần tử toàn màn hình (`requestFullscreen`) là khung bao thanh công cụ
  + các trang + watermark; ở chế độ này khung cuộn dọc được.
- **Chế độ Xem (mặc định)**: lớp vẽ `pointer-events: none` → cuộn / vuốt bình thường. **Công cụ vẽ**: lớp vẽ nhận
  Pointer Events, `touch-action: none` (vẽ trên iPad không trôi trang), `setPointerCapture` khi kéo.
- Bút / Highlight: kéo vẽ nét tự do. Hình: kéo từ điểm đầu tới điểm cuối, xem trước trên lớp tạm. Chữ: bấm vào trang →
  `<input>` đặt tại điểm bấm (màu / cỡ theo lựa chọn); Enter hoặc rời ô → chốt, Esc → huỷ, trống → bỏ. Tẩy: kéo qua
  đối tượng nào xoá cả đối tượng đó; một lần kéo = một bước lịch sử. Laser: chấm đỏ có vệt mờ dần.
- Phím tắt khi khung xem có focus: `Ctrl/⌘+Z` hoàn tác, `Ctrl/⌘+Shift+Z` / `Ctrl+Y` làm lại. Esc khi đang gõ chữ chỉ
  huỷ ô chữ (`preventDefault` để `UiModal` không đóng).
- Nút "Xoá nét trang này" ở góc mỗi trang (chỉ hiện khi trang có nét); "Xoá tất cả" trên thanh công cụ hỏi xác nhận
  (`confirmDialog`). Cả hai hoàn tác được.
- **Đóng khi có nét vẽ**: `PdfAnnotator` phát sự kiện `input` (bubble) khi nét vẽ thay đổi → `UiModal` coi là đã sửa;
  `TeacherView` truyền `dirtyConfirm`: "Đóng tài liệu? Nét vẽ trên tài liệu sẽ bị xoá." Xoá hết nét không đặt lại trạng
  thái (đơn giản; hỏi thừa một lần là chấp nhận được).
- Watermark "Chỉ xem" giữ nguyên, nằm trên cùng, `pointer-events-none`. Nút Tải về / Đánh dấu đã xem không đổi.
- Lỗi tải PDF → thông báo như `PdfViewer` ("Không hiển thị được file PDF trên trình duyệt này.").

### 3.5. Phạm vi không làm

- Không lưu / xuất nét vẽ, không in kèm nét vẽ.
- Không chọn / kéo / đổi kích thước đối tượng đã vẽ.
- Không thêm công cụ vẽ cho ảnh, không port lớp vẽ sang v2 (chỉ đồng bộ định dạng).

## 4. Test

- PHPUnit (`tests/Feature/Phase2SyllabusTest.php`):
  - upload `.pptx` / `.docx` / `.xlsx` → lỗi validation; `.pdf` vẫn nhận;
  - tài liệu Office cũ (tạo thẳng trong DB) vẫn hiện ở TeacherView và tải được khi `downloadable`;
  - TeacherView mở tài liệu PDF → HTML (SSR) có khung annotator, không còn `<iframe>`.
- `node --test` (`resources/js/lib/annotations.test.js`): hit-test từng loại hình (trúng / trượt theo dung sai), lịch sử
  thêm / xoá / xoá trang / hoàn tác / làm lại / nhánh làm lại bị cắt, quy đổi toạ độ tỉ lệ ↔ pixel.
- Go: test `DocumentPolicy` từ chối `.pptx`.
- Thử tay trên trình duyệt: từng công cụ; đổi kích thước & toàn màn hình (nét giữ đúng chỗ); giả lập cảm ứng trong
  DevTools (Xem cuộn được, Bút vẽ không trôi trang); đóng có nét → hỏi; Placement Test vẫn xem PDF bình thường.

## 5. Sprint

Một nhánh `feat/pdf-annotator`, mỗi sprint một commit, một PR.

| Sprint | Nội dung | Trạng thái |
|---|---|---|
| 1 | Siết định dạng tài liệu (Laravel + v2) + test; tách `lib/pdf.js`, `PdfViewer` dùng lại | Chưa làm |
| 2 | `annotations.js` + `node --test`; `PdfAnnotator` (lớp vẽ, Xem / Bút / Highlight / Tẩy, hoàn tác / làm lại, xoá trang / tất cả); thay iframe ở TeacherView | Chưa làm |
| 3 | Hình, Chữ, Laser, phím tắt, toàn màn hình, hỏi khi đóng (`UiModal.dirtyConfirm`); test SSR | Chưa làm |
