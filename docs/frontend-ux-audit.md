# MEnglish — Audit Frontend (bố cục, responsive, module) & kế hoạch sprint

> **Ngày:** 26/09/2026 · **Người lập:** CTO
> **Stack:** Laravel 11 + Blade components + Alpine.js 3 + Tailwind 3.4 (token Material 3 trong `tailwind.config.js`), Vite.
> **Phương pháp:** đo tĩnh trên 222 file Blade bằng grep, đọc layout, component và CSS toàn cục, đối chiếu với bộ quy tắc UI/UX Pro Max (ux / stack laravel). **Chưa chạy app, chưa chụp màn hình từng breakpoint.** Các con số là số đếm grep nên có sai số nhỏ.
> **Bổ sung cho** `audit-report-and-roadmap.md` Phần B (tài liệu đó so với mockup; tài liệu này xét kiến trúc frontend và responsive).

---

## 1. Kết luận nhanh

| Hạng mục | Đánh giá | Ghi chú |
|---|:-:|---|
| App Shell (sidebar + topbar) | **Tốt** | 3 breakpoint (drawer <768, icon 72px 768–1199, đầy đủ ≥1200), sticky topbar, font tự host |
| Thư viện component `x-ui.*` | **Khá** | 21 component có tài liệu inline. 121/222 view đã dùng, số còn lại chưa |
| Nhất quán design token | **Yếu** | 6.827 class màu Tailwind thô (`gray-500`, `blue-600`…) trong 132 file, so với 3.870 class token |
| Responsive trang nội dung | **Trung bình** | Có 1.149 class `sm:/md:/lg:`, nhưng 40 file bảng không qua `x-ui.data-table`. CSS toàn cục đang "chữa cháy" bằng `!important` |
| Portal học viên / phụ huynh / TA | **Sai kiến trúc** | Khung điện thoại 430px vẽ bên trong layout admin, không phải app mobile thật |
| Tách module JS | **Yếu** | JS nằm trong view: 34 khối `<script>` (403 dòng ở `closing-wizard`). Không có `Alpine.data()` nào |
| Accessibility | **Trung bình** | Khoảng 162 nút chỉ có icon mà thiếu `aria-label`. 376 chỗ chữ 8–10px. Không hỗ trợ `prefers-reduced-motion` |

**Tóm lại:** phần khung đã đúng hướng. Nợ kỹ thuật nằm ở **lớp trang**: nhiều view to (400–1.400 dòng), trộn markup, JS và query. Nhiều view vẫn dùng màu thô thay cho token. Portal mobile đang là mockup, chưa phải sản phẩm.

---

## 2. Phát hiện chi tiết

### 2.1 Bố cục & App Shell

**Làm tốt**
- `layouts/app.blade.php` + `layouts/navigation.blade.php` bám đúng DESIGN.md. Sidebar có accordion, flyout khi thu gọn, drawer trên mobile, nhớ vị trí cuộn. Menu lấy từ `SidebarMenu` theo quyền.
- Có viewport meta, font tự host qua fontsource, có `x-cloak`.

**Vấn đề**

| # | Vấn đề | Vị trí | Mức |
|---|---|---|:-:|
| L1 | Layout gọi service lấy thông báo trong `@php` (`getUnreadCount` + `getUserNotifications`) ở **mọi trang** | `layouts/app.blade.php` | P2 |
| L2 | `navigation.blade.php` định nghĩa hàm `sidebarNavigation()` bằng `<script>` global ngay trong Blade. Map `$roleLabels` viết cứng trong view | `layouts/navigation.blade.php` | P2 |
| L3 | Drawer mobile thiếu backdrop bấm để đóng, thiếu focus-trap và không khóa cuộn body (cần kiểm tra lại khi chạy app) | `layouts/navigation.blade.php` | P2 |
| L4 | Không có link "Bỏ qua tới nội dung" (skip link) và `<main>` thiếu `id` | `layouts/app.blade.php` | P2 |
| L5 | Tiêu đề trang bị lặp: topbar hiện `title`, trong trang lại có `x-ui.page-header` (50 view) | nhiều view | P3 |
| L6 | Chỉ có 2 layout (`app`, `guest`). Portal, trang in/PDF, trang làm test công khai đều dùng chung `x-app-layout` | 143 view | **P1** |

### 2.2 Responsive

