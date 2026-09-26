# RBAC linh hoạt — thiết kế & vận hành

> Nhánh `feat/rbac-flexible`. Mục tiêu: **Admin cấu hình quyền cho mọi vai trò (kể cả Học vụ) và từng người, không cần
> sửa code**. Tóm tắt nằm ở `docs/audit-report-and-roadmap.md` → Phần D, mục "RBAC linh hoạt toàn hệ thống".

## 1. Nguyên tắc

1. **Mọi thao tác → một permission**, kiểm tra bằng `can()` / `@can` / middleware `can:` / `Gate`. Code nghiệp vụ
   **không** kiểm tra tên vai trò (`hasRole`, `hasAnyRole`, `@role` bị chặn bởi `tests/Feature/RbacNoHardcodedRolesTest.php`).
2. **Ngoại lệ được phép** (liệt kê cố định trong test chốt chặn):
   - **Super Admin** (vai trò `admin`): luôn có mọi quyền thao tác — `Gate::before` (`AppServiceProvider`). Định nghĩa duy
     nhất: `User::isSuperAdmin()`.
   - **Luật quan hệ** (không phải vai trò): người lập phiếu không tự duyệt phiếu mình lập (Super Admin miễn); người vi
     phạm không tự chốt biên bản của mình; không tự chấm KPI của mình; GV chính của lớp xác nhận báo cáo trực lớp; người
     được giao / người giao việc; người tham gia ticket; học viên xem dữ liệu cổng của chính mình; GV / trợ giảng của lớp /
     buổi thao tác trên lớp đó.
3. **Phạm vi dữ liệu** (Của tôi / Chi nhánh / Toàn hệ thống) cấu hình được theo module, lưu bằng permission
   `<module>.scope_<level>` → dùng chung cơ chế vai trò + phân quyền cá nhân. Một service duy nhất:
   `App\Support\DataScope`.
4. **Mặc định tái hiện đúng hành vi trước RBAC** (kiểm chứng bằng bộ test cũ + `RbacFlexibleTest::test_default_data_scopes_per_role_match_previous_behaviour`).
5. **Seed chỉ thêm, không ghi đè**: cấu hình Admin chỉnh trên màn Vai trò / Phân quyền cá nhân không bao giờ bị deploy
   hay chạy lại seeder ghi đè.

## 2. Danh mục quyền (nguồn duy nhất: `config/permission_catalog.php`)

Đọc qua `App\Support\PermissionCatalog`. Mỗi module có nhãn, nhóm hiển thị, icon và:

| Loại | Tên | Ý nghĩa |
|---|---|---|
| Thao tác | `module.action` | Xem / Thêm / Sửa / Xóa / Duyệt (cột chuẩn ma trận) + "Thao tác khác" (nhãn + mô tả tiếng Việt). |
| Phạm vi dữ liệu | `module.scope_own` / `scope_branch` / `scope_all` | Mức cao nhất được cấp thắng; không cấp mức nào → mức thấp nhất module hỗ trợ. |
| Đối tượng | `portal.student`, `portal.teacher`, `portal.assistant`, `portal.staff`, `class.teach`, `class.assist`, `lead.be_assigned`, `entrance_test.examine` | Người dùng **là ai** (cổng nào, có được xếp dạy lớp / nhận phụ trách khách / làm người chấm test). Super Admin **không** tự có (nếu không Admin lọt vào cổng học viên, danh sách giáo viên…). |
| Động | `user.assign_role.<vai trò>` | Được tạo tài khoản / gán vai trò đó. Tạo / đổi mã / xóa cùng vai trò (`App\Support\Rbac`). Không có `user.assign_role.admin`: chỉ Super Admin gán Super Admin. |

Nhóm hiển thị: CRM & Tuyển sinh · Học vụ & Đào tạo · Kế toán / Học phí · Nhân sự, Chấm công & Lương · Vận hành & Hỗ trợ ·
Hệ thống & Phân quyền · Cổng & Đối tượng người dùng.

**Quyền mới so với trước RBAC** (thay cho kiểm tra cứng vai trò):

