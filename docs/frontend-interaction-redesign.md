# MEnglish — Tái cấu trúc tương tác: Trang ↔ Modal & bố cục theo chức năng

> **Ngày:** 26/09/2026 · **Người lập:** CTO
> **Liên quan:** `frontend-ux-audit.md` (audit kỹ thuật FE). Tài liệu này giải quyết vấn đề **"bấm nút là chuyển trang mới"** và **menu chia theo màn thay vì theo chức năng**.
> **Stack giữ nguyên:** Laravel 11 + Blade + Alpine 3 + Tailwind. Chỉ thêm **htmx 2** (khoảng 14KB) để tải form hoặc chi tiết từ server vào modal.
> **Quyết định 26/09/2026:** chỉ dùng **modal** (hộp thoại giữa màn hình), **không dùng drawer/slide-over**. Form lớn dùng modal cỡ `2xl`–`4xl`; trên điện thoại (< `sm`) modal chiếm toàn màn.

---

## 1. Hiện trạng

| Chỉ số | Giá trị |
|---|--:|
| Route GET mở **trang riêng** chỉ để hiện form tạo/sửa/nhập (`*/create`, `*/{id}/edit`, `import`, `assign`…) | 33 |
| View đang dùng `x-ui.modal` | 12 |
| Mục trong sidebar (gồm mục con) | ~70 |
| Mục sidebar thực chất là **hành động** chứ không phải màn hình (vd. "Lập phiếu thu học phí", "Nhập danh sách từ Excel", "Giao việc cho Trợ giảng", "Báo cáo trực lớp TA", "Chốt học phí & Xếp lớp") | 6 |
| Màn "Duyệt …" nằm rải ở 5 nhóm menu khác nhau | 8 |

**Hệ quả với người dùng:**
- Sửa 1 ngày nghỉ (4 trường) phải qua 3 lần tải trang: danh sách → form → redirect về danh sách. Mất vị trí cuộn, mất bộ lọc đang chọn.
- Kế toán duyệt phiếu thu, hủy hóa đơn, hoàn tiền ở 3 menu khác nhau. Trưởng học vụ duyệt ở 4 chỗ.
- Menu dài khoảng 70 mục nên khó tìm, và trên tablet phải cuộn flyout.

---

## 2. Quy tắc chọn kiểu tương tác (áp dụng toàn hệ thống)

| Kiểu | Khi nào dùng | Kích thước | Ví dụ |
|---|---|---|---|
| **Inline** (sửa tại chỗ, toggle) | 1 trường, đổi trạng thái | trong dòng bảng | Bật/tắt khóa học, đổi giai đoạn lead, đánh dấu đã đọc |
| **Modal nhỏ** | ≤ 8 trường, 1 bước, **hoặc xác nhận** (xóa, duyệt, từ chối kèm lý do) | `sm`–`xl` | Ngày nghỉ, danh mục, quyền, vai trò, ghi nhận phạt, nhập Excel, duyệt/từ chối |
| **Modal lớn** | 8–25 trường, **hoặc xem nhanh chi tiết** mà vẫn giữ danh sách phía sau. Chia tab trong modal nếu dài | `2xl`–`4xl`, toàn màn trên điện thoại, header + footer nút cố định, thân cuộn | Tạo/sửa lead, tạo task, tạo ticket, sửa nhân sự, lập phiếu thu, xem nhanh lead / học viên / phiếu |
| **Trang riêng** | Nhiều bước (wizard), trình soạn (builder), > 25 trường, cần in, hoặc cần URL để chia sẻ lâu dài | full | Chốt học phí & xếp lớp, tạo lớp + TKB, soạn đề test, soạn syllabus, hồ sơ học viên đầy đủ, bảng lương kỳ |

