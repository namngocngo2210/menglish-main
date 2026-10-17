<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\ClassSessionFeedback;
use App\Models\Course;
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
 * Góp ý từng buổi học (chủ dự án 17/10/2026): phụ huynh chấm sao trên cổng học viên; Học vụ góp ý ngay trong báo cáo ngày (không cần
 * dự giờ), "Báo Admin gấp" tạo sự việc mức Khẩn trong Nhật ký; task tạo trong từng khối báo cáo, chưa xong thì hiện lại ở báo cáo sau.
 */
class SessionFeedbackTest extends TestCase
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
        $this->travelTo(today()->setTime(12, 0));
        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CA', 'is_active' => true]);
        $this->teacher = $this->makeUser('teacher_fulltime', ['name' => 'Cô Lan']);
        $course = Course::create(['name' => 'Starters', 'code' => 'ST', 'total_lessons' => 24, 'tuition_fee' => 3000000, 'is_active' => true]);
        $this->class = ClassModel::create([
            'name' => 'Lớp ST1', 'code' => 'ST1', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'teacher_id' => $this->teacher->id,
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2), 'max_capacity' => 12, 'status' => 'active',
        ]);
    }

    private function makeUser(string|array $roles, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['branch_id' => $this->branch->id, 'is_active' => true], $attributes));
        $user->assignRole($roles);

        return $user;
    }

    private function makeSession(array $attributes = []): ClassSession
    {
        return ClassSession::create(array_merge([
            'class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->toDateString(),
            'start_time' => '08:00', 'end_time' => '09:30', 'type' => ClassSession::TYPE_REGULAR, 'status' => 'completed',
            'teacher_id' => $this->teacher->id,
        ], $attributes));
    }

    private function linkedStudent(string $name = 'Nguyễn An'): array
    {
        $account = $this->makeUser('student');
        $student = Student::create(['code' => 'HV-'.random_int(100, 999), 'name' => $name, 'phone' => '09'.random_int(10000000, 99999999),
            'branch_id' => $this->branch->id, 'status' => 'studying', 'current_class_id' => $this->class->id, 'user_id' => $account->id]);

        return [$student, $account];
    }

    public function test_parent_rates_a_session_on_the_student_portal(): void
    {
        [$student, $account] = $this->linkedStudent();
        $session = $this->makeSession(['date' => today()->subDay()->toDateString()]);
        $later = $this->makeSession(['start_time' => '18:00', 'end_time' => '19:30', 'status' => 'scheduled']);

        $this->actingAs($account)->get(route('portal.student.homework', $student->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Portal/Homework')
                ->has('remarks', 1)->where('remarks.0.session_id', $session->id)->where('remarks.0.can_rate', true)->where('remarks.0.feedback', null)
                ->where('remarks.0.remark', null)->has('feedbackCategories', count(ClassSessionFeedback::PARENT_CATEGORIES)));

        $payload = ['student_id' => $student->id, 'class_session_id' => $session->id];
        $this->actingAs($account)->post(route('portal.student.session-feedback.store'), $payload + ['rating' => ''])->assertSessionHasErrors('rating');
        $this->actingAs($account)->post(route('portal.student.session-feedback.store'), $payload + ['rating' => 4, 'categories' => ['teacher', 'nope']])
            ->assertSessionHasErrors('categories.1');
        $this->actingAs($account)->post(route('portal.student.session-feedback.store'), $payload + ['rating' => 4, 'categories' => ['teacher'], 'content' => ' Cô dạy vui '])
            ->assertSessionHasNoErrors();
        // Gửi lại trong 48 giờ → sửa phiếu cũ, không tạo phiếu mới.
        $this->actingAs($account)->post(route('portal.student.session-feedback.store'), $payload + ['rating' => 2, 'categories' => ['timing']])
            ->assertSessionHasNoErrors();
        $feedback = ClassSessionFeedback::sole();
        $this->assertSame([ClassSessionFeedback::SOURCE_PARENT, 2, ['timing'], null, $this->class->id], [$feedback->source, $feedback->rating, $feedback->categories, $feedback->content, (int) $feedback->class_id]);

        $this->actingAs($account)->get(route('portal.student.homework', $student->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('remarks.0.feedback.rating', 2)->where('remarks.0.feedback.editable', true));

        // Buổi chưa diễn ra, hồ sơ không phải của mình → không chấm được.
        $this->actingAs($account)->post(route('portal.student.session-feedback.store'), ['student_id' => $student->id, 'class_session_id' => $later->id, 'rating' => 5])
            ->assertStatus(422);
        [, $stranger] = $this->linkedStudent('Trần Bình');
        $this->actingAs($stranger)->post(route('portal.student.session-feedback.store'), $payload + ['rating' => 5])->assertForbidden();

        // Học viên vắng buổi đó không chấm được.
        [$absent, $absentAccount] = $this->linkedStudent('Lê Chi');
        StudentAttendance::create(['class_id' => $this->class->id, 'class_session_id' => $session->id, 'student_id' => $absent->id,
            'user_id' => $this->teacher->id, 'session_date' => $session->date->toDateString(), 'status' => 'absent']);
        $this->actingAs($absentAccount)->post(route('portal.student.session-feedback.store'), ['student_id' => $absent->id, 'class_session_id' => $session->id, 'rating' => 5])
            ->assertStatus(422);

        // Quá 48 giờ không sửa được nữa.
        $this->travel(49)->hours();
        $this->actingAs($account)->post(route('portal.student.session-feedback.store'), $payload + ['rating' => 5])->assertSessionHasErrors('rating');
        $this->assertSame(2, $feedback->fresh()->rating);
        $this->assertSame(1, ClassSessionFeedback::count());
    }

    public function test_staff_gives_session_feedback_creates_section_tasks_and_escalates_in_the_daily_report(): void
    {
        $admin = $this->makeUser('admin');
        $staff = $this->makeUser('academic_staff', ['name' => 'Học vụ A']);
        $today = today()->toDateString();
        $session = $this->makeSession();
        $evening = $this->makeSession(['start_time' => '18:00', 'end_time' => '19:30', 'status' => 'scheduled']);
        [$student] = $this->linkedStudent();
        ClassSessionFeedback::create(['class_session_id' => $session->id, 'class_id' => $this->class->id, 'source' => ClassSessionFeedback::SOURCE_PARENT,
            'student_id' => $student->id, 'rating' => 2, 'categories' => ['timing'], 'content' => 'Tan học muộn']);

        $this->actingAs($staff)->get(route('reports.periodic.daily', ['date' => $today]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/DailyOps')->has('sections.sessions')
                ->where('sections.sessions.items.0.key', 'remarks_sent')
                ->has('sessionRows', 2)->where('sessionRows.0.id', $session->id)->where('sessionRows.0.remark', 'missing')
                ->where('sessionRows.0.parents.avg', 2)->where('sessionRows.0.parents.items.0.content', 'Tan học muộn')
                ->where('sessionRows.1.remark', 'pending')->where('verdicts', ClassSessionFeedback::VERDICTS)->where('sectionTasks', []));

        // Khối phải có trong báo cáo, lớp phải nằm trong phạm vi người viết được xem.
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), ['date' => $today, 'tasks' => [['title' => 'Việc', 'due_date' => $today, 'section' => 'khong-co']]])
            ->assertSessionHasErrors('tasks.0.section');
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), ['date' => $today, 'tasks' => [['title' => 'Việc', 'due_date' => $today, 'class_id' => 999999]]])
            ->assertSessionHasErrors('tasks.0.class_id');

        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), [
            'date' => $today,
            'feedback' => [
                $session->id => ['verdict' => 'attention', 'content' => 'GV vào lớp muộn 10 phút'],
                $evening->id => ['verdict' => 'good', 'content' => ''],
                999999 => ['verdict' => 'problem', 'content' => 'Buổi không thuộc báo cáo'],
            ],
            'tasks' => [
                ['title' => 'Nhắc cô Lan đúng giờ', 'due_date' => $today, 'section' => 'sessions', 'class_id' => $this->class->id],
                ['title' => 'Việc chung', 'due_date' => $today],
            ],
        ])->assertSessionHasNoErrors();

        $report = StaffReport::where('kind', 'daily_ops')->sole();
        $this->assertSame(2, ClassSessionFeedback::where('source', ClassSessionFeedback::SOURCE_STAFF)->count());
        $this->assertSame(['attention', 'good'], collect($report->data['session_feedback'])->pluck('verdict')->all());
        $this->assertStringContainsString('Góp ý từng buổi:', $report->content);
        $this->assertStringContainsString('Cần lưu ý — GV vào lớp muộn 10 phút', $report->content);
        [$sectionTask, $generalTask] = WorkTask::orderBy('id')->get()->all();
        $this->assertSame([$report->id, 'sessions', $this->class->id], [(int) $sectionTask->staff_report_id, $sectionTask->report_section, (int) $sectionTask->class_id]);
        $this->assertNull($generalTask->report_section);

        $this->actingAs($staff)->get(route('reports.periodic.daily', ['date' => $today]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('tasks', 1)->where('tasks.0.title', 'Việc chung')
                ->has('sectionTasks.sessions', 1)->where('sectionTasks.sessions.0.carried', null)
                ->where('sessionRows.0.verdict', 'attention')->where('sessionRows.1.verdict', 'good'));

        // Bỏ trống góp ý buổi tối → xóa phiếu.
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), [
            'date' => $today,
            'feedback' => [$session->id => ['verdict' => 'attention', 'content' => 'GV vào lớp muộn 10 phút'], $evening->id => ['verdict' => '', 'content' => '']],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, ClassSessionFeedback::where('source', ClassSessionFeedback::SOURCE_STAFF)->count());

        // Báo Admin gấp: sự việc mức Khẩn trong Nhật ký + thông báo Admin; góp ý của buổi chuyển "Có vấn đề"; không báo lặp.
        $this->actingAs($staff)->post(route('reports.periodic.daily.escalate', $session->id), ['content' => ''])->assertSessionHasErrors('content');
        $this->actingAs($staff)->post(route('reports.periodic.daily.escalate', $session->id), ['content' => 'Phụ huynh phản ánh GV quát học sinh'])
            ->assertSessionHasNoErrors();
        $entry = StaffReport::where('type', 'journal')->sole();
        $this->assertSame(['urgent', 'open', $this->class->id, $session->id], [$entry->severity, $entry->status, (int) $entry->class_id, $entry->data['class_session_id']]);
        $this->assertStringStartsWith('Khẩn: Lớp ST1 buổi', $entry->title);
        $mine = ClassSessionFeedback::where('source', ClassSessionFeedback::SOURCE_STAFF)->sole();
        $this->assertSame(['problem', $entry->id, 'GV vào lớp muộn 10 phút'], [$mine->verdict, (int) $mine->escalation_id, $mine->content]);
        $this->assertTrue(AdminNotification::where('user_id', $admin->id)->where('type', 'staff_journal_urgent')->exists());
        $this->actingAs($staff)->post(route('reports.periodic.daily.escalate', $session->id), ['content' => 'Lần nữa'])->assertSessionHasErrors('escalate');
        $this->assertSame(1, StaffReport::where('type', 'journal')->count());

        // Phiếu đã báo Admin không bị xóa khi bỏ trống.
        $this->actingAs($staff)->post(route('reports.periodic.daily.store'), ['date' => $today, 'feedback' => [$session->id => ['verdict' => '', 'content' => '']]])
            ->assertSessionHasNoErrors();
        $this->assertTrue($mine->fresh()->exists);
        $this->actingAs($staff)->get(route('reports.periodic.daily', ['date' => $today]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sessionRows.0.escalation.id', $entry->id)->where('sessionRows.0.escalation.status', 'open'));

        // Admin xem chi tiết: task theo khối + góp ý từng buổi đã chụp.
        $this->actingAs($admin)->get(route('reports.detail', $report->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/Detail')->where('view', 'daily')
                ->has('sectionTasks.sessions', 1)->has('tasks', 1)->where('sessionFeedback.0.escalated', true));

        // Hôm sau: task chưa xong hiện lại ở khối đó, kèm ngày gốc; xong rồi thì thôi.
        $this->travel(1)->days();
        $tomorrow = today()->toDateString();
        $this->actingAs($staff)->get(route('reports.periodic.daily', ['date' => $tomorrow]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('sectionTasks.sessions', 1)
                ->where('sectionTasks.sessions.0.carried', now()->subDay()->format('d/m'))->where('sessionRows', []));
        $sectionTask->update(['status' => 'completed']);
        $this->actingAs($staff)->get(route('reports.periodic.daily', ['date' => $tomorrow]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sectionTasks', []));
        // Báo cáo hôm qua vẫn giữ task đã tạo ở đó.
        $this->actingAs($staff)->get(route('reports.periodic.daily', ['date' => $today]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('sectionTasks.sessions', 1));

        // Học thuật không có góp ý từng buổi trong báo cáo ngày, không báo Admin từ đây; giáo viên không vào được.
        $lead = $this->makeUser('academic_lead');
        $this->actingAs($lead)->get(route('reports.periodic.daily', ['date' => $today]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sessionRows', []));
        $this->actingAs($lead)->post(route('reports.periodic.daily.escalate', $session->id), ['content' => 'x'])->assertForbidden();
        $this->actingAs($this->teacher)->post(route('reports.periodic.daily.escalate', $session->id), ['content' => 'x'])->assertForbidden();
    }

    public function test_weekly_report_tasks_live_in_their_section_and_carry_over_to_next_week(): void
    {
        $staff = $this->makeUser('academic_staff');
        $week = ReportPeriod::currentWeek();
        $today = today()->toDateString();

        $this->actingAs($staff)->post(route('reports.periodic.weekly-kpi.store'), ['week' => $week, 'tasks' => [['title' => 'Việc', 'due_date' => $today, 'section' => 'sessions']]])
            ->assertSessionHasErrors('tasks.0.section');
        $this->actingAs($staff)->post(route('reports.periodic.weekly-kpi.store'), [
            'week' => $week, 'note' => 'Tuần ổn',
            'tasks' => [['title' => 'Rà lại mục chăm sóc phụ huynh', 'due_date' => $today, 'section' => 'kpi'], ['title' => 'Chốt lịch họp GV', 'due_date' => $today, 'section' => 'week']],
        ])->assertSessionHasNoErrors();

        $report = StaffReport::where('kind', 'weekly_kpi')->sole();
        $this->assertSame(WorkTask::orderBy('id')->pluck('id')->all(), $report->data['task_ids']);
        $this->assertSame('Tuần ổn', $report->data['note']);
        $this->assertStringContainsString('Task follow-up: #', $report->content);
        $this->assertStringContainsString('Tạo từ báo cáo tuần', WorkTask::first()->description);
        // Lưu lại không thêm task → giữ nguyên task cũ.
        $this->actingAs($staff)->post(route('reports.periodic.weekly-kpi.store'), ['week' => $week, 'note' => 'Tuần ổn, sửa ghi chú'])->assertSessionHasNoErrors();
        $this->assertCount(2, $report->fresh()->data['task_ids']);

        $this->actingAs($staff)->get(route('reports.periodic.weekly-kpi', ['week' => $week]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('sectionTasks.kpi', 1)->has('sectionTasks.week', 1)->where('sectionTasks.kpi.0.carried', null));

        $this->travel(7)->days();
        WorkTask::where('report_section', 'week')->update(['status' => 'completed']);
        $this->actingAs($staff)->get(route('reports.periodic.weekly-kpi', ['week' => ReportPeriod::currentWeek()]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('sectionTasks.kpi', 1)->missing('sectionTasks.week')
                ->where('sectionTasks.kpi.0.title', 'Rà lại mục chăm sóc phụ huynh')->whereNot('sectionTasks.kpi.0.carried', null));
    }
}