| Permission | Thay cho | Mặc định |
|---|---|---|
| `lead.stage_forward` | `CrmStageService::CM_ROLES` | Admin, Quản lý cơ sở, Học vụ |
| `lead.stage_back` | `BACKWARD_ROLES` (A6 Q1: chỉ Admin lùi bước) | Admin |
| `lead.trial_feedback` | CM nhận xét học thử mọi buổi | Admin, Quản lý cơ sở, Học vụ |
| `lead.be_assigned` *(đối tượng)* | "người phụ trách phải là Sale / Quản lý / Admin" | Admin, Quản lý cơ sở, Sale |
| `entrance_test.examine` *(đối tượng)* | danh sách người chấm test theo vai trò | Admin, Quản lý, Học vụ, Học thuật, giáo viên |
| `class.teach` / `class.assist` *(đối tượng)* | GV chính / GVNN, trợ giảng theo vai trò | GV + Học thuật + Quản lý / Trợ giảng + Học vụ |
| `attendance_student.record_any` | `TeacherPortalController::ON_BEHALF_ROLES` | Quản lý, Học vụ, Học thuật (Admin) |
| `homework.grade` | GV / TA chấm bài nộp | Giáo viên, trợ giảng (Admin) |
| `violation.decide_academic` / `decide_operations` | `Penalty::CATEGORIES[...]['roles']` | Học thuật / Học vụ + Quản lý |
| `staff_report.submit` / `view_all` | `StaffReportController::REPORT_ROLES` / Admin-Quản lý | Nhân sự văn phòng + GV / Admin, Quản lý |
| `notification.view_system` | "Admin / Quản lý thấy thông báo chung" | Admin, Quản lý |
| `dashboard.operations` / `dashboard.academic` | dashboard theo vai trò | Admin, Quản lý / Học thuật |
| `activity_log.undo` | "chỉ Admin hoàn tác" | Admin |
| `portal.*` *(đối tượng)* | menu / cổng theo vai trò | học viên / GV / TA / mọi nhân sự |
| `user.assign_role.<vai trò>` | `UserController::creatableRoles()` | Quản lý: mọi vai trò trừ Admin; Học vụ: TA, GV (3 loại), học viên; Học thuật: GV (3 loại) |
| `tuition.scope_all`, `finance.scope_all` | `tuition.all_branches`, `finance.all_branches` (đã xóa, migration chuyển gán) | Admin (+ kế toán tổng được cấp riêng) |

## 3. Phạm vi dữ liệu theo module (`App\Support\DataScope`)

```php
DataScope::level($user, 'lead');                 // 'own' | 'branch' | 'all'
DataScope::apply($query, $user, 'lead', $own, $branch, branchIncludesOwn: false);
DataScope::branchIds($user, 'tuition');          // null = mọi chi nhánh, [id…] = chi nhánh của mình
```

"Chi nhánh" = chi nhánh chính (`users.branch_id`) + chi nhánh được cấp thêm (`user_branches`) — `User::branchIds()`.

| Module | Mức | Của tôi | Chi nhánh | Dùng ở |
|---|---|---|---|---|
| `lead` | own/branch/all | khách được giao phụ trách | khách thuộc chi nhánh | `CrmCustomer::scopeVisibleTo` (mọi màn / báo cáo / xuất CRM), bộ lọc chi nhánh, bảng hiệu suất, nhập Excel, khách học thử (all = mọi buổi) |
| `student` | own/branch/all | học viên lớp mình được xem | theo `students.branch_id`, trống thì theo lớp đang học | `Student::scopeVisibleTo`, tạo học viên, lớp được xếp |
| `class` | own/branch/all | lớp mình dạy / TA / GVNN / dạy thay theo buổi | lớp của mình + lớp thuộc chi nhánh | `ClassModel::scopeVisibleTo`, `userManagesAll`, tạo / sửa lớp, chấm bài nộp (all = mọi lớp); + cấp riêng theo chi nhánh / lớp (Phân quyền cá nhân) |
| `big_test` | own/branch/all | đợt thi / order của lớp mình (hoặc mình gửi) | + lớp thuộc chi nhánh | `BigTest`, `BigTestOrder` |
| `tuition` | branch/all | — | học phí học viên thuộc chi nhánh | `TuitionBranchScope` (mọi màn Học phí) |
| `finance` | branch/all | — | số liệu chi nhánh | `FinanceController` |
| `attendance_staff` | own/branch/all | lớp trong phạm vi Lớp học | lớp thuộc chi nhánh + lớp của mình | chấm công tay, giờ dạy & đối soát |
| `payroll` | own/branch/all | phiếu lương của mình | phiếu của nhân sự chi nhánh | kỳ lương, phiếu lương, xuất |
| `kpi` | own/branch/all | KPI của mình | KPI nhân sự chi nhánh | tổng hợp / chấm KPI tháng |
| `work_task` | own/branch/all | việc mình giao / được giao; KPI của mình | + việc / KPI của chi nhánh | danh sách việc, giao TA, KPI tự động |
| `support_ticket` | own/branch/all | ticket mình tạo / được giao | + ticket do nhân sự chi nhánh tạo | danh sách & chi tiết ticket |
| `user` | own/branch/all | tài khoản do mình tạo | nhân sự thuộc chi nhánh | danh sách / quản lý nhân sự |
| `activity_log` | own/branch/all | thao tác của mình | thao tác của nhân sự chi nhánh | nhật ký vận hành, xuất, hoàn tác |
| `dashboard` | branch/all | — | số liệu chi nhánh | bảng điều hành |

