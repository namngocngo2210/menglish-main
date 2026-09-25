<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassScheduleConfig;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Holiday;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 2 — Lớp và lịch học: trùng lịch GVNN/TA, ngày nghỉ thêm sau, sĩ số lớp.
 */
class Phase2ScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $foreignTeacher;

    private User $assistant;

    private Branch $branch;

    private Branch $otherBranch;

    private Course $course;

    private ClassModel $classModel;

    protected function setUp(): void
    {
        parent::setUp();
        // Thứ 2, 05/10/2026 — cố định để ngày trong tuần của buổi học là tất định.
        $this->travelTo(Carbon::parse('2026-10-05 08:00:00'));

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-P2', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'Đống Đa', 'code' => 'DD-P2', 'is_active' => true]);
        $this->admin = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $this->admin->assignRole('admin');
        $this->teacher = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $this->teacher->assignRole('teacher');
        $this->foreignTeacher = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $this->foreignTeacher->assignRole('teacher');
        $this->assistant = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $this->assistant->assignRole('assistant');

        $this->course = Course::create(['name' => 'IELTS P2', 'code' => 'IELTS-P2', 'total_lessons' => 24, 'is_active' => true]);
        $this->classModel = $this->makeClass('P2-01', [
            'teacher_id' => $this->teacher->id,
            'foreign_teacher_id' => $this->foreignTeacher->id,
            'assistant_id' => $this->assistant->id,
        ]);
    }

    private function makeClass(string $code, array $overrides = []): ClassModel
    {
        return ClassModel::create($overrides + [
            'name' => "Lớp {$code}", 'code' => $code, 'course_id' => $this->course->id,
            'program' => $this->course->name, 'level' => 'B1', 'branch_id' => $this->branch->id,
            'room' => 'P101', 'status' => 'active', 'max_capacity' => 10,
            'start_date' => '2026-09-01', 'end_date' => '2026-12-31',
        ]);
    }

    private function makeSession(ClassModel $class, string $date, array $overrides = []): ClassSession
    {
        return ClassSession::create($overrides + [
            'class_id' => $class->id, 'branch_id' => $class->branch_id,
            'date' => $date, 'shift_name' => 'Slot 1', 'type' => ClassSession::TYPE_REGULAR,
            'start_time' => '18:00', 'end_time' => '19:30', 'room' => $class->room,
            'teacher_id' => $class->teacher_id ?? $class->foreign_teacher_id,
            'foreign_teacher_id' => $class->foreign_teacher_id,
            'assistant_id' => $class->assistant_id, 'status' => 'scheduled',
        ]);
    }

    // ── 1. GVNN / trợ giảng trong kiểm tra trùng lịch ─────────────────────

    public function test_schedule_config_stores_foreign_teacher_on_generated_sessions(): void
    {
        $this->actingAs($this->admin)->post(route('tasks.schedule-config.update'), [
            'class_id' => $this->classModel->id,
            'start_date' => '2026-10-05', 'end_date' => '2026-10-18',
            'slot1_day' => 'Thứ 2', 'slot1_start' => '18:00', 'slot1_end' => '19:30',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $sessions = ClassSession::where('class_id', $this->classModel->id)->get();
        $this->assertCount(2, $sessions);
        $this->assertTrue($sessions->every(fn ($s) => $s->teacher_id === $this->teacher->id
            && $s->foreign_teacher_id === $this->foreignTeacher->id
            && $s->assistant_id === $this->assistant->id));
    }

    public function test_foreign_teacher_double_booking_is_rejected_even_when_class_has_main_teacher(): void
    {
        $otherTeacher = User::factory()->create(['is_active' => true]);
        $otherTeacher->assignRole('teacher');
        $other = $this->makeClass('P2-OTHER', [
            'teacher_id' => $otherTeacher->id, 'foreign_teacher_id' => $this->foreignTeacher->id,
            'room' => 'P909', 'branch_id' => $this->otherBranch->id,
        ]);
        $this->makeSession($other, '2026-10-05');

        $this->actingAs($this->admin)->post(route('tasks.schedule-config.update'), [
            'class_id' => $this->classModel->id,
            'start_date' => '2026-10-05', 'end_date' => '2026-10-18',
            'slot1_day' => 'Thứ 2', 'slot1_start' => '19:00', 'slot1_end' => '20:30',
        ])->assertSessionHasErrors('class_id');

        $this->assertSame(0, ClassSession::where('class_id', $this->classModel->id)->count());
    }

    public function test_class_update_checks_and_syncs_new_foreign_teacher(): void
    {
        $newForeign = User::factory()->create(['is_active' => true]);
        $newForeign->assignRole('teacher');
        $future = $this->makeSession($this->classModel, '2026-10-12');

        $busy = $this->makeClass('P2-BUSY', ['teacher_id' => $newForeign->id, 'room' => 'P500']);
        $busySession = $this->makeSession($busy, '2026-10-12', ['teacher_id' => $newForeign->id, 'foreign_teacher_id' => null]);

        $payload = [
            'ten_lop' => $this->classModel->name, 'ma_lop' => $this->classModel->code,
            'chi_nhanh' => $this->branch->id, 'chuong_trinh' => $this->course->name,
            'cap_do' => 'B1', 'si_so_toi_da' => 12,
            'giao_vien_chinh' => $this->teacher->id, 'tro_giang' => $this->assistant->id,
            'giao_vien_nn' => $newForeign->id, 'phong_hoc' => 'P101',
        ];
        $this->actingAs($this->admin)->put(route('classes.update', $this->classModel->id), $payload)
            ->assertSessionHasErrors('giao_vien_nn');

        $busySession->update(['status' => 'cancelled']);
        $this->actingAs($this->admin)->put(route('classes.update', $this->classModel->id), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame($newForeign->id, $future->fresh()->foreign_teacher_id);
        $this->assertSame($this->teacher->id, $future->fresh()->teacher_id);
    }

    public function test_check_availability_reports_assistant_and_foreign_teacher_across_branches(): void
    {
        $other = $this->makeClass('P2-AV', [
            'teacher_id' => null, 'foreign_teacher_id' => $this->foreignTeacher->id,
            'assistant_id' => $this->assistant->id, 'branch_id' => $this->otherBranch->id, 'room' => 'P707',
        ]);
        $this->makeSession($other, '2026-10-06', ['teacher_id' => null]);

        $response = $this->actingAs($this->admin)->postJson(route('classes.check-availability'), [
            'branch_id' => $this->branch->id, 'date' => '2026-10-06', 'start_time' => '19:00', 'end_time' => '20:00',
        ])->assertOk();

        $this->assertEqualsCanonicalizing([$this->foreignTeacher->id, $this->assistant->id], $response->json('occupied_teachers'));
        $this->assertSame([], $response->json('occupied_rooms'), 'Phòng của chi nhánh khác không chiếm phòng chi nhánh này');

        // Ca nối tiếp (bắt đầu đúng lúc ca kia kết thúc) không bị coi là trùng.
        $this->actingAs($this->admin)->postJson(route('classes.check-availability'), [
            'branch_id' => $this->otherBranch->id, 'date' => '2026-10-06', 'start_time' => '19:30', 'end_time' => '21:00',
        ])->assertOk()->assertJsonPath('occupied_teachers', [])->assertJsonPath('occupied_rooms', []);
    }

    // ── 2. Ngày nghỉ lễ thêm sau khi đã sinh lịch ────────────────────────

    private function scheduleWeeklyMondays(): array
    {
        ClassScheduleConfig::create([
            'class_id' => $this->classModel->id, 'academic_year' => '2026 - 2027',
            'slot1_day' => 'Thứ 2', 'slot1_start' => '18:00', 'slot1_end' => '19:30',
        ]);

        return collect(['2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26'])
            ->map(fn ($date) => $this->makeSession($this->classModel, $date))->all();
    }

    private function holidayPayload(array $overrides = []): array
    {
        return $overrides + [
            'code' => 'NL-P2', 'name' => 'Nghỉ bù P2',
            'start_date' => '2026-10-12', 'end_date' => '2026-10-12', 'is_system_wide' => '1',
        ];
    }

    public function test_new_holiday_cancels_future_sessions_and_appends_makeup_at_end_of_schedule(): void
    {
        [, $onHoliday] = $this->scheduleWeeklyMondays();
        // Phòng P101 đã bị lớp khác chiếm thứ 2 kế tiếp → buổi bù phải nhảy sang tuần sau nữa.
        $blocker = $this->makeClass('P2-BLOCK', ['teacher_id' => null, 'foreign_teacher_id' => null, 'assistant_id' => null]);
        $this->makeSession($blocker, '2026-11-02', ['teacher_id' => null, 'foreign_teacher_id' => null, 'assistant_id' => null]);

        $this->actingAs($this->admin)->post(route('holidays.store'), $this->holidayPayload())
            ->assertRedirect(route('holidays.index'))
            ->assertSessionHas('status', fn ($msg) => str_contains($msg, 'Đã hủy 1 buổi'));

        $holiday = Holiday::where('code', 'NL-P2')->firstOrFail();
        $onHoliday->refresh();
        $this->assertSame('cancelled', $onHoliday->status);
        $this->assertSame($holiday->id, $onHoliday->holiday_id);

        $makeup = ClassSession::where('rescheduled_from_id', $onHoliday->id)->firstOrFail();
        $this->assertSame(ClassSession::TYPE_MAKEUP, $makeup->type);
        $this->assertSame('2026-11-09', $makeup->date->toDateString());
        $this->assertSame('18:00', $makeup->start_time->format('H:i'));
        $this->assertSame($this->foreignTeacher->id, $makeup->foreign_teacher_id);
        $this->assertSame('scheduled', $makeup->status);
    }

    public function test_holiday_keeps_sessions_with_attendance_and_ignores_other_branches(): void
    {
        [, $onHoliday] = $this->scheduleWeeklyMondays();
        $student = Student::create(['name' => 'HV P2', 'code' => 'HV-P2', 'phone' => '0901000111',
            'current_class_id' => $this->classModel->id, 'branch_id' => $this->branch->id, 'status' => 'studying']);
        StudentAttendance::create(['class_id' => $this->classModel->id, 'class_session_id' => $onHoliday->id,
            'student_id' => $student->id, 'user_id' => $this->teacher->id, 'session_date' => '2026-10-12', 'status' => 'present']);

        $otherClass = $this->makeClass('P2-DD', ['branch_id' => $this->otherBranch->id, 'teacher_id' => null,
            'foreign_teacher_id' => null, 'assistant_id' => null, 'room' => 'D1']);
        $otherSession = $this->makeSession($otherClass, '2026-10-12', ['teacher_id' => null, 'foreign_teacher_id' => null, 'assistant_id' => null]);

        $this->actingAs($this->admin)->post(route('holidays.store'), $this->holidayPayload([
            'is_system_wide' => '0', 'branch_ids' => [$this->branch->id],
        ]))->assertSessionHas('status', fn ($msg) => str_contains($msg, '1 buổi đã có điểm danh'));

        $this->assertSame('scheduled', $onHoliday->fresh()->status);
        $this->assertNull($onHoliday->fresh()->holiday_id);
        $this->assertSame('scheduled', $otherSession->fresh()->status);
        $this->assertSame(0, ClassSession::where('type', ClassSession::TYPE_MAKEUP)->count());
    }

    public function test_moving_or_deleting_holiday_restores_sessions_and_removes_makeup(): void
    {
        [, $first, $second] = $this->scheduleWeeklyMondays();
        $this->actingAs($this->admin)->post(route('holidays.store'), $this->holidayPayload());
        $holiday = Holiday::where('code', 'NL-P2')->firstOrFail();
        $this->assertSame('cancelled', $first->fresh()->status);

        // Dời ngày nghỉ sang 19/10: buổi 12/10 được khôi phục, buổi 19/10 bị hủy và xếp bù.
        $this->actingAs($this->admin)->put(route('holidays.update', $holiday), $this->holidayPayload([
            'start_date' => '2026-10-19', 'end_date' => '2026-10-19',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('scheduled', $first->fresh()->status);
        $this->assertNull($first->fresh()->holiday_id);
        $this->assertSame(0, ClassSession::where('rescheduled_from_id', $first->id)->count());
        $this->assertSame('cancelled', $second->fresh()->status);
        $this->assertSame(1, ClassSession::where('rescheduled_from_id', $second->id)->count());

        $this->actingAs($this->admin)->delete(route('holidays.destroy', $holiday))->assertRedirect();
        $this->assertSame('scheduled', $second->fresh()->status);
        $this->assertSame(0, ClassSession::where('type', ClassSession::TYPE_MAKEUP)->count());
    }

    // ── 6. Sĩ số lớp ──────────────────────────────────────────────────────

    public function test_class_seat_helpers_count_active_students_from_both_sources(): void
    {
        $class = $this->makeClass('P2-CAP', ['max_capacity' => 3]);
        $a = Student::create(['name' => 'A', 'code' => 'CAP-A', 'phone' => '0901000201', 'branch_id' => $this->branch->id,
            'current_class_id' => $class->id, 'status' => 'studying']);
        $b = Student::create(['name' => 'B', 'code' => 'CAP-B', 'phone' => '0901000202', 'branch_id' => $this->branch->id,
            'status' => 'waiting_start']);
        ClassEnrollment::create(['student_id' => $b->id, 'class_id' => $class->id, 'status' => 'pending']);
        // Cùng học viên ở cả hai nguồn chỉ tính 1 lần.
        ClassEnrollment::create(['student_id' => $a->id, 'class_id' => $class->id, 'status' => 'completed']);
        // Học viên thôi học không giữ chỗ.
        Student::create(['name' => 'C', 'code' => 'CAP-C', 'phone' => '0901000203', 'branch_id' => $this->branch->id,
            'current_class_id' => $class->id, 'status' => 'dropped']);

        $this->assertSame(2, $class->occupiedSeats());
        $this->assertSame(1, $class->seatsLeft());
        $this->assertFalse($class->isFull());
        $this->assertTrue($class->hasSeatsFor(1));
        $this->assertFalse($class->hasSeatsFor(2));

        Student::create(['name' => 'D', 'code' => 'CAP-D', 'phone' => '0901000204', 'branch_id' => $this->branch->id,
            'current_class_id' => $class->id, 'status' => 'studying']);
        $this->assertTrue($class->isFull());
        $this->assertSame(0, $class->seatsLeft());

        $unlimited = $this->makeClass('P2-UNL', ['max_capacity' => 0]);
        $this->assertNull($unlimited->seatsLeft());
        $this->assertFalse($unlimited->isFull());
        $this->assertTrue($unlimited->hasSeatsFor(50));
    }
}
