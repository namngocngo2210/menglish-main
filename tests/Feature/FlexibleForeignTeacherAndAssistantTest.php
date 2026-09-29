<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\StudentAttendance;
use App\Models\Student;
use App\Models\User;
use App\Models\WorkTask;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GVNN không cố định theo lớp (gán / đổi theo từng buổi của lớp đã có) và
 * trợ giảng không cố định theo lớp (làm theo ca + giao việc).
 */
class FlexibleForeignTeacherAndAssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $gvnnA;

    private User $gvnnB;

    private User $assistant;

    private Branch $branch;

    private Course $course;

    private ClassModel $classModel;

    protected function setUp(): void
    {
        parent::setUp();
        // Thứ 2, 05/10/2026.
        $this->travelTo(Carbon::parse('2026-10-05 08:00:00'));
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-FX', 'is_active' => true]);
        $this->admin = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $this->admin->assignRole('admin');
        foreach (['teacher', 'gvnnA', 'gvnnB'] as $prop) {
            $this->{$prop} = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
            $this->{$prop}->assignRole('teacher');
        }
        $this->assistant = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $this->assistant->assignRole('assistant');

        $this->course = Course::create(['name' => 'IELTS FX', 'code' => 'IELTS-FX', 'total_lessons' => 24, 'is_active' => true]);
        $this->classModel = $this->makeClass('FX-01', ['teacher_id' => $this->teacher->id]);
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

    private function classPayload(array $overrides = []): array
    {
        return $overrides + [
            'ten_lop' => $this->classModel->name, 'ma_lop' => $this->classModel->code,
            'chi_nhanh' => $this->branch->id, 'chuong_trinh' => $this->course->name,
            'cap_do' => 'B1', 'si_so_toi_da' => 10, 'phong_hoc' => 'P101',
            'giao_vien_chinh' => $this->teacher->id,
        ];
    }

    public function test_foreign_teacher_can_be_assigned_per_session_on_existing_class(): void
    {
        $s1 = $this->makeSession($this->classModel, '2026-10-05');
        $s2 = $this->makeSession($this->classModel, '2026-10-07');
        $s3 = $this->makeSession($this->classModel, '2026-10-12');

        $this->actingAs($this->admin)->post(route('classes.foreign-teacher', $this->classModel->id), [
            'giao_vien_nn' => $this->gvnnA->id, 'session_ids' => [$s1->id, $s3->id],
        ])->assertRedirect(route('classes.show', ['id' => $this->classModel->id, 'tab' => 'schedule']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('classes.foreign-teacher', $this->classModel->id), [
            'giao_vien_nn' => $this->gvnnB->id, 'session_ids' => [$s2->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->gvnnA->id, $s1->fresh()->foreign_teacher_id);
        $this->assertSame($this->gvnnB->id, $s2->fresh()->foreign_teacher_id);
        $this->assertSame($this->gvnnA->id, $s3->fresh()->foreign_teacher_id);
        // GV chính của buổi không đổi; lớp không bị gắn GVNN cố định.
        $this->assertSame($this->teacher->id, $s2->fresh()->teacher_id);
        $this->assertNull($this->classModel->fresh()->foreign_teacher_id);

        // GVNN được báo và thấy lớp qua buổi được gán.
        $this->assertTrue(AdminNotification::where('user_id', $this->gvnnB->id)->where('type', 'class_assigned')->exists());
        $this->assertTrue(ClassModel::query()->visibleTo($this->gvnnB)->whereKey($this->classModel->id)->exists());

        // Ô trống không được hiểu là gỡ GVNN; phải chọn rõ "Không có GVNN".
        $this->actingAs($this->admin)->post(route('classes.foreign-teacher', $this->classModel->id), [
            'giao_vien_nn' => '', 'session_ids' => [$s3->id],
        ])->assertSessionHasErrors('giao_vien_nn');
        $this->assertSame($this->gvnnA->id, $s3->fresh()->foreign_teacher_id);
        $this->actingAs($this->admin)->post(route('classes.foreign-teacher', $this->classModel->id), [
            'giao_vien_nn' => 'none', 'session_ids' => [$s3->id],
        ])->assertSessionHasNoErrors();
        $this->assertNull($s3->fresh()->foreign_teacher_id);
    }

    public function test_class_without_main_teacher_follows_session_foreign_teacher(): void
    {
        $class = $this->makeClass('FX-NN', ['teacher_id' => null, 'foreign_teacher_id' => $this->gvnnA->id]);
        $session = $this->makeSession($class, '2026-10-06');

        $this->actingAs($this->admin)->post(route('classes.foreign-teacher', $class->id), [
            'giao_vien_nn' => $this->gvnnB->id, 'session_ids' => [$session->id], 'set_default' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->gvnnB->id, $session->fresh()->foreign_teacher_id);
        $this->assertSame($this->gvnnB->id, $session->fresh()->teacher_id);
        $this->assertSame($this->gvnnB->id, $class->fresh()->foreign_teacher_id);
    }

    public function test_per_session_assignment_rejects_double_booking_and_past_or_attended_sessions(): void
    {
        $session = $this->makeSession($this->classModel, '2026-10-12');
        $busy = $this->makeClass('FX-BUSY', ['teacher_id' => $this->gvnnA->id, 'room' => 'P500']);
        $this->makeSession($busy, '2026-10-12', ['start_time' => '19:00', 'end_time' => '20:30']);

        $this->actingAs($this->admin)->post(route('classes.foreign-teacher', $this->classModel->id), [
            'giao_vien_nn' => $this->gvnnA->id, 'session_ids' => [$session->id],
        ])->assertSessionHasErrors('giao_vien_nn');
        $this->assertNull($session->fresh()->foreign_teacher_id);

        $past = $this->makeSession($this->classModel, '2026-09-28');
        $attended = $this->makeSession($this->classModel, '2026-10-14');
        $student = Student::create(['name' => 'HV FX', 'code' => 'HV-FX', 'phone' => '0900000001', 'branch_id' => $this->branch->id]);
        StudentAttendance::create([
            'class_session_id' => $attended->id, 'class_id' => $this->classModel->id, 'student_id' => $student->id,
            'session_date' => '2026-10-14', 'status' => 'present',
        ]);
        foreach ([$past, $attended] as $locked) {
            $this->actingAs($this->admin)->post(route('classes.foreign-teacher', $this->classModel->id), [
                'giao_vien_nn' => $this->gvnnB->id, 'session_ids' => [$locked->id],
            ])->assertSessionHasErrors('session_ids');
        }

        // Buổi của lớp khác không đổi được qua lớp này.
        $foreignSession = $this->makeSession($busy, '2026-10-20');
        $this->actingAs($this->admin)->post(route('classes.foreign-teacher', $this->classModel->id), [
            'giao_vien_nn' => $this->gvnnB->id, 'session_ids' => [$foreignSession->id],
        ])->assertSessionHasErrors('session_ids');
    }

    public function test_changing_class_default_foreign_teacher_keeps_per_session_overrides(): void
    {
        $class = $this->classModel;
        $class->update(['foreign_teacher_id' => $this->gvnnA->id]);
        $default = $this->makeSession($class, '2026-10-06');
        $override = $this->makeSession($class, '2026-10-08', ['foreign_teacher_id' => $this->gvnnB->id]);
        $newDefault = User::factory()->create(['is_active' => true]);
        $newDefault->assignRole('teacher');

        $this->actingAs($this->admin)->put(route('classes.update', $class->id), $this->classPayload(['giao_vien_nn' => $newDefault->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($newDefault->id, $default->fresh()->foreign_teacher_id);
        $this->assertSame($this->gvnnB->id, $override->fresh()->foreign_teacher_id);
        $this->assertSame($this->teacher->id, $override->fresh()->teacher_id);
    }

    public function test_new_class_has_no_fixed_assistant_and_create_form_hides_it(): void
    {
        $this->actingAs($this->admin)->get(route('classes.create'))
            ->assertOk()
            ->assertDontSee('name="tro_giang"', false)
            ->assertSee('Giao việc cho Trợ giảng');

        $this->actingAs($this->admin)->post(route('classes.store'), [
            'ten_lop' => 'Lớp mới FX', 'ma_lop' => 'FX-NEW', 'chi_nhanh' => $this->branch->id,
            'chuong_trinh' => $this->course->name, 'cap_do' => 'B1', 'si_so_toi_da' => 10,
            'giao_vien_chinh' => $this->teacher->id, 'tro_giang' => $this->assistant->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull(ClassModel::where('code', 'FX-NEW')->value('assistant_id'));
    }

    public function test_legacy_fixed_assistant_can_be_removed_from_edit_form(): void
    {
        $this->classModel->update(['assistant_id' => $this->assistant->id]);
        $future = $this->makeSession($this->classModel, '2026-10-06');

        $this->actingAs($this->admin)->get(route('classes.edit', $this->classModel->id))
            ->assertOk()->assertSee('Trợ giảng cố định (dữ liệu cũ)');

        $this->actingAs($this->admin)->put(route('classes.update', $this->classModel->id), $this->classPayload(['tro_giang' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->classModel->fresh()->assistant_id);
        $this->assertNull($future->fresh()->assistant_id);

        $this->actingAs($this->admin)->get(route('classes.edit', $this->classModel->id))
            ->assertOk()->assertDontSee('Trợ giảng cố định (dữ liệu cũ)');
    }

    public function test_assistant_comes_from_shift_tasks_on_class_page_dashboard_and_ta_portal(): void
    {
        $session = $this->makeSession($this->classModel, '2026-10-05');
        WorkTask::create([
            'title' => 'Chuẩn bị tài liệu', 'creator_id' => $this->admin->id, 'assignee_id' => $this->assistant->id,
            'branch_id' => $this->branch->id, 'class_id' => $this->classModel->id, 'time_slot_category' => 'before',
            'task_type' => 'one_time', 'due_date' => '2026-10-05', 'due_time' => '17:30', 'status' => 'new',
        ]);

        $this->actingAs($this->admin)->get(route('classes.show', ['id' => $this->classModel->id, 'tab' => 'schedule']))
            ->assertOk()
            ->assertSee($this->assistant->name)
            ->assertSee('Gán GVNN');

        $this->actingAs($this->admin)->get(route('classes.show', ['id' => $this->classModel->id]))
            ->assertOk()->assertSee('Trợ giảng (7 ngày tới)')->assertSee($this->assistant->name);

        $this->actingAs($this->admin)->get(route('tasks.classes-dashboard', ['date' => '2026-10-05']))
            ->assertOk()->assertSee($this->assistant->name)->assertSee('Trước giờ học');

        // Trợ giảng thấy buổi của lớp có việc giao hôm nay dù không được gán cố định vào lớp.
        $response = $this->actingAs($this->assistant)->get(route('portal.ta-tasks', ['date' => '2026-10-05']))->assertOk();
        $this->assertTrue($response->viewData('sessions')->contains('id', $session->id));
    }
}
