# PDF Annotator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tài liệu giáo trình chỉ nhận PDF / ảnh / audio / video; giáo viên vẽ, highlight, gõ chữ, vẽ hình, dùng laser trên PDF ở màn Xem bài giảng, không lưu; tài liệu "Chỉ xem" có watermark định danh trong trang và chặn in / chuột phải / Ctrl+P / Ctrl+S.

**Architecture:** Logic thuần (mô hình nét vẽ, hit-test, lịch sử) trong `resources/js/lib/annotations.js`, test bằng `node --test`. Ba component Vue trong `resources/js/Components/Pdf/`: `PdfAnnotator` (mở PDF qua `lib/pdf.js`, giữ lịch sử, đổi kích thước, toàn màn hình, phím tắt), `AnnotatorPage` (một trang: canvas PDF + canvas nét + lớp tạm, Pointer Events, vẽ trễ), `AnnotatorToolbar`. `TeacherView.vue` thay `<iframe>` bằng `PdfAnnotator`; `UiModal` thêm prop `dirtyConfirm`.

**Tech Stack:** Laravel 13 / PHP 8.4, PHPUnit (SSR qua Node), Vue 3 `<script setup>` (JS, không TS — theo repo), `pdfjs-dist` 6 có sẵn, Tailwind theo token repo, Material Symbols.

**Spec:** `docs/xem-va-ve-tren-pdf-giao-trinh.md`

## Global Constraints

- Không thêm dependency npm / composer. Không sửa `v2/`.
- `SyllabusDocument::ALLOWED_EXTENSIONS` = `pdf, jpg, jpeg, png, gif, webp, mp3, wav, m4a, ogg, mp4, mov, webm, m4v`.
- Nét vẽ chỉ ở bộ nhớ: không request server, không localStorage.
- Toạ độ nét vẽ theo tỉ lệ trang 0–1. Màu: đen `#1f2937`, đỏ `#dc2626`, xanh dương `#2563eb`, xanh lá `#16a34a`, vàng `#facc15`. Độ dày bậc 1–3.
- Chuỗi giao diện tiếng Việt; comment tiếng Việt như code xung quanh; commit message tiếng Anh, một dòng, không trailer.
- `pdfjs` luôn `isEvalSupported: false`.
- Component không đụng `window` / `document` khi setup (SSR); mọi thứ trình duyệt trong `onMounted`.
- Mỗi sprint một commit trên `feat/pdf-annotator`; cập nhật bảng Sprint trong spec trong cùng commit.
- Chống sao chép chỉ bật khi tài liệu "Chỉ xem" (`selected.watermark !== null`).

## Review Focus

1. **PDF nhiều trang (100+) trên iPad**: không vẽ hết một lúc — chỉ trang gần màn hình có canvas (Task 4, test tay với PDF 150 trang, RAM tab < 500 MB trong DevTools > Memory).
2. **Đổi kích thước giữa chừng** (vào / thoát toàn màn hình, xoay máy) khi đã có nét: nét giữ đúng vị trí trên chữ (Task 3 test `toPixels/toRatio`; Task 5 thử tay).
3. **Ngón thứ hai / palm chạm khi đang vẽ**: chỉ con trỏ đầu tiên vẽ, con trỏ khác bỏ qua (Task 4 Step code: theo dõi một `pointerId`).
4. **Esc khi đang gõ chữ** chỉ huỷ ô chữ, không đóng modal; Esc khi không gõ đóng modal (hỏi nếu có nét) (Task 5 thử tay).
5. **Đổi tài liệu khi PDF cũ còn đang tải**: không lẫn trang / nét của tài liệu cũ (Task 4: `renderToken` như `PdfViewer`, lịch sử tạo mới theo `src`).

---

## Chuẩn bị worktree (một lần, trước Task 1)

```bash
cd /home/menglish/wt-pdf-annotator
cp -al ../wt-follow-up-actions/vendor vendor          # composer.lock giống hệt (đã kiểm)
cp -al ../wt-follow-up-actions/node_modules node_modules   # package-lock.json giống hệt (đã kiểm)
cp ../wt-follow-up-actions/.env .env                  # .env test local, không phải .env.production
```

Lệnh chạy test PHP (dùng ở mọi task):

