# RBAC linh hoạt — thiết kế & vận hành

Mục tiêu: **Admin cấu hình quyền cho mọi vai trò (kể cả Học vụ) và cho từng người, không cần sửa code**. Tóm tắt tiến
độ ở `docs/audit-report-and-roadmap.md` → Phần D, mục "RBAC linh hoạt toàn hệ thống".

Tệp chính:

| Tệp | Vai trò |
|---|---|
| `config/permission_catalog.php` | Danh mục quyền — nguồn duy nhất (module, thao tác, đối tượng, phạm vi, nhãn + mô tả tiếng Việt) |
| `config/access.php` | Vai trò mặc định + quyền mặc định (chỉ dùng khi vai trò được tạo lần đầu) |
| `app/Support/PermissionCatalog.php` | Đọc danh mục: nhóm, nhãn, loại quyền, module có phạm vi |
| `app/Support/DataScope.php` | Phạm vi dữ liệu Của tôi / Chi nhánh / Toàn hệ thống theo module |
| `app/Support/Rbac.php` | Super Admin (`Rbac::SUPER_ADMIN`), phân cấp gán vai trò, chống tự khóa, xóa cache |
| `app/Models/User.php` | `isSuperAdmin()` (chỗ duy nhất kiểm tra tên vai trò), `branchIds()`, phân quyền cá nhân |
| `app/Providers/AppServiceProvider.php` | `Gate::before` (Super Admin) + phân quyền cá nhân (override) |
| `app/Http/Controllers/RoleController.php`, `UserPermissionOverrideController.php` | Màn Vai trò / Phân quyền cá nhân |
| `database/seeders/PermissionSeeder.php`, `RoleSeeder.php`, `ProductionBootstrapSeeder.php` | Seed chỉ thêm |
| `tests/Feature/RbacNoHardcodedRolesTest.php`, `RbacFlexibleTest.php` | Chốt chặn + hành vi |

## 1. Nguyên tắc

1. **Mọi thao tác → một permission**, kiểm tra bằng `can()` / `@can` / middleware `can:` / `Gate`. Code nghiệp vụ
   **không** kiểm tra tên vai trò (`hasRole`, `hasAnyRole`, `@role`… bị chặn bởi `tests/Feature/RbacNoHardcodedRolesTest.php`).
2. **Ngoại lệ được phép** (liệt kê cố định trong test chốt chặn):
   - **Super Admin** (vai trò `admin`, hằng `Rbac::SUPER_ADMIN`): luôn có mọi quyền thao tác — `Gate::before`
     (`AppServiceProvider`). Định nghĩa duy nhất: `User::isSuperAdmin()`; nơi được gọi `isSuperAdmin()` bị giới hạn số lần
     trong `RbacNoHardcodedRolesTest::SUPER_ADMIN_ALLOWLIST`.
   - **Luật quan hệ** (không phải vai trò): người lập phiếu thu không tự duyệt phiếu mình lập (Super Admin miễn); người vi
     phạm không tự chốt biên bản của mình; không tự chấm KPI của mình; GV chính của lớp xác nhận báo cáo trực lớp; người
     được giao / người giao việc; người tham gia ticket; học viên xem dữ liệu cổng của chính mình; GV / trợ giảng của lớp /
     buổi thao tác trên lớp đó.
3. **Phạm vi dữ liệu** (Của tôi / Chi nhánh / Toàn hệ thống) cấu hình được theo module, lưu bằng permission
   `<module>.scope_<level>` → dùng chung cơ chế vai trò + phân quyền cá nhân. Một service duy nhất: `App\Support\DataScope`.
4. **Mặc định tái hiện đúng hành vi trước RBAC**, gồm quyết định BA 26/09/2026 (xem §4); kiểm chứng bằng bộ test cũ +
   `RbacFlexibleTest::test_default_data_scopes_per_role_match_previous_behaviour`.
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

**Quy mô** (cài mới, 11 vai trò mặc định): 42 module · 144 quyền thao tác · 8 quyền đối tượng · 39 quyền phạm vi
(14 module có phạm vi) · 10 quyền gán vai trò = **201 permission**.

