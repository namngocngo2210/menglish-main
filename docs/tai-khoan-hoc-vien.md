# Quản lý tài khoản học viên

Trạng thái: **Hoàn thành** — duyệt và triển khai 23/10/2026

## 1. Bối cảnh

- Tài khoản đăng nhập cổng học viên là một dòng `users` (role `student`), gắn với hồ sơ qua `students.user_id`.
- Tài khoản chỉ được tạo ở bước **Xác nhận chính thức** bên CRM (`CrmController`):
  - tên đăng nhập là mã học viên (`users.employee_code`),
  - email sinh tự động `student{id}@…`,
  - kèm mật khẩu tạm, bắt buộc đổi ở lần đăng nhập đầu.
- Học viên **nhập từ Excel** (`ClassRosterImportService`) không đi qua bước đó nên `students.user_id = null`, tức là không có tài khoản. Nút "Cấp mật khẩu tạm" (`enrollments.reset-account`) với các em này báo lỗi "liên hệ Admin", nhưng Admin cũng không có màn nào để cấp.

Yêu cầu: có màn quản lý và cấp tài khoản đăng nhập cho học viên đang học. Học vụ nhận thông tin đăng nhập qua **file Excel tải một lần** (chốt 22/10/2026).

## 2. Quyết định

1. **Tách logic tạo tài khoản** từ `CrmController` thành `App\Services\Students\StudentAccountService`. Luồng chốt CRM và màn mới cùng dùng service này, để quy tắc tên đăng nhập, email, mật khẩu tạm và role giống hệt nhau.
2. **Màn mới "Tài khoản học viên"** (`/students/accounts`), nằm trong nhóm Học viên.
   - Lọc theo cơ sở, lớp, trạng thái tài khoản (chưa có / đã có).
   - Mặc định chỉ hiện học viên đang học (`status = studying`).
   - Dữ liệu tự lọc theo phạm vi người xem (`ScopedToViewer`).
3. **Hai thao tác theo lô** trên các dòng được chọn:
   - "Tạo tài khoản" cho học viên chưa có.
   - "Cấp lại mật khẩu tạm" cho học viên đã có tài khoản thuần học viên.
   - Sau mỗi lô hiện nút "Tải file đăng nhập (.xlsx)".
   - Mật khẩu tạm nằm trong session đã mã hoá và **chỉ tải được một lần**. Tải xong thì xoá; rời trang mà chưa tải thì phải cấp lại.
4. **Quyền mới** `student.manage_account`.
   - Migration cấp quyền cho Admin, Quản lý cơ sở, Học vụ, TP Học vụ.
   - Export lại catalog sang v2.
   - Không dùng chung `student.assign_class`, vì cấp tài khoản hàng loạt là thao tác nhạy cảm hơn xếp lớp.

### Phương án đã cân nhắc

- *Tự tạo tài khoản ngay lúc import*: mật khẩu tạm của cả file phải trả về ngay trong luồng import. Khi đó import không chạy lại được (idempotent) như hiện tại, và không xử lý được học viên đã import trước đây → loại.
- *Gửi mật khẩu qua Zalo ZNS / SMS*: cần template ZNS và tốn phí → để sau.

## 3. Thiết kế chi tiết

### 3.1. `StudentAccountService`

```php
/** Tạo tài khoản cho học viên chưa có. Trả về mật khẩu tạm. */
public function create(Student $student, User $actor): string
/** Cấp lại mật khẩu tạm cho tài khoản thuần học viên. Trả về mật khẩu tạm. */
public function resetPassword(Student $student, User $actor): string
```

- **Tên đăng nhập:** `employee_code` = mã học viên.
  - Nếu mã đã bị một user khác dùng thì bỏ qua và báo lý do. Mã `HV-<ULID>` là định dạng chuẩn (bước chốt CRM cũng dùng), dùng được.
  - Không tự đổi mã: mã đang khớp VietQR / học phí.