Điểm danh học viên theo phạm vi Lớp học; giáo trình theo quyền `syllabus.*` + đối tượng cổng GV / TA.

## 4. Mặc định theo vai trò (`config/access.php`, chỉ dùng khi cài mới)

| Vai trò | Phạm vi mặc định | Ghi chú |
|---|---|---|
| Super Admin `admin` | Toàn hệ thống mọi module | Bất biến; + đối tượng `lead.be_assigned`, `entrance_test.examine`, `portal.staff` |
| Quản lý cơ sở `manager` | Chi nhánh: CRM, học viên, lớp, học phí, thu chi, chấm công, việc, nhân sự, dashboard; Toàn hệ thống: Big Test, lương, KPI, ticket, nhật ký | CRM trừ lùi bước; gán mọi vai trò trừ Admin |
| Kế toán `accountant` | Chi nhánh: học viên, học phí, thu chi, chấm công; Toàn hệ thống: lương | Kế toán tổng = Admin cấp "Toàn hệ thống" theo người |
| Học vụ `academic_staff` | Chi nhánh: CRM, học viên, học phí; Toàn hệ thống: lớp, Big Test, chấm công, KPI, việc; Của tôi: nhân sự (tài khoản mình tạo) | BA 26/09: toàn quyền CRM trừ xóa; gán TA / GV / học viên |
| Học thuật `academic_lead` | Chi nhánh: CRM (xem), học viên; Toàn hệ thống: lớp, Big Test, KPI, việc; Của tôi: nhân sự | gán GV |
| Sale `sales_consultant` | Của tôi | |
| Giáo viên (3 loại), Trợ giảng | Của tôi | `portal.teacher` / `portal.assistant` |
| Học viên `student` | Của tôi | `portal.student` |

## 5. Màn hình quản trị

- **Vai trò** (`/roles`): danh sách (tên hiển thị, mã, số quyền, số nhân sự), **Thêm vai trò**, **Nhân bản**, **Xóa**
  (chỉ khi chưa gán cho ai; không xóa Super Admin), **Cấu hình quyền**: tên hiển thị / mã (vai trò hệ thống không đổi mã) /
  mô tả + ma trận theo module: Xem · Thêm · Sửa · Xóa · Duyệt · Thao tác khác · Phạm vi dữ liệu (Của tôi / Chi nhánh /
  Toàn hệ thống). Rê chuột lên quyền để xem mô tả.
- **Phân quyền cá nhân** (`/users/{id}/permissions`): cùng ma trận; mỗi quyền theo vai trò / "Cấp thêm" / "Thu hồi"
  (override thắng vai trò), mỗi module "Phạm vi dữ liệu": Theo vai trò (hiện mức của vai trò) / Của tôi / Chi nhánh /
  Toàn hệ thống; Lớp học giữ "Phạm vi áp dụng" theo chi nhánh / lớp cụ thể.
