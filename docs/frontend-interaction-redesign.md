# MEnglish — Tái cấu trúc tương tác: Trang ↔ Popup/Drawer & bố cục theo chức năng

> **Ngày:** 26/09/2026 · **Người lập:** CTO
> **Liên quan:** `frontend-ux-audit.md` (audit kỹ thuật FE). Tài liệu này giải quyết vấn đề **"bấm nút là chuyển trang mới"** và **menu chia theo màn thay vì theo chức năng**.
> **Stack giữ nguyên:** Laravel 11 + Blade + Alpine 3 + Tailwind. Chỉ thêm **htmx 2** (khoảng 14KB) để tải form hoặc chi tiết từ server vào popup.

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
| **Modal** | ≤ 8 trường, 1 bước, **hoặc xác nhận** (xóa, duyệt, từ chối kèm lý do) | `md`–`xl` | Ngày nghỉ, danh mục, quyền, vai trò, ghi nhận phạt, nhập Excel, duyệt/từ chối |
| **Drawer phải** (slide-over) | 8–25 trường, **hoặc xem nhanh chi tiết** mà vẫn giữ danh sách phía sau | 480–720px, full màn trên mobile | Tạo/sửa lead, tạo task, tạo ticket, sửa nhân sự, lập phiếu thu, xem chi tiết lead / học viên / phiếu |
| **Trang riêng** | Nhiều bước (wizard), trình soạn (builder), > 25 trường, cần in, hoặc cần URL để chia sẻ lâu dài | full | Chốt học phí & xếp lớp, tạo lớp + TKB, soạn đề test, soạn syllabus, hồ sơ học viên đầy đủ, bảng lương kỳ |

**Nguyên tắc đi kèm**
1. **URL vẫn sống.** Mọi route `create/edit/show` giữ nguyên. Nếu mở trực tiếp (F5, dán link, JS lỗi) thì hiện **trang đầy đủ**. Nếu bấm từ danh sách thì hiện **popup** (progressive enhancement).
2. Drawer chi tiết thì **đẩy URL** (`?drawer=lead:123` hoặc push URL gốc). Nút Back sẽ đóng drawer, và copy link gửi đồng nghiệp vẫn mở đúng.
3. Lưu xong: đóng popup, **chỉ làm mới vùng danh sách** (giữ bộ lọc và vị trí cuộn), hiện toast.
4. Lỗi validate: hiện **ngay trong popup, cạnh từng trường**, không đóng popup.
5. Form có dữ liệu mà bấm ra ngoài hoặc Esc thì hỏi "Bỏ thay đổi?".
6. Không lồng popup trong popup. Nếu cần bước tiếp thì chuyển drawer sang bước 2, hoặc lên trang riêng.

---

## 3. Phân loại 33 route form hiện tại

> Chưa tính 3 trang chi tiết (`crm.customers.show`, `tasks.show`, `tickets.show`). Các trang này được thêm **drawer xem nhanh** nhưng vẫn giữ trang đầy đủ.

### → Modal (14)
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

### → Drawer (11 form + 3 xem nhanh)
| Route | Hiện trạng | Ghi chú |
|---|---|---|
| `crm.customers.create/edit` | 14 trường | drawer 560px. Mở từ Kanban **và** danh sách |
| `crm.customers.show` | trang 752 dòng | **drawer xem nhanh** (thông tin, lịch sử chăm sóc, nút hành động). Có link "Mở trang đầy đủ" |
| `tasks.create`, `tasks.show` | | drawer. Giao việc TA (`tasks.ta-assign`) gộp vào form này bằng lựa chọn "Giao cho: TA" |
| `tasks.class-reports.create` | 13 trường | drawer, mở từ Cổng TA / Dashboard lớp theo ngày |
| `tickets.create`, `tickets.show` | | drawer: luồng hội thoại + ô trả lời |
| `users.create/edit` | 22 trường | drawer 720px, chia 3 tab (Tài khoản / Hồ sơ / Lương) |
| `users.permissions.edit` | | drawer 720px |
| `tuition.receipts.create/edit` | 855 dòng | drawer 720px **khi mở từ dòng học viên** (đã có sẵn học viên và khoản nợ). Vẫn giữ trang riêng cho luồng "lập phiếu tự do" |

### → Giữ trang riêng (8)
`crm.closing-wizard` (wizard nhiều bước) · `classes.create/edit` (lớp + TKB, 600 dòng) · `placement-tests.create/edit` (trình soạn đề, **gộp chung 1 view form**) · `classes.academic-detail` · `profile.edit` · `syllabus.assignments` (màn làm việc).

> **Kết quả:** 25/33 luồng form không còn chuyển trang (14 modal + 11 drawer). 8 luồng giữ trang riêng vì là wizard hoặc builder.

---

## 4. Chia lại bố cục theo chức năng

