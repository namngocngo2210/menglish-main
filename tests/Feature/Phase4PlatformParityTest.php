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

    // ─────────────────────────────────────────────────────────────
    // Trợ giảng 3 ca (Phase 4 mục 4) — Tạo lượt giao việc + Nhiệm vụ hôm nay
    // ─────────────────────────────────────────────────────────────

    public function test_ta_assign_three_shifts_uses_real_session_times_and_reports_late_submission(): void
    {
        \Illuminate\Support\Carbon::setTestNow(today()->setTime(16, 10));
        $ta = $this->makeUser('assistant', $this->branch);
        $teacher = $this->makeUser('teacher', $this->branch);
        $academic = $this->makeUser('academic_staff', $this->branch);
        $class = $this->makeClass(['teacher_id' => $teacher->id, 'assistant_id' => $ta->id]);
        $session = \App\Models\ClassSession::create([
            'class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => today(), 'shift_name' => 'Slot 1',
            'type' => 'regular', 'start_time' => '17:30', 'end_time' => '19:00', 'teacher_id' => $teacher->id,
            'assistant_id' => $ta->id, 'status' => 'scheduled',
        ]);

        $this->actingAs($academic)->get(route('tasks.ta-assign'))->assertOk()
            ->assertSee('Khuyến nghị gửi trước 15h30')
            ->assertSee($ta->name)
            ->assertDontSee($teacher->email); // chỉ trợ giảng trong ô "Chọn Trợ giảng"

        // Gắn lớp mà không chọn lớp → lỗi; giao cho người không phải trợ giảng → lỗi.
        $base = ['assistant_id' => $ta->id, 'assign_date' => today()->toDateString(), 'branch_id' => $this->branch->id];
        $this->actingAs($academic)->post(route('tasks.ta-assign.store'), $base + ['tasks' => [['category' => 'before', 'content' => 'X', 'attach_class' => '1']]])
            ->assertSessionHasErrors('tasks.0.class_id');
        $this->actingAs($academic)->post(route('tasks.ta-assign.store'), ['assistant_id' => $teacher->id] + $base + ['tasks' => [['category' => 'before', 'content' => 'X']]])
            ->assertSessionHasErrors('assistant_id');

        $this->actingAs($academic)->post(route('tasks.ta-assign.store'), $base + ['tasks' => [
            ['category' => 'before', 'content' => 'Chuẩn bị tài liệu', 'attach_class' => '1', 'class_id' => $class->id, 'class_session_id' => $session->id],
            ['category' => 'during', 'content' => 'Hỗ trợ GVNN', 'attach_class' => '1', 'class_id' => $class->id, 'class_session_id' => $session->id],
            ['category' => 'after', 'content' => 'Dọn phòng', 'attach_class' => '1', 'class_id' => $class->id, 'class_session_id' => $session->id],
            ['category' => 'after', 'content' => 'Cập nhật điểm danh', 'attach_class' => '0'],
        ]])->assertSessionHasNoErrors()->assertRedirect(route('tasks.index'));

        $due = WorkTask::where('assignee_id', $ta->id)->pluck('due_time', 'title')->map(fn ($t) => substr($t, 0, 5));
        $this->assertSame('17:30', $due['Chuẩn bị tài liệu']);
        $this->assertSame('19:00', $due['Hỗ trợ GVNN']);
        $this->assertSame('20:00', $due['Dọn phòng']);
        $this->assertSame('21:30', $due['Cập nhật điểm danh']);
        $this->assertStringContainsString('(', WorkTask::where('title', 'Dọn phòng')->value('lesson_session'));

        // Gửi sau 15h30 → báo Admin; TA nhận 1 thông báo gộp.
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->admin->id, 'title' => 'Giao việc trợ giảng sau 15:30']);
        $this->assertSame(1, AdminNotification::where('user_id', $ta->id)->where('type', 'task_assigned')->count());

        // Portal TA: 3 nhóm ca, đầu việc gắn lớp có nút "Nộp báo cáo trực lớp".
        $this->actingAs($ta)->get(route('portal.ta-tasks'))->assertOk()
            ->assertSeeInOrder(['Trước giờ học', 'Chuẩn bị tài liệu', 'Trong giờ học', 'Hỗ trợ GVNN', 'Sau giờ học', 'Dọn phòng'])
            ->assertSee('Nộp báo cáo trực lớp');

        // Qua hạn → lệnh đánh dấu Quá hạn, thẻ hiện "Trễ N giờ" + "Hoàn thành gấp".
        \Illuminate\Support\Carbon::setTestNow(today()->setTime(21, 45));
        $this->artisan('tasks:mark-overdue')->assertSuccessful();
        $this->assertSame('overdue', WorkTask::where('title', 'Chuẩn bị tài liệu')->value('status'));
        $this->assertSame('overdue', WorkTask::where('title', 'Dọn phòng')->value('status'));
        $this->artisan('tasks:mark-overdue')->assertSuccessful(); // idempotent: không báo trùng
        $this->assertSame(4, AdminNotification::where('user_id', $ta->id)->where('title', 'like', 'Công việc quá hạn%')->count());
        $this->actingAs($ta)->get(route('portal.ta-tasks'))->assertOk()->assertSee('Trễ 5 giờ')->assertSee('Hoàn thành gấp');

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_task_list_modal_reason_rules_labels_and_recurring_next_occurrence(): void
    {
        $academic = $this->makeUser('academic_staff', $this->branch);
        $teacher = $this->makeUser('teacher', $this->branch);

        $this->actingAs($academic)->get(route('tasks.index'))->assertOk()
            ->assertSee('Giao việc mới')->assertSee('Lưu và Giao việc')
            ->assertSee($teacher->name.' (Giáo viên)')          // nhãn vai trò, không phải mã "teacher"
            ->assertDontSee('('.$teacher->name.' (teacher)', false)
            ->assertSeeInOrder(['Của tôi', 'Tôi giao', 'Tất cả']);

        // Lỗi validate mở lại modal Giao việc với lỗi từng trường.
        $this->actingAs($academic)->post(route('tasks.store'), ['taskTitle' => '', 'assignee' => $teacher->id, 'taskType' => 'one_time'])
            ->assertSessionHasErrors(['taskTitle', 'dueDate']);

        $task = WorkTask::create(['title' => 'Kiểm kê kho tháng', 'creator_id' => $academic->id, 'assignee_id' => $teacher->id,
            'due_date' => today(), 'task_type' => 'recurring', 'frequency' => 'monthly', 'status' => 'in_progress']);

        // Bị chặn bắt buộc lý do.
        $this->actingAs($teacher)->post(route('tasks.status.update', $task->id), ['status' => 'blocked'])->assertSessionHasErrors('reason');
        $this->assertSame('in_progress', $task->fresh()->status);
        $this->actingAs($teacher)->post(route('tasks.status.update', $task->id), ['status' => 'blocked', 'reason' => 'Thiếu đề bài từ GV'])->assertSessionHasNoErrors();
        $this->assertSame('Thiếu đề bài từ GV', $task->fresh()->blocked_reason);
        $this->actingAs($teacher)->post(route('tasks.status.update', $task->id), ['status' => 'in_progress']);

        // Gửi chờ xác nhận → báo người giao việc; xác nhận → việc lặp sinh lượt tháng sau.
        $this->actingAs($teacher)->post(route('tasks.status.update', $task->id), ['status' => 'pending_confirmation', 'reason' => 'Đã kiểm kê']);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $academic->id, 'title' => 'Việc chờ xác nhận: Kiểm kê kho tháng']);
        $this->actingAs($academic)->post(route('tasks.approve', $task->id))->assertRedirect(route('tasks.manual-approvals'));
        $next = WorkTask::where('title', 'Kiểm kê kho tháng')->where('status', 'new')->firstOrFail();
        $this->assertSame(today()->addMonthNoOverflow()->toDateString(), $next->due_date->toDateString());
        $this->assertSame($teacher->id, $next->assignee_id);
    }

    public function test_kpi_board_period_range_and_staff_scope(): void
    {
        $teacher = $this->makeUser('teacher', $this->branch, ['name' => 'Giáo viên KPI Một']);
        $this->makeUser('teacher', $this->branch, ['name' => 'Giáo viên KPI Hai']);
        $this->makeUser('teacher', $this->otherBranch, ['name' => 'Giáo viên KPI Ba']);
        $manager = $this->makeUser('manager', $this->branch);

        $this->actingAs($this->admin)->get(route('tasks.kpi-dashboard', ['month' => '2026-08', 'month_to' => '2026-09']))->assertOk()
            ->assertSee('Tháng 08/2026 - Tháng 09/2026')
            ->assertSee('Giáo viên KPI Ba');

        $this->actingAs($manager)->get(route('tasks.kpi-dashboard'))->assertOk()
            ->assertSee('Giáo viên KPI Hai')->assertDontSee('Giáo viên KPI Ba');

        // Giáo viên chỉ thấy KPI của chính mình (kể cả khi cố truyền user_id người khác).
        $this->actingAs($teacher)->get(route('tasks.kpi-dashboard', ['user_id' => $this->admin->id]))->assertOk()
            ->assertSee('Giáo viên KPI Một')->assertDontSee('Giáo viên KPI Hai')->assertSee('KPI của chính mình');
    }

    // ─────────────────────────────────────────────────────────────
    // Tài khoản & vai trò
    // ─────────────────────────────────────────────────────────────

    public function test_accounts_list_shows_concurrent_roles_classes_contract_and_filters(): void
    {
        $lead = $this->makeUser('academic_lead', $this->branch, ['name' => 'Học thuật Một']);
        $staff = $this->makeUser('academic_staff', $this->branch, ['name' => 'Học vụ Kiêm TA', 'contract_type' => 'Toàn thời gian', 'contract_end_date' => now()->subDay()]);
        $staff->assignRole('assistant');
        $this->makeClass(['assistant_id' => $staff->id, 'code' => 'KN-01', 'name' => 'Lớp kiêm nhiệm']);
        $locked = $this->makeUser('teacher', $this->branch, ['name' => 'GV Bị khóa', 'locked_at' => now()]);

        $response = $this->actingAs($this->admin)->get(route('users.index'))->assertOk()
            ->assertSee('Quản lý Tài khoản &amp; Vai trò', false)
            ->assertSee('Trợ giảng (kiêm nhiệm)')
            ->assertSee('HĐ đã hết hạn')
            ->assertSee('KN-01')                 // lớp phụ trách trong hồ sơ nhanh (drawer)
            ->assertDontSee('Chưa có thông tin phân công kiêm nhiệm phát sinh');
        $this->assertSame(3, $response->viewData('academicStaff')); // Học thuật + Học vụ + Giáo viên

        $this->actingAs($this->admin)->get(route('users.index', ['status' => 'locked']))->assertOk()
            ->assertSee('GV Bị khóa')->assertDontSee('Học thuật Một');

        $this->actingAs($this->admin)->get(route('users.show', $staff))->assertOk()
            ->assertSee('Đã hết hạn')->assertSee('Kiêm nhiệm: Trợ giảng')->assertSee('Lớp kiêm nhiệm');
        $this->assertNotNull($lead);
        $this->assertNotNull($locked);
    }

    public function test_account_form_sets_concurrent_roles_within_hierarchy(): void
    {
        $staff = $this->makeUser('teacher', $this->branch);
        $academic = $this->makeUser('academic_staff', $this->branch);

        $this->actingAs($this->admin)->get(route('users.edit', $staff))->assertOk()->assertSee('Vai trò kiêm nhiệm');

        $payload = ['name' => $staff->name, 'email' => $staff->email, 'branch_id' => $this->branch->id, 'role' => 'teacher', 'concurrent_roles_present' => 1];
        $this->actingAs($this->admin)->put(route('users.update', $staff), $payload + ['concurrent_roles' => ['assistant', 'academic_staff']])
            ->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['teacher', 'assistant', 'academic_staff'], $staff->fresh()->getRoleNames()->all());

        // Bỏ chọn hết → chỉ còn vai trò chính.
        $this->actingAs($this->admin)->put(route('users.update', $staff), $payload)->assertSessionHasNoErrors();
        $this->assertSame(['teacher'], $staff->fresh()->getRoleNames()->all());

        // Học vụ chỉ gán được vai trò trong phân cấp của mình (không gán Admin làm kiêm nhiệm).
        $this->actingAs($academic)->put(route('users.update', $staff), $payload + ['concurrent_roles' => ['admin']])
            ->assertSessionHasErrors('concurrent_roles');
        $this->assertFalse($staff->fresh()->hasRole('admin'));
    }

    // ─────────────────────────────────────────────────────────────
    // Phân quyền cá nhân
    // ─────────────────────────────────────────────────────────────

    public function test_personal_permission_matrix_uses_checkboxes_scope_and_summary(): void
    {
        $teacher = $this->makeUser('teacher', $this->branch);
        $roleCount = $teacher->getAllPermissions()->count();

        $response = $this->actingAs($this->admin)->get(route('users.permissions.edit', $teacher))->assertOk()
            ->assertSee('Cấu hình quyền chi tiết')
            ->assertSee('Ghi chú bảo mật quan trọng')
            ->assertSeeInOrder(['Xem', 'Thêm', 'Sửa', 'Xóa', 'Phạm vi áp dụng'])
            ->assertSee('type="checkbox"', false)
            ->assertSee('Chọn tất cả')->assertSee('Bỏ chọn')->assertSee('Đặt lại mặc định')
            ->assertSee('Không có quyền truy cập')
            ->assertSee($roleCount.' thao tác cho phép')
            ->assertSee('scope[class][type]', false);
        $this->assertSame($roleCount, $response->viewData('effectiveCount'));

        // Bỏ tích 1 quyền vai trò có (thu hồi) + tích 1 quyền vai trò không có (cấp thêm) theo chi nhánh.
        $this->actingAs($this->admin)->put(route('users.permissions.update', $teacher), [
            'overrides' => ['class' => ['view' => 'inherit', 'delete' => 'allow'], 'work_task' => ['view' => 'deny']],
            'scope' => ['class' => ['type' => 'branch', 'ids' => [$this->branch->id]]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('users.permissions.edit', $teacher))->assertOk()
            ->assertSee('Cấp thêm')->assertSee('Thu hồi')
            ->assertSee('01 đơn vị quản lý')
            ->assertSee('Chi nhánh Một');
    }

    // ─────────────────────────────────────────────────────────────
    // Danh mục hệ thống
    // ─────────────────────────────────────────────────────────────

    public function test_system_categories_tabs_side_panel_suggested_code_and_reactivate(): void
    {
        \App\Models\SystemCategory::create(['type' => 'lead_source', 'code' => 'SRC_01', 'name' => 'Facebook Ads', 'sort_order' => 1, 'is_active' => true]);
        $stopped = \App\Models\SystemCategory::create(['type' => 'lead_source', 'code' => 'SRC_03', 'name' => 'Giới thiệu', 'sort_order' => 3, 'is_active' => false]);

        $this->actingAs($this->admin)->get(route('system-categories.index', ['type' => 'bogus']))->assertOk()
            ->assertSee('Cấu hình các tham số nền tảng của hệ thống MENGLISH.')
            ->assertSeeInOrder(['Nguồn khách hàng', 'Lý do không chốt', 'Chức vụ', 'Mức phạt'])
            ->assertSee('Thêm giá trị mới')->assertSee('Làm mới')
            ->assertSee('value="SRC_04"', false)        // mã gợi ý kế tiếp
            ->assertSee('Kích hoạt lại')
            ->assertDontSee('lead_source</option>', false);

        $this->actingAs($this->admin)->post(route('system-categories.store'), ['type' => 'lead_source', 'code' => 'SRC_04', 'name' => 'Google Search', 'is_active' => 1])
            ->assertRedirect(route('system-categories.index', ['type' => 'lead_source']));
        $this->assertSame(4, \App\Models\SystemCategory::where('code', 'SRC_04')->value('sort_order')); // thứ tự tự nối cuối

        // Sửa mở panel trên trang danh sách.
        $this->actingAs($this->admin)->get(route('system-categories.edit', $stopped))
            ->assertRedirect(route('system-categories.index', ['type' => 'lead_source', 'edit' => $stopped->id]));
        $this->actingAs($this->admin)->get(route('system-categories.index', ['type' => 'lead_source', 'edit' => $stopped->id]))
            ->assertOk()->assertSee('Sửa giá trị')->assertSee('value="Giới thiệu"', false);

        $this->actingAs($this->admin)->post(route('system-categories.reactivate', $stopped))->assertRedirect();
        $this->assertTrue($stopped->fresh()->is_active);
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
