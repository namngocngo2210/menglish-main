<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\TeachingShift;
use App\Models\User;
use App\Services\StaffAttendance\StaffAttendanceService;
use Carbon\CarbonInterface;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Khung giờ ca dạy (file "Khung giờ chấm công ME"): Thứ 2–6 chỉ Ca 1 / Ca 2 (chuẩn hoặc lệch), cuối tuần theo giờ lớp,
 * mỗi ca 90 phút, chấm công GV đối chiếu giờ lớp được phân công.
 */
class TeachingShiftTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $teacher;

    private Branch $branch;

    private ClassModel $classModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-TS', 'is_active' => true]);
        $this->admin = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $this->admin->assignRole('admin');
        $this->manager = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $this->manager->assignRole('manager');
        $this->teacher = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $this->teacher->assignRole('teacher');

        $course = Course::create(['name' => 'IELTS TS', 'code' => 'IELTS-TS', 'total_lessons' => 24, 'is_active' => true]);
        $this->classModel = ClassModel::create([
            'name' => 'Lớp TS', 'code' => 'TS-01', 'course_id' => $course->id,
            'program' => $course->name, 'level' => 'B1', 'branch_id' => $this->branch->id,
            'teacher_id' => $this->teacher->id, 'status' => 'pending_schedule',
        ]);
    }

    private function nextDay(int $isoWeekday): CarbonInterface
    {
        $date = now()->addDay()->startOfDay();
        while ($date->isoWeekday() !== $isoWeekday) {
            $date->addDay();
        }

        return $date;
    }

    private function schedule(array $slot1, array $slot2 = ['', '', '']): TestResponse
    {
        return $this->actingAs($this->manager)->from(route('tasks.schedule-config'))->post(route('tasks.schedule-config.update'), [
            'class_id' => $this->classModel->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(20)->toDateString(),
            'slot1_day' => $slot1[0], 'slot1_start' => $slot1[1], 'slot1_end' => $slot1[2],
            'slot2_day' => $slot2[0], 'slot2_start' => $slot2[1], 'slot2_end' => $slot2[2],
        ]);
    }

    public function test_migration_seeds_frames_from_the_file(): void
    {
        $weekday = TeachingShift::where('day_type', 'weekday')->orderBy('start_time')->get();
        $this->assertSame(['Ca 1', 'Ca 2'], $weekday->pluck('name')->all());
        $this->assertSame('18:00–19:30', $weekday[0]->standardRange());
        $this->assertSame('18:10–19:40', $weekday[0]->altRange());
        $this->assertSame('19:40–21:10', $weekday[1]->altRange());
        $this->assertSame(4, TeachingShift::where('day_type', 'saturday')->count());
        $this->assertSame(5, TeachingShift::where('day_type', 'sunday')->count());
    }

    public function test_weekday_schedule_with_ca_1_offset_names_sessions_ca_1(): void
    {
        $this->schedule(['Thứ 2', '18:10', '19:40'], ['Thứ 4', '19:30', '21:00'])->assertSessionHasNoErrors();

        $sessions = ClassSession::where('class_id', $this->classModel->id)->get();
        $this->assertNotEmpty($sessions);
        $monday = $sessions->first(fn (ClassSession $s) => $s->date->isoWeekday() === 1);
        $wednesday = $sessions->first(fn (ClassSession $s) => $s->date->isoWeekday() === 3);
        $this->assertSame('Ca 1', $monday->shift_name);
        $this->assertSame('18:10', $monday->start_time->format('H:i'));
        $this->assertSame('Ca 2', $wednesday->shift_name);
        $this->assertStringContainsString('Thứ 2 Ca 1 18:10-19:40', $this->classModel->fresh()->schedule_text);
    }

    public function test_weekday_outside_ca_1_ca_2_is_rejected(): void
    {
        $this->schedule(['Thứ 3', '18:30', '20:00'])
            ->assertSessionHasErrors(['slot1_start' => 'Thứ 3 chỉ có Ca 1 (18:00–19:30 hoặc 18:10–19:40) và Ca 2 (19:30–21:00 hoặc 19:40–21:10). Chọn lại ca theo khung giờ.']);
        $this->assertSame(0, ClassSession::where('class_id', $this->classModel->id)->count());
    }

    public function test_every_shift_must_be_90_minutes(): void
    {
        $this->schedule(['Thứ 7', '15:00', '17:00'])
            ->assertSessionHasErrors(['slot1_start' => 'Thứ 7: mỗi ca dạy 90 phút — ca bắt đầu 15:00 phải kết thúc lúc 16:30.']);
    }

    public function test_weekend_takes_the_class_actual_time(): void
    {
        $this->schedule(['Thứ 7', '17:30', '19:00'], ['Chủ nhật', '09:00', '10:30'])->assertSessionHasNoErrors();

        $sessions = ClassSession::where('class_id', $this->classModel->id)->get();
        $saturday = $sessions->first(fn (ClassSession $s) => $s->date->isoWeekday() === 6);
        $sunday = $sessions->first(fn (ClassSession $s) => $s->date->isoWeekday() === 7);
        $this->assertSame('Ca chiều', $saturday->shift_name); // giờ lệch của khung 17:00–18:30
        $this->assertSame('Ca theo TKB', $sunday->shift_name);
        $this->assertSame('09:00', $sunday->start_time->format('H:i'));
    }

    public function test_admin_manages_frames_and_new_frame_becomes_schedulable(): void
    {
        $this->actingAs($this->admin)->post(route('teaching-shifts.store'), [
            'day_type' => 'weekday', 'name' => 'Ca sáng', 'start_time' => '08:00', 'end_time' => '09:30',
        ])->assertSessionHasNoErrors();

        $this->schedule(['Thứ 5', '08:00', '09:30'])->assertSessionHasNoErrors();
        $this->assertSame('Ca sáng', ClassSession::where('class_id', $this->classModel->id)->value('shift_name'));

        $this->actingAs($this->admin)->post(route('teaching-shifts.store'), [
            'day_type' => 'weekday', 'name' => 'Sai', 'start_time' => '08:00', 'end_time' => '10:00',
        ])->assertSessionHasErrors('end_time');

        $shift = TeachingShift::where('name', 'Ca sáng')->first();
        $this->actingAs($this->admin)->delete(route('teaching-shifts.destroy', $shift))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($shift);
    }

    public function test_only_admin_can_change_frames_but_schedulers_can_view(): void
    {
        $this->actingAs($this->manager)->get(route('teaching-shifts.index'))->assertOk()->assertSee('Khung giờ ca dạy')->assertSee('18:10–19:40');
        $this->actingAs($this->manager)->post(route('teaching-shifts.store'), [
            'day_type' => 'weekday', 'name' => 'Ca 3', 'start_time' => '21:00', 'end_time' => '22:30',
        ])->assertForbidden();
        $this->actingAs($this->teacher)->get(route('teaching-shifts.index'))->assertForbidden();
    }

    public function test_off_schedule_classes_are_listed_for_rescheduling(): void
    {
        ClassSession::create([
            'class_id' => $this->classModel->id, 'branch_id' => $this->branch->id,
            'date' => $this->nextDay(2)->toDateString(), 'shift_name' => 'Slot 1',
            'start_time' => '18:00', 'end_time' => '20:00', 'teacher_id' => $this->teacher->id, 'status' => 'scheduled',
        ]);

        $this->actingAs($this->manager)->get(route('tasks.schedule-config'))
            ->assertOk()
            ->assertSee('Lớp có buổi sắp tới lệch khung giờ ca dạy')
            ->assertSee('Thứ 3 18:00–20:00');
    }

    public function test_teacher_check_in_compares_with_assigned_class_time(): void
    {
        $monday = $this->nextDay(1);
        ClassSession::create([
            'class_id' => $this->classModel->id, 'branch_id' => $this->branch->id,
            'date' => $monday->toDateString(), 'shift_name' => 'Ca 1',
            'start_time' => '18:10', 'end_time' => '19:40', 'teacher_id' => $this->teacher->id, 'status' => 'scheduled',
        ]);

        $expected = app(StaffAttendanceService::class)->expectedTimes($this->teacher, $this->branch, Carbon::parse($monday));

        $this->assertSame('18:10', $expected['start']);
        $this->assertSame('19:40', $expected['end']);
        $this->assertSame('Theo giờ lớp được phân công', $expected['basis']);
        $this->assertSame([['shift' => 'Ca 1', 'time' => '18:10–19:40', 'class' => 'TS-01']], $expected['sessions']);
    }
}