**Nguyên tắc đi kèm**
1. **URL vẫn sống.** Mọi route `create/edit/show` giữ nguyên. Nếu mở trực tiếp (F5, dán link, JS lỗi) thì hiện **trang đầy đủ**. Nếu bấm từ danh sách thì hiện **modal** (progressive enhancement).
2. Modal xem chi tiết thì **đẩy URL** (push URL gốc của trang chi tiết). Nút Back sẽ đóng modal, và copy link gửi đồng nghiệp vẫn mở đúng.
3. Lưu xong: đóng modal, **chỉ làm mới vùng danh sách** (giữ bộ lọc và vị trí cuộn), hiện toast.
4. Lỗi validate: hiện **ngay trong modal, cạnh từng trường**, không đóng modal.
5. Form có dữ liệu mà bấm ra ngoài hoặc Esc thì hỏi "Bỏ thay đổi?".
6. Không lồng modal trong modal (trừ hộp xác nhận xóa/bỏ thay đổi). Nếu cần bước tiếp thì đổi nội dung modal sang bước 2, hoặc lên trang riêng.

---

## 3. Phân loại 33 route form hiện tại

> Chưa tính 3 trang chi tiết (`crm.customers.show`, `tasks.show`, `tickets.show`). Các trang này được thêm **modal xem nhanh** nhưng vẫn giữ trang đầy đủ.

### → Modal nhỏ (14)
| Route | Hiện trạng | Ghi chú |
|---|---|---|
| `holidays.create/edit` | trang riêng, 4 trường | modal `md` |
| `system-categories.create/edit` | 6 trường | modal `md` |
| `permissions.create/edit` | form nhỏ | modal `sm` |
| `roles.create/edit` | 3 trường | modal `md`. Ma trận quyền vẫn ở trang `roles.show` |
| `users.roles.edit` | 1 nhóm checkbox | modal `md` |
| `crm.import`, `tuition.import` | 3–4 trường + file | modal `lg`: bước 1 tải file, bước 2 xem trước, cùng trong modal |
| `tuition.refunds.proof` | xem ảnh minh chứng | modal `xl` dạng lightbox |
| `merchandise.create/edit` | 9 trường | modal `xl` |

### → Modal lớn (11 form + 3 xem nhanh)
| Route | Hiện trạng | Ghi chú |
|---|---|---|
| `crm.customers.create/edit` | 14 trường | modal `2xl`. Mở từ Kanban **và** danh sách |
| `crm.customers.show` | trang 752 dòng | **modal xem nhanh** (thông tin, lịch sử chăm sóc, nút hành động). Có link "Mở trang đầy đủ" |
| `tasks.create`, `tasks.show` | | modal `2xl`. Giao việc TA (`tasks.ta-assign`) gộp vào form này bằng lựa chọn "Giao cho: TA" |
| `tasks.class-reports.create` | 13 trường | modal `2xl`, mở từ Cổng TA / Dashboard lớp theo ngày |
| `tickets.create`, `tickets.show` | | modal `3xl`: luồng hội thoại + ô trả lời |
| `users.create/edit` | 22 trường | modal `3xl`, chia 3 tab (Tài khoản / Hồ sơ / Lương) |
| `users.permissions.edit` | | modal `4xl` (ma trận quyền) |
| `tuition.receipts.create/edit` | 855 dòng | modal `4xl` **khi mở từ dòng học viên** (đã có sẵn học viên và khoản nợ). Vẫn giữ trang riêng cho luồng "lập phiếu tự do" |

### → Giữ trang riêng (8)
`crm.closing-wizard` (wizard nhiều bước) · `classes.create/edit` (lớp + TKB, 600 dòng) · `placement-tests.create/edit` (trình soạn đề, **gộp chung 1 view form**) · `classes.academic-detail` · `profile.edit` · `syllabus.assignments` (màn làm việc).

> **Kết quả:** 25/33 luồng form không còn chuyển trang (14 modal nhỏ + 11 modal lớn). 8 luồng giữ trang riêng vì là wizard hoặc builder.

---

## 4. Chia lại bố cục theo chức năng

### 4.1 Nguyên tắc
- **1 mục menu = 1 "không gian làm việc"** (workspace) theo đối tượng nghiệp vụ, không phải theo từng màn.
- Bên trong workspace có **tab** cho các góc nhìn (danh sách / chờ duyệt / lịch sử / báo cáo), và **nút hành động** (Tạo, Nhập, Xuất) ở page-header, mở ra modal.
- **Hành động không nằm trên menu.** "Tạo nhanh" có sẵn ở topbar (đã có `quickCreate`) và phím tắt `N`.
- **Duyệt tập trung:** một hộp "Việc cần duyệt" chung, lọc theo vai trò.
- Cấu hình và danh mục gom vào **1 trang Cài đặt** có menu con bên trái.

