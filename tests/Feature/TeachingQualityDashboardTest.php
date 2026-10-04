<?php

namespace Tests\Feature;

use App\Models\AcademicRecord;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Homework;
use App\Models\MaterialOrder;
use App\Models\MiniTestScore;
use App\Models\Penalty;
use App\Models\StaffAttendanceRequest;
use App\Models\StaffReport;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Support\Dashboard\TeachingQuality;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tổng quan — chất lượng giảng dạy theo tháng (chủ dự án 04/10/2026): ngày công, đi muộn, phép, vi phạm, lớp giữ, học sinh,
 * chuyên cần / bài về nhà / điểm TB theo lớp; theo vai trò; báo cáo tháng theo lớp của giáo viên; bỏ lưới phím tắt.
 */
class TeachingQualityDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $teacher;

    private ClassModel $class;

    /** @var list<Student> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CSA', 'is_active' => true]);
        $this->teacher = $this->userWithRole('teacher', 'Cô Lan');
        $course = Course::create(['code' => 'STA', 'name' => 'Starters', 'tuition_fee' => 1, 'is_active' => true]);
        $this->class = ClassModel::create(['code' => 'ST1', 'name' => 'Lớp ST1', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'teacher_id' => $this->teacher->id, 'max_capacity' => 10, 'status' => 'active']);

        foreach (['An', 'Bình', 'Chi', 'Dũng'] as $i => $name) {
            $this->students[] = Student::create(['code' => 'HV-TQ-'.$i, 'name' => $name, 'phone' => '090000000'.$i, 'branch_id' => $this->branch->id, 'current_class_id' => $this->class->id, 'status' => 'studying']);
        }
        // Học sinh mới trong tháng + 1 học sinh thôi học trong tháng.
        ClassEnrollment::create(['student_id' => $this->students[3]->id, 'class_id' => $this->class->id, 'enrolled_at' => '2026-10-05', 'status' => 'completed']);
        $left = Student::create(['code' => 'HV-TQ-X', 'name' => 'Nghỉ', 'phone' => '0900000099', 'branch_id' => $this->branch->id, 'status' => 'dropped']);
        ClassEnrollment::create(['student_id' => $left->id, 'class_id' => $this->class->id, 'enrolled_at' => '2026-08-01', 'status' => Student::ENROLLMENT_DROPPED]);

        // Chuyên cần: 8 lượt, 6 có mặt / đi muộn → 75%. Tháng trước không tính.
        foreach (['2026-10-06', '2026-10-13'] as $date) {
            foreach ($this->students as $i => $student) {
                $status = $date === '2026-10-13' && $i < 2 ? ($i === 0 ? 'absent' : 'excused') : ($i === 3 ? 'late' : 'present');
                StudentAttendance::create(['class_id' => $this->class->id, 'student_id' => $student->id, 'session_date' => $date, 'status' => $status]);
            }
        }
        StudentAttendance::create(['class_id' => $this->class->id, 'student_id' => $this->students[0]->id, 'session_date' => '2026-09-20', 'status' => 'absent']);

        // Bài về nhà: 1 bài, 3/4 học sinh nộp trong hạn → 75%; bài nộp sau hạn không tính.
        $homework = Homework::create(['class_id' => $this->class->id, 'user_id' => $this->teacher->id, 'title' => 'Unit 1', 'due_at' => '2026-10-10 23:59:00']);
        $homework->forceFill(['created_at' => '2026-10-06 19:00:00'])->saveQuietly();
        foreach ([0, 1, 2] as $i) {
            $this->submission($this->students[$i], '2026-10-08 20:00:00');
        }
        $this->submission($this->students[3], '2026-10-15 20:00:00');

        // Điểm mini test: 8/10 và 30/50 (= 6) → TB 7.
        MiniTestScore::create(['class_id' => $this->class->id, 'student_id' => $this->students[0]->id, 'user_id' => $this->teacher->id, 'name' => 'MT1', 'score' => 8, 'max_score' => 10, 'test_date' => '2026-10-10']);
        MiniTestScore::create(['class_id' => $this->class->id, 'student_id' => $this->students[1]->id, 'user_id' => $this->teacher->id, 'name' => 'MT1', 'score' => 30, 'max_score' => 50, 'test_date' => '2026-10-10']);

        // Giờ dạy 3 ngày, 1 buổi muộn; 2 ngày phép đã duyệt; 1 vi phạm (1 biên bản đã hủy không tính).
        foreach (['2026-10-06' => 0, '2026-10-13' => 12, '2026-10-15' => 0] as $date => $late) {
            TeacherTimesheet::create(['user_id' => $this->teacher->id, 'class_id' => $this->class->id, 'teaching_date' => $date, 'hours' => 1.5, 'late_minutes' => $late, 'status' => 'approved']);
        }
        StaffAttendanceRequest::create(['user_id' => $this->teacher->id, 'branch_id' => $this->branch->id, 'type' => StaffAttendanceRequest::TYPE_LEAVE, 'date_from' => '2026-10-16', 'date_to' => '2026-10-17', 'reason' => 'Ốm', 'status' => StaffAttendanceRequest::STATUS_APPROVED]);
        Penalty::create(['code' => 'VP-1', 'user_id' => $this->teacher->id, 'violation_type' => 'Đi muộn', 'violation_date' => '2026-10-13', 'amount' => 0, 'status' => 'pending']);
        Penalty::create(['code' => 'VP-2', 'user_id' => $this->teacher->id, 'violation_type' => 'Đi muộn', 'violation_date' => '2026-10-14', 'amount' => 0, 'status' => 'cancelled']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_metrics_follow_the_documented_formulas(): void
    {
        $quality = new TeachingQuality('2026-10');
        $class = $quality->classMetrics($quality->classesOf($this->teacher))[0];

        $this->assertSame(4, $class['students']);
        $this->assertSame(1, $class['new']);
        $this->assertSame(1, $class['dropped']);
        $this->assertSame(75.0, $class['attendance']);
        $this->assertSame(75.0, $class['homework']);
        $this->assertSame(7.0, $class['score']);

        $this->assertSame(['work_days' => 3, 'late' => 1, 'leave_days' => 2, 'violations' => 1], $quality->workStats([$this->teacher->id])[$this->teacher->id]);

        // Tháng trước: không lẫn số liệu tháng này.
        $september = new TeachingQuality('2026-09');
        $this->assertSame(0.0, $september->classMetrics(collect([$this->class]))[0]['attendance']);
    }

    public function test_teacher_sees_own_numbers_and_shortcut_grid_is_gone(): void
    {
        $this->actingAs($this->teacher)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('teaching.mine.work.late', 1)
                ->where('teaching.mine.totals.classes', 1)
                ->where('teaching.mine.classes.0.attendance', 75)
                ->where('teaching.team', null)
                ->where('teaching.teacherReports', null)
                ->where('teaching.reportUrl', route('reports.periodic.teacher-monthly', ['month' => '2026-10']))
                ->missing('modules'))
            ->assertDontSee('Các Phân Hệ Chức Năng Của Bạn');
    }

    public function test_watchers_see_per_teacher_table_and_academic_sees_reports(): void
    {
        $admin = $this->userWithRole('admin', 'Admin');
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('teaching.mine', null)
                ->where('teaching.team.rows.0.name', 'Cô Lan')
                ->where('teaching.team.rows.0.homework', 75)
                ->where('teaching.team.rows.0.violations', 1)
                ->where('teaching.teacherReports.0.submitted', false));

        // Học vụ theo dõi giáo viên nhưng không đọc báo cáo tháng của giáo viên.
        $this->actingAs($this->userWithRole('academic_staff', 'Học vụ'))->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('teaching.team.rows.0.name', 'Cô Lan')->where('teaching.teacherReports', null));

        // Kế toán / Sales: không có khối giảng dạy.
        $this->actingAs($this->userWithRole('accountant', 'Kế toán'))->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('teaching', null));

        // Tháng khác qua ?month=.
        $this->actingAs($admin)->get(route('dashboard', ['month' => '2026-09']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('teaching.month', '2026-09')->where('teaching.team.rows.0.homework', null));
    }

    public function test_teacher_monthly_report_by_class_reaches_academic_dashboard(): void
    {
        $this->actingAs($this->teacher)->get(route('reports.periodic.teacher-monthly'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/TeacherMonthly')->where('classes.0.name', 'Lớp ST1'));

        $this->actingAs($this->teacher)->post(route('reports.periodic.teacher-monthly.store'), [
            'month' => '2026-10',
            'general' => ['progress' => 'Xong Unit 1-2', 'difficulties' => 'Lớp đông', 'proposals' => ''],
            'classes' => [
                $this->class->id => ['attention' => 'Bé An hay vắng', 'solution' => 'Gọi phụ huynh', 'need_support' => '1', 'support_note' => 'Cần TA kèm'],
                999 => ['attention' => 'Lớp không phải của tôi'],
            ],
        ])->assertSessionHasNoErrors();

        $report = StaffReport::sole();
        $this->assertSame('2026-10', $report->period_key);
        $this->assertSame(['progress' => 'Xong Unit 1-2', 'difficulties' => 'Lớp đông', 'proposals' => null], $report->data['general']);
        $this->assertSame([$this->class->id], array_keys($report->data['classes']));
        $this->assertTrue($report->data['classes'][$this->class->id]['need_support']);
        $this->assertStringContainsString('Lớp Lớp ST1', $report->content);

        MaterialOrder::create(['code' => 'OD-1', 'branch_id' => $this->branch->id, 'class_id' => $this->class->id, 'requester_id' => $this->teacher->id, 'category' => MaterialOrder::CATEGORY_ACADEMIC, 'title' => 'Đề test giữa kỳ', 'quantity' => 1, 'use_date' => '2026-10-25', 'due_at' => '2026-10-23 17:00:00', 'status' => MaterialOrder::STATUS_PENDING]);

        $lead = $this->userWithRole('academic_lead', 'Học thuật');
        $this->actingAs($lead)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('teaching.teacherReports.0.submitted', true)
                ->where('teaching.teacherReports.0.difficulties', 'Lớp đông')
                ->where('teaching.teacherReports.0.classes.0.class', 'Lớp ST1')
                ->where('teaching.teacherReports.0.classes.0.need_support', true)
                ->where('teaching.academicOrders.0.title', 'Đề test giữa kỳ'));

        // Trống hết → báo lỗi.
        $this->actingAs($this->teacher)->post(route('reports.periodic.teacher-monthly.store'), ['month' => '2026-10', 'general' => ['progress' => '']])
            ->assertSessionHasErrors('general');
    }

    public function test_dashboard_still_opens_when_a_teacher_role_was_deleted(): void
    {
        // Vai trò giáo viên đã xóa ở Cài đặt → Vai trò (vd. "Giáo viên Part-time"): Tổng quan từng lỗi 500 (RoleDoesNotExist).
        Role::whereIn('name', ['teacher_fulltime', 'teacher_parttime'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->userWithRole('admin', 'Admin'))->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('teaching.team.rows.0.name', 'Cô Lan'));
    }

    private function submission(Student $student, string $at): void
    {
        $record = AcademicRecord::create([
            'screen_key' => TeachingQuality::SUBMISSION_SCREEN, 'module' => 'student_portal', 'record_code' => 'SUB-'.uniqid(),
            'title' => 'Bài nộp', 'status' => 'submitted', 'data' => ['student_id' => (string) $student->id, 'homework_type' => 'workbook'],
        ]);
        $record->forceFill(['created_at' => $at])->saveQuietly();
    }

    private function userWithRole(string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
