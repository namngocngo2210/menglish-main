<?php

namespace Tests\Feature;

use App\Http\Controllers\TeacherPortalController;
use App\Models\AcademicRecord;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\ClassReportStudentSupport;
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

        $this->actingAs($this->teacher)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'sessions']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sessions.0.can_submit_report', true)->where('sessions.0.teacher_report', null));
        $this->actingAs($this->foreign)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'sessions']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sessions.0.can_submit_report', false));

        $html = $this->actingAs($this->teacher)->get($this->url($session))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Classes/SessionReport')
                ->where('canSubmit', true)
                ->where('attendanceDone', false)
                ->where('sessionInfo.id', $session->id)
                ->where('checklist', fn ($items) => collect($items)->pluck('key')->all() === ['attendance', 'taught', 'homework'])
                ->where('remarkStudents.0.name', 'Lê Chi')->where('remarkOptions.result', ['Đạt', 'Không đạt']))
            ->getContent();
        // Nhận xét học sinh dạng bảng: mỗi học sinh một hàng.
        $this->assertMatchesRegularExpression('/data-report-section="remarks".*?<table.*?<tr[^>]*data-remark-row.*?Lê Chi/s', $html);
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

        // Học sinh vắng đã tự vào danh sách cần chú ý chung của buổi → hiện chỉ đọc, không gợi ý nhập lại.
        $this->actingAs($this->teacher)->get($this->url($session))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sharedAttention.0.student', 'Lê Chi')->where('sharedAttention.0.source', 'Vắng học')->where('suggestions', []));

        $this->actingAs($this->teacher)->post($this->url($session, 'classes.session-report.store'), $payload)
            ->assertSessionHasNoErrors()->assertRedirect(route('classes.show', ['id' => $this->class->id, 'tab' => 'sessions']));

        $report = TeacherSessionReport::sole();
        $this->assertSame([$this->teacher->id, 'Unit 3: animals', 'Lớp sôi nổi'], [(int) $report->teacher_id, $report->content, $report->class_situation]);
        $this->assertSame([['student_id' => $this->student->id, 'reason' => 'Vắng', 'action' => 'Gửi bài']], $report->attention);
        $this->assertFalse($report->isLate());
        $this->assertSame(0, ClassReport::count(), 'Không tạo báo cáo trực lớp của trợ giảng.');
        // Danh sách chung: không tạo dòng trùng cho học sinh vắng, hướng xử lý của GV ghi vào dòng đã có.
        $this->assertSame(['Gửi bài'], ClassReportStudentSupport::pluck('action_plan')->all());

        // Sửa lại: giữ giờ nộp đầu.
        $first = $report->submitted_at->toDateTimeString();
        $this->travel(1)->hours();
        $this->actingAs($this->teacher)->post($this->url($session, 'classes.session-report.store'), ['content' => 'Unit 3 + 4', 'attention' => $payload['attention']])->assertSessionHasNoErrors();
        $this->assertSame(['Unit 3 + 4', $first], [$report->fresh()->content, $report->fresh()->submitted_at->toDateTimeString()]);

        $this->actingAs($this->teacher)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'sessions']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sessions.0.teacher_report', ['late' => false])->where('sessionStats.reported', 1));

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
            'remarks' => [$this->student->id => ['attitude' => 'Tốt', 'result' => 'Đạt', 'comment' => 'Con phát biểu tốt']],
        ])->assertSessionHasNoErrors();

        $record = AcademicRecord::where('module', 'teacher_remarks')->where('record_code', TeacherPortalController::remarkRecordCode($session))->sole();
        $this->assertSame('completed', $record->status);
        $this->assertSame('Con phát biểu tốt', $record->data[$this->student->id]['comment'] ?? $record->data[(string) $this->student->id]['comment']);

        // Màn Nhận xét cũ chuyển về báo cáo buổi; mở lại báo cáo thấy đúng nhận xét đã nhập.
        $this->actingAs($this->teacher)->get(route('teacher.remarks', ['classId' => $this->class->id, 'session' => $session->id]))
            ->assertRedirect($this->url($session));
        $this->actingAs($this->admin)->get($this->url($session))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('remarkStudents.0.remark.comment', 'Con phát biểu tốt')->where('remarkStudents.0.remark.attitude', 'Tốt'));
    }

    public function test_teacher_and_assistant_share_lesson_content_and_attention_list(): void
    {
        $assistant = $this->makeUser('assistant', 'TA Bình');
        $this->class->update(['assistant_id' => $assistant->id]);
        $session = $this->makeSession(0);
        $session->update(['assistant_id' => $assistant->id]);
        $this->attend($session, 'present');
        $other = Student::create(['code' => 'HV-2', 'name' => 'Phạm Dung', 'phone' => '0900000002', 'branch_id' => $this->branch->id,
            'status' => 'studying', 'current_class_id' => $this->class->id]);

        // TA nộp trước GV: chọn buổi thì không nhập "Hôm nay học gì"; báo Phạm Dung cần bổ trợ.
        $this->actingAs($assistant)->post(route('tasks.class-reports.store'), [
            'class_id' => $this->class->id, 'class_session_id' => $session->id, 'hom_nay_hoc_gi' => 'Bị bỏ qua', 'board_image_url' => 'https://example.com/a.jpg',
            'supports' => [['student_id' => $other->id, 'reason' => 'Chưa theo kịp', 'action_plan' => 'Kèm 15 phút']],
        ])->assertSessionHasNoErrors();
        $this->assertNotSame('Bị bỏ qua', ClassReport::sole()->topics_learned);

        // GV thấy dòng TA báo (chỉ đọc), báo thêm Lê Chi; Phạm Dung GV ghi lại không tạo dòng trùng.
        $this->actingAs($this->teacher)->get($this->url($session))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sharedAttention.0.student', 'Phạm Dung')->where('sharedAttention.0.source', 'Báo cáo trực lớp'));
        $this->actingAs($this->teacher)->post($this->url($session, 'classes.session-report.store'), ['content' => 'Unit 5: food', 'attention' => [
            ['student_id' => $this->student->id, 'reason' => 'Mất tập trung', 'action' => 'Báo phụ huynh'],
            ['student_id' => $other->id, 'reason' => 'Chưa theo kịp', 'action' => 'Khác'],
        ]])->assertSessionHasNoErrors();

        $this->assertSame('Unit 5: food', ClassReport::sole()->topics_learned, 'Nội dung bài của TA lấy từ báo cáo buổi GV.');
        $rows = ClassReportStudentSupport::orderBy('id')->get();
        $this->assertSame([[$other->id, 'class_report', 'Kèm 15 phút'], [$this->student->id, 'teacher_report', 'Báo phụ huynh']],
            $rows->map(fn ($r) => [(int) $r->student_id, $r->source, $r->action_plan])->all());

        // Form TA của buổi: hiện nội dung GV + cả danh sách chung.
        $this->actingAs($assistant)->get(route('tasks.class-reports.create', ['class_id' => $this->class->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where("sessionDetails.{$session->id}.teacher_report.content", 'Unit 5: food')
                ->where("sessionDetails.{$session->id}.listed", fn ($listed) => collect($listed)->pluck('student')->sort()->values()->all() === ['Lê Chi', 'Phạm Dung']));

        // GV bỏ Lê Chi khỏi báo cáo → gỡ dòng của GV (chưa xếp bổ trợ).
        $this->actingAs($this->teacher)->post($this->url($session, 'classes.session-report.store'), ['content' => 'Unit 5: food'])->assertSessionHasNoErrors();
        $this->assertSame(['class_report'], ClassReportStudentSupport::pluck('source')->all());
    }

    public function test_report_after_deadline_is_late(): void
    {
        $session = $this->makeSession(3);
        $this->attend($session, 'present');

        $this->actingAs($this->teacher)->post($this->url($session, 'classes.session-report.store'), ['content' => 'Unit 2'])->assertSessionHasNoErrors();

        $this->assertTrue(TeacherSessionReport::sole()->isLate());
        $this->actingAs($this->teacher)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'sessions']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sessions.0.teacher_report', ['late' => true]));
    }

    public function test_report_saves_bonus_homework_score_and_named_practice_columns(): void
    {
        $session = $this->makeSession(0);
        $this->attend($session, 'present');
        $url = $this->url($session, 'classes.session-report.store');
        $sid = $this->student->id;

        $this->actingAs($this->teacher)->post($url, ['content' => 'Unit 3', 'remarks' => [$sid => ['bonus' => 'abc']]])
            ->assertSessionHasErrors("remarks.{$sid}.bonus");

        $this->actingAs($this->teacher)->post($url, [
            'content' => 'Unit 3',
            'practice_columns' => ['Speaking', '', 'Đọc'],
            'remarks' => [$sid => ['bonus' => '5', 'hw_score' => '8.5', 'practice' => ['Nói tốt', 'bỏ qua', ''], 'attitude' => 'Tốt']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['Speaking', 'Đọc'], TeacherSessionReport::sole()->practice_columns);
        $remark = TeacherPortalController::sessionRemarks($this->class, $session)->first();
        $this->assertSame(['5', '8.5', ['Speaking' => 'Nói tốt']], [$remark['bonus'], $remark['hw_score'], $remark['practice']]);

        $this->actingAs($this->admin)->get($this->url($session))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('practiceColumns', ['Speaking', 'Đọc'])
                ->where('remarkStudents.0.remark.practice', ['Speaking' => 'Nói tốt'])->where('remarkStudents.0.remark.bonus', '5'));

        // Buổi sau chưa có báo cáo: cột thực hành lấy theo báo cáo gần nhất của lớp.
        $next = $this->makeSession(0);
        $next->update(['start_time' => '09:45', 'end_time' => '11:00']);
        $this->actingAs($this->teacher)->get($this->url($next))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('practiceColumns', ['Speaking', 'Đọc']));
    }

    public function test_previous_session_homework_shows_per_student_status(): void
    {
        $previous = $this->makeSession(2);
        $session = $this->makeSession(0);
        $homework = \App\Models\Homework::create(['class_id' => $this->class->id, 'class_session_id' => $previous->id, 'user_id' => $this->teacher->id,
            'title' => 'BTVN', 'items' => ['workbook' => 'Trang 10', 'video' => 'Quay giới thiệu'], 'due_at' => today()->subDay()->setTime(20, 0)]);
        \App\Models\HomeworkSubmission::create(['homework_id' => $homework->id, 'student_id' => $this->student->id, 'basket' => 'paper',
            'status' => 'submitted', 'submitted_at' => today()->subDays(2)->setTime(19, 0)]);

        $this->actingAs($this->teacher)->get($this->url($session))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('homeworkColumns', fn ($cols) => collect($cols)->pluck('key')->all() === ['workbook', 'video'])
                ->where('remarkStudents.0.homework', ['workbook' => 'done', 'video' => 'missing'])
                ->where('checklist.2.link.url', route('teacher.homework', ['classId' => $this->class->id, 'session' => $session->id])));

        // Ngăn "Giao bài" (mở từ báo cáo, header remote modal): form gọn cho đúng buổi, hạn nộp = giờ vào buổi kế tiếp.
        $next = ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->addDays(2)->toDateString(),
            'start_time' => '08:00', 'end_time' => '09:30', 'type' => ClassSession::TYPE_REGULAR, 'status' => 'scheduled', 'teacher_id' => $this->teacher->id]);
        $this->actingAs($this->teacher)->withHeaders(['X-Remote-Modal' => 'true'])
            ->get(route('teacher.homework', ['classId' => $this->class->id, 'session' => $session->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Classes/HomeworkDrawer')
                ->where('session.id', $session->id)->where('dueDefault', $next->date->format('Y-m-d').'T08:00'));
        $this->flushHeaders();
    }

    public function test_incidents_in_report_are_logged_to_class_journal(): void
    {
        $session = $this->makeSession(0);
        $this->attend($session, 'present');
        $url = $this->url($session, 'classes.session-report.store');

        $this->actingAs($this->teacher)->post($url, ['content' => 'Unit 3', 'incidents' => [
            ['title' => 'HS ngã ở hành lang', 'content' => 'Đã báo phụ huynh', 'severity' => 'urgent'],
            ['title' => '', 'content' => ''],
        ]])->assertSessionHasNoErrors();

        $entry = \App\Models\StaffReport::sole();
        $this->assertSame(['journal', $this->class->id, 'urgent', 'open', $session->id], [$entry->type, (int) $entry->class_id, $entry->severity, $entry->status, $entry->data['class_session_id']]);
        $this->actingAs($this->admin)->get($this->url($session))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('incidents.0.title', 'HS ngã ở hành lang'));
        $this->actingAs($this->admin)->get(route('classes.show', $this->class->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('openIncidents', 1));

        // Sửa báo cáo: cập nhật đúng mục cũ; bỏ dòng → xóa mục.
        $this->actingAs($this->teacher)->post($url, ['content' => 'Unit 3', 'incidents' => [['id' => $entry->id, 'title' => 'HS ngã nhẹ', 'severity' => 'normal']]])->assertSessionHasNoErrors();
        $this->assertSame(['HS ngã nhẹ', 'normal'], [$entry->fresh()->title, $entry->fresh()->severity]);
        $this->actingAs($this->teacher)->post($url, ['content' => 'Unit 3'])->assertSessionHasNoErrors();
        $this->assertSame(0, \App\Models\StaffReport::count());
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
