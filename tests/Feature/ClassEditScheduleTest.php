<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassScheduleConfig;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Sửa lớp: lịch học chọn theo thứ + ca giờ (tối đa 2 ca/tuần) thay cho ô mô tả text;
 * đổi lịch thì xếp lại các buổi sắp tới giống màn Lịch & TKB lớp.
 */
class ClassEditScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $teacher;

    private Branch $branch;

    private ClassModel $classModel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-EDS', 'is_active' => true]);
        $this->manager = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $this->manager->assignRole('manager');
        $this->teacher = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $this->teacher->assignRole('teacher');

        $course = Course::create(['name' => 'IELTS EDS', 'code' => 'IELTS-EDS', 'total_lessons' => 24, 'is_active' => true]);
        $this->classModel = ClassModel::create([
            'name' => 'Lớp EDS', 'code' => 'EDS-01', 'course_id' => $course->id,
            'program' => $course->name, 'level' => 'B1', 'branch_id' => $this->branch->id,
            'teacher_id' => $this->teacher->id, 'status' => 'active', 'max_capacity' => 12,
            'start_date' => now()->subWeek()->toDateString(), 'end_date' => now()->addDays(13)->toDateString(),
            'schedule_text' => 'Tối T2-4-6 (19h30 - 21h30)',
        ]);
    }

    private function weekdayName(CarbonInterface $date): string
    {
        return ['Chủ nhật', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7'][$date->dayOfWeek];
    }

    /** Ngày thường gần nhất từ ngày mai (ngày thường có khung Ca 1 18:00–19:30). */
    private function nextWeekday(int $from = 1): CarbonInterface
    {
        $date = now()->addDays($from)->startOfDay();
        while ($date->isWeekend()) {
            $date->addDay();
        }

        return $date;
    }

    private function makeSession(CarbonInterface $date, array $overrides = []): ClassSession
    {
        return ClassSession::create($overrides + [
            'class_id' => $this->classModel->id, 'branch_id' => $this->branch->id,
            'date' => $date->toDateString(), 'shift_name' => 'Ca 1', 'type' => 'regular',
            'start_time' => '18:00', 'end_time' => '19:30',
            'teacher_id' => $this->teacher->id, 'status' => 'scheduled',
        ]);
    }

    private function configure(string $day, string $start = '18:00', string $end = '19:30'): void
    {
        ClassScheduleConfig::create([
            'class_id' => $this->classModel->id, 'academic_year' => '2026 - 2027',
            'slot1_day' => $day, 'slot1_start' => $start, 'slot1_end' => $end,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'ten_lop' => $this->classModel->name,
            'ma_lop' => $this->classModel->code,
            'chi_nhanh' => $this->branch->id,
            'chuong_trinh' => $this->classModel->program,
            'cap_do' => 'B1',
            'si_so_toi_da' => 12,
            'start_date' => $this->classModel->start_date?->toDateString(),
            'end_date' => $this->classModel->end_date?->toDateString(),
        ];
    }

    public function test_edit_page_sends_structured_slots_and_legacy_text(): void
    {
        $this->configure('Thứ 3');

        $this->actingAs($this->manager)->get(route('classes.edit', $this->classModel->id))->assertOk()
            ->assertSee('Lịch học trong tuần')->assertSee('id="edit_slot1_day"', false)->assertSee('name="slot2_day"', false)
            ->assertDontSee('Lịch học (mô tả ngắn)')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('klass.slot1_day', 'Thứ 3')
                ->where('klass.slot1_start', '18:00')
                ->where('klass.slot1_end', '19:30')
                ->where('klass.slot2_day', null)
                ->where('canSchedule', true)
                ->has('shiftOptions.weekday')
                ->missing('klass.schedule_text')
                ->where('klass.legacy_schedule_text', null));

        ClassScheduleConfig::query()->delete();
        $this->actingAs($this->manager)->get(route('classes.edit', $this->classModel->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('klass.slot1_day', null)
                ->where('klass.legacy_schedule_text', 'Tối T2-4-6 (19h30 - 21h30)'))
            ->assertSee('Lịch cũ (dạng mô tả, chưa có thứ/giờ)');
    }

    public function test_changing_slots_reschedules_upcoming_sessions_and_keeps_attended_ones(): void
    {
        $first = $this->nextWeekday();
        $this->configure($this->weekdayName($first));
        $attended = $this->makeSession($first);
        $student = Student::create([
            'name' => 'HV EDS', 'code' => 'HV-EDS', 'phone' => '0901000077',
            'current_class_id' => $this->classModel->id, 'branch_id' => $this->branch->id, 'status' => 'studying',
        ]);
        StudentAttendance::create([
            'class_id' => $this->classModel->id, 'class_session_id' => $attended->id, 'student_id' => $student->id,
            'user_id' => $this->teacher->id, 'session_date' => $first->toDateString(), 'status' => 'present',
        ]);
        $replaceable = $this->makeSession($first->copy()->addWeek());
        $past = $this->makeSession(now()->subDays(3));

        $newDay = $this->nextWeekday(2);
        $this->actingAs($this->manager)->put(route('classes.update', $this->classModel->id), $this->payload([
            'slot1_day' => $this->weekdayName($newDay), 'slot1_start' => '19:30', 'slot1_end' => '21:00',
            'slot2_day' => '',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull(ClassSession::find($attended->id));
        $this->assertNotNull(ClassSession::find($past->id));
        $this->assertNull(ClassSession::find($replaceable->id));

        $upcoming = ClassSession::where('class_id', $this->classModel->id)->whereKeyNot([$attended->id, $past->id])->get();
        $this->assertNotEmpty($upcoming);
        foreach ($upcoming as $session) {
            $this->assertSame($newDay->dayOfWeek, $session->date->dayOfWeek);
            $this->assertSame('19:30', $session->start_time->format('H:i'));
            $this->assertSame('Ca 2', $session->shift_name);
        }

        $config = $this->classModel->scheduleConfig()->first();
        $this->assertSame($this->weekdayName($newDay), $config->slot1_day);
        $this->assertNull($config->slot2_day);
        $this->assertSame($this->weekdayName($newDay).' Ca 2 19:30-21:00', $this->classModel->fresh()->schedule_text);
    }

    public function test_unchanged_slots_do_not_touch_sessions(): void
    {
        $first = $this->nextWeekday();
        $this->configure($this->weekdayName($first));
        $session = $this->makeSession($first, ['start_time' => '18:10', 'end_time' => '19:40']);

        $this->actingAs($this->manager)->put(route('classes.update', $this->classModel->id), $this->payload([
            'ten_lop' => 'Lớp EDS mới',
            'slot1_day' => $this->weekdayName($first), 'slot1_start' => '18:00', 'slot1_end' => '19:30',
            'slot2_day' => '',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('18:10', $session->fresh()->start_time->format('H:i'));
        $this->assertSame(1, ClassSession::where('class_id', $this->classModel->id)->count());
        $this->assertSame('Lớp EDS mới', $this->classModel->fresh()->name);
    }

    public function test_free_text_schedule_is_no_longer_accepted(): void
    {
        $this->actingAs($this->manager)->put(route('classes.update', $this->classModel->id), $this->payload([
            'schedule_text' => 'Gõ tay tự do',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Tối T2-4-6 (19h30 - 21h30)', $this->classModel->fresh()->schedule_text);
    }

    public function test_first_schedule_on_pending_class_generates_sessions_and_activates_it(): void
    {
        $this->classModel->update(['status' => 'pending_schedule', 'start_date' => null, 'end_date' => null]);
        $day = $this->nextWeekday();

        $this->actingAs($this->manager)->put(route('classes.update', $this->classModel->id), $this->payload([
            'status' => 'pending_schedule',
            'start_date' => $day->toDateString(), 'end_date' => $day->copy()->addDays(13)->toDateString(),
            'slot1_day' => $this->weekdayName($day), 'slot1_start' => '18:00', 'slot1_end' => '19:30',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, ClassSession::where('class_id', $this->classModel->id)->count());
        $this->assertSame('active', $this->classModel->fresh()->status);
    }

    public function test_changing_slots_requires_schedule_permission(): void
    {
        $this->configure('Thứ 3');
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $user->givePermissionTo(['class.view', 'class.update', 'class.scope_all']);

        $this->actingAs($user)->get(route('classes.edit', $this->classModel->id))->assertOk()
            ->assertSee('Thứ 3 18:00–19:30')->assertDontSee('name="slot1_day"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canSchedule', false));

        $this->actingAs($user)->put(route('classes.update', $this->classModel->id), $this->payload([
            'slot1_day' => 'Thứ 5', 'slot1_start' => '18:00', 'slot1_end' => '19:30',
        ]))->assertForbidden();
        $this->assertSame('Thứ 3', $this->classModel->scheduleConfig()->first()->slot1_day);

        // Gửi lại đúng lịch hiện tại (form ẩn ô chọn nhưng vẫn submit) thì vẫn sửa được thông tin lớp.
        $this->actingAs($user)->put(route('classes.update', $this->classModel->id), $this->payload([
            'ten_lop' => 'Đổi tên',
            'slot1_day' => 'Thứ 3', 'slot1_start' => '18:00', 'slot1_end' => '19:30',
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Đổi tên', $this->classModel->fresh()->name);
    }

    public function test_slot_outside_weekday_frames_is_rejected(): void
    {
        $this->actingAs($this->manager)->put(route('classes.update', $this->classModel->id), $this->payload([
            'slot1_day' => 'Thứ 3', 'slot1_start' => '14:00', 'slot1_end' => '15:30',
        ]))->assertSessionHasErrors('slot1_start');

        $this->assertNull($this->classModel->scheduleConfig()->first());
        $this->assertSame(0, ClassSession::count());
    }

    public function test_overlapping_slots_on_same_day_are_rejected(): void
    {
        $this->actingAs($this->manager)->put(route('classes.update', $this->classModel->id), $this->payload([
            'slot1_day' => 'Thứ 7', 'slot1_start' => '15:00', 'slot1_end' => '16:30',
            'slot2_day' => 'Thứ 7', 'slot2_start' => '16:00', 'slot2_end' => '17:30',
        ]))->assertSessionHasErrors('slot2_start');

        $this->assertSame(0, ClassSession::count());
    }

    public function test_teacher_conflict_rejects_the_whole_update(): void
    {
        $day = $this->nextWeekday();
        $other = ClassModel::create([
            'name' => 'Lớp khác', 'code' => 'EDS-02', 'program' => 'IELTS EDS', 'level' => 'B1',
            'branch_id' => $this->branch->id, 'teacher_id' => $this->teacher->id, 'status' => 'active',
        ]);
        ClassSession::create([
            'class_id' => $other->id, 'branch_id' => $this->branch->id, 'date' => $day->toDateString(),
            'shift_name' => 'Ca 1', 'type' => 'regular', 'start_time' => '18:00', 'end_time' => '19:30',
            'teacher_id' => $this->teacher->id, 'status' => 'scheduled',
        ]);

        $this->actingAs($this->manager)->put(route('classes.update', $this->classModel->id), $this->payload([
            'ten_lop' => 'Không được lưu',
            'slot1_day' => $this->weekdayName($day), 'slot1_start' => '18:00', 'slot1_end' => '19:30',
        ]))->assertSessionHasErrors('class_id');

        $this->assertSame('Lớp EDS', $this->classModel->fresh()->name);
        $this->assertNull($this->classModel->scheduleConfig()->first());
        $this->assertSame(0, ClassSession::where('class_id', $this->classModel->id)->count());
    }

    public function test_clearing_all_slots_of_scheduled_class_is_rejected(): void
    {
        $this->configure('Thứ 3');

        $this->actingAs($this->manager)->put(route('classes.update', $this->classModel->id), $this->payload([
            'slot1_day' => '', 'slot2_day' => '',
        ]))->assertSessionHasErrors('slot1_day');

        $this->assertSame('Thứ 3', $this->classModel->scheduleConfig()->first()->slot1_day);
    }
}
