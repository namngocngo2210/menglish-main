<?php

namespace Tests\Feature;

use App\Http\Controllers\TeacherPortalController;
use App\Models\AcademicRecord;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\TeacherSessionReport;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Báo cáo buổi học của GV chính (chủ dự án 27/10/2026): màn riêng mở từ nút trên từng buổi ở Trang lớp (không còn là form
 * trực lớp của trợ giảng), bắt buộc đã điểm danh, chỉ GV chính nộp (GVNN không), người khác chỉ xem, nộp sau hạn → trễ.
 */
class ClassSessionReportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ClassModel $class;

    private User $teacher;

    private User $foreign;

    private User $admin;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->travelTo(today()->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CA', 'is_active' => true]);
        $this->teacher = $this->makeUser('teacher_parttime', 'GV An');
        $this->foreign = $this->makeUser('teacher_fulltime', 'GVNN John');
        $this->admin = $this->makeUser('admin', 'Admin');
        $course = Course::create(['name' => 'Starters', 'code' => 'ST', 'total_lessons' => 24, 'tuition_fee' => 3000000, 'is_active' => true]);
        $this->class = ClassModel::create([
            'name' => 'Lớp ST1', 'code' => 'ST1', 'course_id' => $course->id, 'branch_id' => $this->branch->id,
            'teacher_id' => $this->teacher->id, 'foreign_teacher_id' => $this->foreign->id,
            'start_date' => now()->subMonths(3), 'end_date' => now()->addMonths(2), 'max_capacity' => 12, 'status' => 'active',
        ]);
        $this->student = Student::create(['code' => 'HV-1', 'name' => 'Lê Chi', 'phone' => '0900000001', 'branch_id' => $this->branch->id,
            'status' => 'studying', 'current_class_id' => $this->class->id]);
    }

    private function makeUser(string $role, string $name): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'name' => $name]);
        $user->assignRole($role);

        return $user;
    }

    private function makeSession(int $daysAgo): ClassSession
    {
        return ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->subDays($daysAgo)->toDateString(),
            'start_time' => '08:00', 'end_time' => '09:30', 'type' => ClassSession::TYPE_REGULAR, 'status' => 'completed',
            'teacher_id' => $this->teacher->id, 'foreign_teacher_id' => $this->foreign->id]);
    }

    private function attend(ClassSession $session, string $status = 'absent'): void
    {
        StudentAttendance::create(['class_id' => $this->class->id, 'class_session_id' => $session->id, 'student_id' => $this->student->id,
            'user_id' => $this->teacher->id, 'session_date' => $session->date->toDateString(), 'status' => $status]);
    }

    private function url(ClassSession $session, string $name = 'classes.session-report'): string
    {
        return route($name, ['id' => $this->class->id, 'session' => $session->id]);
    }

    public function test_main_teacher_opens_own_report_page_not_the_assistant_form(): void
    {
        $session = $this->makeSession(0);

        $this->actingAs($this->teacher)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'attendance']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('attendanceRows.0.can_submit_report', true)->where('attendanceRows.0.teacher_report', null));
        $this->actingAs($this->foreign)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'attendance']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('attendanceRows.0.can_submit_report', false));

        $html = $this->actingAs($this->teacher)->get($this->url($session))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Classes/SessionReport')
                ->where('canSubmit', true)
                ->where('attendanceDone', false)
                ->where('sessionInfo.id', $session->id)
                ->where('checklist', fn ($items) => collect($items)->pluck('key')->all() === ['attendance', 'taught', 'homework'])
                ->where('remarkStudents.0.name', 'Lê Chi')->where('remarkOptions.result', ['Đạt', 'Không đạt']))
            ->getContent();
        // Sidebar: mục Lớp học sáng, không phải Công việc.
        $this->assertMatchesRegularExpression('/<a(?=[^>]*data-menu-item="classes")[^>]*aria-current="page"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<a(?=[^>]*data-menu-item="tasks")[^>]*aria-current="page"/', $html);
    }

    public function test_submit_requires_attendance_and_only_main_teacher(): void
    {
        $session = $this->makeSession(0);
        $payload = ['content' => 'Unit 3: animals', 'class_situation' => 'Lớp sôi nổi', 'attention' => [['student_id' => $this->student->id, 'reason' => 'Vắng', 'action' => 'Gửi bài']]];

        $this->actingAs($this->teacher)->post($this->url($session, 'classes.session-report.store'), $payload)->assertSessionHasErrors('attendance');
        $this->attend($session);
        $this->actingAs($this->foreign)->post($this->url($session, 'classes.session-report.store'), $payload)->assertForbidden();
        $this->actingAs($this->teacher)->post($this->url($session, 'classes.session-report.store'), ['content' => ''])->assertSessionHasErrors('content');

        // Gợi ý học sinh vắng buổi này.
        $this->actingAs($this->teacher)->get($this->url($session))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('suggestions.0.student_id', (string) $this->student->id)->where('suggestions.0.reason', 'Vắng'));

        $this->actingAs($this->teacher)->post($this->url($session, 'classes.session-report.store'), $payload)
            ->assertSessionHasNoErrors()->assertRedirect(route('classes.show', ['id' => $this->class->id, 'tab' => 'attendance']));

        $report = TeacherSessionReport::sole();
        $this->assertSame([$this->teacher->id, 'Unit 3: animals', 'Lớp sôi nổi'], [(int) $report->teacher_id, $report->content, $report->class_situation]);
        $this->assertSame([['student_id' => $this->student->id, 'reason' => 'Vắng', 'action' => 'Gửi bài']], $report->attention);
        $this->assertFalse($report->isLate());
        $this->assertSame(0, ClassReport::count(), 'Không tạo báo cáo trực lớp của trợ giảng.');

        // Sửa lại: giữ giờ nộp đầu.
        $first = $report->submitted_at->toDateTimeString();
        $this->travel(1)->hours();
        $this->actingAs($this->teacher)->post($this->url($session, 'classes.session-report.store'), ['content' => 'Unit 3 + 4'])->assertSessionHasNoErrors();
        $this->assertSame(['Unit 3 + 4', $first], [$report->fresh()->content, $report->fresh()->submitted_at->toDateTimeString()]);

        $this->actingAs($this->teacher)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'attendance']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('attendanceRows.0.teacher_report', ['late' => false])->where('attendanceStats.reports', 1));

        // Người khác xem được, chỉ đọc.
        $this->actingAs($this->admin)->get($this->url($session))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canSubmit', false)->where('report.content', 'Unit 3 + 4')->where('report.attention.0.student', 'Lê Chi'));
    }

    public function test_student_remarks_are_entered_in_the_report_and_shared_with_remarks_screen(): void
    {
        $session = $this->makeSession(0);
        $this->attend($session, 'present');
        $url = $this->url($session, 'classes.session-report.store');

        $this->actingAs($this->teacher)->post($url, ['content' => 'Unit 3', 'remarks' => [$this->student->id => ['result' => 'Sai giá trị']]])
            ->assertSessionHasErrors("remarks.{$this->student->id}.result");
        $this->assertSame(0, TeacherSessionReport::count());

        $this->actingAs($this->teacher)->post($url, [
            'content' => 'Unit 3',
            'remarks' => [$this->student->id => ['grammar' => 'Khá', 'attitude' => 'Tốt', 'result' => 'Đạt', 'comment' => 'Con phát biểu tốt']],
        ])->assertSessionHasNoErrors();

        $record = AcademicRecord::where('module', 'teacher_remarks')->where('record_code', TeacherPortalController::remarkRecordCode($session))->sole();
        $this->assertSame('completed', $record->status);
        $this->assertSame('Con phát biểu tốt', $record->data[$this->student->id]['comment'] ?? $record->data[(string) $this->student->id]['comment']);

        // Màn Nhận xét của Cổng GV đọc đúng nhận xét đã nhập trong báo cáo; mở lại báo cáo cũng thấy.
        $this->actingAs($this->teacher)->get(route('teacher.remarks', ['classId' => $this->class->id, 'session' => $session->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('students.0.remark.grammar', 'Khá'));
        $this->actingAs($this->admin)->get($this->url($session))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('remarkStudents.0.remark.comment', 'Con phát biểu tốt'));
    }

    public function test_report_after_deadline_is_late(): void
    {
        $session = $this->makeSession(3);
        $this->attend($session, 'present');

        $this->actingAs($this->teacher)->post($this->url($session, 'classes.session-report.store'), ['content' => 'Unit 2'])->assertSessionHasNoErrors();

        $this->assertTrue(TeacherSessionReport::sole()->isLate());
        $this->actingAs($this->teacher)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'attendance']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('attendanceRows.0.teacher_report', ['late' => true]));
    }

    public function test_session_must_belong_to_a_visible_class(): void
    {
        $other = ClassModel::create([
            'name' => 'Lớp khác', 'code' => 'KH1', 'course_id' => $this->class->course_id, 'branch_id' => $this->branch->id,
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2), 'max_capacity' => 12, 'status' => 'active',
        ]);
        $session = $this->makeSession(1);

        $this->actingAs($this->teacher)->get(route('classes.session-report', ['id' => $other->id, 'session' => $session->id]))->assertForbidden();
        $this->actingAs($this->admin)->get(route('classes.session-report', ['id' => $other->id, 'session' => $session->id]))->assertNotFound();
    }
}