### 4.2 Sidebar đề xuất (~70 → ~24 mục)

```
Tổng quan
Việc cần duyệt  (badge số)         ← MỚI: gộp 8 màn duyệt
───────── Tuyển sinh
Khách hàng (CRM)                   tabs: Kanban | Danh sách | Chưa liên hệ SLA | Chờ xếp lớp | Đã nhập học | Thất bại | Đã xóa
Học thử & Test đầu vào             tabs: Lịch học thử | Đề test | Kết quả | Thang điểm
Báo cáo tuyển sinh
───────── Đào tạo
Học viên                           tabs: Danh sách | Xác nhận nhập học
Lớp học                            tabs: Hồ sơ lớp | Sơ đồ khối | Theo ngày | Báo cáo đào tạo | Nhật ký sự vụ
Giáo trình                         tabs: Tài liệu | Soạn syllabus | Giao chặng | Chặng đang dạy
Big Test                           tabs: Phân phối | Nhắc lịch | Bảng điểm
Khảo sát
───────── Tài chính
Học phí                            tabs: Công nợ học viên | Lịch sử thu | Hóa đơn | Hoàn tiền & Khất nợ | Quá hạn
                                   actions: [Lập phiếu thu] [Nhập Excel]
Thu - Chi                          tabs: Doanh thu tạm tính | Khoản chi
Kho vật phẩm
───────── Nhân sự
Nhân sự                            tabs: Danh sách | KPI tháng | Rà soát điểm danh | Xếp hạng & Thưởng
Công việc                          tabs: Của tôi | Tôi giao | Báo cáo trực lớp        action: [Giao việc]
Ticket hỗ trợ
Chấm công                          tabs: Giờ dạy GV | Chấm đơn lẻ | AppSheet | Lịch sử đồng bộ
Lương & Phạt                       tabs: Kỳ lương | Vi phạm & Phạt
───────── Cá nhân  (chỉ hiện theo vai trò)
Cổng giáo viên / Cổng TA / Của tôi
───────── (cuối sidebar)
Cài đặt ⚙                           ← trang Settings có menu con: Cơ sở · Khóa học & giá · Trình độ · Ngày nghỉ ·
                                       Danh mục · Lịch & TKB · KPI · Lương (tham số / đơn giá / hoa hồng) ·
                                       Học phí (nhắc nợ / dải số HĐ / ngân hàng) · Email ticket · Vai trò & Quyền ·
                                       Nhật ký · Media · Hosting
```

Cổng Phụ huynh/Học sinh **ra khỏi sidebar admin**, chuyển sang `layouts/portal` với thanh điều hướng dưới đáy (xem `frontend-ux-audit.md` FE-2).

### 4.3 "Việc cần duyệt" (Approval Inbox)
Gộp các màn: duyệt phiếu thu, duyệt/hủy hóa đơn, hoàn tiền & khất nợ (phần chờ duyệt), xác nhận nhập học, duyệt đề xuất sửa giáo trình, duyệt điều chỉnh tiến độ, duyệt phân phối Big Test, xác nhận hoàn thành việc.

```
┌ Việc cần duyệt ─────────────────────────────────────────────┐
│ [Tất cả 23] [Học phí 9] [Đào tạo 8] [Công việc 6]  🔍 Lọc   │
├─────────────────────────────────────────────────────────────┤
│ ☐ Phiếu thu PT-0923 · Nguyễn … · 4.500.000đ · 2 giờ trước  ▶│──► Modal: chi tiết + ảnh CK
│ ☐ Hủy HĐ HD-1102 · lý do …                                 ▶│    [Từ chối (lý do)] [Duyệt]
│ ☐ Điều chỉnh tiến độ lớp IELTS-07 · GV …                   ▶│
├─────────────────────────────────────────────────────────────┤
│ Đã chọn 3   [Duyệt hàng loạt]  [Từ chối]                    │
└─────────────────────────────────────────────────────────────┘
```
- **Backend:** interface `ApprovableSource` (mỗi module tự đăng ký: `pendingFor(User)`, `count(User)`, `approve()`, `reject()`) cùng `ApprovalInboxService` gom kết quả. Module vẫn độc lập, inbox chỉ đọc qua interface. Badge đếm cache 60s theo user, xóa cache khi có sự kiện duyệt.
- Các route duyệt cũ giữ nguyên và redirect về inbox kèm filter tương ứng.

