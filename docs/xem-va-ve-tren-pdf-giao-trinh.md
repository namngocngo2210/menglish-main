# Xem và vẽ trên PDF giáo trình

Trạng thái: **Hoàn thành** — duyệt và triển khai 23/10/2026

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
3. Hạn chế chụp / sao chép bài giảng. Web **không chặn được** chụp / quay màn hình (PrintScreen, Snipping Tool, phần
   mềm quay, điện thoại chụp TV) — chỉ răn đe và truy vết được.

## 2. Quyết định

1. **Nét vẽ chỉ nằm trong bộ nhớ trình duyệt**: đóng hộp thoại / đổi tài liệu / tải lại trang là mất. Không gọi
   server, không bảng mới, không sửa file PDF.
2. **Công cụ**: Xem (cuộn), Bút, Highlight, Hình (đường thẳng, mũi tên, chữ nhật, ellipse), Chữ, Tẩy, Laser; 5 màu,
   3 độ dày; Hoàn tác / Làm lại; Xoá nét một trang; Xoá tất cả; Toàn màn hình.
3. **Tự dựng lớp vẽ trên pdf.js sẵn có** (phương án A), không thêm thư viện.
4. **Siết định dạng tài liệu** ở `SyllabusDocument::ALLOWED_EXTENSIONS`. Tài liệu Office đã tải lên trước đây giữ
   nguyên. **Không sửa v2** trong đợt này (cảnh báo `v2-parity` không chặn).
5. Test logic thuần của lớp vẽ bằng **`node --test`** (có sẵn trong Node ≥ 20, không thêm dependency).
6. **Chống sao chép tài liệu "Chỉ xem"** (`downloadable = false`): watermark định danh **vẽ thẳng vào ảnh trang PDF**
   (tên · mã nhân viên · thời điểm mở, lặp kín trang) để truy vết ảnh chụp; chặn in, menu chuột phải, `Ctrl/⌘+P`,
   `Ctrl/⌘+S` trên khung xem. Tài liệu được phép tải về không bật.

### Phương án đã cân nhắc

- **B. Chế độ chú thích có sẵn của pdf.js (`AnnotationEditorLayer`)**: có bút / highlight / chữ và kéo-sửa đối tượng,
  nhưng không có hình, laser, tẩy; giao diện theo CSS của pdf.js; API nội bộ đổi theo từng phiên bản → loại.
- **C. Fabric.js / Konva đè lên trang**: có sẵn chọn / kéo / sửa chữ, nhưng thêm ~300 KB dependency cho một màn và hai
  thư viện canvas phải đồng bộ toạ độ → loại.
- **Lưu nét vẽ trên máy (localStorage) hoặc trên server theo giáo viên**: không cần — nét vẽ chỉ phục vụ buổi giảng
  đang diễn ra.
- **Chọn rồi kéo / đổi kích thước đối tượng đã vẽ**: để sau nếu giáo viên cần; hiện sửa bằng Hoàn tác / Tẩy.
- **Che nội dung khi cửa sổ mất focus** (mở công cụ chụp / chuyển app): bắt được Snipping Tool nhưng che luôn khi giáo
  viên bấm sang cửa sổ khác lúc đang giảng → không làm.
- **Server chuyển PDF thành ảnh từng trang có watermark in sẵn** (trình duyệt không nhận file PDF gốc — hiện người biết
  DevTools vẫn lưu được file PDF sạch từ tab Network): cần `poppler-utils` trong Docker image, endpoint ảnh theo trang,
  cache → để PR riêng nếu cần.

## 3. Thiết kế chi tiết

### 3.1. Định dạng tài liệu

- `SyllabusDocument::ALLOWED_EXTENSIONS` bỏ `doc, docx, ppt, pptx, xls, xlsx`. Upload trang Tài liệu và file trong gói
  import (`tai-lieu/`) đều đi qua hằng này nên cùng được siết.
- `Pages/Syllabus/Documents.vue`: `accept` chỉ còn PDF / ảnh / audio / video; dòng hướng dẫn: "Hỗ trợ PDF, ảnh, audio,
  video (tối đa 100MB). Slide / Word hãy xuất sang PDF trước khi tải lên."
- Tài liệu Office cũ: không xoá, không đổi. `getKindAttribute` / `inline_viewable` / `documentFile` giữ nguyên nên vẫn
  hiện trong danh sách và tải được nếu `downloadable`.

### 3.2. Thành phần giao diện

