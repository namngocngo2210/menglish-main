<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Holiday;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\SupportSession;
use App\Models\TeacherTimesheet;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleP0FixesTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $teacher;

    private User $otherTeacher;

    private Branch $branch;

    private ClassModel $classModel;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-SCH', 'is_active' => true]);
        $this->manager = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $this->manager->assignRole('manager');
        $this->teacher = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $this->teacher->assignRole('teacher');
        $this->otherTeacher = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $this->otherTeacher->assignRole('teacher');

        $course = Course::create(['name' => 'IELTS SCH', 'code' => 'IELTS-SCH', 'total_lessons' => 24, 'is_active' => true]);
        $this->classModel = ClassModel::create([
            'name' => 'Lớp SCH', 'code' => 'SCH-01', 'course_id' => $course->id,
            'program' => $course->name, 'level' => 'B1', 'branch_id' => $this->branch->id,
            'teacher_id' => $this->teacher->id, 'room' => 'P101', 'status' => 'active',
            'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addMonth()->toDateString(),
        ]);
        $this->student = Student::create([
            'name' => 'Học viên SCH', 'code' => 'HV-SCH', 'phone' => '0901000009',
            'current_class_id' => $this->classModel->id, 'branch_id' => $this->branch->id, 'status' => 'studying',
        ]);
    }

    private function makeSession(CarbonInterface $date, array $overrides = []): ClassSession
    {
        return ClassSession::create($overrides + [
            'class_id' => $this->classModel->id, 'branch_id' => $this->branch->id,
            'date' => $date->toDateString(), 'shift_name' => 'Slot 1',
            'start_time' => '08:00', 'end_time' => '09:30', 'room' => 'P101',
            'teacher_id' => $this->teacher->id, 'status' => 'scheduled',
        ]);
    }

    private function weekdayName(CarbonInterface $date): string
    {
        return ['Chủ nhật', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7'][$date->dayOfWeek];
    }

    private function updatePayload(array $overrides = []): array
    {
        return $overrides + [
            'ten_lop' => $this->classModel->name,
            'ma_lop' => $this->classModel->code,
            'chi_nhanh' => $this->branch->id,
            'chuong_trinh' => $this->classModel->program,
            'cap_do' => 'B1',
            'si_so_toi_da' => 12,
        ];
    }

    // ── 1. Class update must not rewrite history ──────────────────────────

    public function test_class_update_only_syncs_future_sessions_without_attendance_or_timesheet(): void
    {
        $past = $this->makeSession(now()->subDays(3));
        $today = $this->makeSession(now());
        TeacherTimesheet::create([
            'user_id' => $this->teacher->id, 'class_id' => $this->classModel->id, 'class_session_id' => $today->id,
            'teaching_date' => now()->toDateString(), 'hours' => 1.5, 'type' => 'regular', 'status' => 'pending_review',
        ]);
        $attended = $this->makeSession(now()->addDay());
        StudentAttendance::create([
            'class_id' => $this->classModel->id, 'class_session_id' => $attended->id, 'student_id' => $this->student->id,
            'user_id' => $this->teacher->id, 'session_date' => now()->addDay()->toDateString(), 'status' => 'present',
        ]);
        $future = $this->makeSession(now()->addDays(3));

        $this->actingAs($this->manager)->put(route('classes.update', $this->classModel->id), $this->updatePayload([
            'giao_vien_chinh' => $this->otherTeacher->id,
            'phong_hoc' => 'P202',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        foreach ([$past, $today, $attended] as $locked) {
            $locked->refresh();
            $this->assertSame($this->teacher->id, $locked->teacher_id);
            $this->assertSame('P101', $locked->room);
        }
        $future->refresh();
        $this->assertSame($this->otherTeacher->id, $future->teacher_id);
        $this->assertSame('P202', $future->room);
    }

    public function test_class_update_rejects_new_teacher_conflicting_with_future_sessions(): void
    {
        $date = now()->addDays(2);
        $this->makeSession($date);

        $otherClass = ClassModel::create([
            'name' => 'Lớp khác', 'code' => 'SCH-02', 'program' => 'IELTS SCH', 'branch_id' => $this->branch->id,
            'teacher_id' => $this->otherTeacher->id, 'room' => 'P303', 'status' => 'active',
        ]);
        ClassSession::create([
            'class_id' => $otherClass->id, 'branch_id' => $this->branch->id, 'date' => $date->toDateString(),
            'shift_name' => 'Ca 1', 'start_time' => '09:00', 'end_time' => '10:00', 'room' => 'P303',
            'teacher_id' => $this->otherTeacher->id, 'status' => 'scheduled',
        ]);

        $this->actingAs($this->manager)->put(route('classes.update', $this->classModel->id), $this->updatePayload([
            'giao_vien_chinh' => $this->otherTeacher->id,
        ]))->assertSessionHasErrors('giao_vien_chinh');

        $this->assertSame($this->teacher->id, $this->classModel->fresh()->teacher_id);

        // Đổi phòng sang phòng đang bị lớp khác chiếm cũng bị từ chối
        $this->actingAs($this->manager)->put(route('classes.update', $this->classModel->id), $this->updatePayload([
            'phong_hoc' => 'P303',
        ]))->assertSessionHasErrors('phong_hoc');
        $this->assertSame('P101', $this->classModel->fresh()->room);
    }

    // ── 2. Schedule re-save must not duplicate or delete history ──────────

    public function test_schedule_resave_does_not_duplicate_past_sessions(): void
    {
        $start = now()->subDays(14);
        $end = now()->addDays(14);
        $day = $this->weekdayName(now()->addDay());
        // Các buổi quá khứ đã có (sinh từ lần xếp lịch trước)
        foreach (range(1, 14) as $offset) {
            $date = now()->subDays($offset);
            if ($date->dayOfWeek === now()->addDay()->dayOfWeek) {
                $this->makeSession($date);
            }
        }
        $pastBefore = ClassSession::whereDate('date', '<', now()->toDateString())->count();
        $this->assertGreaterThan(0, $pastBefore);

        $this->actingAs($this->manager)->post(route('tasks.schedule-config.update'), [
            'class_id' => $this->classModel->id,
            'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
            'slot1_day' => $day, 'slot1_start' => '08:00', 'slot1_end' => '09:30',
            'slot2_day' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($pastBefore, ClassSession::whereDate('date', '<', now()->toDateString())->count());
        $this->assertSame(2, ClassSession::whereDate('date', '>=', now()->toDateString())->count());
    }

    public function test_schedule_resave_keeps_today_session_with_checkin_and_does_not_duplicate_it(): void
    {
        $today = $this->makeSession(now());
        $timesheet = TeacherTimesheet::create([
            'user_id' => $this->teacher->id, 'class_id' => $this->classModel->id, 'class_session_id' => $today->id,
            'teaching_date' => now()->toDateString(), 'hours' => 1.5, 'type' => 'regular', 'status' => 'pending_review',
        ]);

        $this->actingAs($this->manager)->post(route('tasks.schedule-config.update'), [
            'class_id' => $this->classModel->id,
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(6)->toDateString(),
            'slot1_day' => $this->weekdayName(now()), 'slot1_start' => '08:00', 'slot1_end' => '09:30',
            'slot2_day' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull(ClassSession::find($today->id));
        $this->assertSame($today->id, $timesheet->fresh()->class_session_id);
        $this->assertSame(1, ClassSession::whereDate('date', now()->toDateString())->count());
    }

    public function test_schedule_resave_regenerates_today_session_without_attendance(): void
    {
        $today = $this->makeSession(now(), ['start_time' => '14:00', 'end_time' => '15:30']);

        $this->actingAs($this->manager)->post(route('tasks.schedule-config.update'), [
            'class_id' => $this->classModel->id,
            'start_date' => now()->toDateString(), 'end_date' => now()->toDateString(),
            'slot1_day' => $this->weekdayName(now()), 'slot1_start' => '08:00', 'slot1_end' => '09:30',
            'slot2_day' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull(ClassSession::find($today->id));
        $session = ClassSession::whereDate('date', now()->toDateString())->sole();
        $this->assertSame('08:00', $session->start_time->format('H:i'));
    }

    public function test_schedule_resave_keeps_future_support_sessions(): void
    {
        $date = now()->addDays(2);
        $this->actingAs($this->manager)->post(route('tasks.support-sessions.store'), [
            'class_id' => $this->classModel->id, 'student_id' => $this->student->id, 'teacher_id' => $this->teacher->id,
            'session_date' => $date->toDateString(), 'start_time' => '14:00', 'end_time' => '15:30', 'room' => 'P102',
        ])->assertRedirect();
        $support = SupportSession::firstOrFail();
        $this->assertSame('support', $support->classSession->type);

        $this->actingAs($this->manager)->post(route('tasks.schedule-config.update'), [
            'class_id' => $this->classModel->id,
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(6)->toDateString(),
            'slot1_day' => $this->weekdayName($date), 'slot1_start' => '08:00', 'slot1_end' => '09:30',
            'slot2_day' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull($support->fresh()->class_session_id);
        $this->assertNotNull(ClassSession::find($support->class_session_id));
        $this->assertSame(1, ClassSession::where('type', 'regular')->count());
    }

    public function test_empty_slot2_is_treated_as_absent(): void
    {
        $this->actingAs($this->manager)->post(route('tasks.schedule-config.update'), [
            'class_id' => $this->classModel->id,
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(14)->toDateString(),
            'slot1_day' => $this->weekdayName(now()->addDay()), 'slot1_start' => '08:00', 'slot1_end' => '09:30',
            'slot2_day' => '', 'slot2_start' => '', 'slot2_end' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, ClassSession::where('shift_name', 'Slot 2')->count());
        $this->assertSame(2, ClassSession::where('shift_name', 'Slot 1')->count());
        $this->assertNull($this->classModel->scheduleConfig()->first()->slot2_day);
        $this->assertStringNotContainsString('Thứ 7 18:00', $this->classModel->fresh()->schedule_text);
    }

    // ── 3. Holidays ───────────────────────────────────────────────────────

    public function test_schedule_config_skips_system_and_branch_holidays(): void
    {
        $d1 = now()->addDays(1);
        $d8 = now()->addDays(8);
        $d15 = now()->addDays(15);

        Holiday::create(['code' => 'H-SYS', 'name' => 'Nghỉ toàn hệ thống', 'start_date' => $d1->toDateString(), 'end_date' => $d1->toDateString(), 'is_system_wide' => true]);
        $branchHoliday = Holiday::create(['code' => 'H-BR', 'name' => 'Nghỉ chi nhánh', 'start_date' => $d8->toDateString(), 'end_date' => $d8->toDateString(), 'is_system_wide' => false]);
        $branchHoliday->branches()->attach($this->branch->id);
        $otherBranch = Branch::create(['name' => 'Khác', 'code' => 'OTHER', 'is_active' => true]);
        $otherHoliday = Holiday::create(['code' => 'H-OT', 'name' => 'Nghỉ chi nhánh khác', 'start_date' => $d15->toDateString(), 'end_date' => $d15->toDateString(), 'is_system_wide' => false]);
        $otherHoliday->branches()->attach($otherBranch->id);

        $this->actingAs($this->manager)->post(route('tasks.schedule-config.update'), [
            'class_id' => $this->classModel->id,
            'start_date' => $d1->toDateString(), 'end_date' => $d15->toDateString(),
            'slot1_day' => $this->weekdayName($d1), 'slot1_start' => '08:00', 'slot1_end' => '09:30',
            'slot2_day' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $dates = ClassSession::orderBy('date')->get()->map(fn ($s) => $s->date->toDateString())->all();
        $this->assertSame([$d15->toDateString()], $dates);
    }

    public function test_class_create_drops_sessions_on_holidays(): void
    {
        $d7 = now()->addDays(7)->toDateString();
        $d9 = now()->addDays(9)->toDateString();
        Holiday::create(['code' => 'H-SYS2', 'name' => 'Nghỉ lễ', 'start_date' => $d7, 'end_date' => $d7, 'is_system_wide' => true]);

        $this->actingAs($this->manager)->post(route('classes.store'), [
            'ten_lop' => 'Lớp Nghỉ Lễ', 'ma_lop' => 'SCH-HOL', 'chi_nhanh' => $this->branch->id,
            'chuong_trinh' => 'IELTS SCH', 'cap_do' => 'B1', 'si_so_toi_da' => 12,
            'schedule_sessions_json' => json_encode([
                ['date' => $d7, 'shift' => 'Ca 1', 'start' => '08:00', 'end' => '09:30', 'room' => ''],
                ['date' => $d9, 'shift' => 'Ca 1', 'start' => '08:00', 'end' => '09:30', 'room' => ''],
            ]),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $class = ClassModel::where('code', 'SCH-HOL')->firstOrFail();
        $this->assertSame([$d9], $class->sessions()->get()->map(fn ($s) => $s->date->toDateString())->all());
        $this->assertSame($d9, $class->start_date->toDateString());
    }

    // ── 4. Class status toggle ────────────────────────────────────────────

    public function test_toggle_only_switches_between_active_and_completed(): void
    {
        $this->actingAs($this->manager)->post(route('tasks.schedule-config.update'), ['toggle_class_id' => $this->classModel->id])->assertRedirect();
        $this->assertSame('completed', $this->classModel->fresh()->status);
        $this->actingAs($this->manager)->post(route('tasks.schedule-config.update'), ['toggle_class_id' => $this->classModel->id])->assertRedirect();
        $this->assertSame('active', $this->classModel->fresh()->status);

        $this->classModel->update(['status' => 'cancelled']);
        $this->actingAs($this->manager)->post(route('tasks.schedule-config.update'), ['toggle_class_id' => $this->classModel->id])
            ->assertSessionHasErrors('toggle_class_id');
        $this->assertSame('cancelled', $this->classModel->fresh()->status);
    }

    public function test_toggle_requires_class_update_permission(): void
    {
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $user->givePermissionTo(['work_task.view', 'work_task.assign']);

        $this->actingAs($user)->post(route('tasks.schedule-config.update'), ['toggle_class_id' => $this->classModel->id])
            ->assertForbidden();
        $this->assertSame('active', $this->classModel->fresh()->status);
    }

    public function test_schedule_save_does_not_reactivate_completed_or_cancelled_class(): void
    {
        foreach (['completed', 'cancelled'] as $status) {
            $this->classModel->update(['status' => $status]);
            $this->actingAs($this->manager)->post(route('tasks.schedule-config.update'), [
                'class_id' => $this->classModel->id,
                'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(7)->toDateString(),
                'slot1_day' => $this->weekdayName(now()->addDay()), 'slot1_start' => '08:00', 'slot1_end' => '09:30',
                'slot2_day' => '',
            ]);
            $this->assertSame($status, $this->classModel->fresh()->status);
        }
    }

    public function test_schedule_save_activates_upcoming_class_only_when_requested(): void
    {
        $this->classModel->update(['status' => 'upcoming']);
        $payload = [
            'class_id' => $this->classModel->id,
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(7)->toDateString(),
            'slot1_day' => $this->weekdayName(now()->addDay()), 'slot1_start' => '08:00', 'slot1_end' => '09:30',
            'slot2_day' => '',
        ];

        $this->actingAs($this->manager)->post(route('tasks.schedule-config.update'), $payload)->assertSessionHasNoErrors();
        $this->assertSame('upcoming', $this->classModel->fresh()->status);

        $this->actingAs($this->manager)->post(route('tasks.schedule-config.update'), $payload + ['activate' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('active', $this->classModel->fresh()->status);
    }

    // ── 5. Attendance marks session done ──────────────────────────────────

    public function test_saving_attendance_marks_today_session_completed(): void
    {
        $today = $this->makeSession(now());

        $this->actingAs($this->teacher)->post(route('teacher.attendance.store', $this->classModel->id), [
            'status' => [$this->student->id => 'present'],
        ])->assertRedirect();

        $this->assertSame('completed', $today->fresh()->status);
        $this->assertSame($today->id, StudentAttendance::firstOrFail()->class_session_id);
    }
}
