<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** TK-2026-0029 / O-37: một lớp không bao giờ có nhiều buổi (chưa hủy) hơn courses.total_lessons. */
class SessionsCappedAtCoursePlanTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Branch $branch;

    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-CAP', 'is_active' => true]);
        $this->manager = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $this->manager->assignRole('manager');
        $teacher = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $teacher->assignRole('teacher');

        $course = Course::create(['name' => 'Khóa CAP', 'code' => 'CAP-6', 'total_lessons' => 6, 'is_active' => true]);
        $this->class = ClassModel::create([
            'name' => 'Lớp CAP', 'code' => 'CAP-01', 'course_id' => $course->id, 'program' => $course->name,
            'level' => 'B1', 'branch_id' => $this->branch->id, 'teacher_id' => $teacher->id, 'status' => 'active',
            'start_date' => now()->subWeeks(4)->toDateString(), 'end_date' => now()->addWeeks(12)->toDateString(),
        ]);
        // 4 buổi đã dạy (lịch sử, không thay được) → còn 2 buổi theo kế hoạch 6 buổi.
        foreach (range(1, 4) as $w) {
            ClassSession::create([
                'class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => now()->subWeeks($w)->toDateString(),
                'shift_name' => 'Slot 1', 'start_time' => '18:00', 'end_time' => '19:30', 'status' => 'completed',
            ]);
        }
    }

    private function nonCancelled(): int
    {
        return ClassSession::where('class_id', $this->class->id)->where('status', '!=', 'cancelled')->count();
    }

    private function saveSchedule(string $day, string $start = '18:00', string $end = '19:30'): void
    {
        $this->actingAs($this->manager)->post(route('tasks.schedule-config.update'), [
            'class_id' => $this->class->id,
            'start_date' => now()->subWeeks(4)->toDateString(), 'end_date' => now()->addWeeks(12)->toDateString(),
            'slot1_day' => $day, 'slot1_start' => $start, 'slot1_end' => $end, 'slot2_day' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_schedule_config_generates_only_the_remaining_lessons(): void
    {
        $this->saveSchedule(['Chủ nhật', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7'][now()->addDay()->dayOfWeek]);

        $this->assertSame(6, $this->nonCancelled());
    }

    public function test_regenerating_after_schedule_config_change_does_not_exceed_plan(): void
    {
        $days = ['Chủ nhật', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7'];
        $this->saveSchedule($days[now()->addDay()->dayOfWeek]);
        $this->saveSchedule($days[now()->addDays(2)->dayOfWeek], '19:30', '21:00');
        $this->saveSchedule($days[now()->addDays(3)->dayOfWeek]);

        $this->assertSame(6, $this->nonCancelled());
    }

    public function test_class_with_full_plan_gets_no_extra_sessions_on_resave(): void
    {
        foreach (range(1, 2) as $w) {
            ClassSession::create([
                'class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => now()->subDays($w)->toDateString(),
                'shift_name' => 'Slot 1', 'start_time' => '09:00', 'end_time' => '10:30', 'status' => 'completed',
            ]);
        }

        $this->saveSchedule(['Chủ nhật', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7'][now()->addDay()->dayOfWeek]);

        $this->assertSame(6, $this->nonCancelled());
    }

    public function test_class_create_truncates_client_schedule_to_the_plan(): void
    {
        $sessions = collect(range(1, 9))->map(fn (int $i) => [
            'date' => now()->addDays($i)->toDateString(), 'shift' => 'Ca 1', 'start' => '18:00', 'end' => '19:30', 'room' => '',
        ])->all();

        $this->actingAs($this->manager)->post(route('classes.store'), [
            'ten_lop' => 'Lớp mới', 'ma_lop' => 'CAP-NEW', 'chi_nhanh' => $this->branch->id,
            'chuong_trinh' => 'Khóa CAP', 'cap_do' => 'B1', 'si_so_toi_da' => 12,
            'schedule_sessions_json' => json_encode($sessions),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(6, ClassModel::where('code', 'CAP-NEW')->firstOrFail()->sessions()->count());
    }
}