| File | Vai trò |
|---|---|
| `resources/js/lib/pdf.js` (mới) | `openPdf(src)`: lazy import pdf.js + worker, `isEvalSupported: false`; trả `{ task, doc }`. Tách từ `PdfViewer.vue`. |
| `Components/PlacementTests/PdfViewer.vue` | Dùng `openPdf`; hành vi giữ nguyên. |
| `resources/js/lib/annotations.js` (mới) | Logic thuần (không Vue / DOM): hằng màu & độ dày, `drawShape(ctx, shape, w, h)`, `hitShape(shape, point, w, h, tolerance)`, `createHistory()` (thêm / xoá / xoá trang, hoàn tác / làm lại). |
| `resources/js/lib/annotations.test.js` (mới) | `node --test`; script `"test:js": "node --test resources/js/lib/"` trong `package.json`. |
| `Components/Pdf/PdfAnnotator.vue` (mới) | Mở PDF, giữ trạng thái nét vẽ + lịch sử, đổi kích thước, toàn màn hình, phím tắt; prop `watermark` (khác null → vẽ watermark + chặn in / chuột phải / phím tắt). |
| `Components/Pdf/AnnotatorPage.vue` (mới) | Một trang: canvas PDF + canvas nét vẽ + lớp tạm; Pointer Events, ô chữ, laser, tẩy; vẽ trễ theo `IntersectionObserver`. |
| `Components/Pdf/AnnotatorToolbar.vue` (mới) | Thanh công cụ (công cụ, màu, độ dày, hoàn tác / làm lại, xoá tất cả, toàn màn hình). |
| `Pages/Syllabus/TeacherView.vue` | `kind === 'pdf'` → `<PdfAnnotator :watermark="selected.watermark">` thay `<iframe>` và lớp watermark HTML. Ảnh / audio / video giữ watermark HTML, thêm chặn in / chuột phải khi "Chỉ xem". |
| `SyllabusController::teacherView` | `selected.watermark`: `null` khi được tải về; ngược lại `"<tên> · <mã NV hoặc email> · <d/m/Y H:i>"` (giờ server lúc mở). |
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
- **Chỉ vẽ trang gần màn hình** (`IntersectionObserver`, lề 1 màn hình): trang ở xa là khung giữ chỗ đúng tỉ lệ, trang
  đã ra xa thì giải phóng canvas. Sách 100+ trang vẽ hết một lúc ở độ nét ×2 tốn hơn 1 GB RAM, làm sập trình duyệt
  trên iPad / máy yếu.
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
- **Watermark trong trang** (tài liệu "Chỉ xem"): sau khi vẽ trang PDF, vẽ thêm lên **cùng canvas PDF** lưới chữ xoay
  −20°: dòng `MENGLISH INTERNAL ONLY` + dòng `selected.watermark`, màu `#1f2937` alpha 0.12, cỡ theo bề rộng trang,
  ô lưới 1/2 × 1/4 bề rộng trang, hàng so le. Nằm dưới nét vẽ giáo viên, có trong mọi ảnh chụp, xoá phần tử HTML không mất.
- **Chặn sao chép** (tài liệu "Chỉ xem"): `contextmenu.prevent` trên khung xem; khi đang mở, `keydown` trên `window`
  chặn `Ctrl/⌘+P`, `Ctrl/⌘+S`; class `print:hidden` cho khung xem (in trang ra giấy trắng phần tài liệu). Áp cho cả
  ảnh / video "Chỉ xem" (trên khung xem hiện có).
- Nút Tải về / Đánh dấu đã xem không đổi.
- Lỗi tải PDF → thông báo như `PdfViewer` ("Không hiển thị được file PDF trên trình duyệt này.").

### 3.5. Phạm vi không làm

- Không lưu / xuất nét vẽ, không in kèm nét vẽ.
- Không chọn / kéo / đổi kích thước đối tượng đã vẽ.
- Không thêm công cụ vẽ cho ảnh. Không sửa v2 (cả định dạng tài liệu lẫn lớp vẽ).
- Không cam kết chặn chụp / quay màn hình; không che khi mất focus; không render ảnh trang phía server (xem 2).

## 4. Test

- PHPUnit (`tests/Feature/Phase2SyllabusTest.php`):
  - upload `.pptx` / `.docx` / `.xlsx` → lỗi validation; `.pdf` vẫn nhận;
  - tài liệu Office cũ (tạo thẳng trong DB) vẫn hiện ở TeacherView và tải được khi `downloadable`;
  - TeacherView mở tài liệu PDF → HTML (SSR) có khung annotator, không còn `<iframe>`;
  - `selected.watermark` chứa tên giáo viên khi "Chỉ xem", `null` khi được tải về.
- `node --test` (`resources/js/lib/annotations.test.js`): hit-test từng loại hình (trúng / trượt theo dung sai), lịch sử
  thêm / xoá / xoá trang / hoàn tác / làm lại / nhánh làm lại bị cắt, quy đổi toạ độ tỉ lệ ↔ pixel.
- Thử tay trên trình duyệt: từng công cụ; đổi kích thước & toàn màn hình (nét giữ đúng chỗ); giả lập cảm ứng trong
  DevTools (Xem cuộn được, Bút vẽ không trôi trang); đóng có nét → hỏi; Placement Test vẫn xem PDF bình thường.

## 5. Sprint

Một nhánh `feat/pdf-annotator`, mỗi sprint một commit, một PR.

| Sprint | Nội dung | Trạng thái |
|---|---|---|
| 1 | Siết định dạng tài liệu + test; tách `lib/pdf.js`, `PdfViewer` dùng lại | Xong |
| 2 | `annotations.js` + `node --test`; `PdfAnnotator` (lớp vẽ, Xem / Bút / Highlight / Tẩy, hoàn tác / làm lại, xoá trang / tất cả, watermark trong trang); thay iframe ở TeacherView | Xong |
| 3 | Hình, Chữ, Laser, phím tắt, toàn màn hình, hỏi khi đóng (`UiModal.dirtyConfirm`); chặn in / chuột phải / phím tắt; test | Xong |
