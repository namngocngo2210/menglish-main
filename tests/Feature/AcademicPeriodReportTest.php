<?php

namespace Tests\Feature;

use App\Models\AcademicProject;
use App\Models\AdminNotification;
use App\Models\AcademicProjectMilestone;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\ClassSessionFeedback;
use App\Models\Course;
use App\Models\MiniTestScore;
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
 * Báo cáo tháng / quý Học thuật (chủ dự án 17/10/2026): các ô tường thuật thay bằng khối số liệu tự động của kỳ, mỗi khối có ghi chú
 * và task follow-up (việc chưa xong hiện lại ở kỳ sau), phần tự viết chỉ còn "Đánh giá chung"; mục tường thuật cũ vẫn đọc được.
 */
class AcademicPeriodReportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ClassModel $class;

    private User $teacher;

    private User $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->travelTo(now()->startOfMonth()->addDays(14)->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CA', 'is_active' => true]);
        $this->teacher = $this->makeUser('teacher_fulltime', ['name' => 'Cô Lan']);
        $this->lead = $this->makeUser('academic_lead', ['name' => 'Học thuật Minh']);
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

    /** @return array<string, array> key => item */
    private static function items(array $section): array
    {
        return collect($section['items'])->keyBy('key')->all();
    }

    public function test_monthly_report_shows_period_metrics_saves_notes_tasks_and_overall(): void
    {
        $month = ReportPeriod::currentMonth();
        $session = ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->subDays(3)->toDateString(),
            'start_time' => '08:00', 'end_time' => '09:30', 'type' => ClassSession::TYPE_REGULAR, 'status' => 'completed', 'teacher_id' => $this->teacher->id]);
        $an = Student::create(['code' => 'HV-1', 'name' => 'Nguyễn An', 'phone' => '0911111111', 'branch_id' => $this->branch->id, 'status' => 'studying', 'current_class_id' => $this->class->id]);
        $binh = Student::create(['code' => 'HV-2', 'name' => 'Trần Bình', 'phone' => '0922222222', 'branch_id' => $this->branch->id, 'status' => 'studying', 'current_class_id' => $this->class->id]);
        foreach ([[$an, 'present'], [$binh, 'absent']] as [$student, $status]) {
            StudentAttendance::create(['class_id' => $this->class->id, 'class_session_id' => $session->id, 'student_id' => $student->id,
                'user_id' => $this->teacher->id, 'session_date' => $session->date->toDateString(), 'status' => $status]);
        }
        MiniTestScore::create(['class_id' => $this->class->id, 'student_id' => $binh->id, 'user_id' => $this->teacher->id, 'name' => 'Unit 2', 'score' => 5, 'max_score' => 10, 'test_date' => today()->subDays(3)]);
        ClassSessionFeedback::create(['class_session_id' => $session->id, 'class_id' => $this->class->id, 'source' => ClassSessionFeedback::SOURCE_PARENT,
            'student_id' => $an->id, 'rating' => 2, 'categories' => ['teacher'], 'content' => 'Con chưa theo kịp ngữ pháp']);
        $project = AcademicProject::create(['code' => 'DA-1', 'name' => 'Giáo trình Movers', 'owner_id' => $this->lead->id, 'status' => 'in_progress',
            'start_date' => today()->subMonth(), 'deadline' => today()->addMonth(), 'created_by' => $this->lead->id]);
        AcademicProjectMilestone::create(['academic_project_id' => $project->id, 'title' => 'Bộ đề Unit 5', 'assignee_id' => $this->teacher->id,
            'due_date' => today()->subDays(3), 'status' => 'in_progress']);
        // Báo cáo tháng nộp trước khi đổi form: mục tường thuật cũ vẫn đọc được và được giữ khi lưu lại.
        StaffReport::create(['user_id' => $this->lead->id, 'kind' => 'academic_monthly', 'type' => 'monthly', 'period_key' => $month,
            'title' => 'Báo cáo tháng', 'report_date' => now()->startOfMonth(), 'status' => 'submitted', 'content' => 'cũ',
            'data' => ['narrative' => ['test_syllabus_review' => 'Đã rà soát đề Starters', 'overall' => 'Ổn']]]);

        $this->actingAs($this->lead)->get(route('reports.periodic.academic-monthly'))->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $page->component('Reports/AcademicMonthly')->where('live', true)->where('values.overall', 'Ổn')
                    ->where('legacyFields', [['label' => 'Rà soát test & syllabus', 'value' => 'Đã rà soát đề Starters']])
                    ->where('sections', function ($sections) {
                        $sections = collect($sections)->all();
                        $this->assertSame(['quality', 'projects', 'parents', 'materials', 'syllabus', 'meetings'], array_keys($sections));
                        $quality = self::items($sections['quality']);
                        $this->assertSame('50%', $quality['attendance']['value']);
                        $this->assertSame('5', $quality['mini_test']['value']);
                        $this->assertStringContainsString('Nguyễn An', collect($sections['parents']['items'])->firstWhere('key', 'low_ratings')['details'][0]['text']);
                        $this->assertStringContainsString('Cô Lan · 1 lớp', $quality['teachers']['details'][0]['text']);
                        $parents = self::items($sections['parents']);
                        $this->assertSame(['1', '2 / 5', '1', '1'], [$parents['ratings']['value'], $parents['rating_avg']['value'], $parents['about_teacher']['value'], $parents['low_ratings']['value']]);
                        $this->assertSame('1', self::items($sections['projects'])['milestones_overdue']['value']);

                        return true;
                    });
            });

        // Phải có đánh giá chung; khối của task phải có trong báo cáo.
        $this->actingAs($this->lead)->post(route('reports.periodic.academic-monthly.store'), ['month' => $month, 'narrative' => ['overall' => '']])
            ->assertSessionHasErrors('narrative');
        $this->actingAs($this->lead)->post(route('reports.periodic.academic-monthly.store'), [
            'month' => $month, 'narrative' => ['overall' => 'Ổn'], 'tasks' => [['title' => 'Việc', 'due_date' => today()->toDateString(), 'section' => 'sessions']],
        ])->assertSessionHasErrors('tasks.0.section');

        $this->actingAs($this->lead)->post(route('reports.periodic.academic-monthly.store'), [
            'month' => $month,
            'narrative' => ['overall' => 'Tháng ổn định, cần kèm GV mới', 'test_syllabus_review' => 'không nhận mục đã bỏ'],
            'notes' => ['parents' => 'Một phụ huynh phản ánh ngữ pháp', 'khong-co' => 'bỏ qua'],
            'tasks' => [['title' => 'Gọi phụ huynh bé An', 'due_date' => today()->toDateString(), 'section' => 'parents', 'class_id' => $this->class->id]],
        ])->assertSessionHasNoErrors();

        $report = StaffReport::where('kind', 'academic_monthly')->sole();
        $this->assertSame(['test_syllabus_review' => 'Đã rà soát đề Starters', 'overall' => 'Tháng ổn định, cần kèm GV mới'], $report->data['narrative']);
        $this->assertSame(['parents' => 'Một phụ huynh phản ánh ngữ pháp'], $report->data['notes']);
        $this->assertArrayHasKey('quality', $report->data['auto']['sections']);
        $this->assertStringContainsString('Feedback phụ huynh:', $report->content);
        $this->assertStringContainsString('Đánh giá chung tháng:', $report->content);
        $task = WorkTask::sole();
        $this->assertSame([$report->id, 'parents', $this->class->id], [(int) $task->staff_report_id, $task->report_section, (int) $task->class_id]);
        $this->assertSame([$task->id], $report->data['task_ids']);

        $this->actingAs($this->lead)->get(route('reports.periodic.academic-monthly'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('live', false)->where('notes.parents', 'Một phụ huynh phản ánh ngữ pháp')
                ->has('sectionTasks.parents', 1)->where('sectionTasks.parents.0.carried', null));

        // Admin xem chi tiết: khối số liệu, ghi chú, task theo khối, đánh giá chung + mục cũ.
        $admin = $this->makeUser('admin');
        $this->actingAs($admin)->get(route('reports.detail', $report->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/Detail')->where('view', 'academic_period')
                ->has('auto.sections.quality')->has('sectionTasks.parents', 1)->where('notes.parents', 'Một phụ huynh phản ánh ngữ pháp')
                ->where('narrative', [['label' => 'Đánh giá chung tháng', 'value' => 'Tháng ổn định, cần kèm GV mới'], ['label' => 'Rà soát test & syllabus', 'value' => 'Đã rà soát đề Starters']]));

        // Tháng sau: việc chưa xong hiện lại ở khối "Feedback phụ huynh".
        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addDays(2));
        $this->actingAs($this->lead)->get(route('reports.periodic.academic-monthly'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('month', ReportPeriod::currentMonth())->has('sectionTasks.parents', 1)
                ->whereNot('sectionTasks.parents.0.carried', null)->where('values', [])->where('legacyFields', []));
    }

    public function test_quarterly_report_keeps_overall_fields_and_section_tasks(): void
    {
        $quarter = ReportPeriod::currentQuarter();

        $this->actingAs($this->lead)->get(route('reports.periodic.academic-quarterly', ['quarter' => $quarter]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/AcademicQuarterly')->has('sections.quality')->has('sections.parents')
                ->where('fields.next_plan', 'Kế hoạch công việc kỳ sau')->missing('fields.academic_work'));

        $this->actingAs($this->lead)->post(route('reports.periodic.academic-quarterly.store'), ['quarter' => $quarter, 'narrative' => []])
            ->assertSessionHasErrors('narrative');
        $this->actingAs($this->lead)->post(route('reports.periodic.academic-quarterly.store'), [
            'quarter' => $quarter, 'narrative' => ['next_plan' => 'Mở 2 lớp Flyers', 'improvement_priorities' => 'Nhận xét buổi đúng hạn'],
            'tasks' => [['title' => 'Soạn đề Unit 6', 'due_date' => today()->addWeek()->toDateString(), 'section' => 'quality', 'assignee_id' => $this->teacher->id]],
        ])->assertSessionHasNoErrors();

        $report = StaffReport::where('kind', 'academic_quarterly')->sole();
        $this->assertSame('Mở 2 lớp Flyers', $report->data['narrative']['next_plan']);
        $this->assertSame(['quality', $this->teacher->id], [WorkTask::sole()->report_section, (int) WorkTask::sole()->assignee_id]);
        // Giao cho người khác: người nhận được báo, nội dung nêu đúng báo cáo gốc.
        $notice = AdminNotification::where('user_id', $this->teacher->id)->where('type', 'task_assigned')->sole();
        $this->assertStringContainsString('báo cáo quý', $notice->message);
        $this->actingAs($this->lead)->get(route('reports.periodic.academic-quarterly', ['quarter' => $quarter]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('live', false)->where('values.next_plan', 'Mở 2 lớp Flyers')->has('sectionTasks.quality', 1));

        // Học vụ không nộp được báo cáo quý Học thuật.
        $this->actingAs($this->makeUser('academic_staff'))->post(route('reports.periodic.academic-quarterly.store'), ['quarter' => $quarter, 'narrative' => ['next_plan' => 'x']])
            ->assertForbidden();
    }
}
