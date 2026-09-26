<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReport;
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
 * Phase 4 — đối chiếu mockup nhóm nền tảng (tài khoản, phân quyền, danh mục, nhật ký,
 * phân công công việc, dashboard) + luật A6 Q8 báo cáo trực lớp.
 */
class Phase4PlatformParityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $admin;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Chi nhánh Một', 'code' => 'CN1', 'address' => 'HN', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'Chi nhánh Hai', 'code' => 'CN2', 'address' => 'HCM', 'is_active' => true]);
        $this->admin = $this->makeUser('admin', $this->branch);
        $this->course = Course::create([
            'name' => 'Starters', 'code' => 'STA', 'total_sessions' => 24, 'tuition_fee' => 5000000, 'is_active' => true,
        ]);
    }

    private function makeUser(string $role, ?Branch $branch = null, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['branch_id' => $branch?->id, 'is_active' => true], $attributes));
        $user->assignRole($role);

        return $user;
    }

    private function makeClass(array $attributes = []): ClassModel
    {
        static $n = 0;
        $n++;

        return ClassModel::create(array_merge([
            'name' => "Lớp Parity {$n}", 'code' => "PAR-{$n}", 'course_id' => $this->course->id,
            'branch_id' => $this->branch->id, 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2),
            'status' => 'active',
        ], $attributes));
    }

    private function dutyTask(User $assigner, User $ta, ClassModel $class, array $attributes = []): WorkTask
    {
        return WorkTask::create(array_merge([
            'title' => 'Trực lớp '.$class->code, 'creator_id' => $assigner->id, 'assignee_id' => $ta->id,
            'class_id' => $class->id, 'branch_id' => $class->branch_id, 'due_date' => today(), 'due_time' => '18:00',
            'time_slot_category' => 'during', 'task_type' => 'one_time', 'status' => 'new',
        ], $attributes));
    }

    private function reportPayload(ClassModel $class, array $extra = []): array
    {
        return array_merge([
            'class_id' => $class->id, 'session_name' => 'Buổi 5 - Listening', 'hom_nay_hoc_gi' => 'Unit 5: numbers',
        ], $extra);
    }

    // ─────────────────────────────────────────────────────────────
    // A6 Q8 — Báo cáo trực lớp
    // ─────────────────────────────────────────────────────────────

    public function test_q8_report_with_photos_auto_completes_duty_task_without_confirmation(): void
    {
        Storage::fake('public');
        $teacher = $this->makeUser('teacher', $this->branch);
        $ta = $this->makeUser('assistant', $this->branch);
        $academic = $this->makeUser('academic_staff', $this->branch);
        $class = $this->makeClass(['teacher_id' => $teacher->id, 'assistant_id' => $ta->id]);
        $task = $this->dutyTask($academic, $ta, $class);

        $this->actingAs($ta)->post(route('tasks.class-reports.store'), $this->reportPayload($class, [
            'task_id' => $task->id,
            'board_images' => [UploadedFile::fake()->image('bang1.jpg'), UploadedFile::fake()->image('bang2.png')],
        ]))->assertSessionHasNoErrors()->assertRedirect(route('portal.ta-tasks'));

        $report = ClassReport::firstOrFail();
        $this->assertSame(ClassReport::STATUS_APPROVED, $report->status);
        $this->assertCount(2, $report->images());
        $this->assertNull($report->confirmer_id);
        foreach ($report->images() as $path) {
            Storage::disk('public')->assertExists($path);
        }
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);
        $this->assertSame(0, AdminNotification::where('type', 'class_report_pending')->count());
    }

    public function test_q8_report_without_photo_waits_only_for_main_teacher(): void
    {
        $teacher = $this->makeUser('teacher', $this->branch);
        $ta = $this->makeUser('assistant', $this->branch);
        $academic = $this->makeUser('academic_staff', $this->branch);
        $manager = $this->makeUser('manager', $this->branch);
        $class = $this->makeClass(['teacher_id' => $teacher->id, 'assistant_id' => $ta->id]);
        $task = $this->dutyTask($academic, $ta, $class);

        // Không gửi task_id: hệ thống tự gắn đầu việc "Trực lớp" đang mở của TA cho lớp trong ngày.
        $this->actingAs($ta)->post(route('tasks.class-reports.store'), $this->reportPayload($class))->assertSessionHasNoErrors();

        $report = ClassReport::firstOrFail();
        $this->assertSame(ClassReport::STATUS_PENDING, $report->status);
        $this->assertSame('Chờ xác nhận', $report->status_label);
        $this->assertSame($task->id, $report->task_id);
        $this->assertSame($teacher->id, $report->confirmer_id);
        $this->assertSame('pending_confirmation', $task->fresh()->status);

        // Thông báo đúng 1 người: GV chính (không báo Học vụ / Quản lý / Admin).
        $this->assertSame([$teacher->id], AdminNotification::where('type', 'class_report_pending')->pluck('user_id')->all());

        // Học vụ (người giao việc), Quản lý cơ sở, Admin, TA đều không xác nhận được khi lớp có GV chính.
        foreach ([$academic, $manager, $this->admin, $ta] as $other) {
            $this->actingAs($other)->post(route('tasks.class-reports.approve', $report->id))->assertForbidden();
            $this->actingAs($other)->post(route('tasks.approve', $task->id))->assertForbidden();
        }
        $this->actingAs($academic)->get(route('tasks.manual-approvals'))->assertOk()->assertDontSee('Buổi 5 - Listening');

        $this->actingAs($teacher)->get(route('tasks.manual-approvals'))->assertOk()
            ->assertSee('Buổi 5 - Listening')->assertSee('GV chính của lớp');
        $this->actingAs($teacher)->post(route('tasks.approve', $task->id), ['admin_note' => 'OK'])->assertRedirect(route('tasks.manual-approvals'));

        $this->assertSame(ClassReport::STATUS_APPROVED, $report->fresh()->status);
        $this->assertSame($teacher->id, $report->fresh()->approved_by);
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame($teacher->id, $task->fresh()->confirmed_by);
    }

    public function test_q8_class_without_main_teacher_is_confirmed_by_task_assigner(): void
    {
        $ta = $this->makeUser('assistant', $this->branch);
        $academic = $this->makeUser('academic_staff', $this->branch);
        $manager = $this->makeUser('manager', $this->branch);
        $class = $this->makeClass(['teacher_id' => null, 'assistant_id' => $ta->id]);
        $task = $this->dutyTask($academic, $ta, $class);

        $this->actingAs($ta)->post(route('tasks.class-reports.store'), $this->reportPayload($class, ['task_id' => $task->id]))
            ->assertSessionHasNoErrors();
        $report = ClassReport::firstOrFail();
        $this->assertSame($academic->id, $report->confirmer_id);
        $this->assertSame([$academic->id], AdminNotification::where('type', 'class_report_pending')->pluck('user_id')->all());

        $this->actingAs($manager)->post(route('tasks.class-reports.approve', $report->id))->assertForbidden();

        // Trả về → đầu việc quay lại "Đang thực hiện", báo người nộp.
        $this->actingAs($academic)->post(route('tasks.class-reports.reject', $report->id), ['reason' => 'Thiếu nhật ký'])->assertSessionHasNoErrors();
        $this->assertSame(ClassReport::STATUS_REJECTED, $report->fresh()->status);
        $this->assertSame('Thiếu nhật ký', $report->fresh()->rejection_reason);
        $this->assertSame('in_progress', $task->fresh()->status);

        // Nộp lại (không ảnh) → người giao việc xác nhận.
        $this->actingAs($ta)->post(route('tasks.class-reports.store'), $this->reportPayload($class, ['task_id' => $task->id]))->assertSessionHasNoErrors();
        $second = ClassReport::latest('id')->firstOrFail();
        $this->actingAs($academic)->post(route('tasks.class-reports.approve', $second->id))->assertSessionHasNoErrors();
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame($academic->id, $task->fresh()->confirmed_by);
    }

    public function test_q8_without_photo_and_without_any_confirmer_is_rejected(): void
    {
        $ta = $this->makeUser('assistant', $this->branch);
        $class = $this->makeClass(['teacher_id' => null, 'assistant_id' => $ta->id]);

        $this->actingAs($ta)->post(route('tasks.class-reports.store'), $this->reportPayload($class))
            ->assertSessionHasErrors('board_images');
        $this->assertSame(0, ClassReport::count());

        // Có ảnh thì nộp được (không cần người xác nhận).
        Storage::fake('public');
        $this->actingAs($ta)->post(route('tasks.class-reports.store'), $this->reportPayload($class, [
            'board_images' => [UploadedFile::fake()->image('bang.jpg')],
        ]))->assertSessionHasNoErrors();
        $this->assertSame(ClassReport::STATUS_APPROVED, ClassReport::firstOrFail()->status);
    }

    public function test_q8_report_form_lists_only_own_classes_and_roster(): void
    {
        $teacher = $this->makeUser('teacher', $this->branch);
        $ta = $this->makeUser('assistant', $this->branch);
        $mine = $this->makeClass(['teacher_id' => $teacher->id, 'assistant_id' => $ta->id, 'name' => 'Lớp của TA']);
        $this->makeClass(['name' => 'Lớp người khác']);

        $this->actingAs($ta)->get(route('tasks.class-reports.create'))
            ->assertOk()
            ->assertSee('Lớp của TA')
            ->assertDontSee('Lớp người khác')
            ->assertSee('chờ GV chính xác nhận')
            ->assertSee($teacher->name)
            ->assertSee('board_images[]', false);

        // Người khác không nộp được báo cáo cho lớp không phụ trách.
        $outsider = $this->makeUser('assistant', $this->branch);
        $this->actingAs($outsider)->post(route('tasks.class-reports.store'), $this->reportPayload($mine))->assertForbidden();
    }
}