---

## 5. Giải pháp kỹ thuật

### 5.1 Chọn công nghệ

| Phương án | Ưu | Nhược | Chọn |
|---|---|---|:-:|
| A. Nhúng sẵn form vào trang danh sách + `x-ui.modal` | Không thêm thư viện | Trang nặng (mỗi dòng 1 form edit), phải truyền dữ liệu edit bằng JS, trùng lặp form | Chỉ dùng cho modal **tạo** nhỏ |
| B. `fetch()` tự viết + `innerHTML` | Không thêm thư viện | Tự xử lý lỗi 422, redirect, CSRF, lịch sử… Mỗi màn tự làm lại | ✗ |
| **C. htmx 2 + Alpine** | Server vẫn render Blade (tái dùng view hiện có), xử lý swap, 422, push URL, trigger event. Chạy cạnh Alpine không xung đột | Thêm 1 thư viện, team cần học ~1 ngày | **✓** |
| D. Livewire 3 | Mạnh, hệ sinh thái Laravel | Phải viết lại thành component, đổi mô hình state, rủi ro lớn với 222 view | ✗ (quá tốn) |

### 5.2 Cách làm (phương án C)

**1. Component mới**
- Nâng cấp `x-ui.modal` (tương thích ngược): thêm cỡ `3xl`/`4xl`, toàn màn trên điện thoại, focus-trap (`@alpinejs/focus`), trả focus về nút mở, hỏi xác nhận khi form có thay đổi.
- `x-ui.remote-modal`: **một** host modal đặt ở `layouts/app`. Nội dung được htmx đổ vào `#remote-modal-body`, cỡ lấy từ nút mở (`data-modal-size`).

**2. Nút mở modal** (vẫn là `<a href>` thật để giữ fallback):
```blade
<x-ui.button :href="route('holidays.edit', $h)" dialog="modal" size="sm">Sửa</x-ui.button>
{{-- render: <a href=".." hx-get=".." hx-target="#modal-body" hx-push-url="false" @click="$dispatch('open-modal','remote')"> --}}
```

**3. Controller không đổi logic, chỉ đổi cách render**, bằng trait dùng chung:
```php
// app/Http/Concerns/RendersDialogs.php
protected function dialogView(string $view, array $data = []): View
{
    // htmx gọi → chỉ trả fragment (layout rỗng); mở trực tiếp → trang đầy đủ
    return view($view, $data)->with('asDialog', request()->header('HX-Request') === 'true');
}

protected function dialogSaved(string $message, string $refresh, ?string $fallback = null): Response|RedirectResponse
{
    if (request()->header('HX-Request') !== 'true') {
        return redirect($fallback ?? url()->previous())->with('status', $message);
    }
    return response()->noContent()->withHeaders([
        'HX-Trigger' => json_encode(['close-dialog' => true, 'toast' => $message, $refresh => true]),
    ]);
}
```
- Form partial `holidays/_form.blade.php` được dùng chung cho **trang đầy đủ** (bọc `x-app-layout`) và **dialog** (bọc `x-ui.dialog-frame`). Không có form nào bị viết 2 lần.
- **Validate lỗi:** Middleware `HtmxValidation` bắt `ValidationException` khi có `HX-Request`, render lại form fragment với status **422** và `HX-Retarget: #dialog-body`. Lỗi hiện cạnh trường qua `x-ui.field` sẵn có.
- **Làm mới danh sách:** vùng bảng bọc `<div id="holiday-list" hx-get="{{ url()->full() }}" hx-trigger="holidays-changed from:body" hx-select="#holiday-list" hx-swap="outerHTML">`. Bộ lọc và trang hiện tại được giữ vì dùng `url()->full()`.
- **Modal chi tiết có URL:** `hx-push-url="true"`. Nếu tải thẳng URL đó thì hiện trang chi tiết đầy đủ.