| # | Vấn đề | Số liệu / ví dụ | Mức |
|---|---|---|:-:|
| R1 | **Portal vẽ khung điện thoại 430px** (`max-w-[430px] min-h-[844px] rounded-3xl shadow-2xl`) bên trong layout admin, có sidebar và topbar desktop. Tiêu đề còn chữ "Flow 4 — Bước 2…". Trên điện thoại thật thì có 2 lớp header, 2 lớp khung | `portal/*.blade.php` (8 file), `tasks/ta-portal` | **P1** |
| R2 | 40 file có `<table>` nhưng không dùng `x-ui.data-table`. CSS toàn cục phải dùng `div:has(> table) { overflow-x:auto !important }` để chữa cháy | `resources/css/app.css` §1 | P1 |
| R3 | CSS toàn cục ép `white-space: nowrap !important` và `flex-shrink:0` lên **mọi** `.whitespace-nowrap`, `[class*="rounded-full"]`, nút trong bảng. Thêm `table td > div.flex { flex-wrap: nowrap }` đè lên class `flex-wrap` của dev. Hậu quả khó đoán: cột bị kéo dài, badge không co | `app.css` §2–4 | P1 |
| R4 | 16 grid cố định 3–12 cột không có breakpoint (vd. `grid-cols-12` ở `tuition/refunds`, `grid-cols-3` ở `branches`, `bank-accounts`, `create-receipt`) nên bị bóp nát ở 360px | xem grep `grid-cols-N` | P2 |
| R5 | Form/modal rộng cố định: `crm/create w-[560px]`, `crm/edit w-[720px]`, `welcome w-[877px]`. Ngoài ra có 102 class `w-[…px]` ≥100px | | P2 |
| R6 | 1.890 giá trị tùy biến `-[…px]`. Spacing và cỡ chữ đi ngoài thang token (`md/lg/xl`), nên không đổi được đồng loạt khi chỉnh density | | P2 |
| R7 | Bảng dữ liệu dày (CRM, học phí) chỉ có cuộn ngang. Chưa có kiểu "card list" cho mobile ở các màn sale và giáo viên hay dùng trên điện thoại (pipeline, điểm danh, danh sách học viên) | | P2 |

### 2.3 Hệ thống thiết kế & nhất quán

| # | Vấn đề | Số liệu | Mức |
|---|---|---|:-:|
| D1 | Màu thô ngoài token: 6.827 lần trong 132 file. Nặng nhất: `tuition/invoices-cancellations` (265), `tuition/approve-receipt` (256), `crm/closing-wizard` (254), `tuition/create-receipt` (223), `media/index` (208) | | P1 |
| D2 | Cỡ chữ thô `text-xs/sm/lg…` (2.544) nhiều hơn token `text-body-*/h*/caption` (1.490) | | P2 |
| D3 | 376 chỗ chữ 8–10px (`text-[10px]`), dưới ngưỡng dễ đọc 12px | | P2 |
| D4 | 251 mã hex viết thẳng trong view, 191 thuộc tính `style=""` | | P2 |
| D5 | Còn 2 bộ component song song: Breeze cũ (`primary-button`, `text-input`, `input-label`, `pagination`: 4–7 view) và `x-ui.*` mới. `modal`, `secondary-button`, `danger-button`, `nav-link`, `responsive-nav-link` không còn view nào dùng | `resources/views/components/*` | P2 |
| D6 | Emoji dùng làm icon trong 21 file, trộn với Material Symbols | | P3 |
| D7 | `rounded-2xl/3xl` (373 lần) nằm ngoài thang `borderRadius` đã khai báo (`DEFAULT/lg/xl`) | | P3 |

### 2.4 Phân chia module frontend

| # | Vấn đề | Chi tiết | Mức |
|---|---|---|:-:|
| M1 | **View quá to, làm nhiều việc**: 29 view > 350 dòng, `crm/closing-wizard` 1.411 dòng, `media/index` 1.009 dòng, `tuition/create-receipt` 855 dòng | | **P1** |
| M2 | **JS nhúng trong view**: 34 khối `<script>` trong 28 file (closing-wizard 403 dòng, create-receipt 193, media 185, pipeline 183, placement-tests create/edit ~180 mỗi file và gần như trùng nhau). Không lint, không test, không cache được, và dễ lỗi XSS khi chèn biến PHP (đã gặp ở pipeline) | | **P1** |
| M3 | `resources/js/app.js` chỉ khởi động Alpine. **Chưa có `Alpine.data()` nào**, 89 `x-data="{…}"` inline. Logic trùng lặp (search, filter, modal state) được copy qua lại | | P1 |
| M4 | Query DB trong view: `dashboard.blade.php` (5 `::count()`), `classes/index`, `crm/edit` (`Course::where…` trong `@foreach`), `syllabus/big-tests-distribution` (query trong vòng lặp nên dễ N+1) | | P1 |
| M5 | 34 `confirm()` và 1 `alert()` gốc của trình duyệt, trong khi đã có `x-ui.modal` và `partials/data-confirm` | | P2 |
| M6 | View theo thư mục nghiệp vụ là đúng, nhưng partial dùng chung nằm rải rác (`partials/`, `*/partials/`, `components/ui/partials/`). Chưa có namespace component theo module (vd. `x-crm::stage-badge`) | | P3 |
| M7 | View chết và mockup: `mockups/hub`, `welcome.blade.php`, cùng các view đã liệt kê ở audit B2.9 | | P3 |

