<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** TK-2026-0140: hồ sơ học viên gate lớp/buổi học theo class.view và điểm danh theo attendance_student.view (kể cả override). */
class StudentProfileGatingTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $branch = Branch::create(['name' => 'CN Hà Nội', 'code' => 'HN', 'address' => 'HN', 'phone' => '0241111', 'is_active' => true]);
        $course = Course::create(['code' => 'C1', 'name' => 'Khóa 1', 'tuition_fee' => 1000000, 'duration_months' => 3, 'is_active' => true]);
        $class = ClassModel::create(['code' => 'L1', 'name' => 'Lớp L1', 'course_id' => $course->id, 'branch_id' => $branch->id, 'status' => 'active']);
        $this->student = Student::create(['code' => 'HV-G1', 'name' => 'Học viên G1', 'phone' => '0900000001', 'branch_id' => $branch->id, 'current_class_id' => $class->id, 'status' => 'studying']);
        ClassEnrollment::create(['student_id' => $this->student->id, 'class_id' => $class->id, 'enrolled_at' => '2026-08-01', 'status' => 'active']);
        $session = ClassSession::create(['class_id' => $class->id, 'branch_id' => $branch->id, 'date' => '2026-09-01', 'start_time' => '08:00', 'end_time' => '09:30', 'status' => 'completed']);
        StudentAttendance::create(['class_id' => $class->id, 'class_session_id' => $session->id, 'student_id' => $this->student->id, 'session_date' => '2026-09-01', 'status' => 'present']);

        $this->viewer = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->viewer->assignRole('academic_staff');
    }

    private function revoke(string $module): void
    {
        UserPermissionOverride::create([
            'user_id' => $this->viewer->id, 'module' => $module, 'action' => 'view',
            'scope_type' => UserPermissionOverride::SCOPE_ALL, 'allow' => false, 'created_by' => $this->viewer->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function props(string $routeName): array
    {
        // Gọi như request Inertia XHR (JSON) để không phải dựng bundle SSR.
        $response = $this->actingAs($this->viewer)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())])
            ->get(route($routeName, $this->student->id))
            ->assertOk();

        return $response->json('props');
    }

    private const CLASS_KEYS = ['classes', 'sessions', 'mainClass', 'linkableClasses'];

    private const ATTENDANCE_KEYS = ['attendances', 'attendanceStats'];

    public static function pages(): array
    {
        return [['students.show'], ['students.scoped']];
    }

    #[DataProvider('pages')]
    public function test_granted_viewer_gets_classes_and_attendance(string $routeName): void
    {
        $props = $this->props($routeName);

        foreach (array_merge(self::CLASS_KEYS, self::ATTENDANCE_KEYS) as $key) {
            $this->assertArrayHasKey($key, $props, $key);
        }
        $this->assertTrue($props['canViewClasses']);
        $this->assertTrue($props['canViewAttendance']);
        $this->assertTrue($props['canViewAcademic']);
        $this->assertNotNull($props['sessions'][0]['attendance']);
    }

    #[DataProvider('pages')]
    public function test_revoked_class_view_omits_class_sections_but_keeps_attendance(string $routeName): void
    {
        $this->revoke('class');
        $props = $this->props($routeName);

        foreach (self::CLASS_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $props, $key);
        }
        $this->assertArrayHasKey('attendances', $props);
        $this->assertTrue($props['attendances'][0]['off_schedule']);
        $this->assertFalse($props['canViewClasses']);
        $this->assertTrue($props['canViewAcademic']);
    }

    #[DataProvider('pages')]
    public function test_revoked_attendance_view_omits_attendance_everywhere(string $routeName): void
    {
        $this->revoke('attendance_student');
        $props = $this->props($routeName);

        foreach (self::ATTENDANCE_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $props, $key);
        }
        $this->assertArrayHasKey('sessions', $props);
        // Guard: trạng thái điểm danh không được lọt qua từng buổi học.
        $this->assertNull($props['sessions'][0]['attendance']);
        $this->assertFalse($props['canViewAttendance']);
    }

    #[DataProvider('pages')]
    public function test_both_revoked_hides_academic_and_leaks_nothing(string $routeName): void
    {
        $this->revoke('class');
        $this->revoke('attendance_student');
        $props = $this->props($routeName);

        foreach (array_merge(self::CLASS_KEYS, self::ATTENDANCE_KEYS, ['care', 'results', 'feedback']) as $key) {
            $this->assertArrayNotHasKey($key, $props, $key);
        }
        $this->assertFalse($props['canViewAcademic']);
        // Tên lớp chính vẫn nằm trong thông tin hành chính của học viên (không phải mục "Lớp & lịch học").
        $this->assertArrayNotHasKey('mainClass', $props);
    }

    public function test_tuition_gating_unchanged(): void
    {
        $this->revoke('class');
        $props = $this->props('students.show');

        $this->assertSame($this->viewer->can('tuition.view'), $props['canViewTuition']);
        $this->assertSame($this->viewer->can('tuition.view'), array_key_exists('tuition', $props));
    }
}