**4. Bảo mật & hiệu năng**
- CSRF: `hx-headers` lấy từ `<meta name="csrf-token">` đặt 1 lần ở `<body>`.
- Quyền: policy / `can:` middleware giữ nguyên, vì fragment đi qua đúng route cũ.
- Fragment không render layout nên không chạy query thông báo và menu, **nhẹ hơn cả trang đầy đủ**.
- Không chèn biến PHP vào JS, dữ liệu đi bằng `@js()` hoặc `data-*`.

**5. Kiểm thử**
- Feature test cho mỗi route chuyển đổi: (a) GET thường → 200 có layout; (b) GET có `HX-Request` → 200, không có `<aside data-sidebar>`; (c) POST lỗi + HX → 422 có thông báo lỗi; (d) POST đúng + HX → 204 có `HX-Trigger`.
- Test trình duyệt: mở/đóng, Esc, Back, mobile 375px (modal toàn màn).

### 5.3 Cách dùng (API chốt sau IX-1)

> Tên cuối cùng khác bản nháp ở 5.2: `RendersDialogs` → **`RendersModals`**, `x-ui.dialog-frame` → **`x-ui.modal-frame`**, `close-dialog` → **`close-modal`**, `#dialog-body` → **`#remote-modal-body`**. Không có middleware `HtmxValidation`: lỗi validate được bắt ở `bootstrap/app.php` (`withExceptions` → `App\Support\Htmx::renderValidationForm`), vì exception trong pipeline không nổi lên được middleware.

**1. Nút mở modal** — vẫn là `<a href>` thật (mở tab mới, F5, JS lỗi → trang đầy đủ):
```blade
<x-ui.button icon="add" :href="route('holidays.create')" modal="md">Thêm ngày nghỉ</x-ui.button>
<x-ui.button variant="ghost" icon="edit" :href="route('holidays.edit', $row)" modal="md" aria-label="Sửa" />
{{-- modal = true (cỡ lg) | sm | md | lg | xl | 2xl | 3xl | 4xl | full --}}
```

**2. Controller** — logic nghiệp vụ giữ nguyên, chỉ đổi dòng `return`:
```php
use App\Http\Concerns\RendersModals;

class HolidayController extends Controller
{
    use RendersModals;

    public function edit(Holiday $holiday): Response
    {
        return $this->modalView('holidays.form', [...]);   // view nhận $asModal (bool); header Vary: HX-Request
    }

    public function update(HolidayRequest $request, Holiday $holiday): Response|RedirectResponse
    {
        // ... lưu như cũ ...
        return $this->modalSaved('Đã cập nhật ngày nghỉ.', 'holidays-changed', route('holidays.index'));
        // htmx → 204 + HX-Trigger {"close-modal":true,"toast":{...},"holidays-changed":true}
        // thường → redirect(fallback)->with('status', ...) như trước
    }
}
```
`$this->isModalRequest()` dùng khi muốn bỏ bớt query chỉ trang đầy đủ mới cần (vd. danh sách bên trái).

**3. View form** — tách `_form.blade.php` dùng chung, view trang rẽ nhánh theo `$asModal`:
```blade
@if ($asModal)
    <x-ui.modal-frame title="Sửa ngày nghỉ">
        @include('holidays._form')                      {{-- <form id="modal-holiday-form" method="POST" action="..."> --}}
        <x-slot:footer><x-ui.button type="submit" form="modal-holiday-form">Lưu thông tin</x-ui.button></x-slot:footer>
    </x-ui.modal-frame>
@else
    <x-app-layout>...trang đầy đủ, @include('holidays._form')...</x-app-layout>
@endif
```
Form trong `x-ui.modal-frame` tự gửi bằng htmx (`hx-boost`), không cần thêm thuộc tính nào. Id trong form modal nên có tiền tố `modal-` để không trùng form ở trang.

