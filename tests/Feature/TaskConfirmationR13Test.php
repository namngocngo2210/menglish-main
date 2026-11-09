<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\User;
use App\Models\WorkTask;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TK-2026-0073 (báo cáo trực lớp 500) + TK-2026-0074 (R-13): việc gắn lớp → GV chính của lớp xác nhận;
 * việc không gắn lớp → người giao xác nhận; người khác (kể cả Admin / Học vụ khác) bị chặn 403 phía server.
 */
class TaskConfirmationR13Test extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $teacher;

    private User $ta;

    private User $academic;

    private User $otherAcademic;

    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Chi nhánh Một', 'code' => 'CN1', 'address' => 'HN', 'is_active' => true]);
        $this->admin = $this->user('admin');
        $this->teacher = $this->user('teacher', ['name' => 'ThS. Nguyễn Quốc Anh']);
        $this->ta = $this->user('assistant');
        $this->academic = $this->user('academic_staff');
        $this->otherAcademic = $this->user('academic_staff');
        $course = Course::create(['name' => 'Starters', 'code' => 'STA', 'total_sessions' => 24, 'tuition_fee' => 5000000, 'is_active' => true]);
        $this->class = ClassModel::create([
            'name' => 'FAM0', 'code' => 'FAM0', 'course_id' => $course->id, 'branch_id' => $this->branch->id,
            'teacher_id' => $this->teacher->id, 'assistant_id' => $this->ta->id,
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2), 'status' => 'active',
        ]);
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['branch_id' => $this->branch->id, 'is_active' => true], $attributes));
        $user->assignRole($role);

        return $user;
    }

    private function dutyTask(): WorkTask
    {
        return WorkTask::create([
            'title' => 'Trực lớp FAM0', 'creator_id' => $this->academic->id, 'assignee_id' => $this->ta->id,
            'class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'due_date' => today(), 'due_time' => '18:00',
            'time_slot_category' => 'during', 'task_type' => 'one_time', 'status' => 'new',
        ]);
    }

    /** Payload như Portal TA: có buổi học (class_session_id), KHÔNG gửi hom_nay_hoc_gi — từng gây 500. */
    private function sessionPayload(WorkTask $task, array $extra = []): array
    {
        $session = ClassSession::create([
            'class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->toDateString(),
            'start_time' => '08:00', 'end_time' => '09:30', 'type' => ClassSession::TYPE_REGULAR,
            'status' => 'scheduled', 'teacher_id' => $this->teacher->id,
        ]);

        return array_merge(['class_id' => $this->class->id, 'class_session_id' => $session->id, 'task_id' => $task->id], $extra);
    }

    public function test_report_with_session_and_photo_completes_task_instead_of_500(): void
    {
        Storage::fake('public');
        $task = $this->dutyTask();

        $this->actingAs($this->ta)->post(route('tasks.class-reports.store'), $this->sessionPayload($task, [
            'board_images' => [UploadedFile::fake()->image('bang.jpg')],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame(ClassReport::STATUS_APPROVED, ClassReport::sole()->status);
    }

    public function test_report_with_session_without_photo_waits_for_main_teacher_and_names_them(): void
    {
        $task = $this->dutyTask();

        $this->actingAs($this->ta)->post(route('tasks.class-reports.store'), $this->sessionPayload($task))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'ThS. Nguyễn Quốc Anh') && ! str_contains($m, 'Admin'));

        $this->assertSame('pending_confirmation', $task->fresh()->status);
        $this->assertSame($this->teacher->id, ClassReport::sole()->currentConfirmerId());
    }

    public function test_report_with_session_and_extra_support_rows_does_not_500(): void
    {
        $task = $this->dutyTask();

        $this->actingAs($this->ta)->post(route('tasks.class-reports.store'), $this->sessionPayload($task, [
            'supports' => [['student_id' => null, 'reason' => null]],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('pending_confirmation', $task->fresh()->status);
    }

    public function test_non_class_task_is_listed_and_confirmed_only_by_assigner(): void
    {
        $task = WorkTask::create([
            'title' => 'Chuẩn bị tài liệu chung', 'creator_id' => $this->academic->id, 'assignee_id' => $this->ta->id,
            'due_date' => today(), 'task_type' => 'one_time', 'status' => 'pending_confirmation',
        ]);

        foreach ([$this->admin, $this->otherAcademic, $this->teacher, $this->ta] as $other) {
            $this->actingAs($other)->get(route('tasks.manual-approvals'))->assertOk()->assertDontSee('Chuẩn bị tài liệu chung');
            $this->actingAs($other)->post(route('tasks.approve', $task->id))->assertForbidden();
            $this->actingAs($other)->post(route('tasks.reject', $task->id), ['admin_note' => 'x'])->assertForbidden();
        }
        $this->assertSame('pending_confirmation', $task->fresh()->status);

        $this->actingAs($this->academic)->get(route('tasks.manual-approvals'))->assertOk()
            ->assertSee('Chuẩn bị tài liệu chung')
            ->assertSee('người giao việc xác nhận')
            ->assertDontSee('Admin xác nhận');
        $this->actingAs($this->academic)->post(route('tasks.approve', $task->id))->assertRedirect(route('tasks.manual-approvals'));
        $this->assertSame('completed', $task->fresh()->status);
    }

    public function test_class_task_is_confirmed_only_by_class_main_teacher(): void
    {
        $task = $this->dutyTask();
        $task->update(['status' => 'pending_confirmation']);

        // Người giao (Học vụ), Học vụ khác, Admin, TA đều bị chặn.
        foreach ([$this->academic, $this->otherAcademic, $this->admin, $this->ta] as $other) {
            $this->actingAs($other)->get(route('tasks.manual-approvals'))->assertOk()->assertDontSee('Trực lớp FAM0');
            $this->actingAs($other)->post(route('tasks.approve', $task->id))->assertForbidden();
            $this->actingAs($other)->post(route('tasks.reject', $task->id), ['admin_note' => 'x'])->assertForbidden();
        }

        $this->actingAs($this->teacher)->get(route('tasks.manual-approvals'))->assertOk()->assertSee('Trực lớp FAM0');
        $this->actingAs($this->teacher)->post(route('tasks.reject', $task->id), ['admin_note' => 'Thiếu ảnh'])->assertRedirect();
        $this->assertSame('in_progress', $task->fresh()->status);
    }
}
