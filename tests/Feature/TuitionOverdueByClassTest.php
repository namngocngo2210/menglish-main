<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** TK-2026-0084: "Công nợ theo lớp" chỉ Admin thấy, có MAX ngày quá hạn + số HV chưa đóng đủ, lọc theo lớp. */
class TuitionOverdueByClassTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ClassModel $classA;

    private ClassModel $classB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'CN Đống Đa', 'code' => 'DD', 'address' => 'x', 'phone' => '024', 'is_active' => true]);
        $course = Course::create(['code' => 'C1', 'name' => 'Khoá 1', 'tuition_fee' => 1000000, 'duration_months' => 3, 'is_active' => true]);
        $this->classA = ClassModel::create(['code' => 'A', 'name' => 'Lớp A', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
        $this->classB = ClassModel::create(['code' => 'B', 'name' => 'Lớp B', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'status' => 'active']);

        $this->debt($this->classA, 'HV-A1', 10);
        $this->debt($this->classA, 'HV-A2', 3);
        $this->debt($this->classB, 'HV-B1', 5);
    }

    private function debt(ClassModel $class, string $code, int $daysOverdue): void
    {
        $student = Student::create(['code' => $code, 'name' => "Học viên {$code}", 'phone' => '09'.random_int(10000000, 99999999), 'branch_id' => $this->branch->id, 'current_class_id' => $class->id, 'status' => 'studying']);
        StudentTuition::create([
            'student_id' => $student->id, 'class_id' => $class->id, 'branch_id' => $this->branch->id,
            'total_amount' => 1000000, 'discount_amount' => 0, 'final_amount' => 1000000, 'paid_amount' => 0, 'debt_amount' => 1000000,
            'due_date' => now()->subDays($daysOverdue), 'status' => 'unpaid',
        ]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    public function test_admin_gets_class_stats_with_max_overdue_days_and_student_count(): void
    {
        $this->actingAs($this->user(Roles::ADMIN))->get(route('tuition.overdue'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('showClassStats', true)
                ->has('statsByClass', 2)
                ->where('statsByClass.0.class_name', fn ($v) => in_array($v, ['Lớp A', 'Lớp B'], true))
                ->where('statsByClass', fn ($rows) => collect($rows)->firstWhere('class_name', 'Lớp A')['max_overdue_days'] === 10
                    && collect($rows)->firstWhere('class_name', 'Lớp A')['count'] === 2
                    && collect($rows)->firstWhere('class_name', 'Lớp A')['branch_name'] === 'CN Đống Đa'
                    && collect($rows)->firstWhere('class_name', 'Lớp B')['max_overdue_days'] === 5)
                ->has('statsByBranch', 1));
    }

    public function test_academic_staff_receives_no_class_stats(): void
    {
        $this->actingAs($this->user(Roles::ACADEMIC_STAFF))->get(route('tuition.overdue'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('showClassStats', false)
                ->has('statsByClass', 0)
                ->has('statsByBranch', 1));
    }

    public function test_class_filter_limits_overdue_rows_to_selected_class(): void
    {
        $this->actingAs($this->user(Roles::ADMIN))->get(route('tuition.overdue', ['class_id' => $this->classB->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('overdueCount', 1)
                ->where('newOverdue.0.student.code', 'HV-B1'));
    }
}