```bash
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v $PWD:$PWD -w $PWD --entrypoint php menglish-test:local \
  -d memory_limit=1G vendor/bin/phpunit <file> [--filter=<tên>]
```

---

## Sprint 1

### Task 1: Chỉ nhận PDF / ảnh / audio / video

**Files:**
- Modify: `app/Models/SyllabusDocument.php:24-30` (`ALLOWED_EXTENSIONS`)
- Modify: `resources/js/Pages/Syllabus/Documents.vue:176,184` (dòng hướng dẫn + `accept`)
- Test: `tests/Feature/Phase2SyllabusTest.php`

**Interfaces:** Produces: `SyllabusDocument::ALLOWED_EXTENSIONS` (gói import của nhánh `feat/syllabus-import` dùng lại).

- [ ] **Step 1: Viết test thất bại** — trong `Phase2SyllabusTest`, cạnh các test tài liệu:
  - helper `private function officeFile(string $name, string $part): UploadedFile` — dựng OOXML thật bằng `ZipArchive` (entry đầu tiên `[Content_Types].xml`, sau đó `$part/document.xml` hoặc tương ứng) trong file tạm, trả `new UploadedFile($path, $name, null, null, true)` (giống `realUpload`).
  - `test_document_upload_accepts_only_pdf_image_audio_video`: `Storage::fake('local')`; với từng `['bai.docx','word'], ['slide.pptx','ppt'], ['tong.xlsx','xl']` → `post(route('syllabus.documents.store'), [...])->assertSessionHasErrors('file')`; sau đó `$this->pdf()` → `assertSessionHasNoErrors()`; cuối cùng `assertSame(['pdf'], SyllabusDocument::pluck('extension')->all())`.
  - `test_legacy_office_document_still_listed_and_downloadable`: `Storage::fake('local')->put('syllabus/old.pptx', 'x')`; `SyllabusDocument::create([... 'title' => 'Slide cũ', 'file_path' => 'syllabus/old.pptx', 'original_name' => 'old.pptx', 'extension' => 'pptx', 'size_bytes' => 1, 'visible_to_teachers' => true, 'downloadable' => true])`; giáo viên `get(route('syllabus.teacher-view'))->assertSee('Slide cũ')`; `get(route('syllabus.documents.file', ['id' => $doc->id, 'download' => 1]))->assertOk()`.

- [ ] **Step 2: Chạy, xác nhận thất bại**

Run: lệnh PHP với `tests/Feature/Phase2SyllabusTest.php --filter='accepts_only_pdf|legacy_office'`
Expected: `accepts_only_pdf…` FAIL (docx được nhận, không có lỗi `file`); `legacy_office…` PASS. Nếu docx bị từ chối ngay từ đầu (libmagic không nhận ra OOXML), sửa helper cho đến khi upload được trước thay đổi — test phải đỏ vì đúng lý do.

- [ ] **Step 3: Sửa** `ALLOWED_EXTENSIONS` theo Global Constraints (sửa comment trên hằng nếu nhắc Word / slide). `Documents.vue`: `accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.mp3,.wav,.m4a,.ogg,.mp4,.mov,.webm,.m4v"`; dòng hướng dẫn: `Hỗ trợ PDF, ảnh, audio, video (tối đa 100MB). Slide / Word hãy xuất sang PDF trước khi tải lên.`

- [ ] **Step 4: Chạy lại** `tests/Feature/Phase2SyllabusTest.php` và `tests/Feature/Phase2MockupSyllabusTest.php` (cả file). Expected: PASS hết.

### Task 2: Tách `lib/pdf.js`

**Files:**
- Create: `resources/js/lib/pdf.js`
- Modify: `resources/js/Components/PlacementTests/PdfViewer.vue`

**Interfaces:**
- Produces: `export async function openPdf(src: string): Promise<{ task, doc }>` — lazy import `pdfjs-dist/legacy/build/pdf.mjs` + worker (`?worker`, gán `GlobalWorkerOptions.workerPort` một lần), `getDocument({ url: src, isEvalSupported: false })`; caller gọi `task.destroy()` khi bỏ.