| Nhóm hiển thị | Module (mã) |
|---|---|
| CRM & Tuyển sinh | `lead` (10 thao tác, gồm `stage_forward`, `stage_back`, `trial_feedback`), `promotion`, `entrance_test`, `placement_test` |
| Học vụ & Đào tạo | `student`, `class`, `attendance_student`, `homework`, `level`, `syllabus` (gồm `approve_adjustment`), `big_test` (`approve`) |
| Kế toán / Học phí | `tuition`, `invoice` (`request_cancel`, `approve_cancel`), `refund_transfer` (`request`, `approve`, `approve_refund`, `approve_transfer`, `reject`), `bank_account`, `invoice_range` (`manage`, `manage_default`), `fee_reminder_config`, `finance` |
| Nhân sự, Chấm công & Lương | `user`, `attendance_staff`, `payroll` (`approve`…), `kpi` (`confirm`…), `teacher_rate`, `commission_config`, `violation` (`decide_academic`, `decide_operations`…), `staff_report` |
| Vận hành & Hỗ trợ | `work_task`, `support_ticket`, `notification`, `media`, `survey`, `course`, `recruitment`, `report`, `dashboard` (`operations`, `academic`) |
| Hệ thống & Phân quyền | `role`, `permission`, `branch`, `system_category`, `holiday`, `activity_log` (`undo`) |
| Cổng & Đối tượng người dùng | `portal` (`student`, `teacher`, `assistant`, `staff`) |

Danh sách đầy đủ kèm mô tả: màn **Vai trò → Cấu hình quyền** (rê chuột lên quyền) hoặc chính file danh mục.

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

Quy tắc chung:

- Mức hiệu lực = mức **cao nhất** mà người dùng có qua vai trò (kể cả kiêm nhiệm) hoặc quyền trực tiếp; phân quyền cá
  nhân "Phạm vi dữ liệu" của module (nếu đặt) **thay thế** mức theo vai trò (cho phép thu hẹp hoặc nới rộng).
