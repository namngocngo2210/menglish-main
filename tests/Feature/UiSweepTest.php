<?php

namespace Tests\Feature;

use App\Models\BigTest;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Navigation\SidebarMenu;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rà soát giao diện (B2 mục 2–7): không còn dữ liệu giả, bộ lọc / phân trang thật,
 * nút xuất file chạy thật, nhãn trạng thái tiếng Việt, tìm kiếm toàn cục.
 */
class UiSweepTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $admin;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở Ngọc Hà', 'code' => 'NH', 'address' => '15/172 Ngọc Hà', 'phone' => '0241111111', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'Cơ sở Đội Cấn', 'code' => 'DC', 'address' => '23/209 Đội Cấn', 'is_active' => true]);
        $this->admin = $this->makeUser('admin', $this->branch);
        $this->course = Course::create([
            'name' => 'IELTS Foundation', 'code' => 'IELTS-F', 'total_lessons' => 24,
            'tuition_fee' => 5000000, 'is_active' => true,
        ]);
    }

    private function makeUser(string $role, ?Branch $branch = null, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'branch_id' => $branch?->id,
            'is_active' => true,
        ], $attributes));
        $user->assignRole($role);

        return $user;
    }

    private function makeClass(Branch $branch, array $attributes = []): ClassModel
    {
        static $n = 0;
        $n++;

        return ClassModel::create(array_merge([
            'name' => "Lớp {$branch->code} {$n}",
            'code' => "{$branch->code}-{$n}",
            'course_id' => $this->course->id,
            'branch_id' => $branch->id,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2),
            'max_capacity' => 12,
            'status' => 'active',
        ], $attributes));
    }

    /** Chuỗi dữ liệu giả từng xuất hiện trên giao diện. */
    private const FAKE_STRINGS = [
        'Cầu Giấy', 'Nguyễn Văn A', 'John Doe', '0912 345 678', '1029384756',
        '12.500.000', '24 bản ghi', '96.8', '94.8', '4.85', 'Trần Thị B', 'Lê Thị Bích',
        'Nguyễn Thị Mai', 'Phòng 301', 'SV-2026', '1900 8899', 'THCS Nguyễn Du',
        'Flow 1 — Bước', '(Bước #', 'Lưu Database', '92% SLA',
    ];

    private function assertNoFakeData($response, string $where = ''): void
    {
        $content = $response->getContent();
        foreach (self::FAKE_STRINGS as $fake) {
            $this->assertStringNotContainsString($fake, $content, "Dữ liệu giả \"{$fake}\" còn ở {$where}");
        }
    }

    public function test_key_screens_render_without_fake_data(): void
    {
        $class = $this->makeClass($this->branch, ['schedule_text' => null, 'room' => null]);
        $teacher = $this->makeUser('teacher', $this->branch);

        $routes = [
            route('academic.dashboards.reports'),
            route('academic.dashboards.reports', ['tab' => 'weekly']),
            route('academic.dashboards.reports', ['tab' => 'monthly']),
            route('academic.dashboards.incidents'),
            route('classes.academic-list'),
            route('classes.academic-overview'),
            route('classes.academic-detail', ['id' => $class->id]),
            route('classes.profile', ['id' => $class->id]),
            route('classes.trial-booking'),
            route('classes.qa-observation'),
            route('classes.checklist'),
            route('classes.evaluate-observation'),
            route('users.show', $teacher),
            route('tasks.ta-assign'),
            route('tasks.class-reports.create'),
            route('placement-tests.create'),
            route('permissions.index'),
            route('tuition.history'),
            route('tuition.invoices.cancellations'),
            route('tuition.receipts.approve'),
            route('profile.edit'),
        ];

        foreach ($routes as $url) {
            $response = $this->actingAs($this->admin)->get($url);
            $this->assertSame(200, $response->status(), "GET {$url} => {$response->status()}");
            $this->assertNoFakeData($response, $url);
        }
    }

    public function test_academic_reports_dashboard_uses_real_attendance(): void
    {
        $class = $this->makeClass($this->branch);
        $students = collect(range(1, 4))->map(fn ($i) => Student::create([
            'code' => 'HV-UI-'.$i, 'name' => 'Học viên '.$i, 'branch_id' => $this->branch->id,
            'phone' => '090000000'.$i, 'current_class_id' => $class->id, 'status' => 'studying',
        ]));
        foreach ($students as $i => $student) {
            StudentAttendance::create([
                'class_id' => $class->id, 'student_id' => $student->id, 'user_id' => $this->admin->id,
                'session_date' => today(), 'status' => $i === 0 ? 'absent' : 'present',
            ]);
        }
        ClassReport::create([
            'class_id' => $class->id, 'reporter_id' => $this->admin->id, 'session_name' => 'Buổi 3',
            'session_date' => today(), 'topics_learned' => 'Unit 3 Reading', 'status' => 'pending_approval',
        ]);

        $this->actingAs($this->admin)->get(route('academic.dashboards.reports'))
            ->assertOk()
            ->assertSee('75%')          // 3/4 có mặt
            ->assertSee('3 / 4 HV')
            ->assertSee('Chờ xác nhận') // A6 Q8: báo cáo trực lớp không ảnh ở trạng thái "Chờ xác nhận"
            ->assertDontSee('pending_approval')
            ->assertDontSee('14 / 15 HV');
    }

    public function test_incidents_dashboard_filters_by_branch(): void
    {
        $creatorA = $this->makeUser('academic_staff', $this->branch);
        $creatorB = $this->makeUser('academic_staff', $this->otherBranch);
        SupportTicket::create(['code' => 'TK-A', 'title' => 'Sự cố chi nhánh A', 'category' => 'technical_issue', 'priority' => 'urgent', 'status' => 'open', 'creator_id' => $creatorA->id, 'description' => 'x']);
        SupportTicket::create(['code' => 'TK-B', 'title' => 'Sự cố chi nhánh B', 'category' => 'technical_issue', 'priority' => 'high', 'status' => 'open', 'creator_id' => $creatorB->id, 'description' => 'y']);

        $this->actingAs($this->admin)->get(route('academic.dashboards.incidents'))
            ->assertOk()->assertSee('Sự cố chi nhánh A')->assertSee('Sự cố chi nhánh B');

        $this->actingAs($this->admin)->get(route('academic.dashboards.incidents', ['branch_id' => $this->otherBranch->id]))
            ->assertOk()->assertDontSee('Sự cố chi nhánh A')->assertSee('Sự cố chi nhánh B');
    }

    public function test_academic_list_filters_by_program_and_paginates(): void
    {
        $this->makeClass($this->branch, ['program' => 'IELTS', 'name' => 'Lớp IELTS Thật']);
        $this->makeClass($this->branch, ['program' => 'Giao tiếp', 'name' => 'Lớp Giao tiếp Thật']);

        $this->actingAs($this->admin)->get(route('classes.academic-list', ['program' => 'IELTS']))
            ->assertOk()
            ->assertSee('Lớp IELTS Thật')
            ->assertDontSee('Lớp Giao tiếp Thật');
    }

    public function test_academic_overview_counts_real_classes_by_program_and_level(): void
    {
        $this->makeClass($this->branch, ['program' => 'Chương trình Thật', 'level' => 'Khối Thật']);
        $this->makeClass($this->branch, ['program' => 'Chương trình Thật', 'level' => 'Khối Thật']);

        $this->actingAs($this->admin)->get(route('classes.academic-overview'))
            ->assertOk()
            ->assertSee('Chương trình Thật')
            ->assertSee('Khối Thật')
            ->assertDontSee('Super Safari')
            ->assertDontSee('Band 6.5+');

        $this->actingAs($this->admin)->get(route('classes.academic-list', ['level' => 'Khối Thật']))
            ->assertOk()->assertSee('Chương trình Thật');
    }

    public function test_class_detail_and_profile_use_real_data_and_server_side_visibility(): void
    {
        $class = $this->makeClass($this->branch, ['name' => 'Lớp Hồ Sơ']);
        Student::create(['code' => 'HV-HS-1', 'name' => 'Học viên Hồ Sơ', 'phone' => '0933444555', 'branch_id' => $this->branch->id, 'current_class_id' => $class->id, 'status' => 'studying']);
        BigTest::create(['code' => 'BT-UI-1', 'title' => 'Big Test Giữa Kỳ Thật', 'class_id' => $class->id, 'test_type' => 'midterm', 'scheduled_at' => now()->addWeek(), 'status' => 'draft']);

        $this->actingAs($this->admin)->get(route('classes.academic-detail', ['id' => $class->id]))
            ->assertOk()
            ->assertSee('Big Test Giữa Kỳ Thật')
            ->assertSee('Chưa giao chặng')
            ->assertDontSee('Chặng 2 (Intermediate)')
            ->assertDontSee('Unit 5 - Buổi 12')
            ->assertDontSee('2023');

        $this->actingAs($this->admin)->get(route('classes.profile', ['id' => $class->id]))
            ->assertOk()->assertSee('0933444555')->assertDontSee('Góc nhìn');

        $teacher = $this->makeUser('teacher', $this->branch);
        $class->update(['teacher_id' => $teacher->id]);
        $this->actingAs($teacher)->get(route('classes.profile', ['id' => $class->id]))
            ->assertOk()->assertSee('Học viên Hồ Sơ')->assertDontSee('0933444555');
    }

    public function test_payroll_period_can_be_exported(): void
    {
        $staff = $this->makeUser('teacher', $this->branch, ['name' => 'GV Xuất Lương']);
        $period = PayrollPeriod::create([
            'code' => 'PR-2026-09', 'title' => 'Tháng 09/2026', 'month' => 9, 'year' => 2026,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'draft',
        ]);
        PayrollRecord::create([
            'payroll_period_id' => $period->id, 'user_id' => $staff->id, 'department' => 'academic',
            'base_salary' => 5000000, 'net_salary' => 5500000, 'status' => 'draft',
        ]);

        $response = $this->actingAs($this->admin)->get(route('payroll.periods.export', [$period->id, 'format' => 'csv']));
        $response->assertOk();
        $content = file_get_contents($response->baseResponse->getFile()->getPathname());
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('GV Xuất Lương', $content);

        $this->actingAs($this->admin)->get(route('payroll.periods.show', $period->id))
            ->assertOk()
            ->assertSee(route('payroll.periods.export', $period->id), false)
            ->assertDontSee('window.print();', false);
    }

    private function downloadedContent($response): string
    {
        $response->assertOk();

        return file_get_contents($response->baseResponse->getFile()->getPathname());
    }

    public function test_dashboard_kpi_and_schedule_reports_export_real_rows(): void
    {
        $teacher = $this->makeUser('teacher', $this->branch, ['name' => 'GV Xuất Lịch']);
        $class = $this->makeClass($this->branch, ['teacher_id' => $teacher->id, 'name' => 'Lớp Xuất Lịch']);
        \App\Models\ClassSession::create([
            'class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => today()->toDateString(),
            'start_time' => '18:00', 'end_time' => '19:30', 'room' => 'P.202', 'teacher_id' => $teacher->id, 'status' => 'scheduled',
        ]);

        $csv = $this->downloadedContent($this->actingAs($this->admin)->get(route('tasks.classes-dashboard', ['export' => 1, 'format' => 'csv', 'date' => today()->toDateString()])));
        $this->assertStringContainsString('Lớp Xuất Lịch', $csv);
        $this->assertStringContainsString('P.202', $csv);

        $csv = $this->downloadedContent($this->actingAs($this->admin)->get(route('tasks.kpi-dashboard', ['export' => 1, 'format' => 'csv'])));
        $this->assertStringContainsString('GV Xuất Lịch', $csv);

        $csv = $this->downloadedContent($this->actingAs($this->admin)->get(route('tasks.schedule-config', ['export' => 1, 'format' => 'csv', 'report_branch_id' => $this->branch->id])));
        $this->assertStringContainsString('Nhu cầu nhân sự', $csv);

        foreach (['tasks.classes-dashboard', 'tasks.kpi-dashboard', 'tasks.schedule-config'] as $route) {
            $this->actingAs($this->admin)->get(route($route))->assertOk()->assertDontSee('window.print()', false);
        }
    }

    public function test_payroll_period_list_is_filtered_server_side(): void
    {
        PayrollPeriod::create(['code' => 'PR-2026-07', 'title' => 'Tháng 07/2026', 'month' => 7, 'year' => 2026, 'start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'status' => 'paid']);
        PayrollPeriod::create(['code' => 'PR-2026-08', 'title' => 'Tháng 08/2026', 'month' => 8, 'year' => 2026, 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'draft']);

        $this->actingAs($this->admin)->get(route('payroll.periods.index', ['status' => 'draft']))
            ->assertOk()
            ->assertSee('PR-2026-08')
            ->assertDontSee('<td class="py-3.5 px-4 font-mono font-bold text-gray-900">PR-2026-07</td>', false);

        $this->actingAs($this->admin)->get(route('payroll.periods.index', ['search' => '07/2026']))
            ->assertOk()
            ->assertSee('1 kỳ lương');
    }

    public function test_syllabus_assignment_search_runs_on_the_server(): void
    {
        $this->actingAs($this->admin)->get(route('syllabus.assignments', ['search' => 'không-tồn-tại']))
            ->assertOk()
            ->assertSee('Lịch sử phân quyền chặng học')->assertSee('0 lượt')
            ->assertDontSee('filterAssignTable', false);
    }

    public function test_forms_show_field_level_validation_errors_and_keep_input(): void
    {
        $errors = (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag([
            'taskTitle' => ['Tiêu đề công việc là bắt buộc.'],
            'title' => ['Tiêu đề ticket là bắt buộc.'],
        ]));

        $this->actingAs($this->admin)
            ->withSession(['errors' => $errors, '_old_input' => ['taskDescription' => 'Nội dung đã nhập']])
            ->get(route('tasks.create'))
            ->assertOk()
            ->assertSee('Tiêu đề công việc là bắt buộc.')
            ->assertSee('Nội dung đã nhập');

        $this->actingAs($this->admin)
            ->withSession(['errors' => $errors])
            ->get(route('tickets.create'))
            ->assertOk()
            ->assertSee('Tiêu đề ticket là bắt buộc.');
    }

    public function test_global_search_finds_customers_students_and_classes_within_scope(): void
    {
        $classA = $this->makeClass($this->branch, ['name' => 'Lớp Tìm Kiếm A', 'code' => 'TK-A1']);
        $this->makeClass($this->otherBranch, ['name' => 'Lớp Tìm Kiếm B', 'code' => 'TK-B1']);
        Student::create(['code' => 'HV-TK-1', 'name' => 'Học viên Tìm Kiếm A', 'phone' => '0911222333', 'branch_id' => $this->branch->id, 'current_class_id' => $classA->id, 'status' => 'studying']);
        Student::create(['code' => 'HV-TK-2', 'name' => 'Học viên Tìm Kiếm B', 'phone' => '0911222444', 'branch_id' => $this->otherBranch->id, 'status' => 'studying']);
        CrmCustomer::create(['code' => 'KH-TK-A', 'name' => 'Khách Tìm Kiếm A', 'phone' => '0988777666', 'branch_id' => $this->branch->id, 'stage' => 'new']);
        CrmCustomer::create(['code' => 'KH-TK-B', 'name' => 'Khách Tìm Kiếm B', 'phone' => '0988777555', 'branch_id' => $this->otherBranch->id, 'stage' => 'new']);

        // Admin thấy tất cả
        $this->actingAs($this->admin)->get(route('search', ['q' => 'Tìm Kiếm']))
            ->assertOk()
            ->assertSee('Khách Tìm Kiếm A')->assertSee('Khách Tìm Kiếm B')
            ->assertSee('Học viên Tìm Kiếm B')->assertSee('Lớp Tìm Kiếm B');

        // Tìm theo SĐT
        $this->actingAs($this->admin)->get(route('search', ['q' => '0911 222 333']))
            ->assertOk()->assertSee('Học viên Tìm Kiếm A')->assertDontSee('Học viên Tìm Kiếm B');

        // Quản lý cơ sở chỉ thấy chi nhánh mình
        $manager = $this->makeUser('manager', $this->branch);
        $this->actingAs($manager)->get(route('search', ['q' => 'Tìm Kiếm']))
            ->assertOk()
            ->assertSee('Khách Tìm Kiếm A')->assertDontSee('Khách Tìm Kiếm B')
            ->assertSee('Học viên Tìm Kiếm A')->assertDontSee('Học viên Tìm Kiếm B')
            ->assertSee('Lớp Tìm Kiếm A')->assertDontSee('Lớp Tìm Kiếm B');

        // Học viên không có quyền tìm các nhóm này
        $studentUser = $this->makeUser('student', $this->branch);
        $this->actingAs($studentUser)->get(route('search', ['q' => 'Tìm Kiếm']))
            ->assertOk()->assertDontSee('Khách Tìm Kiếm A')->assertDontSee('Học viên Tìm Kiếm A');
    }

    public function test_topbar_has_global_search_and_quick_create(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('action="'.route('search').'"', false)
            ->assertSee('Tạo mới');
    }

    public function test_sidebar_menu_has_no_duplicate_labels(): void
    {
        // Tab trùng tên giữa các workspace là bình thường; tên đầy đủ "Workspace › Tab" + mục Cài đặt thì không được trùng.
        $menu = app(SidebarMenu::class);
        $labels = collect($menu->definition())
            ->flatMap(fn ($group) => collect($group['items'])->map(fn ($item) => $group['label'].' › '.$item['label']))
            ->merge(collect($menu->settingsDefinition())->flatMap(fn ($section) => collect($section['items'])->map(fn ($item) => 'Cài đặt › '.$item['label'])))
            ->merge(collect($menu->definition())->pluck('label'));
        $duplicates = $labels->countBy()->filter(fn ($n) => $n > 1)->keys()->all();
        $this->assertSame([], $duplicates, 'Nhãn menu bị lặp: '.implode(', ', $duplicates));
    }

    public function test_status_codes_are_shown_as_vietnamese_labels(): void
    {
        $this->assertSame('Chờ duyệt', \App\Support\StatusLabel::for('pending_review'));
        $this->assertSame('Hợp lệ', \App\Support\StatusLabel::for('valid'));
        $this->assertSame('Chưa cập nhật', \App\Support\StatusLabel::for(null));
    }

    public function test_center_phone_comes_from_settings(): void
    {
        \App\Models\SystemSetting::set('center_phone', '0999 000 111');
        $this->assertSame('0999 000 111', \App\Support\CenterInfo::phone());
    }
}