**4. Validate lỗi** — không cần code: route `x.store` / `x.update` lỗi validate khi gọi từ modal → server chạy lại action `x.create` / `x.edit` (cùng controller, cùng tham số) với lỗi + old input → **422**, htmx thay lại nội dung modal.
Không có `x.create`/`x.edit` thì dùng **màn cha** `x` (luồng nhiều bước trên 1 màn, vd. `crm.import.preview` → `crm.import`, `tuition.import.store` → `tuition.import`). Điều kiện: route form là GET, cùng controller, và middleware của route form ⊆ middleware route submit (không vượt quyền vì middleware route form không chạy lại). Không tìm được route form hoặc route form trả redirect → giữ hành vi cũ (redirect back). File upload bị bỏ khỏi old input.

**5. Làm mới danh sách** — bọc vùng bảng, nghe sự kiện `<refreshEvent>` truyền cho `modalSaved`:
```blade
<div id="holiday-list" hx-get="{{ route('holidays.index', request()->query()) }}"
     hx-trigger="holidays-changed from:body" hx-select="#holiday-list" hx-swap="outerHTML"> ...bảng... </div>
```
Dùng route danh sách + query hiện tại (không dùng `url()->full()` nếu trang có thể là create/edit, vì URL đó trả fragment).

**6. Xác nhận xóa** — `x-ui.modal` thường + form `hx-boost="true" hx-swap="none"` với `:action` Alpine (JS đọc action lúc gửi); controller `destroy` trả `modalSaved(...)`. Xem `holidays/index.blade.php`.

**6b. Lỗi nghiệp vụ / chuyển trang** (IX-2):
- `modalFailed($message, $errorKey)` — thao tác bị từ chối không phải do validate (vd. xóa vai trò đang gán): htmx → 204 + `close-modal` + toast lỗi; thường → `back()->withErrors([...])` như cũ.
- `modalRedirect(redirect()->route(...)->with(...))` — luồng kết thúc ở trang khác (vd. nhập Excel xong → danh sách kèm kết quả): htmx → 204 + `HX-Redirect` (flash vẫn còn cho trang đích); thường → trả nguyên redirect.
- Luồng nhiều bước (nhập Excel): controller giữ nguyên `redirect()->route('x', ...)` sau mỗi bước — trình duyệt đi theo redirect **vẫn gửi `HX-Request`** nên bước kế tiếp hiện ngay trong modal. `<x-ui.modal-frame size="4xl">` nới rộng modal khi bước sau cần bảng rộng.
- Link trong modal dẫn ra ngoài (tải file mẫu, sang trang khác) phải có `hx-boost="false"`, nếu không htmx sẽ tải nội dung đó vào modal.
- Xem file (ảnh minh chứng): cùng URL, htmx → fragment `<img src="cùng URL">`, thường → file; thêm `Vary: HX-Request` cho cả 2.

**7. Test mẫu** — `tests/Feature/HolidayModalTest.php`, `tests/Feature/ModalFlowsTest.php` (data provider cho nhiều module) (GET thường có `data-sidebar`; GET `HX-Request` không có; POST lỗi → 422; POST đúng → 204 + `HX-Trigger`; request thường vẫn redirect).

---

## 6. Kế hoạch sprint

> Chèn sau FE-1 của `frontend-ux-audit.md` (cần `x-ui.icon-button`, `x-ui.confirm` và JS module hóa trước).

### Sprint IX-1 — Hạ tầng modal
- [x] Cài `htmx.org` 2 và `@alpinejs/focus`, cấu hình CSRF và event bridge htmx ↔ Alpine (`toast`, `close-modal`)
- [x] Nâng cấp `x-ui.modal`, thêm `x-ui.remote-modal` ở layout, prop `modal=` cho `x-ui.button`
- [x] Trait `RendersModals`, xử lý lỗi validate htmx (422) ở `bootstrap/app.php`, `x-ui.modal-frame`
- [x] Làm mẫu trọn vẹn với **Ngày nghỉ** (create/edit/delete), kèm feature test mẫu (`HolidayModalTest`, 9 test)

