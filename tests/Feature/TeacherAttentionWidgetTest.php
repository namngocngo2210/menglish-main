<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\MiniTestScore;
use App\Models\Student;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** TK-2026-0067 (US R-11, AC-R10, AC-S06, AC-S06b/c/d): widget "Học sinh cần chú ý" của cổng GV. */
class TeacherAttentionWidgetTest extends TestCase
{
    use RefreshDatabase;

    private User $an;

    private User $other;

    private ClassModel $classA;

    private ClassModel $classB;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00'));
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-ATT', 'is_active' => true]);
        $this->an = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $this->an->assignRole('teacher_fulltime');
        $this->other = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $this->other->assignRole('teacher');
        $course = Course::create(['name' => 'Kids ATT', 'code' => 'KIDS-ATT', 'total_lessons' => 24, 'is_active' => true]);
        $this->classA = $this->makeClass('K27', 'ATT-A', $course, $this->an);
        $this->classB = $this->makeClass('K28', 'ATT-B', $course, $this->other);
    }

    private function makeClass(string $name, string $code, Course $course, User $teacher): ClassModel
    {
        return ClassModel::create([
            'name' => $name, 'code' => $code, 'course_id' => $course->id, 'program' => $course->name, 'level' => 'KID-BEG-01',
            'branch_id' => $this->branch->id, 'teacher_id' => $teacher->id, 'status' => 'active', 'max_capacity' => 12,
            'start_date' => '2026-09-01', 'end_date' => '2026-12-31', 'schedule_text' => 'Thứ 4 08:00-09:30',
        ]);
    }

    private function student(string $name, ClassModel $class): Student
    {
        static $n = 0;
        $n++;

        return Student::create(['code' => 'HV-ATT-'.$n, 'name' => $name, 'phone' => '09130000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'branch_id' => $this->branch->id, 'current_class_id' => $class->id, 'status' => 'studying']);
    }

    private function score(Student $s, ClassModel $class, float $total, ?array $skills = null, float $max = 10, string $name = 'Unit 1', string $testDate = '2026-10-05'): MiniTestScore
    {
        return MiniTestScore::create(['class_id' => $class->id, 'student_id' => $s->id, 'user_id' => $this->an->id, 'name' => $name,
            'score' => $total, 'max_score' => $max, 'skill_scores' => $skills, 'test_date' => $testDate]);
    }

    private function attention(User $teacher): array
    {
        return $this->actingAs($teacher)->get(route('teacher.home'))->assertOk()->inertiaProps('attention');
    }

    public function test_default_threshold_is_60_percent_not_70(): void
    {
        $flagged = $this->student('Dưới 60', $this->classA);
        $ok = $this->student('Đúng 65', $this->classA);
        $this->score($flagged, $this->classA, 5.5);
        $this->score($ok, $this->classA, 6.5);

        $rows = $this->attention($this->an);

        $this->assertSame(['Dưới 60'], array_column($rows, 'student_name'));
        $this->assertSame(55, $rows[0]['percent']);
    }

    public function test_threshold_comes_from_config_key(): void
    {
        $s = $this->student('Cấu hình', $this->classA);
        $this->score($s, $this->classA, 6.5);
        $this->assertSame([], $this->attention($this->an));

        SystemSetting::set('nguong_diem_can_chu_y_pct', 70);

        $this->assertSame(['Cấu hình'], array_column($this->attention($this->an), 'student_name'));
    }

    public function test_any_component_below_threshold_flags_student_even_if_total_is_ok(): void
    {
        $khang = $this->student('Đoàn Minh Khang', $this->classA);
        // Tổng 7.25/10 nhưng Viết 5/10 (50%).
        $this->score($khang, $this->classA, 7.25, ['listening' => 7, 'speaking' => 9, 'reading' => 8, 'writing' => 5]);

        $rows = $this->attention($this->an);

        $this->assertSame(['Đoàn Minh Khang'], array_column($rows, 'student_name'));
        $this->assertSame(50, $rows[0]['percent']);
        $this->assertArrayNotHasKey('score', $rows[0]);
    }

    public function test_component_percent_uses_the_denominator_entered_for_the_row(): void
    {
        $s = $this->student('Mẫu số 20', $this->classA);
        // Thang 20: tổng 14/20 = 70%, Viết 11/20 = 55% → gắn cờ; Viết 12/20 = 60% → không (không dưới ngưỡng).
        $this->score($s, $this->classA, 14, ['listening' => 15, 'speaking' => 15, 'reading' => 15, 'writing' => 11], 20);
        $this->assertSame(55, $this->attention($this->an)[0]['percent']);

        MiniTestScore::query()->delete();
        $this->score($s, $this->classA, 14, ['listening' => 15, 'speaking' => 15, 'reading' => 14, 'writing' => 12], 20);
        $this->assertSame([], $this->attention($this->an));
    }

    public function test_only_latest_created_test_counts_no_30_day_window(): void
    {
        $recovered = $this->student('Đã khá lên', $this->classA);
        $this->score($recovered, $this->classA, 4, null, 10, 'Unit 1');
        $this->score($recovered, $this->classA, 8, null, 10, 'Unit 2');
        $old = $this->student('Bài cũ 90 ngày', $this->classA);
        $this->score($old, $this->classA, 3, null, 10, 'Unit 0', '2026-07-01');

        $names = array_column($this->attention($this->an), 'student_name');

        $this->assertSame(['Bài cũ 90 ngày'], $names); // bài mới nhất của "Đã khá lên" đạt → bỏ; bài cũ nhưng là mới nhất → vẫn cờ
    }

    public function test_transferred_student_only_shows_for_current_class_teacher(): void
    {
        $ngoc = $this->student('Lê Bảo Ngọc', $this->classA);
        $this->score($ngoc, $this->classA, 4); // bài ở lớp cũ (K27)
        $ngoc->update(['current_class_id' => $this->classB->id]); // chuyển sang K28

        $this->assertSame([], $this->attention($this->an));
        $rows = $this->attention($this->other);
        $this->assertSame(['Lê Bảo Ngọc'], array_column($rows, 'student_name'));
        $this->assertSame('K28', $rows[0]['class_name']);
    }

    public function test_transferred_student_latest_test_from_old_class_is_still_considered(): void
    {
        $s = $this->student('Chuyển lớp', $this->classA);
        $this->score($s, $this->classA, 9, null, 10, 'Unit 1');
        $s->update(['current_class_id' => $this->classB->id]);
        $this->score($s, $this->classA, 4, null, 10, 'Unit 2'); // mới nhất vẫn là bài ở lớp cũ

        $this->assertSame(['Chuyển lớp'], array_column($this->attention($this->other), 'student_name'));
    }

    public function test_overview_dashboard_uses_same_rule(): void
    {
        \App\Models\ClassSession::create(['class_id' => $this->classA->id, 'branch_id' => $this->branch->id, 'date' => '2026-10-07', 'shift_name' => 'Ca 1', 'start_time' => '17:30', 'end_time' => '19:00', 'teacher_id' => $this->an->id, 'status' => 'scheduled']);
        $khang = $this->student('Khang Overview', $this->classA);
        $this->score($khang, $this->classA, 7.25, ['listening' => 7, 'speaking' => 9, 'reading' => 8, 'writing' => 5], 10, 'Mini Test Unit 2');
        $ngoc = $this->student('Ngọc Overview', $this->classA);
        $this->score($ngoc, $this->classA, 4);
        $ngoc->update(['current_class_id' => $this->classB->id]);

        $attention = $this->actingAs($this->an)->get(route('dashboard'))->assertOk()->inertiaProps('overview')['shifts']['attention'];

        $this->assertSame(['Khang Overview'], array_column($attention, 'name'));
        $this->assertSame('Mini Test Unit 2: 50%', $attention[0]['why']);
    }
}