### 2.5 Accessibility & tương tác

| # | Vấn đề | Số liệu | Mức |
|---|---|---|:-:|
| A1 | Khoảng 162 `<button>` chỉ có icon mà thiếu `aria-label` (grep ước lượng) | | P1 |
| A2 | 125 `focus:outline-none`, trong khi chỉ có 51 `focus-visible:`. Nhiều chỗ tắt viền focus mà không thay bằng gì | | P1 |
| A3 | 27 nút icon `p-1/p-0.5`, vùng bấm khoảng 28px, nhỏ hơn mức tối thiểu 44px trên màn cảm ứng (portal, giáo viên) | | P2 |
| A4 | Không có `motion-reduce:` hay `prefers-reduced-motion` ở đâu. Có `active:scale-95`, zoom modal | | P3 |
| A5 | Icon Material Symbols trang trí không gắn `aria-hidden="true"` (1.369 span, trình đọc màn hình đọc thành chữ "arrow_forward") | | P2 |
| A6 | 8 thẻ `<img>` thiếu `alt` | | P3 |

---

## 3. Hướng thay đổi (target architecture)

```
resources/
├── css/
│   ├── app.css                 # chỉ @tailwind + font + base, BỎ các rule !important chữa cháy
│   └── components.css          # @layer components: .btn, .card, .table-base (nếu cần)
├── js/
│   ├── app.js                  # đăng ký Alpine + plugin + import modules
│   ├── components/             # Alpine.data dùng chung
│   │   ├── sidebar.js          #   (chuyển từ navigation.blade.php)
│   │   ├── confirm-dialog.js   #   thay confirm() gốc
│   │   ├── data-filter.js      #   filter/search dùng chung
│   │   └── file-upload.js
│   └── modules/                # logic theo nghiệp vụ, 1 file / màn lớn
│       ├── crm/closing-wizard.js
│       ├── crm/pipeline.js
│       ├── tuition/receipt-form.js
│       ├── placement-tests/test-builder.js   # gộp create + edit
│       └── media/library.js
└── views/
    ├── layouts/
    │   ├── app.blade.php       # admin (desktop-first, có responsive)
    │   ├── portal.blade.php    # MỚI: mobile-first, bottom-nav, không sidebar
    │   ├── public.blade.php    # MỚI: trang test online / scorecard công khai
    │   └── print.blade.php     # MỚI: phiếu thu, phiếu lương, PDF
    ├── components/ui/          # design system, chỉ dùng token
    │   └── (+) icon-button, confirm, card, responsive-table, form-grid, drawer
    └── <module>/               # crm, tuition, payroll… view mỏng, chia partials theo section
```

**Nguyên tắc:**
1. **View chỉ render.** Không query và không service call trong Blade. Dữ liệu dùng chung ở layout (thông báo, quick-create) đi qua **View Composer** (`AppServiceProvider` / `ViewServiceProvider`), có cache ngắn hạn.
2. **JS ra file.** Dùng `Alpine.data('closingWizard', …)` và truyền dữ liệu bằng `@js()` hoặc `data-*`, không nối chuỗi PHP vào JS. Vite bundle theo entry (`@vite('resources/js/modules/crm/pipeline.js')`) để trang nào nạp JS trang đó.
3. **Chỉ dùng token.** Thêm rule lint (script CI grep, hoặc `eslint-plugin-tailwindcss` với `no-custom-classname` / whitelist) để chặn `gray-*`, `blue-*`, hex và `text-[Npx]` trong view mới.
4. **Mobile-first cho portal**, desktop-first có responsive cho admin. Bảng dùng `x-ui.data-table` và có biến thể `stack-on-mobile` (mỗi hàng thành một card dưới `md`).
5. **Component thay vì copy.** Mọi nút chỉ có icon dùng `x-ui.icon-button` với prop `label` bắt buộc (sinh `aria-label` và `title`). Mọi thao tác xóa hoặc hủy dùng `x-ui.confirm`.

---

## 4. Kế hoạch sprint

> Mỗi sprint khoảng 1 tuần, 1 FE. Chạy song song với các phase nghiệp vụ trong `audit-report-and-roadmap.md` và **không đổi hành vi nghiệp vụ**. Mỗi PR có ảnh chụp 3 breakpoint (375 / 768 / 1280).