- [ ] **Step 1:** Chuyển phần import / worker / `getDocument` của `PdfViewer.render()` sang `openPdf`, giữ nguyên comment giải thích (worker `.js`, `isEvalSupported`). `PdfViewer` gọi `const { task: t, doc } = await openPdf(props.src)`; giữ `renderToken`, xử lý lỗi, giao diện như cũ.
- [ ] **Step 2:** `npx vite build --ssr` rồi chạy `tests/Feature/PlacementTest*.php` có dùng PdfViewer (`grep -l "pdf_url\|PdfViewer" tests/Feature`). Expected: PASS. `npm run build` không lỗi.
- [ ] **Step 3: Commit sprint 1** — bảng Sprint trong spec: Sprint 1 → "Xong". Hoàn nguyên `public/build` (`git checkout -- public/build && git clean -fd public/build`).

```bash
git add app/Models/SyllabusDocument.php resources/js/Pages/Syllabus/Documents.vue tests/Feature/Phase2SyllabusTest.php resources/js/lib/pdf.js resources/js/Components/PlacementTests/PdfViewer.vue docs/xem-va-ve-tren-pdf-giao-trinh.md
git commit -m "feat: accept only pdf, image, audio, video syllabus documents"
```

---

## Sprint 2

### Task 3: `lib/annotations.js` + `node --test`

**Files:**
- Create: `resources/js/lib/annotations.js`, `resources/js/lib/annotations.test.js`
- Modify: `package.json` (`"test:js": "node --test resources/js/lib/"`)

**Interfaces — Produces:**
- `COLORS: { key, value, label }[]` (5 màu Global Constraints; label `Đen, Đỏ, Xanh dương, Xanh lá, Vàng`).
- `SIZES = [1, 2, 3]`.
- `TOOLS: { key, icon, label }[]` theo thứ tự `view (pan_tool, Xem), pen (edit, Bút), highlighter (ink_highlighter, Highlight), line (horizontal_rule, Đường thẳng), arrow (arrow_right_alt, Mũi tên), rect (rectangle, Chữ nhật), ellipse (circle, Hình tròn), text (title, Chữ), eraser (ink_eraser, Tẩy), laser (point_scan, Laser)`; `SHAPE_TOOLS = ['line','arrow','rect','ellipse']`.
- `unit(pageWidth) = pageWidth / 800`; `strokePx(shape, pageWidth)`: pen & hình `[2, 4, 7]`, highlighter `[12, 20, 32]` (× unit, theo `width` 1–3); `fontPx(size, pageWidth)`: `[16, 24, 36]` × unit.
- `toRatio(px, py, w, h) → [x, y]` (kẹp 0–1); `toPixels([x, y], w, h) → [px, py]`.
- Shape: `{ type, color, width, points: [[x,y],…], text?, box?: [bw, bh] }` — `text`: `points[0]` là góc trên-trái, `box` kích thước khung chữ theo tỉ lệ (component đo khi chốt).
- `drawShape(ctx, shape, w, h)`: pen / highlighter `lineCap/lineJoin = 'round'`, highlighter `globalAlpha 0.35` + `globalCompositeOperation 'multiply'` (khôi phục bằng `save/restore`); arrow = đường + đầu mũi tên 2 cạnh dài `3 × strokePx`, góc 28°; ellipse nội tiếp hình chữ nhật 2 điểm; text `fontPx` font `Be Vietnam Pro, sans-serif`, `textBaseline 'top'`.
- `hitShape(shape, [x, y], w, h, tolerancePx = 8) → boolean`: tính ở pixel; pen / highlighter / line / arrow: khoảng cách tới đoạn thẳng ≤ `tolerancePx + strokePx/2`; rect: tới 4 cạnh (chỉ viền); ellipse: tới polyline 48 điểm; text: điểm nằm trong `box` nới `tolerancePx`.
- `drawWatermark(ctx, lines: string[], w, h)`: lưới chữ xoay −20° phủ kín trang, ô lưới rộng `w / 3`, cao `w / 5`, mỗi ô vẽ các dòng (cỡ `fontPx(1, w)`, `bold`), `fillStyle '#1f2937'`, `globalAlpha 0.12` (`save/restore`).
- `createHistory()` → `{ shapesOn(page): Shape[], pagesWithShapes(): number[], add(page, shape), remove(page, shapes: Shape[]), clear(page), clearAll(), undo(): boolean, redo(): boolean, canUndo(): boolean, canRedo(): boolean, isEmpty(): boolean }`. Bước lịch sử: `{ kind: 'add'|'remove', page, shapes }` hoặc `{ kind: 'clear', pages: [[page, shapes]] }` (`clear(page)` và `clearAll()` đều ghi `clear`; trang trống → không ghi bước). Mọi thao tác mới làm rỗng ngăn làm lại. `remove` giữ nguyên thứ tự vẽ khi hoàn tác (chèn lại đúng chỉ số).