### Sprint IX-2 — Chuyển 14 luồng sang Modal nhỏ
- [x] Danh mục, quyền, vai trò, gán vai trò user, vật phẩm, ngày nghỉ (xong ở IX-1)
- [x] Nhập Excel CRM / học phí: modal 2 bước (tải file → xem trước → xác nhận)
- [x] Ảnh minh chứng hoàn tiền: lightbox
- [x] Thay các form "từ chối kèm lý do" tự viết bằng modal chuẩn

### Sprint IX-3 — Chuyển 11 luồng sang Modal lớn (+ 3 modal xem nhanh)
- [ ] Lead: tạo/sửa + **xem nhanh** từ Kanban và danh sách (push URL)
- [ ] Task (gộp giao việc TA), báo cáo trực lớp, ticket
- [ ] Nhân sự: tạo/sửa (3 tab), phân quyền riêng
- [ ] Phiếu thu mở từ dòng học viên (giữ trang riêng cho lập phiếu tự do)

### Sprint IX-4 — Bố cục theo chức năng
- [ ] `SidebarMenu` về khoảng 24 mục. Thêm `x-ui.workspace-tabs` (tab là link có `aria-current`, giữ query filter)
- [ ] Gộp các màn thành workspace có tab: CRM, Học phí, Lớp học, Giáo trình, Big Test, Chấm công, Lương & Phạt, Nhân sự
- [ ] Trang **Cài đặt** có menu con, gom 20 màn cấu hình và danh mục
- [ ] Redirect 301 các route menu cũ nếu có đổi URL (ưu tiên giữ URL cũ và chỉ đổi menu)

### Sprint IX-5 — Việc cần duyệt
- [ ] Interface `ApprovableSource` + `ApprovalInboxService`, đăng ký 8 nguồn duyệt
- [ ] Màn inbox: lọc theo nhóm, modal chi tiết, duyệt/từ chối hàng loạt (transaction theo từng item, báo kết quả từng dòng)
- [ ] Badge đếm trên sidebar (cache 60s, xóa cache theo sự kiện)
- [ ] Feature test phân quyền: mỗi vai trò chỉ thấy nguồn mình được duyệt

**Chỉ số nghiệm thu**

| Chỉ số | Hiện tại | Mục tiêu |
|---|--:|--:|
| Luồng tạo/sửa phải chuyển trang | 33 | 8 |
| Mục sidebar (admin) | ~70 | ≤ 25 |
| Số nơi kế toán phải vào để duyệt | 3 | 1 |
| Số nơi trưởng học vụ phải vào để duyệt | 4 | 1 |
| Số click để sửa 1 danh mục và quay lại danh sách | 3 lần tải trang | 0 lần tải trang |

---

## 7. Rủi ro & quyết định cần chốt

| Rủi ro / câu hỏi | Đề xuất |
|---|---|
| Người dùng quen menu cũ | Có bảng "menu cũ → vị trí mới" trong thông báo phát hành. Ô tìm kiếm topbar thêm tìm theo tên màn |
| Form lớn trong modal trên mobile | Modal chiếm toàn màn khi `< sm`, header và footer nút cố định, thân cuộn |
| Lead show 752 dòng khó thu gọn thành modal | Modal chỉ lấy 3 khối (thông tin, chăm sóc gần nhất, hành động). Trang đầy đủ vẫn giữ |
| ~~Cần chốt: có thêm htmx không~~ | **Đã chốt 26/09/2026: dùng htmx 2 + Alpine**, chỉ dùng modal |
| **Cần chốt:** Approval Inbox có gồm duyệt lương kỳ không | Đề xuất phase sau, vì duyệt lương có quy trình khóa kỳ riêng |

---

## 8. Nhật ký sprint

