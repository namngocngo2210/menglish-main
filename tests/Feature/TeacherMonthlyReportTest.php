<?php

namespace Tests\Feature;

use App\Http\Controllers\TeacherPortalController;
use App\Models\AcademicRecord;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\MiniTestScore;
use App\Models\SlaEvent;
use App\Models\StaffReport;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Models\WorkTask;
use App\Support\ReportPeriod;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Báo cáo tháng giáo viên (chủ dự án 17/10/2026): số liệu tự động của tháng (chấm công + từng lớp), học sinh cần chú ý do hệ thống
 * gợi ý, task follow-up theo lớp thay cho ô "Đề xuất hỗ trợ từ quản lý" (việc chưa xong hiện lại đúng lớp tháng sau).
 */
class TeacherMonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ClassModel $class;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->travelTo(now()->startOfMonth()->addDays(14)->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CA', 'is_active' => true]);
        $this->teacher = $this->makeUser('teacher_fulltime', ['name' => 'Cô Lan']);
        $course = Course::create(['name' => 'Starters', 'code' => 'ST', 'total_lessons' => 24, 'tuition_fee' => 3000000, 'is_active' => true]);
        $this->class = ClassModel::create([
            'name' => 'Lớp ST1', 'code' => 'ST1', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'teacher_id' => $this->teacher->id,
            'start_date' => now()->subMonths(2), 'end_date' => now()->addMonths(2), 'max_capacity' => 12, 'status' => 'active',
        ]);
    }

    private function makeUser(string|array $roles, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['branch_id' => $this->branch->id, 'is_active' => true], $attributes));
        $user->assignRole($roles);

        return $user;
    }

    private function classSession(int $daysAgo): ClassSession
    {
        return ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->subDays($daysAgo)->toDateString(),
            'start_time' => '08:00', 'end_time' => '09:30', 'type' => ClassSession::TYPE_REGULAR, 'status' => 'completed', 'teacher_id' => $this->teacher->id]);
    }

    public function test_monthly_report_shows_class_metrics_attention_and_saves_class_tasks(): void
    {
        $month = ReportPeriod::currentMonth();
        $key = 'class_'.$this->class->id;
        [$s1, $s2, $s3] = [$this->classSession(5), $this->classSession(3), $this->classSession(1)];
        $this->classSession(-5)->update(['status' => 'scheduled']);
        $an = Student::create(['code' => 'HV-1', 'name' => 'Nguyễn An', 'phone' => '0911111111', 'branch_id' => $this->branch->id, 'status' => 'studying', 'current_class_id' => $this->class->id]);
        $binh = Student::create(['code' => 'HV-2', 'name' => 'Trần Bình', 'phone' => '0922222222', 'branch_id' => $this->branch->id, 'status' => 'studying', 'current_class_id' => $this->class->id]);
        foreach ([$s1, $s2] as $s) {
            foreach ([[$an, 'present'], [$binh, 'absent']] as [$student, $status]) {
                StudentAttendance::create(['class_id' => $this->class->id, 'class_session_id' => $s->id, 'student_id' => $student->id,
                    'user_id' => $this->teacher->id, 'session_date' => $s->date->toDateString(), 'status' => $status]);
            }
        }
        MiniTestScore::create(['class_id' => $this->class->id, 'student_id' => $an->id, 'user_id' => $this->teacher->id, 'name' => 'Unit 2', 'score' => 5, 'max_score' => 10, 'test_date' => today()->subDays(3)]);
        AcademicRecord::create(['module' => 'teacher_remarks', 'screen_key' => 'teacher_remarks', 'record_code' => TeacherPortalController::remarkRecordCode($s1),
            'title' => 'Nhận xét', 'status' => 'completed', 'data' => []]);
        SlaEvent::create(['rule_key' => 'gv.remarks_late', 'subject_type' => 'class_session', 'subject_id' => $s2->id, 'user_id' => $this->teacher->id,
            'triggered_at' => now()->subDays(3), 'due_at' => now()->subDays(3), 'breached_at' => now()->subDays(2)]);
        // Tháng trước giao task cho lớp, chưa xong → hiện lại ở lớp đó.
        $prev = StaffReport::create(['user_id' => $this->teacher->id, 'kind' => 'teacher_monthly', 'type' => 'monthly', 'period_key' => now()->subMonthNoOverflow()->format('Y-m'),
            'title' => 'Báo cáo tháng trước', 'report_date' => now()->subMonthNoOverflow()->startOfMonth(), 'status' => 'submitted', 'content' => '', 'data' => []]);
        WorkTask::create(['title' => 'Xin thêm TA cho ST1', 'creator_id' => $this->teacher->id, 'assignee_id' => $this->teacher->id, 'branch_id' => $this->branch->id,
            'class_id' => $this->class->id, 'staff_report_id' => $prev->id, 'report_section' => $key, 'due_date' => today()->addDay(), 'task_type' => 'one_time', 'status' => 'in_progress']);
        // Bản nộp trước khi đổi form, có ô "Đề xuất hỗ trợ từ quản lý": vẫn giữ khi lưu lại.
        StaffReport::create(['user_id' => $this->teacher->id, 'kind' => 'teacher_monthly', 'type' => 'monthly', 'period_key' => $month,
            'title' => 'Báo cáo giảng dạy tháng', 'report_date' => now()->startOfMonth(), 'status' => 'submitted', 'content' => 'cũ',
            'data' => ['general' => ['progress' => 'Unit 3'], 'classes' => [$this->class->id => ['situation' => 'Ồn', 'support_note' => 'Cần thêm TA', 'need_support' => true]]]]);
        $admin = $this->makeUser('admin', ['name' => 'Admin Trung tâm']);

        $this->actingAs($this->teacher)->get(route('reports.periodic.teacher-monthly'))->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($key, $admin) {
                $page->component('Reports/TeacherMonthly')->where('live', true)
                    ->where('auto.work.items.1.value', '3')
                    ->where("sectionTasks.{$key}.0.title", 'Xin thêm TA cho ST1')->whereNot("sectionTasks.{$key}.0.carried", null)
                    ->where('assignees', fn ($list) => collect($list)->pluck('value')->contains($admin->id))
                    ->where("auto.classes.{$key}", function ($section) {
                        $section = collect($section)->all();
                        $items = collect($section['items'])->keyBy('key');
                        $this->assertSame(['3 / 4', '50%', '5', '1 / 3'], [$items['sessions']['value'], $items['attendance']['value'], $items['score']['value'], $items['remarks']['value']]);
                        $tags = collect($items['remarks']['details'])->pluck('tag')->all();
                        $this->assertSame(['Trễ hạn', 'Thiếu', 'Thiếu'], $tags);
                        $this->assertSame(['Mini test 5', 'Vắng 2 buổi'], collect($section['attention'])->pluck('tag')->all());
                        $this->assertStringContainsString('Trần Bình', $section['attention'][1]['text']);

                        return true;
                    });
            });

        // Khối của task phải là lớp mình giữ.
        $this->actingAs($this->teacher)->post(route('reports.periodic.teacher-monthly.store'), [
            'month' => $month, 'tasks' => [['title' => 'Việc', 'due_date' => today()->toDateString(), 'section' => 'class_999999']],
        ])->assertSessionHasErrors('tasks.0.section');

        $this->actingAs($this->teacher)->post(route('reports.periodic.teacher-monthly.store'), [
            'month' => $month,
            'classes' => [$this->class->id => ['situation' => 'Lớp ồn hơn tháng trước']],
            'tasks' => [['title' => 'Liên hệ phụ huynh Trần Bình', 'due_date' => today()->toDateString(), 'section' => $key, 'assignee_id' => $admin->id]],
        ])->assertSessionHasNoErrors();

        $report = StaffReport::where('kind', 'teacher_monthly')->where('period_key', $month)->sole();
        $row = $report->data['classes'][$this->class->id];
        $this->assertSame(['Lớp ồn hơn tháng trước', 'Cần thêm TA'], [$row['situation'], $row['support_note']]);
        $this->assertArrayHasKey($key, $report->data['auto']['classes']);
        $this->assertStringContainsString('Nhận xét buổi: 1 / 3', $report->content);
        $task = WorkTask::where('title', 'Liên hệ phụ huynh Trần Bình')->sole();
        $this->assertSame([$report->id, $key, $this->class->id, $admin->id], [(int) $task->staff_report_id, $task->report_section, (int) $task->class_id, (int) $task->assignee_id]);

        // Đã lưu: số liệu là số lúc lưu; task mới hiện ở lớp, cùng việc tồn tháng trước.
        $this->actingAs($this->teacher)->get(route('reports.periodic.teacher-monthly'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('live', false)->has("sectionTasks.{$key}", 2));

        // Tổng quan chất lượng giảng dạy: lớp có task giao cho người khác → "Cần hỗ trợ", kèm danh sách task.
        $this->actingAs($admin)->get(route('reports.detail', $report->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('view', 'teacher_monthly')->where('overallTitle', 'Nhận xét chung tháng')
                ->has('auto.sections.work')->where("auto.sections.{$key}.title", 'Lớp ST1')
                ->where("auto.sections.{$key}.items.5.key", 'attention')
                ->where("notes.{$key}", fn ($note) => str_contains($note, 'Lớp ồn hơn tháng trước'))
                ->where('narrative.0.value', null)
                ->has("sectionTasks.{$key}", 2));
    }

    public function test_task_only_report_is_accepted_and_shows_on_teaching_overview(): void
    {
        $month = ReportPeriod::currentMonth();
        $key = 'class_'.$this->class->id;
        $admin = $this->makeUser('admin', ['name' => 'Admin Trung tâm']);

        $this->actingAs($this->teacher)->post(route('reports.periodic.teacher-monthly.store'), ['month' => $month])
            ->assertSessionHasErrors('general');
        $this->actingAs($this->teacher)->post(route('reports.periodic.teacher-monthly.store'), [
            'month' => $month, 'tasks' => [['title' => 'Xin thêm bàn ghế phòng 302', 'due_date' => today()->toDateString(), 'section' => $key, 'assignee_id' => $admin->id]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->get(route('dashboard', ['month' => $month]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('teaching.teacherReports', function ($reports) {
                $lan = collect($reports)->firstWhere('teacher', 'Cô Lan');
                $this->assertTrue($lan['submitted']);
                $this->assertSame('Lớp ST1', $lan['classes'][0]['class']);
                $this->assertTrue($lan['classes'][0]['need_support']);
                $this->assertSame('Xin thêm bàn ghế phòng 302', $lan['classes'][0]['tasks'][0]['title']);

                return true;
            }));
    }
}