### 4.1 Nguyên tắc
- **1 mục menu = 1 "không gian làm việc"** (workspace) theo đối tượng nghiệp vụ, không phải theo từng màn.
- Bên trong workspace có **tab** cho các góc nhìn (danh sách / chờ duyệt / lịch sử / báo cáo), và **nút hành động** (Tạo, Nhập, Xuất) ở page-header, mở ra popup.
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
│ ☐ Phiếu thu PT-0923 · Nguyễn … · 4.500.000đ · 2 giờ trước  ▶│──► Drawer: chi tiết + ảnh CK
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
- `x-ui.drawer` (name, title, width `md|lg|xl`, side `right`). Tái dùng logic `x-ui.modal`, thêm focus-trap (`@alpinejs/focus`), khóa cuộn, hỏi xác nhận khi form có thay đổi.
- `x-ui.remote-dialog`: **một** host modal và **một** host drawer đặt ở `layouts/app`. Nội dung được htmx đổ vào `#dialog-body`.

**2. Nút mở popup** (vẫn là `<a href>` thật để giữ fallback):
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
- **Drawer chi tiết có URL:** `hx-push-url="true"`. Nếu tải thẳng URL đó thì controller trả trang danh sách **cộng** drawer mở sẵn (`show=true`), hoặc trang chi tiết đầy đủ tùy màn.

**4. Bảo mật & hiệu năng**
- CSRF: `hx-headers` lấy từ `<meta name="csrf-token">` đặt 1 lần ở `<body>`.
- Quyền: policy / `can:` middleware giữ nguyên, vì fragment đi qua đúng route cũ.
- Fragment không render layout nên không chạy query thông báo và menu, **nhẹ hơn cả trang đầy đủ**.
- Không chèn biến PHP vào JS, dữ liệu đi bằng `@js()` hoặc `data-*`.

**5. Kiểm thử**
- Feature test cho mỗi route chuyển đổi: (a) GET thường → 200 có layout; (b) GET có `HX-Request` → 200, không có `<aside data-sidebar>`; (c) POST lỗi + HX → 422 có thông báo lỗi; (d) POST đúng + HX → 204 có `HX-Trigger`.
- Test trình duyệt: mở/đóng, Esc, Back, mobile 375px (drawer full màn).

---

## 6. Kế hoạch sprint

> Chèn sau FE-1 của `frontend-ux-audit.md` (cần `x-ui.icon-button`, `x-ui.confirm` và JS module hóa trước).

### Sprint IX-1 — Hạ tầng popup
- [ ] Cài `htmx.org` 2 và `@alpinejs/focus`, cấu hình CSRF và event bridge htmx ↔ Alpine (`toast`, `close-dialog`)
- [ ] `x-ui.drawer`, host dialog ở layout, prop `dialog=` cho `x-ui.button`
- [ ] Trait `RendersDialogs`, middleware `HtmxValidation`, `x-ui.dialog-frame`
- [ ] Làm mẫu trọn vẹn với **Ngày nghỉ** (create/edit/delete), kèm 4 feature test mẫu

### Sprint IX-2 — Chuyển 15 luồng sang Modal
- [ ] Danh mục, quyền, vai trò, gán vai trò user, vật phẩm, ngày nghỉ (xong ở IX-1)
- [ ] Nhập Excel CRM / học phí: modal 2 bước (tải file → xem trước → xác nhận)
- [ ] Ảnh minh chứng hoàn tiền: lightbox
- [ ] Thay các form "từ chối kèm lý do" tự viết bằng modal chuẩn

### Sprint IX-3 — Chuyển 13 luồng sang Drawer
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
- [ ] Màn inbox: lọc theo nhóm, drawer chi tiết, duyệt/từ chối hàng loạt (transaction theo từng item, báo kết quả từng dòng)
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
| Form lớn trong drawer trên mobile | Drawer chiếm toàn màn khi `< md`, footer nút cố định đáy |
| Lead show 752 dòng khó thu gọn thành drawer | Drawer chỉ lấy 3 khối (thông tin, chăm sóc gần nhất, hành động). Trang đầy đủ vẫn giữ |
| **Cần chốt:** có đồng ý thêm htmx không | CTO đề xuất **có**. Phương án thay thế là A (thuần Alpine), chấp nhận trang nặng hơn và code trùng |
| **Cần chốt:** Approval Inbox có gồm duyệt lương kỳ không | Đề xuất phase sau, vì duyệt lương có quy trình khóa kỳ riêng |

---

## 8. Nhật ký sprint

| Sprint | Trạng thái | Đã làm | Chưa làm / chuyển sprint |
|---|---|---|---|
| IX-1 | Chưa bắt đầu | | |
| IX-2 | Chưa bắt đầu | | |
| IX-3 | Chưa bắt đầu | | |
| IX-4 | Chưa bắt đầu | | |
| IX-5 | Chưa bắt đầu | | |
