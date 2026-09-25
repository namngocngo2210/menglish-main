<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\Student;
use App\Models\SupportTicket;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SystemCategory;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Models\WorkTask;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Phase 4 — Nền tảng: nhật ký, phân quyền phạm vi, tài khoản, giao việc,
 * ticket, portal học viên, dashboard theo vai trò.
 */
class Phase4PlatformTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;

    private Branch $branchB;

    private User $admin;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branchA = Branch::create(['name' => 'Chi nhánh A', 'code' => 'CNA', 'address' => 'HN', 'is_active' => true]);
        $this->branchB = Branch::create(['name' => 'Chi nhánh B', 'code' => 'CNB', 'address' => 'HCM', 'is_active' => true]);
        $this->admin = $this->makeUser('admin', $this->branchA);
        $this->course = Course::create([
            'name' => 'IELTS Foundation', 'code' => 'IELTS-F', 'total_sessions' => 24,
            'tuition_fee' => 5000000, 'is_active' => true,
        ]);
    }

    private function makeUser(string $role, ?Branch $branch = null, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'branch_id' => $branch?->id,
            'is_active' => true,
        ], $attributes));
        $user->assignRole($role);

        return $user;
    }

    private function makeClass(Branch $branch, array $attributes = []): ClassModel
    {
        static $n = 0;
        $n++;

        return ClassModel::create(array_merge([
            'name' => "Lớp {$branch->code} {$n}",
            'code' => "{$branch->code}-{$n}",
            'course_id' => $this->course->id,
            'branch_id' => $branch->id,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2),
            'status' => 'active',
        ], $attributes));
    }

    // ─────────────────────────────────────────────────────────────
    // 1. Nhật ký thao tác
    // ─────────────────────────────────────────────────────────────

    public function test_user_update_is_logged_once_with_before_after_and_masked_secrets(): void
    {
        $staff = $this->makeUser('teacher', $this->branchA, ['name' => 'Tên cũ', 'phone' => '0900000001']);
        Activity::query()->delete();

        $this->actingAs($this->admin)->put(route('users.update', $staff), [
            'name' => 'Tên mới',
            'email' => $staff->email,
            'phone' => '0900000002',
            'branch_id' => $this->branchA->id,
            'role' => 'teacher',
            'id_card_number' => '001099000111',
        ])->assertRedirect(route('users.index'));

        $logs = Activity::query()->get();
        $this->assertCount(1, $logs, 'Một thao tác sửa chỉ được ghi 1 dòng nhật ký (không trùng middleware + controller).');

        $log = $logs->first();
        $this->assertSame('updated', $log->event);
        $this->assertSame(User::class, $log->subject_type);
        $this->assertSame('Tên cũ', $log->properties['old']['name']);
        $this->assertSame('Tên mới', $log->properties['attributes']['name']);
        $this->assertSame('***', $log->properties['attributes']['id_card_number']);
        $this->assertArrayNotHasKey('password', $log->properties['attributes']);
        $this->assertSame($this->admin->id, $log->causer_id);
        $this->assertArrayHasKey('ip', $log->properties->toArray());
    }

    public function test_request_without_model_change_still_logged_once_by_middleware(): void
    {
        Activity::query()->delete();

        $this->actingAs($this->admin)->post(route('placement-tests.store'), [
            'code' => 'TEST-P4',
            'title' => 'Đề Phase 4',
            'target_level' => 'B1',
            'duration_minutes' => 45,
            'questions_count' => 10,
        ]);

        $this->assertSame(1, Activity::query()->where('log_name', 'Khảo sát & Đề thi')->count());
    }

    public function test_activity_log_filters_export_and_diff_view(): void
    {
        $staff = $this->makeUser('teacher', $this->branchA, ['name' => 'Nhân sự Nhật ký']);
        $this->actingAs($this->admin)->put(route('users.update', $staff), [
            'name' => 'Nhân sự Đã sửa', 'email' => $staff->email, 'branch_id' => $this->branchA->id, 'role' => 'teacher',
        ]);

        $this->actingAs($this->admin)->get(route('activity-logs.index', ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]))
            ->assertOk()
            ->assertSee('So sánh trước / sau')
            ->assertSee('Nhân sự Đã sửa')
            ->assertSee('Hoàn tác')
            ->assertSee('Xuất Excel');

        $this->actingAs($this->admin)->get(route('activity-logs.index', ['date_from' => now()->addDay()->toDateString()]))
            ->assertOk()
            ->assertDontSee('So sánh trước / sau');

        $response = $this->actingAs($this->admin)->get(route('activity-logs.export'));
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Nhân sự Đã sửa', $csv);
        $this->assertStringContainsString('name: Nhân sự Nhật ký → Nhân sự Đã sửa', $csv);
    }

    public function test_admin_can_undo_simple_update_but_manager_cannot(): void
    {
        $staff = $this->makeUser('teacher', $this->branchA, ['name' => 'Trước khi sửa']);
        $this->actingAs($this->admin)->put(route('users.update', $staff), [
            'name' => 'Sau khi sửa', 'email' => $staff->email, 'branch_id' => $this->branchA->id, 'role' => 'teacher',
        ]);
        $log = Activity::query()->where('subject_id', $staff->id)->where('event', 'updated')->latest('id')->firstOrFail();

        $manager = $this->makeUser('manager', $this->branchA);
        $this->actingAs($manager)->post(route('activity-logs.undo', $log->id))->assertForbidden();
        $this->assertSame('Sau khi sửa', $staff->fresh()->name);

        $this->actingAs($this->admin)->post(route('activity-logs.undo', $log->id))->assertSessionHasNoErrors();
        $this->assertSame('Trước khi sửa', $staff->fresh()->name);

        // Bản ghi đã đổi sau thao tác -> không hoàn tác lại lần nữa.
        $this->actingAs($this->admin)->post(route('activity-logs.undo', $log->id))->assertSessionHasErrors('undo');
    }

    // ─────────────────────────────────────────────────────────────
    // 2 + 3. Phân quyền theo phạm vi & giới hạn chi nhánh Quản lý
    // ─────────────────────────────────────────────────────────────

    public function test_branch_scoped_override_grants_class_access_only_in_that_branch(): void
    {
        $classA = $this->makeClass($this->branchA);
        $classB = $this->makeClass($this->branchB);
        $sales = $this->makeUser('sales_consultant', $this->branchA);

        $this->actingAs($sales)->get(route('classes.index'))->assertForbidden();

        $this->actingAs($this->admin)->put(route('users.permissions.update', $sales), [
            'overrides' => ['class' => ['view' => 'allow', 'update' => 'allow']],
            'scope' => ['class' => ['type' => 'branch', 'ids' => [$this->branchA->id]]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $sales->id, 'module' => 'class', 'action' => 'view',
            'scope_type' => UserPermissionOverride::SCOPE_BRANCH, 'scope_id' => $this->branchA->id,
        ]);

        $sales->refresh();
        $visible = ClassModel::query()->visibleTo($sales)->pluck('id')->all();
        $this->assertContains($classA->id, $visible);
        $this->assertNotContains($classB->id, $visible);

        $this->actingAs($sales)->get(route('classes.index'))->assertOk();
        $this->assertTrue($classA->userCan($sales, 'update'));
        $this->assertFalse($classB->userCan($sales, 'update'));
        $this->actingAs($sales)->get(route('classes.edit', $classB->id))->assertForbidden();

        // Phân hệ chưa hỗ trợ phạm vi không nhận override theo chi nhánh.
        $this->actingAs($this->admin)->put(route('users.permissions.update', $sales), [
            'overrides' => ['tuition' => ['view' => 'allow']],
            'scope' => ['tuition' => ['type' => 'branch', 'ids' => [$this->branchA->id]]],
        ])->assertSessionHasErrors('scope.tuition.type');
    }

    public function test_permission_matrix_page_renders_crud_columns_and_scope_selector(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA);

        $this->actingAs($this->admin)->get(route('users.permissions.edit', $teacher))
            ->assertOk()
            ->assertSee('Ma trận phân quyền chi tiết')
            ->assertSee('Phạm vi áp dụng')
            ->assertSee('Theo chi nhánh')
            ->assertSee('scope[class][type]', false)
            ->assertDontSee('Cơ sở Cầu Giấy');
    }

    public function test_manager_sees_only_own_branch_classes_users_and_tasks(): void
    {
        $manager = $this->makeUser('manager', $this->branchA);
        $classA = $this->makeClass($this->branchA);
        $classB = $this->makeClass($this->branchB);
        $staffA = $this->makeUser('teacher', $this->branchA, ['name' => 'Giáo viên Chi nhánh A']);
        $staffB = $this->makeUser('teacher', $this->branchB, ['name' => 'Giáo viên Chi nhánh B']);

        $visible = ClassModel::query()->visibleTo($manager)->pluck('id')->all();
        $this->assertContains($classA->id, $visible);
        $this->assertNotContains($classB->id, $visible);
        $this->assertSame(2, ClassModel::query()->visibleTo($this->admin)->count());

        $this->actingAs($manager)->get(route('users.index'))
            ->assertOk()
            ->assertSee('Giáo viên Chi nhánh A')
            ->assertDontSee('Giáo viên Chi nhánh B');
        $this->actingAs($manager)->post(route('users.lock', $staffB))->assertForbidden();
        $this->actingAs($manager)->get(route('classes.edit', $classB->id))->assertForbidden();
        $this->actingAs($manager)->get(route('classes.edit', $classA->id))->assertOk();

        WorkTask::create(['title' => 'Việc chi nhánh A', 'creator_id' => $this->admin->id, 'assignee_id' => $staffA->id, 'branch_id' => $this->branchA->id, 'due_date' => now()->addDay(), 'task_type' => 'one_time', 'status' => 'new']);
        WorkTask::create(['title' => 'Việc chi nhánh B', 'creator_id' => $this->admin->id, 'assignee_id' => $staffB->id, 'branch_id' => $this->branchB->id, 'due_date' => now()->addDay(), 'task_type' => 'one_time', 'status' => 'new']);

        $this->actingAs($manager)->get(route('tasks.index', ['tab' => 'all']))
            ->assertOk()
            ->assertSee('Việc chi nhánh A')
            ->assertDontSee('Việc chi nhánh B');
    }

    // ─────────────────────────────────────────────────────────────
    // 4. Tài khoản
    // ─────────────────────────────────────────────────────────────

    public function test_update_keeps_concurrent_roles_and_new_accounts_must_change_password(): void
    {
        $staff = $this->makeUser('teacher', $this->branchA);
        $staff->assignRole('assistant');

        $this->actingAs($this->admin)->put(route('users.update', $staff), [
            'name' => $staff->name, 'email' => $staff->email, 'branch_id' => $this->branchA->id, 'role' => 'teacher_fulltime',
        ])->assertSessionHasNoErrors();

        $roles = $staff->fresh()->getRoleNames()->all();
        $this->assertContains('teacher_fulltime', $roles);
        $this->assertContains('assistant', $roles, 'Vai trò kiêm nhiệm phải được giữ lại.');
        $this->assertNotContains('teacher', $roles);

        $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'Nhân sự mới', 'email' => 'moi@menglish.edu.vn', 'branch_id' => $this->branchA->id,
            'role' => 'teacher', 'password' => 'MatKhau123!',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(User::where('email', 'moi@menglish.edu.vn')->first()->must_change_password);

        $staff->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->admin)->post(route('users.reset-password', $staff));
        $this->assertTrue($staff->fresh()->must_change_password);
    }

    public function test_contract_file_is_stored_privately_and_downloadable_only_by_authorized_users(): void
    {
        Storage::fake('local');
        $staff = $this->makeUser('teacher', $this->branchA);

        $this->actingAs($this->admin)->put(route('users.update', $staff), [
            'name' => $staff->name, 'email' => $staff->email, 'branch_id' => $this->branchA->id, 'role' => 'teacher',
            'contract_file' => UploadedFile::fake()->create('hop-dong.pdf', 50, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $path = $staff->fresh()->contract_file_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);

        $this->actingAs($this->admin)->get(route('users.contract.download', $staff))->assertOk();
        $this->actingAs($staff)->get(route('users.contract.download', $staff))->assertOk();
        $other = $this->makeUser('teacher', $this->branchA);
        $this->actingAs($other)->get(route('users.contract.download', $staff))->assertForbidden();
    }

    public function test_accounts_screen_has_no_fake_branch_or_phone(): void
    {
        $staff = $this->makeUser('teacher', null, ['phone' => null]);

        $this->actingAs($this->admin)->get(route('users.show', $staff))
            ->assertOk()
            ->assertDontSee('Cơ sở Cầu Giấy')
            ->assertDontSee('0912 345 678');
    }

    // ─────────────────────────────────────────────────────────────
    // 6 + 7. Hợp đồng sắp hết hạn, danh mục
    // ─────────────────────────────────────────────────────────────

    public function test_expiring_contract_command_notifies_admin_and_branch_manager_once(): void
    {
        $managerA = $this->makeUser('manager', $this->branchA);
        $managerB = $this->makeUser('manager', $this->branchB);
        $staff = $this->makeUser('teacher', $this->branchA, ['name' => 'GV Sắp hết HĐ', 'contract_end_date' => now()->addDays(10)]);
        $this->makeUser('teacher', $this->branchA, ['contract_end_date' => now()->addDays(90)]);

        $this->artisan('hr:notify-expiring-contracts')->assertExitCode(0);
        $this->artisan('hr:notify-expiring-contracts')->assertExitCode(0);

        $notified = AdminNotification::where('type', 'contract_expiring')->pluck('user_id')->all();
        $this->assertEqualsCanonicalizing([$this->admin->id, $managerA->id], $notified);
        $this->assertNotContains($managerB->id, $notified);

        $this->actingAs($this->admin)->get(route('users.index'))
            ->assertOk()
            ->assertSee('HĐ sắp hết hạn');
    }

    public function test_inactive_category_can_be_reactivated(): void
    {
        $category = SystemCategory::create(['type' => SystemCategory::TYPE_LEAD_SOURCE, 'code' => 'FB', 'name' => 'Facebook', 'sort_order' => 1, 'is_active' => false]);

        $this->actingAs($this->admin)->get(route('system-categories.index', ['type' => SystemCategory::TYPE_LEAD_SOURCE]))
            ->assertOk()
            ->assertSee('Kích hoạt lại');

        $this->actingAs($this->admin)->post(route('system-categories.reactivate', $category))->assertRedirect();
        $this->assertTrue($category->fresh()->is_active);
    }

    // ─────────────────────────────────────────────────────────────
    // 8. Giao việc
    // ─────────────────────────────────────────────────────────────

    public function test_task_visibility_transitions_and_no_self_approval(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA);
        $other = $this->makeUser('teacher', $this->branchA);
        $academic = $this->makeUser('academic_staff', $this->branchA);

        $mine = WorkTask::create(['title' => 'Việc của tôi', 'creator_id' => $academic->id, 'assignee_id' => $teacher->id, 'due_date' => now()->addDay(), 'task_type' => 'one_time', 'status' => 'new']);
        $notMine = WorkTask::create(['title' => 'Việc người khác', 'creator_id' => $academic->id, 'assignee_id' => $other->id, 'due_date' => now()->addDay(), 'task_type' => 'one_time', 'status' => 'new']);

        $this->actingAs($teacher)->get(route('tasks.index', ['tab' => 'all']))
            ->assertOk()
            ->assertSee('Việc của tôi')
            ->assertDontSee('Việc người khác');

        // Người ngoài không đổi được trạng thái.
        $this->actingAs($teacher)->post(route('tasks.status.update', $notMine->id), ['status' => 'in_progress'])->assertForbidden();

        // Người thực hiện không tự hoàn thành, phải gửi chờ xác nhận.
        $this->actingAs($teacher)->post(route('tasks.status.update', $mine->id), ['status' => 'in_progress']);
        $this->actingAs($teacher)->post(route('tasks.status.update', $mine->id), ['status' => 'completed'])->assertSessionHasErrors('status');
        $this->assertSame('in_progress', $mine->fresh()->status);
        $this->actingAs($teacher)->post(route('tasks.status.update', $mine->id), ['status' => 'pending_confirmation']);
        $this->assertSame('pending_confirmation', $mine->fresh()->status);

        // Chuyển trạng thái không hợp lệ bị chặn.
        $this->actingAs($academic)->post(route('tasks.status.update', $mine->id), ['status' => 'blocked'])->assertSessionHasErrors('status');

        // Không tự duyệt việc của chính mình.
        $selfTask = WorkTask::create(['title' => 'Tự làm tự duyệt', 'creator_id' => $academic->id, 'assignee_id' => $academic->id, 'due_date' => now()->addDay(), 'task_type' => 'one_time', 'status' => 'pending_confirmation']);
        $this->actingAs($academic)->post(route('tasks.approve', $selfTask->id))->assertForbidden();

        $this->actingAs($academic)->post(route('tasks.approve', $mine->id))->assertRedirect(route('tasks.manual-approvals'));
        $this->assertSame('completed', $mine->fresh()->status);
        $this->assertSame($academic->id, $mine->fresh()->confirmed_by);
    }

    public function test_two_way_assignment_and_assignment_notification(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA);
        $otherTeacher = $this->makeUser('teacher', $this->branchA);
        $academic = $this->makeUser('academic_staff', $this->branchA);

        $payload = fn (User $assignee) => [
            'taskTitle' => 'Đề nghị in tài liệu', 'assignee' => $assignee->id,
            'dueDate' => now()->addDays(2)->toDateString(), 'taskType' => 'one_time',
        ];

        $this->actingAs($teacher)->post(route('tasks.store'), $payload($otherTeacher))->assertSessionHasErrors('assignee');
        $this->actingAs($teacher)->post(route('tasks.store'), $payload($academic))->assertSessionHasNoErrors();

        $task = WorkTask::where('assignee_id', $academic->id)->firstOrFail();
        $this->assertSame($teacher->id, $task->creator_id);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $academic->id, 'type' => 'task_assigned']);

        // Người giao (GV) duyệt khi Học vụ gửi chờ xác nhận.
        $this->actingAs($academic)->post(route('tasks.status.update', $task->id), ['status' => 'pending_confirmation']);
        $this->actingAs($teacher)->post(route('tasks.approve', $task->id))->assertRedirect();
        $this->assertSame('completed', $task->fresh()->status);
    }

    public function test_class_report_without_image_waits_for_main_teacher_approval(): void
    {
        $mainTeacher = $this->makeUser('teacher', $this->branchA);
        $ta = $this->makeUser('assistant', $this->branchA);
        $class = $this->makeClass($this->branchA, ['teacher_id' => $mainTeacher->id, 'assistant_id' => $ta->id]);

        $this->actingAs($ta)->post(route('tasks.class-reports.store'), [
            'class_id' => $class->id, 'session_name' => 'Buổi 5', 'hom_nay_hoc_gi' => 'Unit 3',
        ])->assertSessionHasNoErrors();

        $report = ClassReport::firstOrFail();
        $this->assertSame('pending_approval', $report->status);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $mainTeacher->id, 'type' => 'class_report_pending']);

        $this->actingAs($ta)->post(route('tasks.class-reports.approve', $report->id))->assertForbidden();
        $this->actingAs($mainTeacher)->get(route('tasks.manual-approvals'))->assertOk()->assertSee('Buổi 5');
        $this->actingAs($mainTeacher)->post(route('tasks.class-reports.reject', $report->id), ['reason' => 'Thiếu nhật ký'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $report->fresh()->status);

        $report->fresh()->update(['status' => 'pending_approval']);
        $this->actingAs($mainTeacher)->post(route('tasks.class-reports.approve', $report->id))->assertSessionHasNoErrors();
        $this->assertSame('approved', $report->fresh()->status);
        $this->assertSame($mainTeacher->id, $report->fresh()->approved_by);
    }

    // ─────────────────────────────────────────────────────────────
    // 9. Ticket
    // ─────────────────────────────────────────────────────────────

    public function test_unassigned_ticket_notifies_branch_dispatchers_personally(): void
    {
        $managerA = $this->makeUser('manager', $this->branchA);
        $managerB = $this->makeUser('manager', $this->branchB);
        $teacher = $this->makeUser('teacher', $this->branchA);

        $this->actingAs($teacher)->post(route('tickets.store'), [
            'title' => 'Máy chiếu hỏng', 'category' => 'technical_issue', 'priority' => 'high', 'description' => 'Phòng 101',
        ])->assertRedirect();

        $this->assertSame(0, AdminNotification::whereNull('user_id')->count(), 'Không phát thông báo chung (user_id null).');
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $managerA->id, 'type' => 'ticket_new']);
        $this->assertDatabaseMissing('admin_notifications', ['user_id' => $managerB->id, 'type' => 'ticket_new']);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->admin->id, 'type' => 'ticket_new']);
    }

    public function test_ticket_assignee_must_be_ticket_handler_and_legacy_attachments_are_guarded(): void
    {
        $manager = $this->makeUser('manager', $this->branchA);
        $teacher = $this->makeUser('teacher', $this->branchA);
        $creator = $this->makeUser('sales_consultant', $this->branchA);

        $legacy = 'uploads/p4-legacy-'.uniqid().'.png';
        file_put_contents(public_path($legacy), base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        try {
            $ticket = SupportTicket::create([
                'code' => 'TK-P4-1', 'title' => 'Ticket cũ', 'category' => 'other', 'priority' => 'low',
                'description' => 'x', 'creator_id' => $creator->id, 'status' => 'open', 'attachment_path' => $legacy,
            ]);

            $this->actingAs($manager)->post(route('tickets.assign', $ticket->id), ['assignee_id' => $teacher->id])
                ->assertSessionHasErrors('assignee_id');
            $this->actingAs($manager)->post(route('tickets.assign', $ticket->id), ['assignee_id' => $manager->id])
                ->assertSessionHasNoErrors();

            $this->actingAs($manager)->get(route('tickets.create'))->assertOk()->assertDontSee($teacher->name);

            // File cũ (public/uploads) vẫn xem được qua route có kiểm tra quyền; người ngoài bị chặn.
            $this->actingAs($creator)->get(route('tickets.attachment', ['id' => $ticket->id, 'path' => $legacy]))->assertOk();
            $this->actingAs($teacher)->get(route('tickets.attachment', ['id' => $ticket->id, 'path' => $legacy]))->assertForbidden();
            auth()->logout();
            $this->get(route('tickets.attachment', ['id' => $ticket->id, 'path' => $legacy]))->assertRedirect(route('login'));
        } finally {
            @unlink(public_path($legacy));
        }
    }

    // ─────────────────────────────────────────────────────────────
    // 10. Portal: giáo viên chấm phát âm
    // ─────────────────────────────────────────────────────────────

    public function test_teacher_grades_pronunciation_submission(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA);
        $class = $this->makeClass($this->branchA, ['teacher_id' => $teacher->id]);
        $studentUser = $this->makeUser('student', $this->branchA);
        $student = Student::create([
            'user_id' => $studentUser->id, 'name' => 'Học viên Phát âm', 'code' => 'HV-P4-1', 'phone' => '0911222333',
            'status' => 'studying', 'current_class_id' => $class->id, 'branch_id' => $this->branchA->id,
        ]);

        $this->actingAs($studentUser)->post(route('portal.student.pronunciation.store'), [
            'student_id' => $student->id, 'unit_title' => 'Unit 2',
        ])->assertSessionHasNoErrors();

        $record = \App\Models\AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/04_luyen_phat_am')->firstOrFail();
        $this->assertSame('pending_review', $record->status);

        $this->actingAs($teacher)->get(route('portal.teacher.submissions', ['classId' => $class->id, 'type' => 'pronunciation']))
            ->assertOk()->assertSee('Học viên Phát âm');
        $this->actingAs($teacher)->post(route('portal.teacher.submissions.mark', ['id' => $record->id]), [])->assertSessionHasErrors('score');
        $this->actingAs($teacher)->post(route('portal.teacher.submissions.mark', ['id' => $record->id]), ['score' => '85/100', 'feedback' => 'Tốt'])->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame('reviewed', $record->status);
        $this->assertSame('85/100', $record->data['score']);
    }

    // ─────────────────────────────────────────────────────────────
    // 11. Dashboard theo vai trò
    // ─────────────────────────────────────────────────────────────

    public function test_role_dashboards_show_real_scoped_numbers(): void
    {
        $studentA = Student::create(['name' => 'HV A', 'code' => 'HV-DA', 'phone' => '0911000001', 'status' => 'studying', 'branch_id' => $this->branchA->id]);
        $studentB = Student::create(['name' => 'HV B', 'code' => 'HV-DB', 'phone' => '0911000002', 'status' => 'studying', 'branch_id' => $this->branchB->id]);
        foreach ([[$studentA, 3000000, 'approved'], [$studentB, 2000000, 'approved'], [$studentA, 999000, 'pending']] as $i => [$student, $amount, $status]) {
            TuitionReceipt::create([
                'receipt_number' => 'PT-P4-'.$i, 'student_id' => $student->id, 'amount' => $amount,
                'payment_method' => 'cash', 'payment_date' => now(), 'creator_id' => $this->admin->id, 'status' => $status,
            ]);
        }
        CrmCustomer::create(['code' => 'KH-P4-1', 'name' => 'Lead mới', 'phone' => '0911000111', 'branch_id' => $this->branchA->id, 'stage' => 'new']);
        $this->makeClass($this->branchA);
        $this->makeClass($this->branchB);
        WorkTask::create(['title' => 'Việc trễ hạn', 'creator_id' => $this->admin->id, 'assignee_id' => $this->admin->id, 'branch_id' => $this->branchA->id, 'due_date' => now()->subDays(2), 'task_type' => 'one_time', 'status' => 'in_progress']);

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Tổng quan toàn hệ thống')
            ->assertSee('5.000.000đ')
            ->assertSee('Việc trễ hạn');

        $manager = $this->makeUser('manager', $this->branchB);
        $this->actingAs($manager)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Tổng quan chi nhánh')
            ->assertSee('2.000.000đ')
            ->assertDontSee('5.000.000đ');

        $lead = $this->makeUser('academic_lead', $this->branchA);
        $class = ClassModel::first();
        SyllabusAdjustmentRequest::create(['class_id' => $class->id, 'user_id' => $this->admin->id, 'request_type' => 'slow_down', 'reason' => 'Lớp cần giãn tiến độ Unit 4', 'status' => 'pending']);
        $this->actingAs($lead)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Tổng quan học thuật')
            ->assertSee('Lớp cần giãn tiến độ Unit 4');

        // Vai trò khác giữ lưới lối tắt, không có dashboard số liệu.
        $teacher = $this->makeUser('teacher', $this->branchA);
        $this->actingAs($teacher)->get(route('dashboard'))->assertOk()->assertDontSee('Tổng quan toàn hệ thống');
    }
}