- [ ] **Step 1: Viết test thất bại** (`node:test` + `node:assert/strict`):
  - `toRatio/toPixels`: `toRatio(400, 300, 800, 600)` → `[0.5, 0.5]`; `toRatio(-5, 900, 800, 600)` → `[0, 1]`; `toPixels([0.25, 0.5], 1600, 1200)` → `[400, 600]`.
  - `strokePx`: pen width 2 trang 800 → 4; highlighter width 3 trang 1600 → 64.
  - `hitShape`: pen `[[0.1,0.1],[0.5,0.1]]` trang 800×600: `[0.3, 0.1]` trúng, `[0.3, 0.2]` trượt; rect `[[0.2,0.2],[0.6,0.6]]`: cạnh `[0.2, 0.4]` trúng, tâm `[0.4, 0.4]` trượt; ellipse cùng khung: `[0.4, 0.2]` trúng, tâm trượt; text `points [[0.1,0.1]], box [0.2, 0.05]`: `[0.2, 0.12]` trúng, `[0.5, 0.5]` trượt.
  - `createHistory`: add 2 nét trang 1 → `shapesOn(1).length === 2`; `remove(1, [đầu])` → 1; `undo()` → 2 và thứ tự cũ (`shapesOn(1)[0] === đầu`); `clearAll()` khi trang 1, 3 có nét → `isEmpty()`; `undo()` → cả hai trang trở lại; `redo()` → rỗng lại; sau `undo()` mà `add(...)` → `canRedo() === false`; `clear(5)` trang trống → `canUndo()` không đổi; `pagesWithShapes()` → `[1, 3]`.
- [ ] **Step 2:** `npm run test:js` → FAIL (module chưa có).
- [ ] **Step 3:** Viết `annotations.js` theo Interfaces.
- [ ] **Step 4:** `npm run test:js` → PASS.

### Task 4: `PdfAnnotator` + `AnnotatorPage` + `AnnotatorToolbar`, thay iframe

**Files:**
- Create: `resources/js/Components/Pdf/PdfAnnotator.vue`, `AnnotatorPage.vue`, `AnnotatorToolbar.vue`
- Modify: `resources/js/Pages/Syllabus/TeacherView.vue` (khối `<iframe v-if="selected.kind === 'pdf'">` và nút toàn màn hình)
- Test: `tests/Feature/Phase2MockupSyllabusTest.php`

**Interfaces:**
- Consumes: `openPdf` (Task 2); toàn bộ `annotations.js` (Task 3).
- `AnnotatorToolbar` props `tool, color, size` (`v-model:tool` / `v-model:color` / `v-model:size`), `canUndo, canRedo, hasAny, fullscreen: Boolean`; emits `undo, redo, clear-all, toggle-fullscreen`. Nút là `<button type="button">` có `title` + `aria-pressed`; Hình gom một nút mở menu 4 hình (Task 5 — ở task này chỉ hiện các công cụ `view, pen, highlighter, eraser`; dùng `TOOLS.filter` theo prop `tools: string[]`).
- `AnnotatorPage` props `page` (pdf.js page, `markRaw`), `number`, `width` (px CSS), `tool, color, size`, `shapes: Shape[]`, `root` (Element cuộn cho `IntersectionObserver`); emits `add(shape)`, `erase(shapes)`, `clear()`.
- `PdfAnnotator` props `src: String`, `title: String`, `watermark: { type: String, default: null }`; phát sự kiện DOM `input` (bubbles) trên phần tử gốc mỗi khi lịch sử thay đổi. Phần tử gốc có `data-pdf-annotator`.
- `AnnotatorPage` thêm prop `watermark` (chuỗi hoặc null); sau mỗi lần vẽ trang PDF xong mà `watermark` khác null → `drawWatermark(pdfCtx, ['MENGLISH INTERNAL ONLY', watermark], canvas.width, canvas.height)` lên **chính canvas PDF**.
- `SyllabusController::teacherView` → `selected.watermark`: `null` nếu `canDownload($user)`, ngược lại `$user->name.' · '.($user->employee_code ?: $user->email).' · '.now()->format('d/m/Y H:i')`.

