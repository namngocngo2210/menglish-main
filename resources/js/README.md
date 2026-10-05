# Giao diện Vue (Inertia)

Mọi màn hình là trang Vue 3 chạy qua [Inertia](https://inertiajs.com): controller Laravel trả
`Inertia::render('Module/Trang', $props)` → trang `resources/js/Pages/Module/Trang.vue` nhận `$props`.
Chuyển trang không tải lại; layout (sidebar, topbar) giữ nguyên. Route, quyền, validate vẫn ở Laravel.

Mẫu đầy đủ: **Ngày nghỉ** — `app/Http/Controllers/HolidayController.php`, `resources/js/Pages/Holidays/*`,
`tests/Feature/HolidayModalTest.php`.

## Thư mục

| Đường dẫn | Nội dung |
| --- | --- |
| `Pages/<Module>/…vue` | Trang (tên component = đường dẫn, vd. `Crm/Customers/Index`). Thành phần riêng của module để cạnh trang (`Pages/Crm/Customers/CustomerRow.vue`) |
| `Components/ui/Ui*.vue` | Bộ component chung, đăng ký toàn cục (không cần import) — xem chú thích đầu mỗi file |
| `Components/*.vue` | Thành phần dùng ở nhiều module (vd. `WorkspaceChips`) |
| `Layouts/AppLayout.vue`, `Layouts/Shell/*` | Khung ứng dụng; dữ liệu từ `App\Support\Navigation\AppShell` |
| `lib/*.js` | `route`, `can`, `format` (tiền, ngày), `confirm`, `toast`, `remoteModal`, `url`, `rowLink`, `backLink` |
| `ziggy.js` | Danh sách route sinh sẵn — đổi `routes/web.php` xong chạy `php artisan ziggy:generate resources/js/ziggy.js` |

Dùng trực tiếp trong template (không import): `route()`, `routeIs()`, `can()`, `canAny()`, `formatMoney()`,
`formatDate(value, 'd/m/Y H:i')`, `formatNumber()`, `shortCode()`.

## Controller

```php
return Inertia::render('Courses/Index', [
    'courses' => Course::query()->…->paginate($request->perPage(15))->withQueryString()
        ->through(fn (Course $c) => ['id' => $c->id, 'name' => $c->name, 'fee' => $c->fee, 'start_date' => $c->start_date?->toDateString()]),
    'levels' => Ui::options($levels, 'name'),           // [{value, label}] cho <UiSelect>
    'canManage' => $request->user()->can('course.manage'),
]);
```

- **Chỉ gửi trường trang cần** (mảng / `through()`), không gửi nguyên model: props nằm trong HTML trang, ai xem trang cũng đọc được.
- Tiền gửi số, ngày gửi `Y-m-d` / ISO → Vue định dạng (`formatMoney` giống `Money::format`, `formatDate` theo giờ VN).
  Chuỗi đã tính sẵn ở PHP (nhãn trạng thái, `diffForHumans`) gửi luôn chuỗi.
- Quyền chung: `can('lead.create')` trong Vue (shared prop `can`). Quyền trên bản ghi cụ thể (policy) → tính ở controller, gửi prop (`canEdit`).
- Validate, redirect, `with('status', …)` giữ nguyên. Flash `success` / `status` / `error` / `warning` / `info` tự hiện toast.
  Flash khác (kết quả nhập Excel, mật khẩu tạm…) → controller đọc `session(...)` rồi gửi thành prop.
- Tải file / xuất Excel / bản in: route vẫn trả file hoặc view Blade in; ở Vue dùng `<UiButton native :href>` hoặc `<a :href>`
  (link Inertia tới trang không phải Inertia tự mở hẳn trang đó). Xuất file dùng route GET.

## Trang

```vue
<script setup>
defineOptions({ layout: { title: 'Khóa học' } }); // tiêu đề topbar (render phía server)
defineProps({ courses: Object, levels: Array, canManage: Boolean });
</script>

<template>
    <UiPageHeader title="Khóa học" description="…">
        <template v-if="canManage" #actions><UiButton icon="add" :href="route('courses.create')" modal="lg">Thêm khóa học</UiButton></template>
    </UiPageHeader>
    <UiFilterBar placeholder="Tìm tên khóa học…">
        <UiSelect name="level_id" label="Trình độ" :options="levels" placeholder="Tất cả trình độ" />
    </UiFilterBar>
    <UiDataTable min-width="720px">
        <table>…<tr v-for="c in courses.data" :key="c.id">…</tr>…</table>
        <template #footer><UiPagination :paginator="courses" unit="khóa học" /></template>
    </UiDataTable>
</template>
```

- Chuyển thay đổi Blade từ nhánh khác (component Blade cũ đã xoá): `x-ui.button` → `UiButton`, `x-ui.input` → `UiInput`, `x-ui.select` → `UiSelect`, `x-ui.textarea` → `UiTextarea`,
  `x-ui.date` / `date-range` → `UiDate` / `UiDateRange`, `x-ui.data-table` → `UiDataTable`, `x-ui.pagination` → `UiPagination`,
  `x-ui.filter-bar` → `UiFilterBar`, `x-ui.page-header` → `UiPageHeader`, `x-ui.modal` → `UiModal`, `x-ui.modal-frame` → `UiModalFrame`,
  `x-ui.tabs`/`tab` → `UiTabs`/`UiTab`, `x-ui.badge`, `alert`, `avatar`, `code`, `money`, `stat-card`, `empty-state`, `dropdown` → `Ui…` cùng tên,
  `x-ui.workspace-chips` → `<WorkspaceChips :counts>` (import `@/Components/WorkspaceChips.vue`), checkbox → `UiCheckbox`.
- Alpine (`x-data`, `x-show`, `@click`) → state Vue (`ref`, `computed`, `v-show`, `@click`); `<script>` trong view → `<script setup>`.
- `{{ }}` tự escape. **Không dùng `v-html`** trừ nội dung server đã làm sạch.
- Dòng bảng bấm được: `<tr :data-href="url">` (thêm `data-modal="lg"` để mở trong modal).
- Nút quay lại: `<UiPageHeader :back="route('…index')">` — về trang vừa mở trước đó (giữ lọc / tab / trang), URL truyền vào chỉ là dự phòng.
  Nút quay lại tự làm: `const back = useBackLink(() => route('…'), 'Nhãn')` → `:href="back.href" data-back-link`, `{{ back.label }}` (`@/lib/backLink`).
- Trang tự đặt tab workspace chỗ khác: `defineOptions({ layout: { workspaceTabs: false } })` + `import WorkspaceTabs from '@/Layouts/Shell/WorkspaceTabs.vue'`.
- Trang không dùng khung ứng dụng (trang công khai, làm bài test): `import BareLayout from '@/Layouts/BareLayout.vue'` + `defineOptions({ layout: BareLayout })`; trang chưa đăng nhập (Auth/*) tự dùng `GuestLayout`.

## Form

```vue
<UiForm :action="route('courses.update', course.id)" method="put">
    <UiInput name="name" label="Tên khóa học" required :value="course.name" />
    <UiButton type="submit">Lưu</UiButton>
</UiForm>
```

- Trường dùng `name` như form HTML (`items[0][qty]`, `branch_ids[]`); giá trị ban đầu qua `:value` / `:checked`, hoặc `v-model` khi cần giá trị trong Vue.
  Lỗi validate tự hiện dưới trường, dữ liệu đã nhập giữ nguyên, nút submit tự khoá khi đang gửi.
- Hỏi trước khi gửi: `confirm="Xóa khóa học A?" confirm-label="Xóa" danger`. Trong JS: `await confirmDialog({...})` (`@/lib/confirm`).
- Upload file: `method="post"` + `<input type="hidden" name="_method" value="put">`.
- Form ngay trên trang muốn quay lại đúng trang đang xem (nút Xóa từng dòng): thêm `back`.

## Modal (Thêm / Sửa / Chi tiết)

Cùng 1 route vừa là modal vừa là trang đầy đủ khi mở thẳng URL:

```php
public function edit(Course $course) { return $this->modalPage('Courses/Form', ['course' => [...]]); }   // trait RendersModals
public function update(...) { …; return $this->modalSaved('Đã lưu.', route('courses.index')); }
```

```vue
<UiButton :href="route('courses.edit', c.id)" modal="lg">Sửa</UiButton>          <!-- mở trong modal chung -->

<!-- Pages/Courses/Form.vue -->
<UiModalFrame :title="course ? 'Sửa khóa học' : 'Thêm khóa học'" :action="…" :method="course ? 'put' : 'post'" :back="route('courses.index')">
    <UiInput name="name" label="Tên khóa học" required :value="course?.name" />
</UiModalFrame>
```

- Request từ modal mang header `X-Remote-Modal` → `modalPage` gửi prop `asModal`; `modalSaved` quay lại trang đang mở kèm thông báo
  (modal đóng, trang nền có dữ liệu mới); lỗi validate hiện ngay trong modal. `modalFailed` → đóng modal + toast lỗi.
- Giữ modal mở sau khi gửi (vd. gửi phản hồi ticket): controller `back()->with(...)`, form `<UiForm stay>`.
- Modal dựng sẵn trong trang (xác nhận có lý do…): `<UiModal :show="open" title="…" @close="open = false">`.

## Test

- Request thường trả HTML thật (Vue render phía server bằng Node, `tests/Support/InertiaSsrServer`): `assertSee('Tên khóa học')` như cũ.
  Thứ tự thuộc tính do Vue quyết định → assertion regex không phụ thuộc thứ tự (`(?=[^>]*href="…")`).
- Props: `->assertInertia(fn (AssertableInertia $page) => $page->component('Courses/Index')->where('courses.total', 3))`
  (thay `assertViewHas`).
- Modal: `$this->get($url, self::MODAL)` / `->from($listUrl)->put($url, $data, self::MODAL)->assertRedirect($listUrl)` — trait `Tests\Concerns\InteractsWithInertia`.
- Chạy: `CACHE_STORE=database php artisan test tests/Feature/…`. Lần đầu tự build `bootstrap/ssr/ssr.js` (sửa Vue xong test tự build lại).