- Không được cấp mức nào → mức thấp nhất module hỗ trợ (`levels[0]`, vd. `tuition` → Chi nhánh).
- Super Admin: luôn Toàn hệ thống.
- "Chi nhánh" = chi nhánh chính (`users.branch_id`) + chi nhánh được cấp thêm (`user_branches`) — `User::branchIds()`.
  Người ở mức Chi nhánh nhưng chưa gán chi nhánh nào → không thấy dữ liệu (không còn quy ước "để trống chi nhánh = toàn hệ
  thống").
- Phạm vi chỉ lọc **dữ liệu**; muốn thao tác vẫn cần quyền thao tác tương ứng (vd. `lead.update`).

| Module | Mức | Của tôi | Chi nhánh | Dùng ở |
|---|---|---|---|---|
| `lead` | own/branch/all | khách được giao phụ trách | khách thuộc chi nhánh | `CrmCustomer::scopeVisibleTo` (mọi màn / báo cáo / xuất CRM), bộ lọc chi nhánh, bảng hiệu suất, nhập Excel, khách học thử (all = mọi buổi) |
| `student` | own/branch/all | học viên lớp mình được xem | theo `students.branch_id`, trống thì theo lớp đang học | `Student::scopeVisibleTo`, tạo học viên, lớp được xếp |
| `class` | own/branch/all | lớp mình dạy / TA / GVNN / dạy thay theo buổi | lớp của mình + lớp thuộc chi nhánh | `ClassModel::scopeVisibleTo`, `userManagesAll`, tạo / sửa lớp, chấm bài nộp (all = mọi lớp); + cấp riêng theo chi nhánh / lớp (Phân quyền cá nhân) |
| `big_test` | own/branch/all | đợt thi / order của lớp mình (hoặc mình gửi) | + lớp thuộc chi nhánh | `BigTest`, `BigTestOrder` |
| `tuition` | branch/all | — | học phí học viên thuộc chi nhánh | `TuitionBranchScope` (mọi màn Học phí, dải số, người nhận phiếu chờ duyệt) |
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

## 4. Mặc định theo vai trò (`config/access.php`, chỉ dùng khi vai trò được tạo lần đầu)

| Vai trò | Thao tác chính | Phạm vi mặc định |
|---|---|---|
| Super Admin `admin` | Mọi quyền (bất biến) + đối tượng `lead.be_assigned`, `entrance_test.examine`, `portal.staff` | Toàn hệ thống mọi module |
| Quản lý cơ sở `manager` | Nhân sự (xem / khóa / reset MK / gán mọi vai trò trừ Admin); CRM mọi thao tác **trừ lùi bước**; học viên, lớp, điểm danh, giáo trình (không duyệt đề xuất); học phí (tạo / duyệt / từ chối), yêu cầu hủy HĐ, hoàn phí (khất nợ / bảo lưu, duyệt chuyển nhượng, từ chối — **không** duyệt hoàn tiền); lương **chỉ xem**; KPI; chấm công tay; biên bản lỗi vận hành; việc, ticket, thông báo, báo cáo | Chi nhánh: CRM, học viên, lớp, học phí, thu chi, chấm công, việc, nhân sự, dashboard · Toàn hệ thống: Big Test, lương, KPI, ticket, nhật ký |
| Kế toán `accountant` | Học phí (tạo / duyệt / từ chối), yêu cầu hủy HĐ, hoàn phí như Quản lý, tài khoản ngân hàng, dải số chi nhánh, cấu hình nhắc nợ; lương (tạo / sửa / tính — **không** duyệt); báo cáo, thu chi | Chi nhánh: học viên, học phí, thu chi, chấm công · Toàn hệ thống: lương · Của tôi: việc, ticket. **Kế toán tổng** = Admin cấp "Toàn hệ thống" cho Học phí / Thu chi (+ Chấm công) theo người |
| Học vụ `academic_staff` | **BA 26/09: toàn quyền CRM / test đầu vào / đề test trừ xóa**, chuyển bước tiến (không lùi); học viên, lớp; giáo trình (không duyệt); KPI xem / xác nhận; biên bản lỗi vận hành; học phí (tạo phiếu, liên hệ, báo quá hạn); việc; gán TA / GV (3 loại) / học viên | Chi nhánh: CRM, học viên, học phí · Toàn hệ thống: lớp, Big Test, chấm công, KPI, việc · Của tôi: nhân sự (tài khoản mình tạo), ticket |
| Học thuật `academic_lead` | CRM xem; lớp, giáo trình **gồm duyệt đề xuất**, **Big Test gồm duyệt**; test / đề test; KPI; biên bản lỗi chuyên môn; dashboard học thuật; gán GV (3 loại) | Chi nhánh: CRM (xem), học viên · Toàn hệ thống: lớp, Big Test, KPI, việc · Của tôi: nhân sự, ticket |
| Sale `sales_consultant` | CRM xem / thêm / sửa / chốt / thất bại, gửi test, báo cáo | Của tôi |
| Giáo viên (3 loại), Trợ giảng | Lớp mình, điểm danh, chấm bài nộp, giáo trình (xem / sửa / đề xuất), lương của mình; `portal.teacher` / `portal.assistant` | Của tôi |
| Học viên `student` | Cổng học viên, ticket | Của tôi (`portal.student`) |

Quyết định BA / A6 phản ánh trong mặc định: quyền kế toán cấu hình được (duyệt hoàn tiền, duyệt hủy HĐ, dải số mặc định,
phạm vi mọi chi nhánh mặc định chỉ Admin); Học vụ toàn quyền CRM trừ xóa; chỉ Admin lùi bước CRM (`lead.stage_back`);
duyệt Big Test (`big_test.approve`) và duyệt đề xuất giáo trình (`syllabus.approve_adjustment`) chỉ Học thuật + Admin;
duyệt lương (`payroll.approve`) chỉ Admin; **không chốt được bảng lương khi còn nhân sự chưa chốt KPI** (luật nghiệp vụ
trong `PayrollController`, áp dụng cả Admin).

## 5. Admin tùy chỉnh quyền như thế nào

- **Vai trò** (`/roles`, quyền `role.*`): danh sách (tên hiển thị, mã, số quyền, số nhân sự), **Thêm vai trò**,
  **Nhân bản**, **Xóa** (chỉ khi chưa gán cho ai; không xóa Super Admin), **Cấu hình quyền**: tên hiển thị / mã (vai trò
  hệ thống không đổi mã) / mô tả + ma trận theo module: Xem · Thêm · Sửa · Xóa · Duyệt · Thao tác khác · Phạm vi dữ liệu
  (Của tôi / Chi nhánh / Toàn hệ thống). Rê chuột lên quyền để xem mô tả. Thay đổi có hiệu lực ngay cho mọi người giữ
  vai trò.
- **Phân quyền cá nhân** (`/users/{id}/permissions`, quyền `permission.override`): cùng ma trận; mỗi quyền theo vai trò /
  "Cấp thêm" / "Thu hồi" (override thắng vai trò), mỗi module "Phạm vi dữ liệu": Theo vai trò (hiện mức của vai trò) /
  Của tôi / Chi nhánh / Toàn hệ thống; Lớp học giữ "Phạm vi áp dụng" theo chi nhánh / lớp cụ thể.
- **Gán vai trò** (`/users/{id}/roles`, kiêm nhiệm): chỉ thêm / bớt được vai trò có `user.assign_role.<vai trò>`.
- **Menu** (`App\Support\Navigation\SidebarMenu`): mục hiện khi có quyền của route (`can:`); nhóm có "quyền neo" (vd. nhóm
  Học phí: người xử lý nghiệp vụ kế toán; nhóm Cổng giáo viên: `portal.teacher` / `portal.assistant`; nhóm Ticket:
  `portal.staff`) — Admin đổi quyền là menu đổi theo.

Ví dụ thường gặp:

| Yêu cầu | Làm ở đâu |
|---|---|
| Kế toán tổng xem mọi chi nhánh | Phân quyền cá nhân người đó → Học phí / Thu chi / Chấm công: Phạm vi "Toàn hệ thống" |
| Cho Kế toán duyệt hoàn tiền | Vai trò Kế toán → Hoàn phí… → Thao tác khác: "Duyệt hoàn tiền" |
| Một Học vụ không được chốt khách | Phân quyền cá nhân → CRM → "Chốt khách & xếp lớp": Thu hồi |
| Quản lý chỉ xem khách mình phụ trách | Vai trò Quản lý cơ sở → CRM → Phạm vi "Của tôi" (hoặc theo từng người) |
| Vai trò mới "Trưởng phòng tuyển sinh" | Vai trò Sale → Nhân bản → đổi tên, thêm quyền / phạm vi Chi nhánh |

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

- `PermissionSeeder`: `findOrCreate` mọi quyền trong danh mục + `user.assign_role.<vai trò>` (chỉ thêm).
- `RoleSeeder`: **chỉ tạo vai trò chưa có** kèm quyền mặc định; vai trò đã có không bị đụng tới (trừ Super Admin được
  bổ sung quyền còn thiếu). Chạy lại trên production an toàn.
- `ProductionBootstrapSeeder` (hook deploy `seed=bootstrap`, chỉ khi database chưa có người dùng): `PermissionSeeder` +
  `RoleSeeder` + `SystemCategorySeeder` + một tài khoản Super Admin (`Rbac::SUPER_ADMIN`) từ `INITIAL_ADMIN_*`, bắt đổi
  mật khẩu. Xem `docs/deploy-directadmin.md`.
- `deploy.sh` và hook `/_deploy/hook` (không `seed`) **không** chạy seeder — chỉ `migrate --force` + làm mới cache.
- Quyền mới cho hệ thống đang chạy → **migration** cấp mặc định cho vai trò (xem
  `2026_10_07_100100_introduce_flexible_rbac_permissions`).

**Nâng cấp hệ thống đang chạy lên RBAC linh hoạt** (2 migration, chỉ đụng bảng quyền, có `down`):

1. `2026_10_07_100000_add_label_and_description_to_roles_table` — cột `roles.label`, `roles.description`.
2. `2026_10_07_100100_introduce_flexible_rbac_permissions`:
   - tạo quyền mới của danh mục; cấp cho vai trò hệ thống **chỉ các quyền mới** theo `config/access.php` (quyền Admin đã
     chỉnh trước đó giữ nguyên);
   - `tuition.all_branches` → `tuition.scope_all`, `finance.all_branches` → `finance.scope_all`: chuyển gán theo vai trò,
     quyền trực tiếp và phân quyền cá nhân; ai có `tuition.all_branches` nhận thêm `attendance_staff.scope_all` (trước đây
     quyền này quyết định lớp được chấm công tay); quyền cũ bị xóa;
   - vai trò tự tạo / quyền cá nhân: suy ra phạm vi từ quyền đang có như code cũ (vd. `class.update` → mọi lớp,
     `work_task.approve` → mọi công việc, `kpi.view` / `payroll.view` / `activity_log.view` → toàn hệ thống);
   - cài mới (chưa có vai trò): chỉ dọn quyền cũ — seeder tạo toàn bộ. Chạy lại không tạo trùng.

Sau migrate: migration tự xóa cache quyền (dùng cache ngoài thì thêm `php artisan permission:cache-reset`); vào **Vai
trò** kiểm tra ma trận; kế toán tổng có "Phạm vi dữ liệu: Toàn hệ thống" cho Học phí / Thu chi / Chấm công ở Phân quyền
cá nhân. Không cần build lại asset.

## 8. Thêm quyền mới (checklist)

1. Khai báo trong `config/permission_catalog.php` (module → `actions` / `audience` / `scope`) với nhãn + mô tả tiếng Việt.
2. Kiểm tra trong code bằng `can('module.action')` / middleware `can:` / `@can`; dữ liệu theo phạm vi dùng `DataScope`.
   Không dùng `hasRole()` (test chốt chặn sẽ đỏ).
3. Thêm vào vai trò mặc định trong `config/access.php` (cài mới).
4. Viết migration cho hệ thống đang chạy — chỉ thêm, không `syncPermissions`:

   ```php
   public function up(): void
   {
       \App\Support\Rbac::flushCache();
       $permission = Permission::findOrCreate('lead.export', 'web');
       foreach (['manager', 'academic_staff'] as $name) {
           Role::query()->where('guard_name', 'web')->where('name', $name)->first()?->givePermissionTo($permission);
       }
       \App\Support\Rbac::flushCache();
   }
   ```

   Super Admin không cần cấp (Gate::before; `RoleSeeder` bổ sung khi chạy lại).
5. Test: `RbacFlexibleTest::test_every_catalog_permission_has_vietnamese_label_and_description` bắt buộc có nhãn / mô tả;
   thêm test hành vi (có quyền → được, thu hồi → 403).

Module mới cần phạm vi dữ liệu: thêm `scope` (levels + mô tả từng mức) vào module trong danh mục, lọc truy vấn bằng
`DataScope::apply()` / `DataScope::branchIds()`, cấp `<module>.scope_<level>` mặc định cho vai trò (config + migration).

## 9. Tên vai trò còn xuất hiện trong code (không phải phân quyền)

- `User::isSuperAdmin()` (+ các chỗ dùng được liệt kê trong `RbacNoHardcodedRolesTest::SUPER_ADMIN_ALLOWLIST`).
- **Phân loại chức danh** (công thức lương, đơn giá, KPI Học vụ, kỳ báo cáo): `PayrollFormulaService::profile`,
  `App\Support\StaffType`, danh sách nhân sự thuộc diện KPI (`KpiController::STAFF_ROLES`, `KpiBoardService::ROLES`).
- **Danh bạ / người nhận thông báo** (ai nhận thông báo, danh sách lọc theo chức danh): `BranchStaff`,
  `StudentDeferralService`, `SepayWebhookController`, `NotifyExpiringContractsCommand`, `WorkTaskController` (báo Admin
  khi giao việc TA muộn), người nhận phiếu chờ duyệt / dải số / báo quá hạn (`TuitionController`), danh sách giáo viên của
  màn giáo trình / bổ trợ / chấm công, bảng hiệu suất Sale của báo cáo CRM. Người nhận là Super Admin dùng
  `Rbac::SUPER_ADMIN`. Các chỗ này không cấp quyền truy cập dữ liệu; có thể chuyển dần sang quyền đối tượng khi BA cần.
- Seeder demo / test (`UserSeeder`, `DemoPhase*Seeder`, `tests/`) gán vai trò theo tên — dữ liệu mẫu, không phải kiểm tra
  quyền.