- [ ] **Step 1: Viết test thất bại** — trong `test_documents_pick_real_stage_and_teacher_view_has_mockup_tabs`, ở lượt `get(route('syllabus.teacher-view', ['document' => $doc->id]))`: bỏ `->assertSee('MENGLISH INTERNAL ONLY')->assertSee($this->teacher->email)` (watermark PDF giờ nằm trong canvas), thêm `->assertSee('data-pdf-annotator', false)->assertDontSee('<iframe', false)->assertSee('Highlight')->assertInertia(fn ($page) => $page->where('selected.watermark', fn ($w) => str_contains($w, $this->teacher->name)))`. Trong `Phase2SyllabusTest` thêm `test_downloadable_document_has_no_watermark`: tài liệu PDF `downloadable = true`, giáo viên mở `?document=` → `assertInertia(fn ($page) => $page->where('selected.watermark', null))`.
- [ ] **Step 2:** `npx vite build --ssr`, chạy hai test đó → FAIL (`data-pdf-annotator` / `selected.watermark` chưa có).
- [ ] **Step 3: `PdfAnnotator`** — `onMounted` + `watch(src)`: `renderToken` như `PdfViewer`, `task?.destroy()`, `history = createHistory()` mới, `rev` (ref số) tăng sau mỗi thao tác lịch sử để Vue vẽ lại; lấy `doc.getPage(n)` cho mọi trang (chỉ là proxy, chưa vẽ) → `pages = shallowRef([...])` với `markRaw`. `ResizeObserver` trên khung trang → `width` (debounce 150 ms). Khung: `relative`, chứa toolbar `sticky top-0 z-10`, danh sách `AnnotatorPage` (`space-y-3`), truyền `watermark` xuống từng trang. "Xoá tất cả" → `confirmDialog({ title: 'Xoá tất cả nét vẽ?', message: 'Có thể hoàn tác.', confirmLabel: 'Xoá', danger: true })`. Trạng thái tải / lỗi như `PdfViewer` ("Đang mở tài liệu…", "Không hiển thị được file PDF trên trình duyệt này."). `onBeforeUnmount`: tăng token, `task?.destroy()`, ngắt observer.
- [ ] **Step 4: `AnnotatorPage`** — khung `relative` có `aspect-ratio` từ `page.getViewport({ scale: 1 })` (giữ chỗ trước khi vẽ). `IntersectionObserver` (root = `props.root`, `rootMargin: '100% 0px'`): vào vùng → vẽ PDF lên canvas (`scale = width / vp1.width × min(devicePixelRatio, 2)`, huỷ `renderTask` cũ nếu đang vẽ) + đặt kích thước canvas nét + `redraw()`; ra khỏi vùng `rootMargin: '300% 0px'` (observer thứ hai) → `canvas.width = canvas.height = 0` cả hai canvas. Canvas nét: `absolute inset-0`, `pointer-events-none` khi `tool === 'view'`, ngược lại `touch-action: none`. Pointer: `pointerdown` chỉ nhận khi chưa có `activePointer` (ghi `pointerId`, `setPointerCapture`); `pointermove/up/cancel` bỏ qua `pointerId` khác. Bút / highlight: gom điểm (`toRatio`), vẽ tạm lên lớp tạm, `pointerup` → `emit('add', shape)` nếu ≥ 2 điểm. Tẩy: mỗi `pointermove` gom `shapes.filter(hitShape)` vào `Set` + ẩn ngay (vẽ lại bỏ qua chúng), `pointerup` → `emit('erase', [...set])` nếu khác rỗng. `watch([shapes, width])` → `redraw()`. Nút "Xoá nét trang này" (`absolute right-2 top-2`, icon `ink_eraser`, chỉ khi `shapes.length`) → `emit('clear')`.
- [ ] **Step 5: `AnnotatorToolbar`** — bố cục theo spec 3.4 (công cụ │ màu │ độ dày │ hoàn tác / làm lại │ xoá tất cả │ toàn màn hình), `flex-wrap`, nút đang chọn dùng class `bg-primary-container/10 text-primary` như chip trong `TeacherView`. Màu là chấm tròn `style="background: value"` + `sr-only` label. Ẩn màu / độ dày khi `tool` là `view`, `eraser`.
- [ ] **Step 6: Controller + `TeacherView`** — thêm `selected.watermark` theo Interfaces. `kind === 'pdf'` → `<PdfAnnotator :src="fileUrl" :title="selected.title" :watermark="selected.watermark">`; khối watermark HTML và khung `ref="viewer"` chỉ còn cho ảnh / audio / video / định dạng khác; bỏ nút toàn màn hình ở header modal khi `kind === 'pdf'`.
- [ ] **Step 7:** `npx vite build --ssr`, chạy `Phase2MockupSyllabusTest.php` và `Phase2SyllabusTest.php` → PASS. `npm run build` không lỗi; `npm run test:js` PASS.
- [ ] **Step 8: Thử tay** (`composer dev` hoặc build + `php artisan serve` trong container, đăng nhập giáo viên demo): bút / highlight / tẩy / hoàn tác / làm lại / xoá trang / xoá tất cả; chế độ Xem cuộn được; DevTools giả lập cảm ứng: chế độ Bút không trôi trang; PDF 150 trang: cuộn mượt, Memory < 500 MB; đổi tài liệu khi đang tải không lẫn trang; tài liệu "Chỉ xem" có lưới watermark mờ trên mọi trang (xoá phần tử bằng DevTools vẫn còn trong canvas), tài liệu được tải về thì không.
- [ ] **Step 9: Commit sprint 2** — Sprint 2 → "Xong" trong spec; hoàn nguyên `public/build`.

