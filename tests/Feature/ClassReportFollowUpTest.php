<?php

namespace Tests\Feature;

use App\Http\Controllers\TeacherPortalController;
use App\Models\AcademicRecord;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Homework;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Models\WorkTask;
use App\Support\Dashboard\TeachingQuality;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Báo cáo trực lớp của Trợ giảng theo khối (chủ dự án 17/10/2026): số liệu tự động của buổi (sĩ số, đi muộn, BTVN đã nộp,
 * nhận xét GV), task follow-up gửi kèm báo cáo (gắn lớp + báo cáo, việc chưa xong hiện lại ở báo cáo sau của lớp) và hiện ở màn duyệt.
 */
class ClassReportFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ClassModel $class;

    private User $admin;

    private User $ta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->travelTo(today()->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CA', 'is_active' => true]);
        $this->admin = $this->makeUser('admin', ['name' => 'Admin Trung tâm']);
        $this->ta = $this->makeUser('assistant', ['name' => 'Trợ giảng Mai']);
        $course = Course::create(['name' => 'Starters', 'code' => 'ST', 'total_lessons' => 24, 'tuition_fee' => 3000000, 'is_active' => true]);
        $this->class = ClassModel::create([
            'name' => 'Lớp ST1', 'code' => 'ST1', 'course_id' => $course->id, 'branch_id' => $this->branch->id,
            'teacher_id' => $this->admin->id, 'assistant_id' => $this->ta->id,
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2), 'max_capacity' => 12, 'status' => 'active',
        ]);
    }

    private function makeUser(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['branch_id' => $this->branch->id, 'is_active' => true], $attributes));
        $user->assignRole($role);

        return $user;
    }

    public function test_class_report_shows_session_metrics_and_saves_follow_up_tasks(): void
    {
        $session = ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->subDay()->toDateString(),
            'start_time' => '08:00', 'end_time' => '09:30', 'type' => ClassSession::TYPE_REGULAR, 'status' => 'completed', 'teacher_id' => $this->admin->id]);
        $students = collect(['Nguyễn An' => 'present', 'Trần Bình' => 'late', 'Lê Chi' => 'absent'])->map(function (string $status, string $name) use ($session) {
            $student = Student::create(['code' => 'HV-'.crc32($name), 'name' => $name, 'phone' => '09'.random_int(10000000, 99999999),
                'branch_id' => $this->branch->id, 'status' => 'studying', 'current_class_id' => $this->class->id]);
            StudentAttendance::create(['class_id' => $this->class->id, 'class_session_id' => $session->id, 'student_id' => $student->id,
                'user_id' => $this->admin->id, 'session_date' => $session->date->toDateString(), 'status' => $status]);

            return $student;
        });
        $homework = Homework::create(['class_id' => $this->class->id, 'user_id' => $this->admin->id, 'title' => 'Unit 3 workbook']);
        $homework->forceFill(['created_at' => now()->subDays(3)])->save();
        AcademicRecord::create(['module' => 'homework', 'screen_key' => TeachingQuality::SUBMISSION_SCREEN, 'record_code' => 'SUB-1', 'title' => 'Nộp bài',
            'status' => 'completed', 'data' => ['student_id' => (string) $students['Nguyễn An']->id]]);
        AcademicRecord::create(['module' => 'teacher_remarks', 'screen_key' => 'teacher_remarks', 'record_code' => TeacherPortalController::remarkRecordCode($session),
            'title' => 'Nhận xét', 'status' => 'completed', 'data' => []]);

        $this->actingAs($this->ta)->get(route('tasks.class-reports.create', ['class_id' => $this->class->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tasks/ClassReportCreate')
                ->where("sessionDetails.{$session->id}.metrics", function ($metrics) {
                    $items = collect($metrics)->keyBy('key');
                    $this->assertSame(['2 / 3', '1', '1 / 3', 'Đã gửi'], [$items['attendance']['value'], $items['late']['value'], $items['homework']['value'], $items['remarks']['value']]);
                    $this->assertSame(['Lê Chi'], collect($items['attendance']['details'])->pluck('text')->all());
                    $this->assertStringContainsString('Unit 3 workbook', $items['homework']['details'][0]['text']);

                    return true;
                })
                ->where('followUps', [])
                ->where('assignees', fn ($list) => collect($list)->pluck('value')->contains($this->admin->id)));

        $payload = ['class_id' => $this->class->id, 'class_session_id' => $session->id, 'hom_nay_hoc_gi' => 'Unit 3', 'nhat_ky_day' => 'Máy chiếu phòng 302 chập chờn'];

        // Người nhận phải nằm trong danh sách được giao việc.
        $outsider = $this->makeUser('teacher_fulltime', ['name' => 'GV khác']);
        $this->actingAs($this->ta)->post(route('tasks.class-reports.store'), $payload + [
            'tasks' => [['action' => 'other', 'title' => 'Việc', 'due_date' => today()->toDateString(), 'assignee_id' => $outsider->id]],
        ])->assertSessionHasErrors('tasks.0.assignee_id');
        $this->actingAs($this->ta)->post(route('tasks.class-reports.store'), $payload + [
            'tasks' => [['action' => 'other', 'title' => 'Việc', 'due_date' => today()->subDay()->toDateString()]],
        ])->assertSessionHasErrors('tasks.0.due_date');
        $this->assertSame(0, ClassReport::count());

        $this->actingAs($this->ta)->post(route('tasks.class-reports.store'), $payload + [
            'tasks' => [['action' => 'check_equipment', 'title' => 'Kiểm tra máy chiếu phòng 302', 'content' => 'Hình bị nháy', 'due_date' => today()->addDay()->toDateString(), 'assignee_id' => $this->admin->id]],
        ])->assertSessionHasNoErrors();

        $report = ClassReport::sole();
        $task = WorkTask::where('title', 'Kiểm tra máy chiếu phòng 302')->sole();
        $this->assertSame([$report->id, $this->class->id, $this->admin->id, $this->ta->id], [(int) $task->class_report_id, (int) $task->class_id, (int) $task->assignee_id, (int) $task->creator_id]);
        $this->assertStringContainsString('Hình bị nháy', $task->description);
        $this->assertSame('check_equipment', $task->follow_up_action);
        $this->assertDatabaseHas('admin_notifications', ['user_id' => $this->admin->id, 'type' => 'task_assigned']);

        // Báo cáo sau của lớp: việc chưa xong hiện lại, kèm ngày buổi của báo cáo gốc.
        $this->actingAs($this->ta)->get(route('tasks.class-reports.create', ['class_id' => $this->class->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('followUps.0.title', 'Kiểm tra máy chiếu phòng 302')
                ->where('followUps.0.carried', $session->date->format('d/m'))->where('followUps.0.assignee', 'Admin Trung tâm')
                ->where('followUps.0.action_label', 'Kiểm tra thiết bị / phòng'));

        // Màn xác nhận báo cáo (không ảnh → chờ Admin): hiện task follow-up đã tạo.
        $this->actingAs($this->admin)->get(route('tasks.manual-approvals', ['report' => $report->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('selected.tasks.0.title', 'Kiểm tra máy chiếu phòng 302'));

        // Xong việc → không hiện lại nữa.
        $task->update(['status' => 'completed']);
        $this->actingAs($this->ta)->get(route('tasks.class-reports.create', ['class_id' => $this->class->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('followUps', []));
    }

    public function test_follow_up_task_needs_an_action_and_can_be_updated_from_the_next_report(): void
    {
        $session = ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->subDay()->toDateString(),
            'start_time' => '08:00', 'end_time' => '09:30', 'type' => ClassSession::TYPE_REGULAR, 'status' => 'completed', 'teacher_id' => $this->admin->id]);
        $create = route('tasks.class-reports.create', ['class_id' => $this->class->id]);
        $this->actingAs($this->ta)->get($create)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('followUpActions', fn ($list) => collect($list)->pluck('label', 'value')->all() === WorkTask::FOLLOW_UP_ACTIONS));

        $payload = ['class_id' => $this->class->id, 'class_session_id' => $session->id, 'hom_nay_hoc_gi' => 'Unit 3', 'nhat_ky_day' => 'Ổn'];
        $due = today()->addDay()->toDateString();
        // Bắt buộc chọn hành động; "Khác" thì phải có tiêu đề.
        $this->actingAs($this->ta)->post(route('tasks.class-reports.store'), $payload + ['tasks' => [['title' => 'Việc', 'due_date' => $due]]])
            ->assertSessionHasErrors('tasks.0.action');
        $this->actingAs($this->ta)->post(route('tasks.class-reports.store'), $payload + ['tasks' => [['action' => 'other', 'due_date' => $due]]])
            ->assertSessionHasErrors('tasks.0.title');
        $this->assertSame(0, ClassReport::count());

        // Bỏ trống tiêu đề → lấy tên hành động. Giao cho chính mình.
        $this->actingAs($this->ta)->post(route('tasks.class-reports.store'), $payload + [
            'tasks' => [['action' => 'call_parent', 'due_date' => $due, 'assignee_id' => $this->ta->id]],
        ])->assertSessionHasNoErrors();
        $task = WorkTask::whereNotNull('class_report_id')->sole();
        $this->assertSame(['Gọi phụ huynh', 'call_parent'], [$task->title, $task->follow_up_action]);

        // Báo cáo sau: dòng việc cũ có thao tác nhanh theo quyền người xem (người nhận việc: làm / gửi chờ xác nhận).
        $this->actingAs($this->ta)->get($create)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('followUps.0.id', $task->id)
            ->where('followUps.0.action_label', 'Gọi phụ huynh')
            ->where('followUps.0.actions', [
                ['value' => 'in_progress', 'label' => 'Đang thực hiện'],
                ['value' => 'pending_confirmation', 'label' => 'Gửi chờ xác nhận'],
            ]));
        $this->actingAs($this->ta)->from($create)->post(route('tasks.status.update', $task->id), ['status' => 'in_progress'])
            ->assertSessionHasNoErrors()->assertRedirect($create);
        $this->assertSame('in_progress', $task->fresh()->status);

        // Người giao (không nhận việc, không phải Admin) không có thao tác nhanh nào — chỉ mở chi tiết.
        $other = WorkTask::create(['title' => 'Xếp buổi bù', 'creator_id' => $this->ta->id, 'assignee_id' => $this->admin->id, 'class_id' => $this->class->id,
            'class_report_id' => $task->class_report_id, 'follow_up_action' => 'makeup', 'due_date' => $due, 'task_type' => 'one_time', 'status' => 'new']);
        $this->actingAs($this->ta)->get($create)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('followUps.1.id', $other->id)->where('followUps.1.actions', []));
    }
}
