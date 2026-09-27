<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MultiRoleComprehensiveQaTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $admin;

    protected User $admin2;

    protected User $academicStaff;

    protected User $academicLead;

    protected User $accountant;

    protected User $teacher;

    protected User $studentUser;

    protected Student $studentModel;

    protected ClassModel $testClass;

    protected StudentTuition $studentTuition;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Seed Roles & Permissions
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        // 2. Tạo Cơ sở & Chi nhánh
        $this->branch = Branch::create([
            'name' => 'Chi nhánh Cầu Giấy',
            'code' => 'CG',
            'phone' => '0241234567',
            'is_active' => true,
        ]);
        BankAccount::create([
            'bank_code' => 'VCB', 'bank_name' => 'Vietcombank', 'account_number' => '123456789',
            'account_holder' => 'MENGLISH', 'branch_id' => $this->branch->id,
            'is_default_vietqr' => true, 'is_active' => true,
        ]);

        // 3. Khởi tạo 6 vai trò (7 tài khoản) với mật khẩu Password123!
        // Role 1: Admin (admin@menglish.edu.vn & lequelcm@gmail.com)
        $this->admin = User::factory()->create([
            'name' => 'Hệ thống Quản trị',
            'email' => 'admin@menglish.edu.vn',
            'password' => Hash::make('Password123!'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');

        $this->admin2 = User::factory()->create([
            'name' => 'Lequel CM Admin',
            'email' => 'lequelcm@gmail.com',
            'password' => Hash::make('Password123!'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->admin2->assignRole('admin');

        // Role 2: Học vụ (hocvu@menglish.edu.vn)
        $this->academicStaff = User::factory()->create([
            'name' => 'Nhân viên Học vụ',
            'email' => 'hocvu@menglish.edu.vn',
            'password' => Hash::make('Password123!'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->academicStaff->assignRole('academic_staff');

        // Role 3: Học thuật (hocthuat@menglish.edu.vn)
        $this->academicLead = User::factory()->create([
            'name' => 'Trưởng ban Học thuật',
            'email' => 'hocthuat@menglish.edu.vn',
            'password' => Hash::make('Password123!'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->academicLead->assignRole('academic_lead');

        // Role 4: Kế toán (ketoan@menglish.edu.vn)
        $this->accountant = User::factory()->create([
            'name' => 'Kế toán Trưởng',
            'email' => 'ketoan@menglish.edu.vn',
            'password' => Hash::make('Password123!'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->accountant->assignRole('accountant');

        // Role 5: Giáo viên (giaovien@menglish.edu.vn)
        $this->teacher = User::factory()->create([
            'name' => 'Giáo viên Tiếng Anh',
            'email' => 'giaovien@menglish.edu.vn',
            'password' => Hash::make('Password123!'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->teacher->assignRole('teacher');

        // Role 6: Học sinh (hocvien@menglish.edu.vn)
        $this->studentUser = User::factory()->create([
            'name' => 'Học viên Nguyễn Văn An',
            'email' => 'hocvien@menglish.edu.vn',
            'password' => Hash::make('Password123!'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->studentUser->assignRole('student');

        // Khởi tạo Course & Class & Student mẫu cho nghiệp vụ
        $course = Course::create([
            'name' => 'IELTS Intensive 6.5',
            'code' => 'IELTS-INT',
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $this->testClass = ClassModel::create([
            'name' => 'IELTS-K24-CG',
            'code' => 'IK24CG',
            'branch_id' => $this->branch->id,
            'course_id' => $course->id,
            'teacher_id' => $this->teacher->id,
            'status' => 'active',
            'start_date' => now()->subMonth(),
        ]);

        $this->studentModel = Student::create([
            'name' => 'Nguyễn Văn An',
            'code' => 'HV-0001',
            'email' => 'hocvien@menglish.edu.vn',
            'phone' => '0988776655',
            'branch_id' => $this->branch->id,
            'current_class_id' => $this->testClass->id,
            'status' => 'studying',
        ]);

        $this->studentTuition = StudentTuition::create([
            'student_id' => $this->studentModel->id,
            'class_id' => $this->testClass->id,
            'branch_id' => $this->branch->id,
            'total_amount' => 15000000,
            'paid_amount' => 10000000,
            'debt_amount' => 5000000,
            'status' => 'partially_paid',
        ]);
    }

    // =========================================================================
    // a. KIỂM THỬ ĐĂNG NHẬP & XÁC THỰC CHO CẢ 6 VAI TRÒ (7 TÀI KHOẢN)
    // =========================================================================

    public function test_all_six_roles_can_authenticate_with_default_password(): void
    {
        $accounts = [
            'admin@menglish.edu.vn',
            'lequelcm@gmail.com',
            'hocvu@menglish.edu.vn',
            'hocthuat@menglish.edu.vn',
            'ketoan@menglish.edu.vn',
            'giaovien@menglish.edu.vn',
            'hocvien@menglish.edu.vn',
        ];

        foreach ($accounts as $email) {
            $response = $this->post('/login', [
                'email' => $email,
                'password' => 'Password123!',
            ]);

            $response->assertRedirect(route('dashboard'));
            $this->assertAuthenticated();

            // Đăng xuất để kiểm tra tài khoản tiếp theo
            $this->post('/logout');
            $this->assertGuest();
        }
    }

    public function test_login_fails_with_invalid_password(): void
    {
        $response = $this->post('/login', [
            'email' => 'admin@menglish.edu.vn',
            'password' => 'WrongPassword999!',
        ]);

        $response->assertSessionHasErrors(['email']);
        $this->assertGuest();
    }

    // =========================================================================
    // a. KIỂM THỬ TRUY CẬP ROUTE ĐẶC TRƯNG & MÃ PHẢN HỒI HTTP (200 OK vs 403)
    // =========================================================================

    public function test_admin_has_full_access_to_management_routes(): void
    {
        $routes = [
            route('dashboard'),
            route('users.index'),
            route('users.create'),
            route('roles.index'),
            route('permissions.index'),
            route('branches.index'),
            route('classes.index'),
            route('classes.create'),
            route('tuition.students'),
            route('finance.reports.revenue'),
            route('finance.expenses.index'),
            route('syllabus.builder'),
            route('academic-system.index'),
            route('mockup-hub.index'),
        ];

        foreach ($routes as $url) {
            $response = $this->actingAs($this->admin)->get($url);
            $response->assertOk();
        }
    }

    public function test_accountant_can_access_financial_routes_but_forbidden_from_user_class_creation(): void
    {
        // Allowed: 200 OK
        $allowedRoutes = [
            route('tuition.students'),
            route('tuition.receipts.create'),
            route('tuition.history'),
            route('tuition.overdue'),
            route('finance.reports.revenue'),
            route('finance.expenses.index'),
            route('academic-system.show', ['category' => '01_Web_Admin', 'screen' => '10_doi_soat_chot_bang_cong']),
        ];

        foreach ($allowedRoutes as $url) {
            $response = $this->actingAs($this->accountant)->followingRedirects()->get($url);
            $response->assertOk();
        }

        // Forbidden: 403
        $forbiddenRoutes = [
            route('users.create'),
            route('classes.create'),
        ];

        foreach ($forbiddenRoutes as $url) {
            $response = $this->actingAs($this->accountant)->get($url);
            $response->assertForbidden();
        }

        // Accountant cannot POST to users.store
        $resStore = $this->actingAs($this->accountant)->post(route('users.store'), [
            'name' => 'Illegal User',
            'email' => 'illegal@menglish.edu.vn',
            'branch_id' => $this->branch->id,
            'role' => 'teacher',
            'password' => 'Password123!',
        ]);
        $resStore->assertForbidden();
    }

    public function test_teacher_can_access_teaching_and_salary_routes_but_forbidden_from_admin_creation(): void
    {
        // Allowed: 200 OK
        $allowedRoutes = [
            route('portal.my-salary'),
            route('portal.teacher.submissions'),
            route('portal.teacher.submissions.alias'),
            route('academic-system.show', ['category' => '03_Cong_Giao_Vien', 'screen' => '15_check_in_cua_toi']),
            route('academic-system.show', ['category' => '03_Cong_Giao_Vien', 'screen' => '02_diem_danh_lop_giao_vien']),
            route('academic-system.show', ['category' => '03_Cong_Giao_Vien', 'screen' => '04_bai_nop_cua_lop']),
            route('payroll.timesheets.teachers'),
        ];

        foreach ($allowedRoutes as $url) {
            $response = $this->actingAs($this->teacher)->followingRedirects()->get($url);
            $response->assertOk();
        }

        // Forbidden: 403
        $forbiddenRoutes = [
            route('users.create'),
            route('classes.create'),
        ];

        foreach ($forbiddenRoutes as $url) {
            $response = $this->actingAs($this->teacher)->get($url);
            $response->assertForbidden();
        }

        $resStore = $this->actingAs($this->teacher)->post(route('users.store'), [
            'name' => 'Illegal Teacher User',
            'email' => 'illegal_t@menglish.edu.vn',
            'branch_id' => $this->branch->id,
            'role' => 'teacher',
            'password' => 'Password123!',
        ]);
        $resStore->assertForbidden();
    }

    public function test_academic_staff_can_access_operations_routes(): void
    {
        $allowedRoutes = [
            route('users.index'),
            route('users.create'),
            route('classes.index'),
            route('classes.create'),
            route('payroll.timesheets.manual'),
            route('tasks.class-reports.create'),
            route('tasks.ta-assign'),
            route('academic-system.show', ['category' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu', 'screen' => '06_bao_cao_ngay_hoc_vu']),
            route('academic-system.show', ['category' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu', 'screen' => '05_nhat_ky_hoc_vu']),
        ];

        foreach ($allowedRoutes as $url) {
            $response = $this->actingAs($this->academicStaff)->followingRedirects()->get($url);
            $response->assertOk();
        }
    }

    public function test_academic_lead_can_access_academic_and_syllabus_routes(): void
    {
        $allowedRoutes = [
            route('syllabus.builder'),
            route('syllabus.assignments'),
            route('syllabus.versions'),
            route('syllabus.big-tests.distribution'),
            route('syllabus.big-tests.results'),
            route('classes.index'),
            route('academic-system.show', ['category' => '01_Web_Admin', 'screen' => '02_soan_syllabus_theo_chang']),
            route('academic-system.show', ['category' => '01_Web_Admin', 'screen' => '03_giao_chang_cho_giao_vien']),
            route('academic-system.show', ['category' => '01_Web_Admin', 'screen' => '05_chi_tiet_de_xuat_sua_giao_trinh']),
            route('academic-system.show', ['category' => '01_Web_Admin', 'screen' => '06_duyet_phan_phoi_de_big_test']),
            route('academic-system.show', ['category' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu', 'screen' => '13_tong_quan_danh_sach_lop_hoc_thuat']),
        ];

        foreach ($allowedRoutes as $url) {
            $response = $this->actingAs($this->academicLead)->followingRedirects()->get($url);
            $response->assertOk();
        }
    }

    // =========================================================================
    // b. KIỂM THỬ PHÂN CẤP TẠO TÀI KHOẢN (HIERARCHICAL USER CREATION)
    // =========================================================================

    public function test_admin_can_create_any_role(): void
    {
        $rolesToTest = ['manager', 'accountant', 'academic_lead', 'academic_staff', 'teacher', 'student'];

        foreach ($rolesToTest as $idx => $role) {
            $email = "test_create_{$role}_{$idx}@menglish.edu.vn";
            $res = $this->actingAs($this->admin)->post(route('users.store'), [
                'name' => "User {$role}",
                'email' => $email,
                'branch_id' => $this->branch->id,
                'role' => $role,
                'password' => 'Password123!',
            ]);
            $res->assertRedirect(route('users.index'));
            $this->assertDatabaseHas('users', ['email' => $email]);
        }
    }

    public function test_academic_lead_can_only_create_teachers(): void
    {
        // 1. Cho phép: teacher, teacher_fulltime, teacher_parttime
        $allowed = ['teacher', 'teacher_fulltime', 'teacher_parttime'];
        foreach ($allowed as $idx => $role) {
            $email = "lead_created_{$role}_{$idx}@menglish.edu.vn";
            $res = $this->actingAs($this->academicLead)->post(route('users.store'), [
                'name' => "GV Created by Lead {$idx}",
                'email' => $email,
                'branch_id' => $this->branch->id,
                'role' => $role,
                'password' => 'Password123!',
            ]);
            $res->assertRedirect(route('users.index'));
            $this->assertDatabaseHas('users', ['email' => $email]);
        }

        // 2. Từ chối: academic_staff, assistant, student, admin, manager, accountant
        $forbidden = ['academic_staff', 'assistant', 'student', 'admin', 'manager', 'accountant'];
        foreach ($forbidden as $idx => $role) {
            $email = "lead_illegal_{$role}_{$idx}@menglish.edu.vn";
            $res = $this->actingAs($this->academicLead)->post(route('users.store'), [
                'name' => "Illegal {$role}",
                'email' => $email,
                'branch_id' => $this->branch->id,
                'role' => $role,
                'password' => 'Password123!',
            ]);
            $res->assertSessionHasErrors(['role']);
            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
    }

    public function test_academic_staff_can_only_create_underlings_assistant_teachers_student(): void
    {
        // 1. Cho phép: assistant, teacher_fulltime, teacher_parttime, teacher, student
        $allowed = ['assistant', 'teacher_fulltime', 'teacher_parttime', 'teacher', 'student'];
        foreach ($allowed as $idx => $role) {
            $email = "staff_created_{$role}_{$idx}@menglish.edu.vn";
            $res = $this->actingAs($this->academicStaff)->post(route('users.store'), [
                'name' => "Subordinate {$role} {$idx}",
                'email' => $email,
                'branch_id' => $this->branch->id,
                'role' => $role,
                'password' => 'Password123!',
            ]);
            $res->assertRedirect(route('users.index'));
            $this->assertDatabaseHas('users', ['email' => $email]);
        }

        // 2. Từ chối: admin, academic_lead, manager, accountant
        $forbidden = ['admin', 'academic_lead', 'manager', 'accountant'];
        foreach ($forbidden as $idx => $role) {
            $email = "staff_illegal_{$role}_{$idx}@menglish.edu.vn";
            $res = $this->actingAs($this->academicStaff)->post(route('users.store'), [
                'name' => "Illegal Upper {$role}",
                'email' => $email,
                'branch_id' => $this->branch->id,
                'role' => $role,
                'password' => 'Password123!',
            ]);
            $res->assertSessionHasErrors(['role']);
            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
    }

    // =========================================================================
    // c. ĐỐI SOÁT CÁC MÀN HÌNH HIỂN THỊ VỚI PROTOTYPE ROUNDCUOI-KIEULIEN
    // Từ P0 #3: chỉ Admin xem bản mockup; vai trò khác được chuyển sang màn thật.
    // =========================================================================

    public function test_teacher_screens_verification(): void
    {
        // 1. Màn check-in ca dạy nhiều ca (03_Cong_Giao_Vien/15_check_in_cua_toi)
        $resCheckin = $this->actingAs($this->teacher)->followingRedirects()->get(route('academic-system.show', [
            'category' => '03_Cong_Giao_Vien',
            'screen' => '15_check_in_cua_toi',
        ]));
        $resCheckin->assertOk();
        $this->assertStringContainsString('Check-in', $resCheckin->getContent());
        // Không phải Admin: được chuyển sang màn thật, không nhận bản mockup kèm dữ liệu thô
        $this->assertStringNotContainsString('menglish-real-data-engine.js', $resCheckin->getContent());

        // 2. Cổng điểm danh lớp (03_Cong_Giao_Vien/02_diem_danh_lop_giao_vien)
        $resAttendance = $this->actingAs($this->teacher)->followingRedirects()->get(route('academic-system.show', [
            'category' => '03_Cong_Giao_Vien',
            'screen' => '02_diem_danh_lop_giao_vien',
        ]));
        $resAttendance->assertOk();
        $this->assertStringContainsString('Điểm danh', $resAttendance->getContent());

        // 3. Chấm bài nộp của lớp (/portal/teacher/submissions)
        $resSubmissions = $this->actingAs($this->teacher)->get(route('portal.teacher.submissions.alias'));
        $resSubmissions->assertOk();
        $resSubmissions->assertSee('Chấm bài');

        // 4. Xem lương cá nhân (/portal/my-salary)
        $resSalary = $this->actingAs($this->teacher)->get(route('portal.my-salary'));
        $resSalary->assertOk();
        $resSalary->assertSee('Lương');
    }

    public function test_student_portal_seven_screens_and_management_menus_hidden(): void
    {
        // 7 Màn hình Cổng Phụ huynh / Học sinh:
        // MH1: App Shell
        $res1 = $this->actingAs($this->studentUser)->get(route('portal.app-shell'));
        $res1->assertOk();

        // MH2: Trang chủ Phụ huynh / HS
        $res2 = $this->actingAs($this->studentUser)->get(route('portal.student.home'));
        $res2->assertOk();

        // MH3: Nộp bài tập
        $res3 = $this->actingAs($this->studentUser)->get(route('portal.student.homework'));
        $res3->assertOk();

        // MH4: Luyện phát âm AI
        $res4 = $this->actingAs($this->studentUser)->get(route('portal.student.pronunciation'));
        $res4->assertOk();

        // MH5: Danh sách thông báo
        $res5 = $this->actingAs($this->studentUser)->get(route('portal.student.notifications'));
        $res5->assertOk();

        // MH6: Khảo sát 5 sao
        $res6 = $this->actingAs($this->studentUser)->get(route('portal.student.survey'));
        $res6->assertOk();

        // MH7: Gửi Feedback
        $res7 = $this->actingAs($this->studentUser)->get(route('portal.student.feedback'));
        $res7->assertOk();

        // Đối soát các prototype tương ứng ở academic-system
        $prototypeRes = $this->actingAs($this->studentUser)->followingRedirects()->get(route('academic-system.show', [
            'category' => '04_Cong_Phu_Huynh_Hoc_Sinh',
            'screen' => '02_trang_chu_phu_huynh_hoc_sinh',
        ]));
        $prototypeRes->assertOk();

        // Xác minh học sinh KHÔNG nhìn thấy các menu nội bộ của quản lý
        $dashboardRes = $this->actingAs($this->studentUser)->get(route('dashboard'));
        $dashboardRes->assertOk();
        $dashboardRes->assertDontSee('CRM & Tuyển sinh');
        $dashboardRes->assertDontSee('Nhân sự & Vận hành');
        $dashboardRes->assertDontSee('Học phí & Hoá đơn');
        $dashboardRes->assertDontSee('Phân quyền & Hệ thống');
        $dashboardRes->assertDontSee('Báo cáo Thu - Chi');
        $dashboardRes->assertDontSee('Mockup Hub');
    }

    public function test_academic_staff_screens_verification(): void
    {
        // 1. Màn Báo cáo ngày (02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/06_bao_cao_ngay_hoc_vu)
        $resDayReport = $this->actingAs($this->academicStaff)->followingRedirects()->get(route('academic-system.show', [
            'category' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
            'screen' => '06_bao_cao_ngay_hoc_vu',
        ]));
        $resDayReport->assertOk();
        $this->assertStringContainsString('Báo cáo ngày', $resDayReport->getContent());

        // 2. Nhật ký sự vụ (02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/05_nhat_ky_hoc_vu)
        $resIncident = $this->actingAs($this->academicStaff)->followingRedirects()->get(route('academic-system.show', [
            'category' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
            'screen' => '05_nhat_ky_hoc_vu',
        ]));
        $resIncident->assertOk();
        $this->assertStringContainsString('Nhật ký', $resIncident->getContent());

        // 3. Chấm công cho GV/TA (/payroll/timesheets/manual)
        $resManualTimesheet = $this->actingAs($this->academicStaff)->get(route('payroll.timesheets.manual'));
        $resManualTimesheet->assertOk();
        $resManualTimesheet->assertSee('Chấm công');
    }

    public function test_accountant_screens_verification(): void
    {
        // 1. Màn Chốt bảng công (01_Web_Admin/10_doi_soat_chot_bang_cong)
        $resPayrollReview = $this->actingAs($this->accountant)->followingRedirects()->get(route('academic-system.show', [
            'category' => '01_Web_Admin',
            'screen' => '10_doi_soat_chot_bang_cong',
        ]));
        $resPayrollReview->assertOk();
        // Được chuyển sang màn kỳ lương thật (payroll.periods.index), không phải mockup
        $this->assertStringNotContainsString('menglish-real-data-engine.js', $resPayrollReview->getContent());
        $this->assertStringContainsString('lương', mb_strtolower($resPayrollReview->getContent()));

        // 2. Thu phí (/tuition/students)
        $resTuition = $this->actingAs($this->accountant)->get(route('tuition.students'));
        $resTuition->assertOk();

        // 3. Phiếu thu VietQR chuẩn CRM (/crm/tuition-bill/{id} và /tuition/receipts/create)
        $resCreateReceipt = $this->actingAs($this->accountant)->get(route('tuition.receipts.create'));
        $resCreateReceipt->assertOk();

        $resBill = $this->actingAs($this->accountant)->get(route('crm.tuition-bill', ['id' => $this->studentTuition->id]));
        $resBill->assertOk();
        $resBill->assertSee('VietQR');

        // 4. Báo cáo Doanh thu & Chi phí
        $resRevenue = $this->actingAs($this->accountant)->get(route('finance.reports.revenue'));
        $resRevenue->assertOk();

        $resExpenses = $this->actingAs($this->accountant)->get(route('finance.expenses.index'));
        $resExpenses->assertOk();
    }

    public function test_academic_lead_screens_verification(): void
    {
        // 1. Soạn Syllabus chặng
        $resSyllabusNative = $this->actingAs($this->academicLead)->get(route('syllabus.builder'));
        $resSyllabusNative->assertOk();
        $resSyllabusProto = $this->actingAs($this->academicLead)->followingRedirects()->get(route('academic-system.show', [
            'category' => '01_Web_Admin',
            'screen' => '02_soan_syllabus_theo_chang',
        ]));
        $resSyllabusProto->assertOk();

        // 2. Giao chặng GV
        $resAssignNative = $this->actingAs($this->academicLead)->get(route('syllabus.assignments'));
        $resAssignNative->assertOk();
        $resAssignProto = $this->actingAs($this->academicLead)->followingRedirects()->get(route('academic-system.show', [
            'category' => '01_Web_Admin',
            'screen' => '03_giao_chang_cho_giao_vien',
        ]));
        $resAssignProto->assertOk();

        // 3. Duyệt sửa GT
        $resVersionsNative = $this->actingAs($this->academicLead)->get(route('syllabus.versions'));
        $resVersionsNative->assertOk();
        $resVersionsProto = $this->actingAs($this->academicLead)->followingRedirects()->get(route('academic-system.show', [
            'category' => '01_Web_Admin',
            'screen' => '05_chi_tiet_de_xuat_sua_giao_trinh',
        ]));
        $resVersionsProto->assertOk();

        // 4. Duyệt Big Test
        $resBigTestDist = $this->actingAs($this->academicLead)->get(route('syllabus.big-tests.distribution'));
        $resBigTestDist->assertOk();
        $resBigTestProto = $this->actingAs($this->academicLead)->followingRedirects()->get(route('academic-system.show', [
            'category' => '01_Web_Admin',
            'screen' => '06_duyet_phan_phoi_de_big_test',
        ]));
        $resBigTestProto->assertOk();

        // 5. Sơ đồ khối lớp
        $resOverviewNative = $this->actingAs($this->academicLead)->get(route('classes.index'));
        $resOverviewNative->assertOk();
        $resOverviewProto = $this->actingAs($this->academicLead)->followingRedirects()->get(route('academic-system.show', [
            'category' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu',
            'screen' => '13_tong_quan_danh_sach_lop_hoc_thuat',
        ]));
        $resOverviewProto->assertOk();
    }
}