```bash
git add resources/js/lib/annotations.js resources/js/lib/annotations.test.js package.json resources/js/Components/Pdf resources/js/Pages/Syllabus/TeacherView.vue app/Http/Controllers/SyllabusController.php tests/Feature/Phase2MockupSyllabusTest.php tests/Feature/Phase2SyllabusTest.php docs/xem-va-ve-tren-pdf-giao-trinh.md
git commit -m "feat: draw and highlight on syllabus PDFs"
```

---

## Sprint 3

### Task 5: Hình, Chữ, Laser, phím tắt, toàn màn hình, hỏi khi đóng, chặn in / chuột phải

**Files:**
- Modify: `resources/js/Components/Pdf/*.vue`, `resources/js/Components/ui/UiModal.vue`, `resources/js/Pages/Syllabus/TeacherView.vue`
- Test: `tests/Feature/Phase2MockupSyllabusTest.php`

**Interfaces:**
- `UiModal` prop `dirtyConfirm: { type: Object, default: null }`; `requestClose` gọi `confirmDialog({ title: 'Đóng biểu mẫu?', message: 'Các thay đổi chưa lưu sẽ bị mất.', confirmLabel: 'Bỏ thay đổi', cancelLabel: 'Tiếp tục sửa', danger: true, ...props.dirtyConfirm })`. Cập nhật docblock đầu file.

- [ ] **Step 1: Test thất bại** — cùng lượt `get(...['document' => $doc->id])` thêm `->assertSee('Mũi tên')->assertSee('Laser')->assertSee('Chữ')`. Chạy → FAIL.
- [ ] **Step 2: Toolbar** hiện đủ `TOOLS`; Hình = một nút (icon của hình đang chọn, mặc định `line`) mở menu 4 hình (`role="menu"`, đóng khi chọn / bấm ngoài / Esc).
- [ ] **Step 3: `AnnotatorPage`** —
  - Hình: `pointerdown` ghi điểm đầu, `pointermove` vẽ xem trước trên lớp tạm, `pointerup` → `emit('add', { type, color, width, points: [start, end] })` nếu hai điểm cách nhau > 4 px.
  - Chữ: `pointerup` (không kéo) → `<input>` `absolute` tại điểm bấm, `font-size = fontPx`, màu theo `color`, autofocus; `keydown.enter` / `blur` → đo `ctx.measureText` (font như `drawShape`) → `box = [textWidth / w, fontPx × 1.25 / h]` → `emit('add', …)`; chuỗi trim rỗng → bỏ; `keydown.esc` → `event.preventDefault()` + `event.stopPropagation()` + bỏ ô.
  - Laser: điểm `{ x, y, t }` trên lớp tạm, vòng `requestAnimationFrame` vẽ vệt đỏ `#ef4444` (alpha giảm theo tuổi, bỏ điểm > 1500 ms), chấm đầu bán kính `6 × unit`; dừng vòng khi hết điểm và không còn kéo. Không emit.