| Sprint | Trạng thái | Đã làm | Chưa làm / chuyển sprint |
|---|---|---|---|
| IX-1 | Xong (26/09/2026) | htmx 2 + `@alpinejs/focus` (`resources/js/components/remote-modal.js`: CSRF, 422 swap, bridge `toast`/`close-modal`, skeleton, trang đầy đủ lọt vào modal → chuyển trang, toast khi lỗi 403/404/419/5xx). `x-ui.modal` tương thích ngược + cỡ `3xl`/`4xl`/`full`, toàn màn < `sm` cho cỡ ≥ `2xl`, header/footer cố định, `x-trap` + trả focus, `aria-labelledby`, hỏi "Bỏ các thay đổi chưa lưu?". `x-ui.remote-modal` (layout), `x-ui.modal-frame`, `x-ui.button modal=`. Trait `RendersModals` + `App\Support\Htmx::renderValidationForm`. Ngày nghỉ: `_form` dùng chung, Thêm/Sửa bằng modal, Xóa bằng modal xác nhận, danh sách tự làm mới giữ bộ lọc. Test: `HolidayModalTest` (9), sửa `Phase2MockupClassesTest` (form Thêm chuyển sang modal / trang create) | Chưa có test trình duyệt (Esc, Back, 375px) — làm cùng IX-2. Hỏi "Bỏ thay đổi?" dùng `confirm()` gốc, chờ `x-ui.confirm` (FE-1). Nút Back chưa đóng modal (chỉ cần khi có modal xem chi tiết push URL — IX-3). Chưa có phím tắt `N` |
| IX-2 | Xong (26/09/2026) | **Modal:** `system-categories.create/edit` (md; mở thẳng `edit` vẫn về panel `?edit=`), `permissions.edit` (sm), `roles.create/edit` (md, chỉ tên/mã/mô tả — ma trận quyền vẫn ở trang đầy đủ, nút "Cấu hình quyền" giữ link trang; thêm nút "Đổi tên" mở modal), `users.roles.edit` (md, từ menu dòng ở danh sách nhân sự), `merchandise.create/edit` (xl), `crm.import` (lg → bước 2 xem trước nới 4xl; nhập xong `HX-Redirect` sang danh sách khách kèm kết quả như cũ), `tuition.import` (lg → 4xl, đủ 3 bước Tải file / Xem trước / Kết quả trong modal), `tuition.refunds.proof` (lightbox xl). Mỗi luồng: `_form` dùng chung trang ↔ modal, `modalView`/`modalSaved`, vùng danh sách tự làm mới (`system-categories-changed`, `permissions-changed`, `roles-changed`, `users-changed`, `merchandise-changed`). **Xác nhận xóa bằng `x-ui.modal`** thay `confirm()`: ngừng dùng danh mục, xóa quyền, xóa vai trò, xóa vật phẩm (htmx, 204 + làm mới), xóa tài khoản nhân sự (form thường). **Hạ tầng:** `Htmx::renderValidationForm` thêm quy tắc màn cha (`x.<action>` → `x`) + điều kiện middleware ⊆, bỏ file khỏi old input, bỏ qua khi route form redirect; trait thêm `modalFailed`, `modalRedirect`; `x-ui.modal-frame` thêm prop `size`. Sửa kèm: trang nhập học phí trước đây không hiện lỗi `excel_file` → nay hiện ngay dưới ô file. Trang `permissions`, `users/roles`, form `merchandise` chuyển sang component `x-ui.*` (token màu). Test: `ModalFlowsTest` (24 test, 308 assert); full suite 892 test, chỉ 3 lỗi có sẵn (DeployHook, TicketEmailConfig, WorkTaskModule) | Form "từ chối kèm lý do" hoàn tiền đã là `x-ui.modal` từ trước (không đổi); các form từ chối ở màn khác (phiếu thu, hủy HĐ…) để IX-5 (Việc cần duyệt). `permissions.create` vẫn redirect "Chỉ DEV" (giữ nghiệp vụ). Link gán vai trò ở `users.show` / `users.permissions` vẫn mở trang (chưa có vùng làm mới ở 2 trang này). Sau khi lưu vật phẩm, thẻ số liệu đầu trang chưa tự làm mới (chỉ bảng). Nhập học phí xong danh sách thu phí phía sau chưa tự làm mới (bước Kết quả có nút sang danh sách). Xác nhận "Đặt lại mật khẩu" vẫn `confirm()` (không phải xóa/từ chối). Chưa có test trình duyệt (Esc, Back, 375px) — chuyển IX-3 |
| IX-3 | Chưa bắt đầu | | |
| IX-4 | Chưa bắt đầu | | |
| IX-5 | Chưa bắt đầu | | |
