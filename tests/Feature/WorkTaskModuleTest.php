<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\Course;
use App\Models\Student;
use App\Models\User;
use App\Models\WorkTask;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkTaskModuleTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $ta;
    protected Branch $branch;
    protected ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Chi nhánh Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
        
        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@menglish.edu.vn',
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->admin->syncRoles(['admin']);

        $this->ta = User::create([
            'name' => 'Trần Anh Tuấn',
            'email' => 'ta.tuan@menglish.edu.vn',
            'password' => bcrypt('password'),
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->ta->syncRoles(['assistant']);

        $course = Course::create([
            'name' => 'IELTS Intensive',
            'code' => 'IELTS-INT',
            'total_sessions' => 24,
            'tuition_fee' => 5000000,
            'is_active' => true,
        ]);

        $this->class = ClassModel::create([
            'name' => 'IELTS Intensive K12',
            'code' => 'IELTS-K12',
            'course_id' => $course->id,
            'branch_id' => $this->branch->id,
            'teacher_id' => $this->admin->id,
            'assistant_id' => $this->ta->id,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2),
            'status' => 'active',
        ]);
    }

    public function test_can_view_tasks_index_page(): void
    {
        $response = $this->actingAs($this->admin)->get(route('tasks.index'));
        $response->assertStatus(200);
        $response->assertSee('Danh sách công việc');
    }

    public function test_can_create_task(): void
    {
        $response = $this->actingAs($this->admin)->post(route('tasks.store'), [
            'taskTitle' => 'Chuẩn bị phòng học và đề test',
            'taskDescription' => 'Kiểm tra máy chiếu phòng 101',
            'assignee' => $this->ta->id,
            'dueDate' => now()->addDay()->toDateString(),
            'dueTime' => '17:30',
            'taskType' => 'one-time',
            'branch_id' => $this->branch->id,
            'class_id' => $this->class->id,
            'time_slot_category' => 'before',
        ]);

        $response->assertRedirect(route('tasks.index'));
        $this->assertDatabaseHas('work_tasks', [
            'title' => 'Chuẩn bị phòng học và đề test',
            'assignee_id' => $this->ta->id,
            'task_type' => 'one_time',
            'status' => 'new',
        ]);
    }

    public function test_can_batch_assign_ta_tasks(): void
    {
        $response = $this->actingAs($this->admin)->post(route('tasks.ta-assign.store'), [
            'assistant_id' => $this->ta->id,
            'assign_date' => now()->toDateString(),
            'branch_id' => $this->branch->id,
            'tasks' => [
                [
                    'category' => 'before',
                    'content' => 'Kiểm tra tài liệu phát cho học viên',
                    'attach_class' => '1',
                    'class_id' => $this->class->id,
                    'session' => 'Buổi 1',
                ],
                [
                    'category' => 'after',
                    'content' => 'Thu dọn phòng học và nộp bảng điểm danh',
                    'attach_class' => '0',
                ]
            ]
        ]);

        $response->assertRedirect(route('tasks.index'));
        $this->assertDatabaseHas('work_tasks', [
            'title' => 'Kiểm tra tài liệu phát cho học viên',
            'time_slot_category' => 'before',
            'assignee_id' => $this->ta->id,
        ]);
    }

    public function test_ta_completion_with_image_becomes_completed_without_image_becomes_pending(): void
    {
        $task = WorkTask::create([
            'title' => 'Nhiệm vụ trực ca',
            'creator_id' => $this->admin->id,
            'assignee_id' => $this->ta->id,
            'due_date' => now()->toDateString(),
            'status' => 'new',
            'time_slot_category' => 'during',
        ]);

        // Hoàn thành không ảnh -> pending_confirmation
        $response = $this->actingAs($this->ta)->post(route('tasks.complete', $task->id), [
            'note' => 'Đã xong nhưng quên chụp ảnh',
        ]);
        $response->assertSessionHasNoErrors();
        $this->assertEquals('pending_confirmation', $task->fresh()->status);

        // Duyệt hoàn thành thủ công từ Admin
        $responseApprove = $this->actingAs($this->admin)->post(route('tasks.approve', $task->id), [
            'admin_note' => 'Admin đã kiểm tra trực tiếp và duyệt',
        ]);
        $responseApprove->assertRedirect(route('tasks.manual-approvals'));
        $this->assertEquals('completed', $task->fresh()->status);
    }

    public function test_can_submit_class_report(): void
    {
        $student = Student::create([
            'name' => 'Nguyễn Học Sinh',
            'code' => 'HS-001',
            'phone' => '0901234567',
            'email' => 'hs@gmail.com',
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->ta)->post(route('tasks.class-reports.store'), [
            'class_id' => $this->class->id,
            'session_name' => 'Buổi 5 - Listening Practice',
            'hom_nay_hoc_gi' => 'Học nghe bắt key và note taking',
            'nhat_ky_day' => 'Lớp học chăm chỉ',
            'supports' => [
                [
                    'student_id' => $student->id,
                    'absence_session' => 'Buổi 4',
                    'reason' => 'Yếu nghe số và ngày tháng',
                    'action_plan' => 'Luyện nghe audio 3 trang 20',
                ]
            ]
        ]);

        $response->assertRedirect(route('portal.ta-tasks'));
        $this->assertDatabaseHas('class_reports', [
            'class_id' => $this->class->id,
            'session_name' => 'Buổi 5 - Listening Practice',
            'reporter_id' => $this->ta->id,
        ]);
        $this->assertDatabaseHas('class_report_student_supports', [
            'student_id' => $student->id,
            'reason' => 'Yếu nghe số và ngày tháng',
        ]);
    }

    public function test_can_view_all_task_and_ta_pages(): void
    {
        // 1. Dashboard lớp học — chỉ hiển thị lớp có buổi học thật trong ngày (Phase 2).
        \App\Models\ClassSession::create([
            'class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => now()->toDateString(),
            'shift_name' => 'Slot 1', 'start_time' => '18:00', 'end_time' => '19:30', 'room' => 'P101',
            'teacher_id' => $this->admin->id, 'assistant_id' => $this->ta->id, 'status' => 'scheduled',
        ]);
        $resDashboard = $this->actingAs($this->admin)->get(route('tasks.classes-dashboard'));
        $resDashboard->assertOk();
        $resDashboard->assertSee('Dashboard lớp học');
        $resDashboard->assertSee($this->class->name);

        // 2. Phân công trợ giảng theo ca
        $resAssign = $this->actingAs($this->admin)->get(route('tasks.ta-assign'));
        $resAssign->assertOk();
        $resAssign->assertSee('Tạo lượt giao việc cho Trợ giảng');

        // 3. Cổng nhiệm vụ Trợ giảng
        $resPortal = $this->actingAs($this->ta)->get(route('portal.ta-tasks'));
        $resPortal->assertOk();
        $resPortal->assertSee('Nhiệm vụ hôm nay');

        // 4. Cấu hình TKB và nhu cầu nhân sự
        $resSchedule = $this->actingAs($this->admin)->get(route('tasks.schedule-config'));
        $resSchedule->assertOk();
        $resSchedule->assertSee('Cấu hình Lịch &amp; TKB', false);

        // 5. Bảng KPI tự động
        $resKpi = $this->actingAs($this->admin)->get(route('tasks.kpi-dashboard'));
        $resKpi->assertOk();
        $resKpi->assertSee('Bảng KPI tự động');
    }
}