- [ ] **Step 4: `PdfAnnotator`** — phím tắt trên phần tử gốc (`tabindex="-1"`, focus khi bấm vào khung): `Ctrl/⌘+Z` → undo, `Ctrl/⌘+Shift+Z` hoặc `Ctrl+Y` → redo (bỏ qua khi focus ở `<input>`). Toàn màn hình: `root.requestFullscreen()`, theo dõi `fullscreenchange` → prop `fullscreen` cho toolbar (icon `fullscreen` / `fullscreen_exit`); khi toàn màn hình phần tử gốc `overflow-y-auto bg-surface-container h-full`.
- [ ] **Step 5: `TeacherView`** — `UiModal` của tài liệu: `:dirty-confirm="{ title: 'Đóng tài liệu?', message: 'Nét vẽ trên tài liệu sẽ bị xoá.', confirmLabel: 'Đóng', cancelLabel: 'Tiếp tục xem' }"`.
- [ ] **Step 5b: Chặn sao chép** (chỉ khi "Chỉ xem"): tạo `resources/js/lib/copyGuard.js` — `useCopyGuard(enabled: Ref<boolean>)` gắn listener `keydown` trên `window` khi `enabled` (trong `onMounted` / `watch`, gỡ ở `onBeforeUnmount`), chặn `(ctrlKey || metaKey) && ['p','s'].includes(key.toLowerCase())` bằng `preventDefault`. `PdfAnnotator` khi `watermark` khác null → `useCopyGuard`, `@contextmenu.prevent` trên gốc, class `print:hidden`. `TeacherView`: khung `ref="viewer"` của ảnh / video / audio khi `!selected.can_download` → `@contextmenu.prevent` + `print:hidden` + `useCopyGuard`.
- [ ] **Step 6:** `npx vite build --ssr`; chạy `Phase2MockupSyllabusTest.php`, `Phase2SyllabusTest.php`, `tests/Feature/HolidayModalTest.php` (UiModal) → PASS. `npm run test:js` PASS; `npm run build` không lỗi.
- [ ] **Step 7: Thử tay** — từng hình, chữ (Enter / blur / Esc / rỗng), laser mờ dần; tài liệu "Chỉ xem": chuột phải không hiện menu, Ctrl+P / Ctrl+S không làm gì, in trang từ menu trình duyệt ra trắng phần tài liệu; tài liệu được tải về: chuột phải / in bình thường; Esc khi gõ chữ không đóng modal; có nét rồi đóng → hỏi "Đóng tài liệu?"; vào / thoát toàn màn hình và xoay (DevTools) → nét đúng chỗ; Ctrl+Z / Ctrl+Shift+Z; Placement Test vẫn xem PDF.
- [ ] **Step 8: Commit sprint 3** — Sprint 3 → "Xong", trạng thái spec → "Hoàn thành"; hoàn nguyên `public/build`.

```bash
git add resources/js/Components/Pdf resources/js/Components/ui/UiModal.vue resources/js/lib/copyGuard.js resources/js/Pages/Syllabus/TeacherView.vue tests/Feature/Phase2MockupSyllabusTest.php docs/xem-va-ve-tren-pdf-giao-trinh.md
git commit -m "feat: add shapes, text, laser and copy guard to PDF viewer"
```

### Task 6: Kiểm tra cuối và mở PR

- [ ] **Step 1:** Chạy guard-rail + file bị ảnh hưởng: `--filter='Phase2SyllabusTest|Phase2MockupSyllabusTest|PlacementTest|HolidayModalTest|AllRoutesSmokeTest|NavigationPermissionTest'` → PASS. `vendor/bin/pint --test` trên file PHP đã sửa.
- [ ] **Step 2:** `git status` sạch (không `public/build`, không `vendor` / `node_modules` / `.env` — đã nằm trong `.gitignore`, kiểm lại).
- [ ] **Step 3:** `git push -u origin feat/pdf-annotator`; `gh pr create --base main` — tiêu đề `feat: draw and highlight on syllabus PDFs`, mô tả tiếng Việt ngắn: phạm vi, file Office cũ giữ nguyên, không sửa v2 (cảnh báo parity), kết quả test, ghi chú thử tay. Không trailer. Gửi link PR.