- **Gán vai trò** (`/users/{id}/roles`, kiêm nhiệm): chỉ thêm / bớt được vai trò có `user.assign_role.<vai trò>`.
- **Menu** (`App\Support\Navigation\SidebarMenu`): mục hiện khi có quyền của route (`can:`); nhóm có "quyền neo" (vd. nhóm
  Học phí: người xử lý nghiệp vụ kế toán; nhóm Cổng giáo viên: `portal.teacher` / `portal.assistant`; nhóm Ticket:
  `portal.staff`) — Admin đổi quyền là menu đổi theo.

## 6. An toàn

- Super Admin bất biến: không thu hồi quyền, không đổi mã, không xóa; phân quyền cá nhân "chặn" không thu hẹp được Super
  Admin; chỉ Super Admin chỉnh quyền / tài khoản / gán vai trò Super Admin.
- Không tự khóa mình: không tự phân quyền cá nhân; không bỏ được quyền quản trị phân quyền (`role.assign_permission`,
  `permission.override`, `user.assign_role`) của chính mình khi sửa vai trò mình giữ hoặc đổi vai trò của mình; Super Admin
  không tự bỏ vai trò Super Admin (`Rbac::ensureKeepsAccessManagement`).
- Nhật ký: mọi thay đổi vai trò / quyền vai trò / phân quyền cá nhân / gán vai trò ghi log "Người dùng & Phân quyền" với
  `old` / `attributes` (trước / sau) + `added` / `removed`.
- Cache quyền Spatie được xóa sau mỗi thay đổi (`Rbac::flushCache()`).

## 7. Seed & triển khai

- `PermissionSeeder`: `findOrCreate` mọi quyền trong danh mục (chỉ thêm).
- `RoleSeeder`: **chỉ tạo vai trò chưa có** kèm quyền mặc định; vai trò đã có không bị đụng tới (trừ Super Admin được
  bổ sung quyền còn thiếu). Chạy lại trên production an toàn.
- `deploy.sh` không chạy seeder — chỉ `php artisan migrate --force` + `optimize:clear` / cache.
- Quyền mới cho hệ thống đang chạy → **migration** cấp mặc định cho vai trò (xem `2026_10_07_100100_introduce_flexible_rbac_permissions`).

## 8. Thêm quyền mới (checklist)

1. Khai báo trong `config/permission_catalog.php` (module → `actions` / `audience` / `scope`) với nhãn + mô tả tiếng Việt.
2. Kiểm tra trong code bằng `can('module.action')` / middleware `can:` / `@can`; dữ liệu theo phạm vi dùng `DataScope`.
3. Thêm vào vai trò mặc định trong `config/access.php` (cài mới).
4. Viết migration: `Permission::findOrCreate()` + `givePermissionTo()` cho các vai trò hiện có (chỉ quyền mới, không
   `syncPermissions`), gọi `Rbac::flushCache()`.
5. Test: `RbacFlexibleTest::test_every_catalog_permission_has_vietnamese_label_and_description` bắt buộc có nhãn / mô tả.

## 9. Tên vai trò còn xuất hiện trong code (không phải phân quyền)

- `User::isSuperAdmin()` (+ các chỗ dùng được liệt kê trong `RbacNoHardcodedRolesTest::SUPER_ADMIN_ALLOWLIST`).
- **Phân loại chức danh** (công thức lương, đơn giá, KPI Học vụ, kỳ báo cáo): `PayrollFormulaService::profile`,
  `App\Support\StaffType`, danh sách nhân sự thuộc diện KPI (`KpiController::STAFF_ROLES`, `KpiBoardService::ROLES`).
- **Danh bạ / người nhận thông báo** (ai nhận thông báo, danh sách lọc theo chức danh): `BranchStaff`,
  `StudentDeferralService`, `SepayWebhookController`, `NotifyExpiringContractsCommand`, người nhận phiếu chờ duyệt / dải
  số (`TuitionController`), danh sách giáo viên của màn giáo trình / bổ trợ / chấm công, bảng hiệu suất Sale của báo cáo CRM.
  Các chỗ này không cấp quyền truy cập dữ liệu; có thể chuyển dần sang quyền đối tượng khi BA cần.