- **Email:** `User::generatedStudentEmail($student->id)`, trừ khi hồ sơ có email riêng chưa ai dùng (giống luồng CRM).
- **Mật khẩu:** `TemporaryPassword::generate()`, kèm `must_change_password = true` và `is_active = true`.
- **Liên kết:** gán role `student`, ghi `students.user_id`.
- **Nhật ký:** ghi `activity('Người dùng & Phân quyền')`, và ghi `crm_customer_histories` nếu học viên có khách CRM.
- **Hai tầng chống trùng:**
  - Lệnh tạo khoá dòng học viên (`lockForUpdate`) và kiểm tra lại `user_id`.
  - Cột `users.employee_code` có ràng buộc unique.
- **Tài khoản hỗn hợp:** chỉ đặt lại mật khẩu cho tài khoản có đúng một role `student`, không đụng tài khoản nhân sự (giống `resetStudentAccount`).

### 3.2. Route (`StudentAccountController`)

```
GET  /students/accounts            students.accounts.index      can:student.manage_account
POST /students/accounts            students.accounts.store      can:student.manage_account   body: action=create|reset, student_ids[] (≤ 200)
GET  /students/accounts/download   students.accounts.download   can:student.manage_account
```

- **Khai báo route:** đặt trong nhóm `students.` (có sẵn middleware `can:student.view`) và **trước** `/{id}`, nếu không chữ `accounts` sẽ bị hiểu thành id.
- **Phân trang danh sách:** 50 dòng/trang. Chỉ gửi các cột cần thiết: mã, tên, lớp hiện tại, cơ sở, tên đăng nhập, có tài khoản không, đã gửi tài khoản chưa.
- **Truy vấn:** eager load `user:id,employee_code,email`, `currentClass:id,name`, `branch:id,name`, nên không có N+1.
- **`store`:**
  - Xử lý từng học viên trong transaction riêng, để một em lỗi không chặn cả lô.
  - Mật khẩu tạm được flash vào session dạng mảng mã hoá `[login, name, class, password]`.
  - Trả về trang danh sách kèm thông báo "Đã tạo N, bỏ qua M (lý do…)".
- **`download`:**
  - Dùng `session()->pull()` để đọc rồi xoá ngay.
  - Xuất file bằng `ArrayExport` (maatwebsite/excel đã có sẵn).
  - Đánh dấu `class_enrollments.account_sent = true` cho ghi danh đang học.
  - Nếu không còn dữ liệu trong session thì quay lại kèm lỗi "File chỉ tải được một lần, hãy cấp lại mật khẩu".
- **Giao diện:**
  - Trang `Pages/Students/Accounts.vue` có bảng chọn nhiều dòng và thanh thao tác.
  - Thêm một mục sidebar trong nhóm Học viên.
  - Chạy `ziggy:generate` sau khi thêm route.

### 3.3. Dữ liệu

- Không thêm bảng hay cột mới.
- Có một migration cấp quyền `student.manage_account` cho các role mặc định, chỉ thêm, không ghi đè cấu hình Admin đã sửa.

## 4. Kiểm thử

- **Unit `StudentAccountService`:**
  - tạo tài khoản mới;
  - học viên đã có tài khoản thì bỏ qua;
  - mã học viên trùng `employee_code` của người khác thì bỏ qua;
  - đặt lại mật khẩu cho tài khoản thuần học viên được;
  - tài khoản có thêm role nhân sự thì bị từ chối.
- **Feature:**
  - danh sách lọc theo phạm vi chi nhánh;
  - tạo theo lô với kết quả từng phần;
  - file chỉ tải được một lần và đánh dấu `account_sent`;
  - trả 403 khi thiếu quyền;
  - học viên ở chi nhánh khác trả 404.
- **Hồi quy:** các test CRM xác nhận chính thức và reset tài khoản hiện có phải vẫn pass sau khi tách service.
- **Guard-rail:** `RbacNoHardcodedRolesTest`, `AllRoutesSmokeTest`, `BranchIsolationRouteSweepTest`, `NavigationPermissionTest`.
- **v2:** chạy lại `export.php` để đồng bộ catalog quyền. Khu vực `students` sẽ nhận cảnh báo parity.

## 5. Rủi ro