### Sprint FE-1 — Nền móng (không đổi giao diện)
- [ ] Chuyển `sidebarNavigation` sang `resources/js/components/sidebar.js` (`Alpine.data`)
- [ ] View Composer cho thông báo topbar và `quickCreate`, cache theo user 60s. Bỏ `@php` service call trong layout
- [ ] Bỏ query khỏi `dashboard`, `classes/index`, `crm/edit`, `syllabus/big-tests-distribution` (L1, M4)
- [ ] Thêm `x-ui.icon-button` (bắt buộc `label`) và `x-ui.confirm` (Alpine store). Thay 34 `confirm()` và 1 `alert()`
- [ ] Skip link, `id="main"`, backdrop, focus-trap và khóa cuộn cho drawer mobile (L3, L4)
- [ ] Script CI `scripts/lint-views.sh`: đếm màu thô, hex và `text-[<12px]`, **chỉ fail khi số tăng** (ratchet)

### Sprint FE-2 — Portal mobile thật (R1, L6)
- [ ] `layouts/portal.blade.php`: mobile-first, header gọn, `partials/bottom-nav` (≤5 mục), safe-area
- [ ] Chuyển 8 view `portal/*` và `tasks/ta-portal` sang layout mới. Bỏ khung 430px, bỏ tiêu đề "Flow 4 — Bước…"
- [ ] Vùng bấm ≥44px, sửa grid `grid-cols-3/4` cố định ở portal
- [ ] `layouts/public.blade.php` cho các trang test online và scorecard công khai

### Sprint FE-3 — Bảng & CSS toàn cục (R2, R3, R4, R5)
- [ ] Đưa 40 file bảng thô vào `x-ui.data-table`. Thêm prop `stack-on-mobile` và áp dụng cho pipeline, danh sách học viên, điểm danh
- [ ] Xóa các rule `!important` ở `app.css` §1–5 **sau khi** mục trên xong (kiểm tra 3 breakpoint từng màn)
- [ ] Sửa 16 grid cố định và các form/modal `w-[560/720/877px]`, chuyển sang `w-full max-w-*`

### Sprint FE-4 — Tách module màn lớn (M1, M2, M3)
- [ ] `crm/closing-wizard`: chia partial theo bước, JS sang `modules/crm/closing-wizard.js`
- [ ] `placement-tests/create` + `edit`: gộp thành 1 form partial và 1 module JS `test-builder.js`
- [ ] `tuition/create-receipt`, `media/index`, `crm/pipeline` (sửa luôn chèn biến PHP vào JS)
- [ ] Mục tiêu: không view nào > 400 dòng, không `<script>` inline > 20 dòng

### Sprint FE-5 — Chuẩn hóa token (D1–D4, D7)
- [ ] Bảng mapping `gray-500 → on-surface-variant`, `blue-600 → secondary`, `red-* → error`… rồi codemod bằng script, theo thứ tự 12 file nặng nhất trước
- [ ] `text-[10px]` → `text-caption` (11px) hoặc `text-label`. Chữ nội dung tối thiểu 12px
- [ ] Hạ ngưỡng ratchet của lint-views

### Sprint FE-6 — Dọn dẹp & a11y (D5, D6, M5–M7, A1–A6)
- [ ] Xóa component Breeze không dùng. Chuyển 4–7 view còn lại sang `x-ui.*`
- [ ] Thay emoji bằng Material Symbols. Icon trang trí thêm `aria-hidden`
- [ ] `focus:outline-none` → `focus-visible:ring-2 ring-primary-container`. Thêm `motion-reduce:`
- [ ] Xóa view chết và mockup (sau khi xác nhận route)

**Chỉ số nghiệm thu cuối cùng**

| Chỉ số | Hiện tại | Mục tiêu |
|---|--:|--:|
| Class màu thô trong view | 6.827 | < 300 |
| `text-[8–10px]` | 376 | 0 |
| `<script>` inline | 34 | ≤ 5 (chỉ JSON config) |
| View > 400 dòng | 23 | 0 |
| Bảng ngoài `x-ui.data-table` | 40 | 0 |
| Nút icon thiếu `aria-label` | ~162 | 0 |
| Rule `!important` responsive trong `app.css` | 5 nhóm | 0 |

---

## 5. Nhật ký sprint

| Sprint | Trạng thái | Đã làm | Chưa làm / chuyển sprint |
|---|---|---|---|
| FE-1 | Chưa bắt đầu | | |
| FE-2 | Chưa bắt đầu | | |
| FE-3 | Chưa bắt đầu | | |
| FE-4 | Chưa bắt đầu | | |
| FE-5 | Chưa bắt đầu | | |
| FE-6 | Chưa bắt đầu | | |