- **Mật khẩu tạm trong session:**
  - Session lưu ở DB/Redis dạng mã hoá, chỉ tồn tại đến lần tải đầu tiên.
  - Không ghi mật khẩu vào log hay activity; `SensitiveData` đã che trường `password`.
- **Tên đăng nhập dài:** học viên có mã `HV-<ULID>` (26 ký tự sau "HV-") sẽ đăng nhập bằng mã dài này, giống tài khoản tạo từ bước chốt CRM hiện nay.
- **Lô lớn:** giới hạn 200 học viên mỗi lần. Hash mật khẩu bcrypt mất khoảng 50–100 ms mỗi lần, nên một lô 200 mất khoảng 10–20 giây, vẫn chấp nhận được với request đồng bộ.

## 6. Kế hoạch triển khai

Thực hiện trực tiếp trong phiên (chủ dự án yêu cầu "làm tiếp" 23/10/2026), TDD, review cả nhánh ở cuối.

**Sprint 1 — `App\Services\Students\StudentAccountService`**

- `skipReason(Student $student): ?string` — "đã có tài khoản" / "mã học viên trùng tên đăng nhập của tài khoản khác" /
  "email … đã thuộc tài khoản khác". (Mã `HV-<ULID>` là định dạng chuẩn của bước chốt CRM, dùng làm tên đăng nhập được —
  sửa lại giả định ban đầu của thiết kế.)
- `create(Student $student, ?string $email = null): string` (mật khẩu tạm) — `employee_code` = mã HV (đăng nhập được bằng mã hoặc email;
  file / màn hình hiện `User::loginIdentifier()` giống bước chốt CRM — email tự sinh `student{id}@…`), email = `$email` ??
  email riêng của hồ sơ (nếu chưa ai dùng) ?? `User::generatedStudentEmail()`, role `student`, `must_change_password`,
  ghi `students.user_id` + `students.email`. Gọi `skipReason()` trước, có lý do → `ValidationException` (`student`).
- `resetPassword(User $account): string` — chỉ tài khoản có đúng role `student`, ngược lại `ValidationException` (`student`).
- `CrmController`: bước chốt gọi `create($student, $studentEmail)`; `resetStudentAccount` gọi `resetPassword()`
  (giữ nguyên kiểm tra / thông báo / nhật ký hiện có).
- Test `tests/Feature/StudentAccountServiceTest.php` + hồi quy `LeadToStudentFlowTest`, `CrmPipelineTest`, `Phase1AcceptanceTest`.

**Sprint 2 — màn "Tài khoản học viên"**

- Catalog: `student.manage_account`; `config/access.php` thêm cho `academic_lead` (manager / academic_staff có `student.*`);
  migration `add_student_manage_account_permission` cấp cho `manager`, `academic_staff`, `academic_lead`; export catalog v2.
- `StudentAccountController` (`index`, `store`, `download`), route trong nhóm `students.` trước `/{id}`, middleware
  `can:student.manage_account`; sidebar nhóm Học viên thêm "Tài khoản học viên"; `ziggy:generate`.
- `Pages/Students/Accounts.vue`: lọc (cơ sở, lớp, tài khoản: chưa có / đã có, trạng thái mặc định đang học), bảng chọn nhiều,
  nút "Tạo tài khoản" / "Cấp lại mật khẩu tạm", khối "Tải file đăng nhập" khi session có kết quả.
- Test `tests/Feature/StudentAccountManagementTest.php`: phạm vi chi nhánh, lọc, tạo theo lô (kết quả từng phần),
  tải một lần + `account_sent`, 403 thiếu quyền, 404 học viên ngoài phạm vi; guard-rail `RbacNoHardcodedRolesTest`,
  `AllRoutesSmokeTest`, `BranchIsolationRouteSweepTest`, `NavigationPermissionTest`, `WorkspaceNavigationTest`.

## 7. Sprint

| Sprint | Nội dung | Trạng thái |
|---|---|---|
| 1 | `StudentAccountService` + refactor `CrmController` dùng service, kèm test hồi quy | **Xong** (23/10) |
| 2 | Quyền mới + migration, màn danh sách, tạo / cấp lại theo lô, tải Excel một lần, sidebar, kèm test | **Xong** (23/10) |
